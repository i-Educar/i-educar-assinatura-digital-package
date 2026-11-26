<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PedidoAssinaturaService
{
    public function __construct(
        private readonly LibreSignClient $libreSign = new LibreSignClient(),
        private readonly DocumentoValidacaoAssinatura $validacao = new DocumentoValidacaoAssinatura(),
    ) {}

    /**
     * @param  list<array{account?:string,email?:string,displayName?:string,password?:string}>  $signers
     * @return array<string,mixed>
     */
    public function criarEAssinarTeste(
        string $titulo,
        string $cenario,
        string $pdfBinary,
        array $signers,
        bool $assinarAgora = true,
    ): array {
        if (! $this->libreSign->configurado()) {
            throw new RuntimeException('Configure LIBRESIGN_BASE_URL / USER / PASSWORD.');
        }

        $created = $this->libreSign->requestSignature(
            $titulo,
            $pdfBinary,
            array_map(static fn (array $s): array => array_filter([
                'account' => $s['account'] ?? null,
                'email' => $s['email'] ?? null,
                'displayName' => $s['displayName'] ?? null,
            ]), $signers),
            $cenario === 'multipla' ? 'teste-multipla.pdf' : 'teste-unica.pdf',
        );

        $pedidoId = (int) DB::table('assinatura_digital.pedido')->insertGetId([
            'titulo' => $titulo,
            'cenario' => $cenario,
            'status' => 'aguardando',
            'status_libresign' => $created['status'],
            'libresign_file_id' => (string) ($created['id'] ?? ''),
            'libresign_uuid' => $created['uuid'] !== '' ? $created['uuid'] : null,
            'signatarios' => json_encode($signers, JSON_UNESCAPED_UNICODE),
            'meta' => json_encode(['somente_libresign' => true], JSON_UNESCAPED_UNICODE),
            'solicitante_id' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->evento($pedidoId, 'criado', 'Pedido enviado ao LibreSign.', $created);

        $assinaturas = [];
        if ($assinarAgora) {
            $assinaturas = $this->assinarTodos($created['uuid'], $signers);
            $this->sincronizarAposAssinatura($pedidoId, $created['uuid']);
        }

        return $this->obter($pedidoId) + ['assinaturas_teste' => $assinaturas];
    }

    /**
     * @param  list<array{account?:string,password?:string}>  $signers
     * @return list<array{account:string,ok:bool,mensagem:string}>
     */
    public function assinarTodos(string $fileUuid, array $signers): array
    {
        $out = [];
        foreach ($signers as $signer) {
            $account = (string) ($signer['account'] ?? '');
            $password = (string) ($signer['password'] ?? '');
            if ($account === '' || $password === '') {
                $out[] = ['account' => $account, 'ok' => false, 'mensagem' => 'Conta/senha do signatário ausente.'];
                continue;
            }

            $file = $this->libreSign->findFileByUuid($fileUuid, $account, $password);
            if ($file === null) {
                $out[] = ['account' => $account, 'ok' => false, 'mensagem' => 'Arquivo não visível para o signatário.'];
                continue;
            }

            $signUuid = null;
            foreach (($file['signers'] ?? []) as $row) {
                if (! empty($row['sign_request_uuid'])) {
                    // Preferir o sign request ainda não assinado deste account
                    $signed = (bool) ($row['signed'] ?? false);
                    if (! $signed) {
                        $signUuid = (string) $row['sign_request_uuid'];
                        break;
                    }
                }
            }
            if ($signUuid === null) {
                foreach (($file['signers'] ?? []) as $row) {
                    if (! empty($row['sign_request_uuid'])) {
                        $signUuid = (string) $row['sign_request_uuid'];
                        break;
                    }
                }
            }
            if ($signUuid === null) {
                $out[] = ['account' => $account, 'ok' => false, 'mensagem' => 'sign_request_uuid não encontrado.'];
                continue;
            }

            try {
                $resp = $this->libreSign->signByUuid($signUuid, 'clickToSign', $account, $password);
                $out[] = [
                    'account' => $account,
                    'ok' => true,
                    'mensagem' => (string) ($resp['message'] ?? 'Assinado'),
                ];
            } catch (\Throwable $e) {
                $out[] = ['account' => $account, 'ok' => false, 'mensagem' => $e->getMessage()];
            }
        }

        return $out;
    }

    public function sincronizarAposAssinatura(int $pedidoId, string $fileUuid): void
    {
        $file = $this->libreSign->findFileByUuid($fileUuid);
        $status = (int) ($file['status'] ?? 0);
        $statusText = (string) ($file['statusText'] ?? '');

        $update = [
            'status_libresign' => $status,
            'status' => $this->mapStatus($status),
            'updated_at' => now(),
            'meta' => json_encode([
                'somente_libresign' => true,
                'libresign_file' => [
                    'url' => $file['file']['url'] ?? null,
                    'signedNodeId' => $file['file']['signedNodeId'] ?? null,
                ],
                'signers' => $file['signers'] ?? [],
            ], JSON_UNESCAPED_UNICODE),
        ];

        if ($status === 3) {
            $update['assinado_em'] = now();
            $pedido = $this->obter($pedidoId);
            $reg = $this->validacao->registrar($pedido);
            if ($reg !== null) {
                $update['codigo_validacao'] = $reg['codigo'];
                $update['url_validacao'] = $reg['url'];
            }
        }

        DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->update($update);
        $this->evento($pedidoId, 'sincronizado', "Status LibreSign: {$status} {$statusText}", [
            'status' => $status,
            'statusText' => $statusText,
        ]);
    }

    /** Stream do PDF a partir do LibreSign (sem salvar no storage i-Educar). */
    public function baixarPdfAssinado(int $pedidoId): string
    {
        $pedido = $this->obter($pedidoId);
        if ($pedido === []) {
            throw new RuntimeException('Pedido não encontrado.');
        }

        $uuid = (string) ($pedido['libresign_uuid'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('Pedido sem UUID LibreSign.');
        }

        $file = $this->libreSign->findFileByUuid($uuid);
        if ($file === null) {
            throw new RuntimeException('Documento não encontrado no LibreSign.');
        }

        $url = (string) ($file['file']['url'] ?? '');
        if ($url === '') {
            // fallback: algumas versões expõem download em signed
            $url = (string) ($file['file']['signed'] ?? '');
        }
        if ($url === '') {
            throw new RuntimeException('LibreSign não retornou URL do PDF. Documento ainda não assinado?');
        }

        return $this->libreSign->downloadFromUrl($url);
    }

    /** @return list<array<string,mixed>> */
    public function listar(int $limit = 50): array
    {
        return DB::table('assinatura_digital.pedido')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->hydrate((array) $row))
            ->all();
    }

    /** @return array<string,mixed> */
    public function obter(int $id): array
    {
        $row = DB::table('assinatura_digital.pedido')->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : [];
    }

    public function pdfDemo(string $titulo): string
    {
        // PDF mínimo válido (1 página) — suficiente para o LibreSign aceitar base64.
        $texto = substr(preg_replace('/[^\x20-\x7E]/', '', $titulo) ?: 'Assinatura', 0, 60);
        $stream = "BT /F1 18 Tf 50 750 Td (".addcslashes($texto, '()\\').") Tj ET";
        $objects = [];
        $objects[] = "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
        $objects[] = "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
        $objects[] = "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj\n";
        $objects[] = '4 0 obj<< /Length '.strlen($stream)." >>stream\n{$stream}\nendstream endobj\n";
        $objects[] = "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($offsets))."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer<< /Size '.count($offsets).' /Root 1 0 R >>\n';
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private function mapStatus(int $status): string
    {
        return match ($status) {
            0 => 'rascunho',
            1 => 'aguardando',
            2 => 'parcial',
            3 => 'assinado',
            4 => 'excluido',
            default => 'desconhecido',
        };
    }

    /** @param  array<string,mixed>  $payload */
    private function evento(int $pedidoId, string $tipo, string $mensagem, array $payload = []): void
    {
        DB::table('assinatura_digital.evento')->insert([
            'pedido_id' => $pedidoId,
            'tipo' => $tipo,
            'mensagem' => $mensagem,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    /** @param  array<string,mixed>  $row */
    private function hydrate(array $row): array
    {
        foreach (['signatarios', 'meta'] as $jsonField) {
            if (isset($row[$jsonField]) && is_string($row[$jsonField])) {
                $decoded = json_decode($row[$jsonField], true);
                $row[$jsonField] = is_array($decoded) ? $decoded : [];
            }
        }

        return $row;
    }
}
