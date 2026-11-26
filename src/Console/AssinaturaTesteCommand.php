<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Console;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use Illuminate\Console\Command;

final class AssinaturaTesteCommand extends Command
{
    protected $signature = 'assinatura:teste
        {cenario : unica|multipla}
        {--sem-assinar : Só cria o pedido no LibreSign, sem clickToSign}';

    protected $description = 'Roda teste de assinatura digital contra o LibreSign de desenvolvimento';

    public function handle(PedidoAssinaturaService $pedidos, LibreSignClient $client): int
    {
        $cenario = strtolower((string) $this->argument('cenario'));
        if (! in_array($cenario, ['unica', 'multipla'], true)) {
            $this->error('Cenário inválido. Use: unica | multipla');

            return self::FAILURE;
        }

        if (! $client->configurado()) {
            $this->error('Configure LIBRESIGN_BASE_URL, LIBRESIGN_USER e LIBRESIGN_PASSWORD.');

            return self::FAILURE;
        }

        if (! $client->ping()) {
            $this->warn('Nextcloud/LibreSign não respondeu em '.$client->base().' — tentando mesmo assim.');
        }

        $titulo = ($cenario === 'unica' ? 'CLI assinatura única' : 'CLI múltiplas assinaturas')
            .' — '.now()->toDateTimeString();
        $signers = [[
            'account' => (string) config('assinatura.libresign.signer1'),
            'password' => (string) config('assinatura.libresign.signer1_password'),
            'displayName' => 'Assinante 1',
        ]];
        if ($cenario === 'multipla') {
            $signers[] = [
                'account' => (string) config('assinatura.libresign.signer2'),
                'password' => (string) config('assinatura.libresign.signer2_password'),
                'displayName' => 'Assinante 2',
            ];
        }

        $this->info("Enviando PDF ao LibreSign ({$cenario})...");
        $pedido = $pedidos->criarEAssinarTeste(
            $titulo,
            $cenario,
            $pedidos->pdfDemo($titulo),
            $signers,
            ! $this->option('sem-assinar'),
        );

        $this->table(
            ['Campo', 'Valor'],
            [
                ['id', $pedido['id'] ?? ''],
                ['status', $pedido['status'] ?? ''],
                ['libresign_uuid', $pedido['libresign_uuid'] ?? ''],
                ['codigo_validacao', $pedido['codigo_validacao'] ?? '—'],
                ['url_validacao', $pedido['url_validacao'] ?? '—'],
                ['ver', url('/assinatura-digital/pedidos/'.($pedido['id'] ?? 0).'/ver')],
                ['baixar', url('/assinatura-digital/pedidos/'.($pedido['id'] ?? 0).'/baixar')],
            ]
        );

        foreach (($pedido['assinaturas_teste'] ?? []) as $a) {
            $icon = ($a['ok'] ?? false) ? 'OK' : 'FALHA';
            $this->line("  [{$icon}] {$a['account']}: {$a['mensagem']}");
        }

        $this->info('PDF permanece no LibreSign (sem cópia no storage i-Educar).');

        return self::SUCCESS;
    }
}
