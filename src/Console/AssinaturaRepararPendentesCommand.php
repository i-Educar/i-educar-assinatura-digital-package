<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Console;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use iEducar\Packages\BuritiAssinaturaDigital\Services\UsuarioLibreSign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repara pedidos em aguardando/parcial: tenta clickToSign + sincroniza status,
 * e regenera HMAC de códigos públicos vinculados.
 */
final class AssinaturaRepararPendentesCommand extends Command
{
    protected $signature = 'assinatura:reparar-pendentes
        {--limite=100 : Máximo de pedidos a processar}
        {--somente-hmac : Só regenera HMAC dos códigos, sem assinar no LibreSign}
        {--dry-run : Lista o que seria feito}';

    protected $description = 'Conclui assinaturas pendentes no LibreSign e corrige códigos HMAC inválidos';

    public function handle(
        PedidoAssinaturaService $pedidos,
        LibreSignClient $client,
        UsuarioLibreSign $usuarios,
    ): int {
        $limite = max(1, (int) $this->option('limite'));
        $dry = (bool) $this->option('dry-run');
        $somenteHmac = (bool) $this->option('somente-hmac');

        $hmacOk = $this->repararHmacAssinaturas($dry);
        $this->info("HMAC regenerados: {$hmacOk}");

        if ($somenteHmac) {
            return self::SUCCESS;
        }

        if (! $client->configurado()) {
            $this->error('LibreSign não configurado.');

            return self::FAILURE;
        }

        $rows = DB::table('assinatura_digital.pedido')
            ->whereIn('status', ['aguardando', 'parcial'])
            ->whereNotNull('libresign_uuid')
            ->orderBy('id')
            ->limit($limite)
            ->get()
            ->filter(static fn ($r) => trim((string) ($r->libresign_uuid ?? '')) !== '')
            ->values();

        $this->info('Pedidos pendentes: '.$rows->count());
        $assinados = 0;
        $sincronizados = 0;
        $falhas = 0;

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $uuid = (string) $row->libresign_uuid;
            $titulo = (string) $row->titulo;
            $this->line("#{$id} {$titulo}");

            if ($dry) {
                continue;
            }

            try {
                $signers = json_decode((string) $row->signatarios, true);
                if (! is_array($signers)) {
                    $signers = [];
                }
                $comSenha = [];
                foreach ($signers as $s) {
                    if (! is_array($s)) {
                        continue;
                    }
                    $account = trim((string) ($s['account'] ?? ''));
                    $password = trim((string) ($s['password'] ?? ''));
                    if ($password === '' && $account !== '') {
                        $password = $usuarios->senhaDaConta($account);
                    }
                    if ($account === '' || $password === '') {
                        continue;
                    }
                    $comSenha[] = array_merge($s, ['account' => $account, 'password' => $password]);
                }

                if ($comSenha !== []) {
                    $res = $pedidos->assinarTodos($uuid, $comSenha);
                    foreach ($res as $r) {
                        $icon = ! empty($r['ok']) ? 'ok' : 'falha';
                        $this->line("  [{$icon}] {$r['account']}: {$r['mensagem']}");
                    }
                }

                $pedidos->sincronizarAposAssinatura($id, $uuid);
                $atual = $pedidos->obter($id);
                $st = (string) ($atual['status'] ?? '');
                $this->line("  → status {$st}");
                if ($st === 'assinado') {
                    $assinados++;
                } else {
                    $sincronizados++;
                }
            } catch (Throwable $e) {
                $falhas++;
                $this->warn('  erro: '.$e->getMessage());
            }
        }

        // Almox: reprocessa notas com etapas assinadas sem LibreSign concluído.
        if (class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\NotaAssinaturaDigital::class)
            && class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::class)
            && ! $dry
        ) {
            $this->repararNotasAlmox($limite);
        }

        $this->info("Concluídos: {$assinados} · sincronizados: {$sincronizados} · falhas: {$falhas}");

        return $falhas > 0 && $assinados === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function repararHmacAssinaturas(bool $dry): int
    {
        if (! class_exists(\iEducar\Packages\AdvancedReports\Models\AdvancedReportsDocument::class)
            || ! class_exists(\iEducar\Packages\AdvancedReports\Services\DocumentSigningService::class)
        ) {
            return 0;
        }

        $signing = app(\iEducar\Packages\AdvancedReports\Services\DocumentSigningService::class);
        $q = \iEducar\Packages\AdvancedReports\Models\AdvancedReportsDocument::query()
            ->where(function ($w): void {
                $w->where('type', 'like', 'almox_%')
                    ->orWhere('type', 'like', 'assinatura_%')
                    ->orWhere('type', 'like', '%libresign%');
            })
            ->orderByDesc('id')
            ->limit(500);

        $n = 0;
        foreach ($q->get() as $doc) {
            $payload = is_array($doc->payload) ? $doc->payload : [];
            $payload = json_decode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', true) ?: [];
            if ($doc->issued_at === null) {
                continue;
            }
            $ok = $signing->verifyStoredDocument(
                (string) $doc->mac,
                (string) $doc->code,
                (string) $doc->type,
                $doc->issued_at,
                $payload,
            );
            if ($ok) {
                continue;
            }
            $issuedAtIso = \iEducar\Packages\AdvancedReports\Services\DocumentSigningService::issuedAtForMac($doc->issued_at);
            $novo = $signing->mac((string) $doc->code, (string) $doc->type, $issuedAtIso, $payload);
            $this->line('HMAC '.$doc->code.' ('.$doc->type.')');
            if (! $dry) {
                $doc->mac = $novo;
                // Alinha URL pública canônica no payload.
                if (class_exists(\iEducar\Packages\BuritiAssinaturaDigital\Services\ValidacaoPublicaService::class)) {
                    $payload['validation_url'] = app(\iEducar\Packages\BuritiAssinaturaDigital\Services\ValidacaoPublicaService::class)
                        ->urlPublica((string) $doc->code);
                    $doc->payload = $payload;
                    $doc->mac = $signing->mac((string) $doc->code, (string) $doc->type, $issuedAtIso, $payload);
                }
                $doc->save();
            }
            $n++;
        }

        return $n;
    }

    private function repararNotasAlmox(int $limite): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('modules.buriti_poc_registro')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $nota = new \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\NotaAssinaturaDigital();
        $rows = DB::table('modules.buriti_poc_registro')
            ->where('tipo', 'almox_pedido')
            ->orderByDesc('id')
            ->limit($limite)
            ->get(['id', 'payload']);

        $alt = 0;
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (! is_array($payload)) {
                continue;
            }
            try {
                $r = $nota->repararSeNecessario((int) $row->id, $payload);
                if (! empty($r['alterado'])) {
                    DB::table('modules.buriti_poc_registro')->where('id', $row->id)->update([
                        'payload' => json_encode($r['payload'], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                    $alt++;
                    $this->line('Almox pedido #'.$row->id.' reparado');
                }
            } catch (Throwable $e) {
                $this->warn('Almox #'.$row->id.': '.$e->getMessage());
            }
        }
        $this->info("Notas almox reparadas: {$alt}");
    }
}
