<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

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
            $out[] = $this->assinarUm(
                $fileUuid,
                (string) ($signer['account'] ?? ''),
                (string) ($signer['password'] ?? ''),
            );
        }

        return $out;
    }

    /**
     * Assina um slot no LibreSign (clickToSign) de forma resiliente para o usuário leigo.
     * Localiza o arquivo como integrador (sempre vê o pedido) e assina com a conta certa.
     *
     * @return array{account:string,ok:bool,mensagem:string}
     */
    public function assinarUm(string $fileUuid, string $account, string $password, ?string $accountPreferida = null): array
    {
        $account = trim($account);
        $password = trim($password);
        $preferida = trim((string) $accountPreferida);
        if ($preferida === '') {
            $preferida = $account;
        }

        // 1) Integrador enxerga o pedido; 2) se faltar sign_uuid, busca como a conta do signatário
        //    (o LibreSign só devolve sign_uuid com "me": true para quem deve assinar).
        $file = $this->libreSign->findFileByUuid($fileUuid);
        $lsSigners = is_array($file['signers'] ?? null) ? $file['signers'] : [];
        $alvo = $file !== null
            ? $this->resolverSignerPendente($lsSigners, $preferida !== '' ? $preferida : $account)
            : null;

        $contaLookup = $preferida !== '' ? $preferida : $account;
        $senhaLookup = $password;
        if ($alvo === null && $contaLookup !== '') {
            $usuariosTmp = app(UsuarioLibreSign::class);
            if ($senhaLookup === '') {
                $senhaLookup = $usuariosTmp->senhaDaConta($contaLookup);
            }
            if ($senhaLookup !== '') {
                $fileAsSigner = $this->libreSign->findFileByUuid($fileUuid, $contaLookup, $senhaLookup);
                if ($fileAsSigner !== null) {
                    $file = $fileAsSigner;
                    $lsSigners = is_array($file['signers'] ?? null) ? $file['signers'] : [];
                    $alvo = $this->resolverSignerPendente($lsSigners, $contaLookup);
                }
            }
        }

        if ($file === null) {
            return [
                'account' => $account,
                'ok' => false,
                'mensagem' => 'Arquivo não encontrado no LibreSign (integrador/signatário).',
            ];
        }

        if ($alvo === null) {
            return [
                'account' => $account,
                'ok' => false,
                'mensagem' => 'Nenhuma assinatura pendente neste arquivo LibreSign.',
            ];
        }

        $signUuid = $alvo['uuid'];
        $lsAccount = $alvo['account'] !== '' ? $alvo['account'] : $account;

        $usuarios = app(UsuarioLibreSign::class);
        $pwdLs = $usuarios->senhaDaConta($lsAccount);
        if ($pwdLs === '' && strcasecmp($lsAccount, $account) === 0) {
            $pwdLs = $password;
        }
        if ($pwdLs === '' && $password !== '' && $account !== '') {
            // Senha conhecida de outra conta mapeada — tenta só se for a mesma identidade.
            $pwdLs = strcasecmp($lsAccount, $account) === 0 ? $password : '';
        }

        $tentativas = [];
        if ($lsAccount !== '' && $pwdLs !== '') {
            $tentativas[] = [$lsAccount, $pwdLs];
        }
        if ($account !== '' && $password !== ''
            && ($lsAccount === '' || strcasecmp($account, $lsAccount) !== 0 || $pwdLs === '')) {
            $tentativas[] = [$account, $password];
        }
        // Integrador (conta que criou o pedido) — clickToSign automático para usuário leigo.
        $tentativas[] = [null, null];

        $ultimoErro = 'Falha ao assinar no LibreSign.';
        foreach ($tentativas as [$asUser, $asPass]) {
            try {
                $resp = $this->libreSign->signByUuid(
                    $signUuid,
                    'clickToSign',
                    $asUser,
                    $asPass,
                );

                return [
                    'account' => $lsAccount !== '' ? $lsAccount : (string) ($asUser ?? $account),
                    'ok' => true,
                    'mensagem' => (string) ($resp['message'] ?? 'Assinado'),
                ];
            } catch (Throwable $e) {
                $ultimoErro = $e->getMessage();
            }
        }

        return [
            'account' => $lsAccount !== '' ? $lsAccount : $account,
            'ok' => false,
            'mensagem' => $ultimoErro,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $signers
     * @return array{uuid:string,account:string}|null
     */
    private function resolverSignerPendente(array $signers, string $accountPreferida): ?array
    {
        $pendentes = [];
        foreach ($signers as $row) {
            if (! is_array($row) || ! empty($row['signed'])) {
                continue;
            }
            $uuid = (string) ($row['sign_uuid'] ?? $row['sign_request_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $pendentes[] = [
                'uuid' => $uuid,
                'account' => $this->accountDoSignerLibreSign($row),
            ];
        }
        if ($pendentes === []) {
            return null;
        }

        $pref = mb_strtolower(trim($accountPreferida));
        if ($pref !== '') {
            foreach ($pendentes as $p) {
                if ($p['account'] !== '' && mb_strtolower($p['account']) === $pref) {
                    return $p;
                }
            }
        }

        return $pendentes[0];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function accountDoSignerLibreSign(array $row): string
    {
        foreach (($row['identifyMethods'] ?? []) as $m) {
            if (! is_array($m)) {
                continue;
            }
            if (($m['method'] ?? '') === 'account') {
                $v = trim((string) ($m['value'] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        $identify = $row['identify'] ?? null;
        if (is_array($identify)) {
            $v = trim((string) ($identify['account'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return trim((string) ($row['account'] ?? ''));
    }

    public function sincronizarAposAssinatura(int $pedidoId, string $fileUuid): void
    {
        $file = $this->libreSign->findFileByUuid($fileUuid);
        $status = (int) ($file['status'] ?? 0);
        $statusText = (string) ($file['statusText'] ?? '');
        $pedidoAtual = $this->obter($pedidoId);

        $meta = is_array($pedidoAtual['meta'] ?? null) ? $pedidoAtual['meta'] : [];
        $meta['somente_libresign'] = true;
        $meta['libresign_file'] = [
            'url' => $file['file']['url'] ?? null,
            'signedNodeId' => $file['file']['signedNodeId'] ?? null,
        ];
        $meta['signers'] = $file['signers'] ?? [];

        // Atualiza status visual de cada assinatura conforme retorno do LibreSign.
        if (! empty($meta['assinaturas_visuais']) && is_array($meta['assinaturas_visuais'])) {
            $lsSigners = is_array($file['signers'] ?? null) ? $file['signers'] : [];
            foreach ($meta['assinaturas_visuais'] as $i => $visual) {
                if (! is_array($visual)) {
                    continue;
                }
                $signed = $status === 3;
                if ($lsSigners !== []) {
                    $account = strtolower(trim((string) ($visual['account'] ?? '')));
                    foreach ($lsSigners as $ls) {
                        if (! is_array($ls)) {
                            continue;
                        }
                        $lsAccount = '';
                        foreach (($ls['identifyMethods'] ?? []) as $m) {
                            if (($m['method'] ?? '') === 'account') {
                                $lsAccount = strtolower(trim((string) ($m['value'] ?? '')));
                                break;
                            }
                        }
                        if ($account !== '' && $lsAccount === $account) {
                            $signed = ! empty($ls['signed']);
                            break;
                        }
                        if ($account === '' && isset($lsSigners[$i])) {
                            $signed = ! empty($lsSigners[$i]['signed']);
                        }
                    }
                }
                $meta['assinaturas_visuais'][$i]['status'] = $signed
                    ? 'Assinado digitalmente'
                    : ($status === 2 ? 'Assinatura parcial' : 'Aguardando assinatura digital');
                $meta['assinaturas_visuais'][$i]['signed'] = $signed;
                if ($signed) {
                    $meta['assinaturas_visuais'][$i]['quando'] = $pedidoAtual['assinado_em']
                        ?? now()->toDateTimeString();
                    // Mantém link do cidadão no portal; LS fica só em url_validacao_libresign.
                    $portal = trim((string) ($meta['assinaturas_visuais'][$i]['url_validacao_portal'] ?? ''));
                    $atual = trim((string) ($meta['assinaturas_visuais'][$i]['url_validacao'] ?? ''));
                    if ($portal !== '') {
                        if ($atual !== '' && str_contains($atual, '/apps/libresign/')) {
                            $meta['assinaturas_visuais'][$i]['url_validacao_libresign'] = $atual;
                        }
                        $meta['assinaturas_visuais'][$i]['url_validacao'] = $portal;
                    }
                }
            }
        }

        $update = [
            'status_libresign' => $status,
            'status' => $this->mapStatus($status),
            'updated_at' => now(),
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ];

        if ($status === 3) {
            $update['assinado_em'] = now();
            if (empty($pedidoAtual['codigo_validacao'])) {
                $reg = $this->validacao->registrar($pedidoAtual);
                if ($reg !== null) {
                    $update['codigo_validacao'] = $reg['codigo'];
                    $update['url_validacao'] = $reg['url'];
                }
            }
        }

        DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->update($update);
        $this->evento($pedidoId, 'sincronizado', "Status LibreSign: {$status} {$statusText}", [
            'status' => $status,
            'statusText' => $statusText,
        ]);
    }

    /** Stream do PDF a partir do LibreSign (com recuperação de visual institucional). */
    public function baixarPdfAssinado(int $pedidoId): string
    {
        $pedido = $this->obter($pedidoId);
        if ($pedido === []) {
            throw new RuntimeException('Pedido não encontrado.');
        }

        $cachePath = storage_path('app/assinatura-digital/pdf-visual-'.$pedidoId.'.pdf');

        // Nota almox: prioriza PDF institucional (4 assinaturas + mesmos dados do export da nota).
        if ($this->pedidoEhAlmoxNota($pedido)) {
            $visual = $this->regenerarPdfVisualInstitucional($pedido);
            if ($visual !== null) {
                @mkdir(dirname($cachePath), 0775, true);
                @file_put_contents($cachePath, $visual);

                return $visual;
            }
            if (is_file($cachePath)) {
                $cached = (string) file_get_contents($cachePath);
                if ($this->pdfTemVisualInstitucional($cached)) {
                    return $cached;
                }
            }
        } elseif (is_file($cachePath)) {
            $cached = (string) file_get_contents($cachePath);
            if ($this->pdfTemVisualInstitucional($cached)) {
                return $cached;
            }
        }

        $uuid = (string) ($pedido['libresign_uuid'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('Pedido sem UUID LibreSign.');
        }

        $file = $this->libreSign->findFileByUuid($uuid);
        if ($file === null) {
            throw new RuntimeException('Documento não encontrado no LibreSign.');
        }

        $urls = $this->urlsArquivoCandidatas($file);
        if ($urls === []) {
            throw new RuntimeException('LibreSign não retornou URL do PDF. Documento ainda não assinado?');
        }

        $ultimo = 'LibreSign não devolveu PDF válido.';
        $bin = null;
        foreach ($urls as $url) {
            try {
                $bin = $this->libreSign->downloadFromUrl($url);
                break;
            } catch (Throwable $e) {
                $ultimo = $e->getMessage();
            }
        }
        if ($bin === null || $bin === '') {
            throw new RuntimeException($ultimo);
        }

        if ($this->pdfTemVisualInstitucional($bin)) {
            return $bin;
        }

        $visual = $this->regenerarPdfVisualInstitucional($pedido);
        if ($visual !== null) {
            @mkdir(dirname($cachePath), 0775, true);
            @file_put_contents($cachePath, $visual);

            return $visual;
        }

        return $bin;
    }

    /**
     * Blocos da lateral em /ver e /pedidos/{id}: para almox, sempre as 4 etapas da nota.
     *
     * @param  array<string,mixed>  $pedido
     * @return list<array<string,mixed>>
     */
    public function listaAssinantesExibicao(array $pedido): array
    {
        $meta = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];
        $almoxId = (int) ($meta['pedido_id'] ?? 0);
        if ($almoxId <= 0 && preg_match('/Nota almoxarifado #(\d+)/ui', (string) ($pedido['titulo'] ?? ''), $m)) {
            $almoxId = (int) $m[1];
        }
        if ($this->pedidoEhAlmoxNota($pedido) && $almoxId > 0
            && class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\NotaAssinaturaDigital::class)
        ) {
            try {
                $lista = app(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\NotaAssinaturaDigital::class)
                    ->assinantesParaExibicao($almoxId);
                if ($lista !== []) {
                    return $lista;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $visuais = is_array($meta['assinaturas_visuais'] ?? null) ? $meta['assinaturas_visuais'] : [];
        if ($visuais !== []) {
            return array_values(array_filter($visuais, 'is_array'));
        }

        $signatarios = is_array($pedido['signatarios'] ?? null) ? $pedido['signatarios'] : [];

        return array_values(array_filter($signatarios, 'is_array'));
    }

    /** @param  array<string,mixed>  $pedido */
    private function pedidoEhAlmoxNota(array $pedido): bool
    {
        $meta = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];
        $origem = mb_strtolower(trim((string) ($meta['origem'] ?? '')));
        $modulo = mb_strtolower(trim((string) ($meta['modulo_origem'] ?? '')));
        $titulo = (string) ($pedido['titulo'] ?? '');

        return str_contains($origem, 'almox')
            || str_contains($modulo, 'almox')
            || (! empty($meta['pedido_id']) && ! empty($meta['etapa']))
            || (bool) preg_match('/Nota almoxarifado #\d+/ui', $titulo);
    }

    private function pdfTemVisualInstitucional(string $bin): bool
    {
        if ($bin === '' || ! str_starts_with($bin, '%PDF')) {
            return false;
        }
        if (str_contains($bin, 'BT /F1 10 Tf')) {
            return false;
        }

        return strlen($bin) > 4000;
    }

    /** @param  array<string, mixed>  $pedido */
    private function regenerarPdfVisualInstitucional(array $pedido): ?string
    {
        if (! class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\NotaAssinaturaDigital::class)) {
            return null;
        }

        $titulo = (string) ($pedido['titulo'] ?? '');
        $meta = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];

        $almoxId = (int) ($meta['pedido_id'] ?? 0);
        $etapa = trim((string) ($meta['etapa'] ?? ''));

        // Título antigo: "Nota almoxarifado #N — Etapa" ou só "Nota almoxarifado #N".
        if (preg_match('/Nota almoxarifado #(\d+)(?:\s*[—\-]\s*(.+))?$/ui', $titulo, $m)) {
            if ($almoxId <= 0) {
                $almoxId = (int) $m[1];
            }
            if ($etapa === '' && ! empty($m[2])) {
                $rotulo = mb_strtolower(trim($m[2]));
                $mapa = [
                    'separação' => 'separacao',
                    'separacao' => 'separacao',
                    'aceite' => 'aceite',
                    'entrega' => 'entrega',
                    'conferência' => 'conferencia',
                    'conferencia' => 'conferencia',
                ];
                $etapa = $mapa[$rotulo] ?? '';
            }
        }

        if ($almoxId <= 0) {
            return null;
        }

        try {
            $nota = app(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\NotaAssinaturaDigital::class);
            // Pré-visualização: só gera o PDF institucional (não reenvia ao LibreSign).
            $pdf = $nota->pdfInstitucional($almoxId);
            if (is_string($pdf) && $this->pdfTemVisualInstitucional($pdf)) {
                return $pdf;
            }

            // Fallback legado: tenta regenerar no LS se a etapa estiver conhecida.
            if ($etapa !== '') {
                $res = $nota->regenerarPdfLibreSign($almoxId, $etapa);
                $pdf = is_array($res) ? ($res['pdf'] ?? null) : null;
                if (is_string($pdf) && $this->pdfTemVisualInstitucional($pdf)) {
                    return $pdf;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }
    public function sincronizarPendentesRecentes(int $limit = 50): void
    {
        if (! $this->libreSign->configurado()) {
            return;
        }

        $rows = DB::table('assinatura_digital.pedido')
            ->whereIn('status', ['aguardando', 'parcial', ''])
            ->whereNotNull('libresign_uuid')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get(['id', 'libresign_uuid']);

        foreach ($rows as $row) {
            $uuid = trim((string) ($row->libresign_uuid ?? ''));
            if ($uuid === '') {
                continue;
            }
            try {
                $this->sincronizarAposAssinatura((int) $row->id, $uuid);
            } catch (Throwable) {
                //
            }
        }
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
            $rowAccount = $this->accountDoSignerLibreSign($row);
            $candidatos[] = compact('uuid', 'signed', 'rowAccount');
        }

        $accLower = mb_strtolower(trim($account));
        foreach ($candidatos as $c) {
            $rowAcc = mb_strtolower(trim((string) $c['rowAccount']));
            if (! $c['signed'] && ($rowAcc === '' || ($accLower !== '' && $rowAcc === $accLower))) {
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

    /**
     * Preferência: PDF assinado (quando existir) → original Dompdf → path → fallback público.
     *
     * @param  array<string,mixed>  $file
     * @return list<string>
     */
    private function urlsArquivoCandidatas(array $file): array
    {
        $out = [];
        $node = $file['file'] ?? null;
        if (is_string($node) && $node !== '') {
            $out[] = $node;
        }
        if (is_array($node)) {
            // url/original = PDF Dompdf (layout institucional); signed = versão LS (pode ter carimbo).
            // Tentamos todos até achar binário %PDF válido.
            foreach (['url', 'original', 'signed', 'path'] as $k) {
                if (! empty($node[$k]) && is_string($node[$k])) {
                    $out[] = $node[$k];
                }
            }
        }
        foreach (['url', 'original', 'signed', 'path'] as $k) {
            if (! empty($file[$k]) && is_string($file[$k])) {
                $out[] = $file[$k];
            }
        }

        $uuid = trim((string) ($file['uuid'] ?? ''));
        if ($uuid !== '') {
            $out[] = '/apps/libresign/p/pdf/'.$uuid;
            $out[] = '/index.php/apps/libresign/pdf/'.$uuid;
        }

        $uniq = [];
        foreach ($out as $u) {
            $u = trim($u);
            if ($u !== '' && ! in_array($u, $uniq, true)) {
                $uniq[] = $u;
            }
        }

        return $uniq;
    }

    /** @param  array<string,mixed>  $file */
    private function extrairUrlArquivo(array $file): string
    {
        return $this->urlsArquivoCandidatas($file)[0] ?? '';
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
    public function hydrate(array $row): array
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
