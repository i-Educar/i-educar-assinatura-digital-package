<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Http\Controllers;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssinaturaController extends Controller
{
    public function __construct(
        private readonly PedidoAssinaturaService $pedidos,
        private readonly LibreSignClient $libreSign,
    ) {}

    public function painel(): View
    {
        $pedidos = $this->pedidos->listar(10);

        return view('assinatura::painel', [
            'pedidos' => $pedidos,
            'libresignOk' => $this->libreSign->configurado() && $this->libreSign->ping(),
            'baseUrl' => $this->libreSign->base(),
        ]);
    }

    public function pedidos(): View
    {
        return view('assinatura::pedidos.index', [
            'pedidos' => $this->pedidos->listar(100),
        ]);
    }

    public function show(int $id): View
    {
        $pedido = $this->pedidos->obter($id);
        abort_if($pedido === [], 404);

        return view('assinatura::pedidos.show', [
            'pedido' => $pedido,
        ]);
    }

    public function ver(int $id): StreamedResponse|Response
    {
        $bin = $this->pedidos->baixarPdfAssinado($id);

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="assinado-'.$id.'.pdf"',
            'Cache-Control' => 'no-store',
            'X-Assinatura-Storage' => 'libresign-proxy',
        ]);
    }

    public function baixar(int $id): StreamedResponse|Response
    {
        $bin = $this->pedidos->baixarPdfAssinado($id);

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="assinado-'.$id.'.pdf"',
            'Cache-Control' => 'no-store',
            'X-Assinatura-Storage' => 'libresign-proxy',
        ]);
    }

    public function sincronizar(int $id)
    {
        $pedido = $this->pedidos->obter($id);
        abort_if($pedido === [], 404);
        $uuid = (string) ($pedido['libresign_uuid'] ?? '');
        abort_if($uuid === '', 422, 'Pedido sem UUID LibreSign.');

        $this->pedidos->sincronizarAposAssinatura($id, $uuid);

        return redirect()
            ->route('assinatura.pedidos.show', $id)
            ->with('ok', 'Status sincronizado com o LibreSign.');
    }
}
