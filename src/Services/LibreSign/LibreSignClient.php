<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente OCS da API LibreSign (Nextcloud).
 *
 * Preferência: formato atual (`signers` + `identifyMethods`, cloud.libresign.coop).
 * Fallback legado: LibreSign 10.x (`users` + `identify`).
 *
 * @see https://docs.libresign.coop/main/developer_manual/api/guide-api.html
 */
final class LibreSignClient
{
    private static bool $rodapeAplicado = false;

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        private readonly ?bool $verifySsl = null,
        private readonly ?int $timeout = null,
    ) {}

    public function configurado(): bool
    {
        return $this->base() !== '' && $this->user() !== '' && $this->password() !== '';
    }

    public function base(): string
    {
        return rtrim((string) ($this->baseUrl ?? config('assinatura.libresign.base_url')), '/');
    }

    public function apiBase(): string
    {
        return $this->base().'/ocs/v2.php/apps/libresign/api/v1';
    }

    /**
     * URL pública do validador LibreSign (apps/libresign/validation/{uuid}).
     */

    /**
     * Atualiza o display name da conta Nextcloud (aparece na validação LibreSign).
     */
    public function definirNomeExibicaoConta(string $account, string $displayName): bool
    {
        $account = trim($account);
        $displayName = trim($displayName);
        if ($account === '' || $displayName === '') {
            return false;
        }
        try {
            $adminUser = trim((string) config('assinatura.libresign.admin_user', 'admin'));
            $adminPass = (string) config('assinatura.libresign.admin_password', 'admin');
            $asUser = $adminUser !== '' ? $adminUser : $this->user();
            $asPass = $adminUser !== '' ? $adminPass : $this->password();
            $url = $this->base().'/ocs/v1.php/cloud/users/'.rawurlencode($account);
            $res = Http::withBasicAuth($asUser, $asPass)
                ->withHeaders([
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ])
                ->timeout(8)
                ->connectTimeout(3)
                ->withOptions(['verify' => (bool) ($this->verifySsl ?? false)])
                ->put($url, [
                    'key' => 'displayname',
                    'value' => $displayName,
                ]);
            if (! $res->successful()) {
                // Fallback form-urlencoded (OCS clássico).
                $res = Http::withBasicAuth($asUser, $asPass)
                    ->asForm()
                    ->withHeaders([
                        'OCS-APIRequest' => 'true',
                        'Accept' => 'application/json',
                    ])
                    ->timeout(8)
                    ->connectTimeout(3)
                    ->withOptions(['verify' => (bool) ($this->verifySsl ?? false)])
                    ->put($url, [
                        'key' => 'displayname',
                        'value' => $displayName,
                    ]);
            }

            return $res->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function urlValidacao(string $uuid): string
    {
        $uuid = trim($uuid);
        $base = $this->publicBase();
        if ($uuid === '' || $base === '') {
            return '';
        }

        return $base.'/apps/libresign/validation/'.$uuid;
    }

    /**
     * Host alcançável no browser (evita host.docker.internal nas telas públicas).
     */
    public function publicBase(): string
    {
        $public = rtrim((string) config('assinatura.libresign.public_url', ''), '/');
        if ($public !== '') {
            return $public;
        }
        $base = $this->base();
        // PHP no Docker usa host.docker.internal; o browser no host precisa de localhost.
        return str_replace('://host.docker.internal', '://localhost', $base);
    }


    /**
     * LibreSign monta pasta/arquivo a partir de name — barras (ex.: 28/11/2026) geram 422.
     */
    private function nomeSeguroLibreSign(string $nome, bool $arquivo = false): string
    {
        $nome = trim(str_replace(["\0", "\r", "\n"], '', $nome));
        // Separadores de path e caracteres que o Nextcloud/LibreSign rejeitam em pastas.
        $nome = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $nome);
        $nome = preg_replace('/\s+/u', ' ', $nome) ?? $nome;
        $nome = trim($nome, " .-_");
        if ($nome === '') {
            $nome = $arquivo ? 'documento.pdf' : 'documento';
        }
        if ($arquivo) {
            $base = pathinfo($nome, PATHINFO_FILENAME);
            $base = trim((string) $base);
            if ($base === '') {
                $base = 'documento';
            }
            // Espaços → underscore no arquivo (Nextcloud costuma normalizar assim).
            $base = str_replace(' ', '_', $base);
            $nome = $base.'.pdf';
        }

        return mb_substr($nome, 0, 180);
    }

    /**
     * Tenta substituir o PDF de um pedido ainda não assinado (melhor esforço).
     */
    public function substituirPdf(string $uuid, string $pdfBinary, string $fileName = 'documento.pdf'): bool
    {
        $uuid = trim($uuid);
        if ($uuid === '' || $pdfBinary === '') {
            return false;
        }

        $fileName = $this->nomeSeguroLibreSign($fileName, true);

        $payload = [
            'uuid' => $uuid,
            'file' => [
                'name' => $fileName,
                'base64' => base64_encode($pdfBinary),
            ],
        ];

        try {
            $this->post('/request-signature', $payload);

            return true;
        } catch (\Throwable) {
            //
        }

        try {
            $this->post('/file/uuid/'.rawurlencode($uuid), [
                'file' => $payload['file'],
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Cria fluxo de assinatura com PDF em base64.
     *
     * @param  list<array{account?:string,email?:string,displayName?:string}>  $signers
     * @return array{id:mixed,uuid:string,status:int,statusText?:string,raw:array}
     */
    public function requestSignature(string $name, string $pdfBinary, array $signers, string $fileName = 'documento.pdf'): array
    {
        // Título com data d/m/Y vira path inválido no Nextcloud ("Could not create path").
        $name = $this->nomeSeguroLibreSign($name, false);
        $fileName = $this->nomeSeguroLibreSign($fileName, true);

        $users = [];
        $signersPayload = [];
        foreach ($signers as $signer) {
            $account = trim((string) ($signer['account'] ?? ''));
            $email = trim((string) ($signer['email'] ?? ''));
            if ($account === '' && $email === '') {
                throw new RuntimeException('Cada signatário precisa de account ou email.');
            }
            // Preferir displayName/description já montados (ex.: "matricula — Almoxarifado escolar").
            $label = trim((string) ($signer['displayName'] ?? $signer['description'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($signer['nome'] ?? ''));
            }
            $cargo = trim((string) ($signer['cargo'] ?? ''));
            // Só prefixa cargo se o rótulo ainda não veio completo.
            if ($label !== '' && str_contains($label, ' — ')) {
                // rótulo explícito — não alterar
            } elseif ($cargo !== '' && $label !== '' && ! str_contains($label, $cargo)) {
                $label = $cargo.' — '.$label;
            } elseif ($label === '' && $cargo !== '') {
                $label = $cargo;
            }
            // Validação LibreSign mostra displayName da conta NC — alinhamos antes do pedido
            // (cache por conta+rótulo: evita N PUTs lentos/429 a cada assinatura de nota).
            if ($account !== '' && $label !== '') {
                $cacheNome = 'assinatura.ls.displayname.'.md5(mb_strtolower($account).'|'.$label);
                if (! Cache::has($cacheNome)) {
                    if ($this->definirNomeExibicaoConta($account, $label)) {
                        Cache::put($cacheNome, 1, now()->addHours(12));
                    }
                }
            }

            // Formato legado (LibreSign 10 / self-host antigo).
            $identify = $account !== ''
                ? ['account' => $account]
                : ['email' => $email];
            $legacy = ['identify' => $identify];
            if ($label !== '') {
                $legacy['description'] = $label;
                $legacy['displayName'] = $label;
            }
            $users[] = $legacy;

            // Formato atual (cloud.libresign.coop e docs recentes).
            $methods = $account !== ''
                ? [['method' => 'account', 'value' => $account]]
                : [['method' => 'email', 'value' => $email]];
            $modern = ['identifyMethods' => $methods];
            if ($label !== '') {
                $modern['description'] = $label;
                $modern['displayName'] = $label;
            }
            $signersPayload[] = $modern;
        }

        if ($signersPayload === []) {
            throw new RuntimeException('Informe ao menos um signatário (account ou email).');
        }

        // Rodapé mínimo (sem QR) antes de criar o pedido — evita carimbo sobre o documento.
        $this->garantirRodapeSimples();

        $file = [
            'name' => $fileName,
            'base64' => base64_encode($pdfBinary),
        ];

        // Cloud/API nova exige `signers`+identifyMethods; `users` sozinho → "Empty signers list".
        try {
            $data = $this->post('/request-signature', [
                'name' => $name,
                'file' => $file,
                'signers' => $signersPayload,
            ]);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $precisaLegado = str_contains($msg, 'Empty signers')
                || str_contains($msg, 'identify')
                || str_contains($msg, 'users');
            if (! $precisaLegado) {
                throw $e;
            }
            $data = $this->post('/request-signature', [
                'name' => $name,
                'file' => $file,
                'users' => $users,
            ]);
        }

        $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;

        return [
            'id' => $inner['id'] ?? $inner['nodeId'] ?? null,
            'uuid' => (string) ($inner['uuid'] ?? ''),
            'status' => (int) ($inner['status'] ?? 0),
            'statusText' => (string) ($inner['statusText'] ?? ''),
            'raw' => $inner,
        ];
    }

    /**
     * @return array{data:list<array<string,mixed>>,raw:array}
     */
    public function listFiles(bool $details = true, ?array $status = null, ?string $asUser = null, ?string $asPassword = null): array
    {
        $query = [];
        if ($details) {
            $query['details'] = 1;
        }
        if ($status !== null) {
            foreach ($status as $i => $st) {
                $query["status[{$i}]"] = $st;
            }
        }

        $data = $this->get('/file/list', $query, $asUser, $asPassword);
        $rows = $data['data'] ?? $data;
        if (is_array($rows) && isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }

        return [
            'data' => is_array($rows) ? array_values($rows) : [],
            'raw' => $data,
        ];
    }

    /**
     * Localiza um arquivo pelo UUID e devolve signers com sign_uuid / sign_request_uuid.
     *
     * @return array<string,mixed>|null
     */
    public function findFileByUuid(string $uuid, ?string $asUser = null, ?string $asPassword = null): ?array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }

        // Preferência: endpoint direto (não depende de paginação/visibilidade da lista).
        try {
            $data = $this->get('/file/uuid/'.rawurlencode($uuid), [], $asUser, $asPassword);
            $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;
            if (is_array($inner) && (string) ($inner['uuid'] ?? '') === $uuid) {
                return $inner;
            }
            if (is_array($inner) && isset($inner['file']) && is_array($inner['file'])) {
                return $inner;
            }
        } catch (\Throwable) {
            //
        }

        $list = $this->listFiles(true, null, $asUser, $asPassword);
        foreach ($list['data'] as $row) {
            if ((string) ($row['uuid'] ?? '') === $uuid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function signByUuid(string $signRequestUuid, string $method = 'clickToSign', ?string $asUser = null, ?string $asPassword = null): array
    {
        return $this->post(
            '/sign/uuid/'.rawurlencode($signRequestUuid),
            ['method' => $method],
            $asUser,
            $asPassword,
        );
    }

    /**
     * Configura no LibreSign um rodapé elegante e baixo: só “Signed by LibreSign”, sem QR.
     * Best-effort: em cloud (ex. cloud.libresign.coop) a API de config costuma cair em HTTP 429
     * (bruteforce Nextcloud) — aí o rodapé fica a cargo da instância (painel/occ).
     *
     * @return array{ok:bool,via:string,mensagem:string}
     */
    public function garantirRodapeSimples(bool $forcar = false): array
    {
        if (self::$rodapeAplicado && ! $forcar) {
            return ['ok' => true, 'via' => 'cache', 'mensagem' => 'Rodapé já aplicado nesta requisição.'];
        }

        $cacheKey = $this->chaveCacheRodape();
        $cooldownKey = $cacheKey.':cooldown';

        if (! $forcar && Cache::get($cacheKey) === 'ok') {
            self::$rodapeAplicado = true;

            return ['ok' => true, 'via' => 'cache', 'mensagem' => 'Rodapé já confirmado (cache).'];
        }

        if (! $this->deveAplicarRodapeViaApi()) {
            self::$rodapeAplicado = true;
            Cache::put($cacheKey, 'ok', now()->addDay());

            return [
                'ok' => true,
                'via' => 'instancia',
                'mensagem' => 'Rodapé gerenciado na instância LibreSign (API de config desligada para este host).',
            ];
        }

        if (! $forcar && Cache::has($cooldownKey)) {
            return [
                'ok' => false,
                'via' => 'cooldown',
                'mensagem' => 'LibreSign em cooldown após HTTP 429. Aguarde alguns minutos ou configure o rodapé no painel/occ da instância.',
            ];
        }

        $enabled = (bool) config('assinatura.libresign.footer_enabled', true);
        $qrcode = (bool) config('assinatura.libresign.footer_qrcode', false);
        $signedBy = trim((string) config('assinatura.libresign.footer_signed_by', 'Signed by LibreSign'));
        if ($signedBy === '') {
            $signedBy = 'Signed by LibreSign';
        }
        $validateIn = (string) config('assinatura.libresign.footer_validate_in', '');
        $template = $this->templateRodapeSimples();

        $policy = json_encode([
            'enabled' => $enabled,
            'writeQrcodeOnFooter' => $qrcode,
            'validationSite' => '',
            'customizeFooterTemplate' => true,
        ], JSON_UNESCAPED_UNICODE);

        $adminUser = trim((string) config('assinatura.libresign.admin_user', 'admin'));
        $adminPass = (string) config('assinatura.libresign.admin_password', 'admin');
        $asUser = $adminUser !== '' ? $adminUser : $this->user();
        $asPass = $adminUser !== '' ? $adminPass : $this->password();

        $erros = [];
        $okParcial = false;

        // Policy API (LibreSign recente) — uma tentativa; 429 aborta o lote.
        try {
            $this->putPolicySystem('add_footer', (string) $policy, $asUser, $asPass);
            $okParcial = true;
            usleep(350_000);
        } catch (\Throwable $e) {
            $erros[] = 'policy: '.$e->getMessage();
            if ($this->ehRateLimit($e)) {
                $this->registrarCooldownRodape($cooldownKey);

                return [
                    'ok' => false,
                    'via' => 'rate-limit',
                    'mensagem' => 'LibreSign limitou requisições (HTTP 429). Não reenvie agora — '
                        .'aguarde o delay do Nextcloud ou configure o rodapé no painel/occ. '.$e->getMessage(),
                ];
            }
        }

        // App configs (LibreSign 10.x / estáveis) — com pausa e abort em 429.
        $configs = [
            'add_footer' => $enabled ? '1' : '0',
            'write_qrcode_on_footer' => $qrcode ? '1' : '0',
            'footer_signed_by' => $signedBy,
            'footer_validate_in' => $validateIn,
            'footer_template' => $template,
            'footer_link_to_site' => 'https://libresign.coop',
        ];
        foreach ($configs as $key => $value) {
            try {
                $this->setAppConfig('libresign', $key, $value, $asUser, $asPass);
                $okParcial = true;
                usleep(350_000);
            } catch (\Throwable $e) {
                $erros[] = $key.': '.$e->getMessage();
                if ($this->ehRateLimit($e)) {
                    $this->registrarCooldownRodape($cooldownKey);

                    return [
                        'ok' => $okParcial,
                        'via' => $okParcial ? 'api-parcial' : 'rate-limit',
                        'mensagem' => ($okParcial
                            ? 'Rodapé parcial; parado no HTTP 429. '
                            : 'LibreSign limitou requisições (HTTP 429). ')
                            .'Aguarde alguns minutos ou use occ/painel. '.$e->getMessage(),
                    ];
                }
            }
        }

        if ($okParcial) {
            self::$rodapeAplicado = true;
            Cache::put($cacheKey, 'ok', now()->addDay());
            Cache::forget($cooldownKey);

            return [
                'ok' => true,
                'via' => $erros === [] ? 'api' : 'api-parcial',
                'mensagem' => $erros === []
                    ? 'Rodapé LibreSign simples aplicado (sem QR).'
                    : 'Rodapé parcialmente aplicado: '.implode('; ', array_slice($erros, 0, 3)),
            ];
        }

        return [
            'ok' => false,
            'via' => 'falha',
            'mensagem' => 'Não foi possível aplicar rodapé via API. No Docker: bootstrap/occ; '
                .'em cloud: configure no painel LibreSign (LIBRESIGN_FOOTER_VIA_API=false). '
                .implode('; ', array_slice($erros, 0, 3)),
        ];
    }

    /**
     * Hosts locais (Docker) empurram config; cloud/SaaS não (evita 429).
     */
    public function deveAplicarRodapeViaApi(): bool
    {
        $raw = config('assinatura.libresign.footer_via_api');
        if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
            return false;
        }
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            return true;
        }

        $host = strtolower((string) (parse_url($this->base(), PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }

        $locais = [
            'localhost',
            '127.0.0.1',
            'host.docker.internal',
            'nextcloud',
            'ieducar-libresign-nc',
        ];
        if (in_array($host, $locais, true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        // cloud.libresign.coop e demais SaaS: não martelar provisioning_api.
        return false;
    }

    private function chaveCacheRodape(): string
    {
        return 'assinatura:libresign:rodape:'.md5($this->base().'|'.(string) config('assinatura.libresign.footer_signed_by'));
    }

    private function registrarCooldownRodape(string $cooldownKey): void
    {
        Cache::put($cooldownKey, 1, now()->addMinutes(20));
    }

    private function ehRateLimit(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, '429')
            || str_contains($msg, 'maximum delay')
            || str_contains($msg, 'Reached maximum');
    }

    /**
     * Template Twig/mPDF: uma linha, sem QR, sem página extra.
     */
    public function templateRodapeSimples(): string
    {
        return <<<'TWIG'
<table style="width:100%;border:0;border-collapse:collapse;margin:0;padding:0;">
<tr>
<td style="text-align:center;vertical-align:middle;padding:0;margin:0;font-size:7px;line-height:1.1;color:#475569;border-top:0.35pt solid #94a3b8;">
<a href="{{ linkToSite }}" style="text-decoration:none;color:#0f4c5c;font-weight:bold;">{{ signedBy }}</a>
</td>
</tr>
</table>
TWIG;
    }

    private function putPolicySystem(string $key, string $value, ?string $asUser = null, ?string $asPassword = null): void
    {
        $response = $this->http($asUser, $asPassword)
            ->asJson()
            ->put('policies/system/'.rawurlencode($key), [
                'value' => $value,
            ]);
        $this->assertOk($response->status(), $response->json() ?? [], 'PUT policies/system/'.$key);
    }

    private function setAppConfig(string $app, string $key, string $value, ?string $asUser = null, ?string $asPassword = null): void
    {
        $base = $this->base();
        $response = Http::withBasicAuth($asUser ?? $this->user(), $asPassword ?? $this->password())
            ->withHeaders([
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeout())
            ->withOptions(['verify' => $this->verify()])
            ->asForm()
            ->post(
                $base.'/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/'.rawurlencode($app).'/'.rawurlencode($key),
                ['value' => $value],
            );

        $status = $response->status();
        $json = $response->json() ?? [];
        $meta = is_array($json['ocs']['meta'] ?? null) ? $json['ocs']['meta'] : [];
        $code = (int) ($meta['statuscode'] ?? $status);
        if ($status < 200 || $status >= 300 || ($code !== 0 && ($code < 200 || $code >= 300))) {
            $msg = (string) ($meta['message'] ?? 'HTTP '.$status);
            throw new RuntimeException('config '.$app.'/'.$key.': '.$msg);
        }
    }

    /**
     * Baixa o PDF a partir da URL do Nextcloud/LibreSign (sem gravar no i-Educar).
     * Usa cliente HTTP próprio — não o OCS (baseUrl da API + Accept JSON quebram o binário).
     */
    public function downloadFromUrl(string $url): string
    {
        $absolute = $this->resolverUrlInterna($url);
        if ($absolute === '' || $absolute === $this->base()) {
            throw new RuntimeException('URL do PDF LibreSign vazia.');
        }

        $response = Http::withBasicAuth($this->user(), $this->password())
            ->withHeaders([
                'Accept' => 'application/pdf,*/*',
                'OCS-APIRequest' => 'true',
            ])
            ->timeout($this->timeout())
            ->withOptions(['verify' => $this->verify()])
            ->get($absolute);

        if (! $response->successful()) {
            throw new RuntimeException('Falha ao baixar PDF do LibreSign (HTTP '.$response->status().').');
        }

        $bin = $response->body();
        if ($bin === '' || ! str_starts_with($bin, '%PDF')) {
            throw new RuntimeException('LibreSign não devolveu um PDF válido (conteúdo inesperado).');
        }

        return $bin;
    }

    /**
     * Converte URL relativa/absoluta do Nextcloud para o host interno (ex.: host.docker.internal).
     * LibreSign costuma devolver localhost:8088 (OVERWRITEHOST) — inacessível de dentro do FPM.
     */
    public function resolverUrlInterna(string $url): string
    {
        $base = $this->base();
        if ($url === '') {
            return $base;
        }
        if (! str_starts_with($url, 'http')) {
            return $base.'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);
        $baseParts = parse_url($base);
        if (! is_array($parts) || ! is_array($baseParts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $publicHosts = ['localhost', '127.0.0.1', 'nextcloud', 'ieducar-libresign-nc'];
        if (in_array($host, $publicHosts, true) || $host === strtolower((string) ($baseParts['host'] ?? ''))) {
            $scheme = (string) ($baseParts['scheme'] ?? 'http');
            $newHost = (string) ($baseParts['host'] ?? $host);
            $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';

            return $scheme.'://'.$newHost.$port.$path.$query;
        }

        return $url;
    }

    public function ping(): bool
    {
        try {
            $response = Http::withOptions(['verify' => $this->verify()])
                ->timeout(8)
                ->get($this->base().'/status.php');

            return $response->successful() && (bool) ($response->json('installed') ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function user(): string
    {
        return (string) ($this->user ?? config('assinatura.libresign.user'));
    }

    private function password(): string
    {
        return (string) ($this->password ?? config('assinatura.libresign.password'));
    }

    private function verify(): bool
    {
        return (bool) ($this->verifySsl ?? config('assinatura.libresign.verify_ssl', false));
    }

    private function timeout(): int
    {
        return (int) ($this->timeout ?? config('assinatura.libresign.timeout', 60));
    }

    private function http(?string $asUser = null, ?string $asPassword = null): PendingRequest
    {
        if (! $this->configurado() && ($asUser === null || $asPassword === null)) {
            throw new RuntimeException('LibreSign não configurado (LIBRESIGN_BASE_URL / USER / PASSWORD).');
        }

        return Http::withBasicAuth($asUser ?? $this->user(), $asPassword ?? $this->password())
            ->withHeaders([
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeout())
            ->withOptions(['verify' => $this->verify()])
            ->baseUrl($this->apiBase());
    }

    /** @param  array<string,mixed>  $query */
    private function get(string $path, array $query = [], ?string $asUser = null, ?string $asPassword = null): array
    {
        $response = $this->http($asUser, $asPassword)->get(ltrim($path, '/'), $query);
        $this->assertOk($response->status(), $response->json() ?? [], 'GET '.$path);

        return $this->unwrap($response->json() ?? []);
    }

    /** @param  array<string,mixed>  $body */
    private function post(string $path, array $body, ?string $asUser = null, ?string $asPassword = null): array
    {
        $response = $this->http($asUser, $asPassword)
            ->asJson()
            ->post(ltrim($path, '/'), $body);
        $this->assertOk($response->status(), $response->json() ?? [], 'POST '.$path);

        return $this->unwrap($response->json() ?? []);
    }

    /** @param  array<string,mixed>  $json */
    private function unwrap(array $json): array
    {
        if (isset($json['ocs']['data']) && is_array($json['ocs']['data'])) {
            return $json['ocs']['data'];
        }

        return $json;
    }

    /** @param  array<string,mixed>  $json */
    private function assertOk(int $status, array $json, string $ctx): void
    {
        $meta = $json['ocs']['meta'] ?? null;
        $code = is_array($meta) ? (int) ($meta['statuscode'] ?? $status) : $status;
        $ok = $status >= 200 && $status < 300 && ($code === 0 || ($code >= 200 && $code < 300));
        if ($ok) {
            return;
        }

        $data = $json['ocs']['data'] ?? [];
        $msg = is_array($meta) ? (string) ($meta['message'] ?? '') : '';
        if ($msg === '' && is_array($data)) {
            $msg = (string) ($data['message'] ?? '');
            if ($msg === '' && ! empty($data['errors']) && is_array($data['errors'])) {
                $parts = [];
                foreach ($data['errors'] as $err) {
                    if (is_array($err) && isset($err['message'])) {
                        $parts[] = (string) $err['message'];
                    } elseif (is_string($err)) {
                        $parts[] = $err;
                    }
                }
                $msg = implode('; ', $parts);
            }
        }

        throw new RuntimeException("LibreSign {$ctx} falhou (HTTP {$status}/OCS {$code}): {$msg}");
    }
}
