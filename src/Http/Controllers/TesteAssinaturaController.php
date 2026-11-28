<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Http\Controllers;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Throwable;

final class TesteAssinaturaController extends Controller
{
    public function __construct(
        private readonly PedidoAssinaturaService $pedidos,
        private readonly LibreSignClient $libreSign,
    ) {}

    public function index(): View
    {
        return view('assinatura::testes.index', [
            'libresignOk' => $this->libreSign->configurado() && $this->libreSign->ping(),
            'baseUrl' => $this->libreSign->base(),
            'signer1' => (string) config('assinatura.libresign.signer1'),
            'signer2' => (string) config('assinatura.libresign.signer2'),
        ]);
    }

    public function unica(): RedirectResponse
    {
        return $this->executar('unica');
    }

    public function multipla(): RedirectResponse
    {
        return $this->executar('multipla');
    }

    private function executar(string $cenario): RedirectResponse
    {
        try {
            $pedido = $this->montar($cenario);

            return redirect()
                ->route('assinatura.pedidos.show', (int) $pedido['id'])
                ->with('ok', $cenario === 'unica'
                    ? 'Teste de assinatura única concluído. PDF no LibreSign; use Ver/Baixar (sem storage local).'
                    : 'Teste de múltiplas assinaturas concluído. PDF no LibreSign; validação pública registrada se status=assinado.');
        } catch (Throwable $e) {
            return redirect()
                ->route('assinatura.testes')
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function montar(string $cenario): array
    {
        $titulo = $cenario === 'unica'
            ? 'Teste assinatura única — '.now()->format('d-m-Y H:i')
            : 'Teste múltiplas assinaturas — '.now()->format('d-m-Y H:i');

        $pdf = $this->pedidos->pdfDemo($titulo);
        $signers = [
            [
                'account' => (string) config('assinatura.libresign.signer1'),
                'password' => (string) config('assinatura.libresign.signer1_password'),
                'displayName' => 'Assinante 1',
            ],
        ];
        if ($cenario === 'multipla') {
            $signers[] = [
                'account' => (string) config('assinatura.libresign.signer2'),
                'password' => (string) config('assinatura.libresign.signer2_password'),
                'displayName' => 'Assinante 2',
            ];
        }

        return $this->pedidos->criarEAssinarTeste($titulo, $cenario, $pdf, $signers, true);
    }
}
