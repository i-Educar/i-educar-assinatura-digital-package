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

            $signUuid = $this->extrairSignUuid($file['signers'] ?? [], $account);
            if ($signUuid === null) {
                $out[] = ['account' => $account, 'ok' => false, 'mensagem' => 'sign_uuid / sign_request_uuid não encontrado.'];
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

        $url = $this->extrairUrlArquivo($file);
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
        // PDF gerado por Ghostscript — LibreSign 10 rejeita PDFs “mínimos” artesanais (Invalid PDF).
        // O título segue no metadado do pedido; o binário é estável para o fluxo de teste.
        unset($titulo);
        $bin = base64_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/pdf/demo-assinatura.pdf.b64'), true);
        if ($bin === false || $bin === '' || ! str_starts_with($bin, '%PDF')) {
            throw new RuntimeException('PDF demo inválido (resources/pdf/demo-assinatura.pdf.b64).');
        }

        return $bin;
    }

    /**
     * @param  list<array<string,mixed>>  $signers
     */
    private function extrairSignUuid(array $signers, string $account): ?string
    {
        $candidatos = [];
        foreach ($signers as $row) {
            $uuid = (string) ($row['sign_uuid'] ?? $row['sign_request_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $signed = ! empty($row['signed']);
            $rowAccount = '';
            foreach (($row['identifyMethods'] ?? []) as $m) {
                if (($m['method'] ?? '') === 'account') {
                    $rowAccount = (string) ($m['value'] ?? '');
                    break;
                }
            }
            $candidatos[] = compact('uuid', 'signed', 'rowAccount');
        }

        foreach ($candidatos as $c) {
            if (! $c['signed'] && ($c['rowAccount'] === '' || $c['rowAccount'] === $account)) {
                return $c['uuid'];
            }
        }
        foreach ($candidatos as $c) {
            if (! $c['signed']) {
                return $c['uuid'];
            }
        }

        return $candidatos[0]['uuid'] ?? null;
    }

    /** @param  array<string,mixed>  $file */
    private function extrairUrlArquivo(array $file): string
    {
        $node = $file['file'] ?? null;
        if (is_string($node) && $node !== '') {
            return $node;
        }
        if (is_array($node)) {
            foreach (['url', 'signed', 'path'] as $k) {
                if (! empty($node[$k]) && is_string($node[$k])) {
                    return $node[$k];
                }
            }
        }

        $uuid = (string) ($file['uuid'] ?? '');

        return $uuid !== '' ? '/apps/libresign/p/pdf/'.$uuid : '';
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
