<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente OCS da API LibreSign (Nextcloud).
 *
 * Compatível com LibreSign 10.x (`users` + `identify`) e com o formato
 * documentado mais recente (`signers` + `identifyMethods`) como fallback.
 *
 * @see https://docs.libresign.coop/main/developer_manual/api/guide-api.html
 */
final class LibreSignClient
{
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
     * Cria fluxo de assinatura com PDF em base64.
     *
     * @param  list<array{account?:string,email?:string,displayName?:string}>  $signers
     * @return array{id:mixed,uuid:string,status:int,statusText?:string,raw:array}
     */
    public function requestSignature(string $name, string $pdfBinary, array $signers, string $fileName = 'documento.pdf'): array
    {
        $users = [];
        foreach ($signers as $signer) {
            $identify = [];
            if (! empty($signer['account'])) {
                $identify['account'] = (string) $signer['account'];
            } elseif (! empty($signer['email'])) {
                $identify['email'] = (string) $signer['email'];
            }
            if ($identify === []) {
                throw new RuntimeException('Cada signatário precisa de account ou email.');
            }
            $row = ['identify' => $identify];
            if (! empty($signer['displayName'])) {
                $row['description'] = (string) $signer['displayName'];
            }
            $users[] = $row;
        }

        // LibreSign 10.x espera `users` + `identify`. Tentamos esse formato primeiro.
        $data = $this->post('/request-signature', [
            'name' => $name,
            'file' => [
                'name' => $fileName,
                'base64' => base64_encode($pdfBinary),
            ],
            'users' => $users,
        ]);

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
     * Baixa o PDF assinado a partir da URL retornada pelo LibreSign (sem gravar no i-Educar).
     */
    public function downloadFromUrl(string $url): string
    {
        $absolute = $this->resolverUrlInterna($url);
        $response = $this->http()->get($absolute);
        if (! $response->successful()) {
            throw new RuntimeException('Falha ao baixar PDF do LibreSign (HTTP '.$response->status().').');
        }

        return $response->body();
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
