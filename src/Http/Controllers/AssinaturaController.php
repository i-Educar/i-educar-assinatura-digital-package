<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Http\Controllers;

use iEducar\Packages\BuritiAssinaturaDigital\Services\ConfigAssinatura;
use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PendenciasAssinaturaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AssinaturaController extends Controller
{
    public function __construct(
        private readonly PedidoAssinaturaService $pedidos,
        private readonly LibreSignClient $libreSign,
        private readonly PendenciasAssinaturaService $pendencias,
    ) {}

    public function painel(): View
    {
        $pedidos = $this->pedidos->listar(10);
        $cfg = ConfigAssinatura::estadoAtual();

        return view('assinatura::painel', [
            'pedidos' => $pedidos,
            'libresignOk' => $this->libreSign->configurado() && $this->libreSign->ping(),
            'baseUrl' => $this->libreSign->base(),
            'cfg' => $cfg,
        ]);
    }

    public function configuracoes(): View
    {
        return view('assinatura::configuracoes', [
            'cfg' => ConfigAssinatura::estadoAtual(),
            'libresignOk' => $this->libreSign->configurado() && $this->libreSign->ping(),
            'baseUrl' => $this->libreSign->base(),
            'urlModulos' => url('/configuracoes/modulos?modulo=assinatura&abrir=1'),
        ]);
    }

    public function repararPendentes(): RedirectResponse
    {
        try {
            $code = Artisan::call('assinatura:reparar-pendentes', ['--limite' => 200]);
            $resumo = trim(Artisan::output());
            $msg = $resumo !== ''
                ? preg_replace('/\s+/u', ' ', $resumo)
                : ($code === 0
                    ? 'Reparo de assinaturas pendentes e códigos HMAC concluído.'
                    : 'Reparo executado com avisos; verifique LibreSign e senhas dos signatários.');

            return redirect()
                ->route('assinatura.configuracoes')
                ->with($code === 0 ? 'ok' : 'erro', mb_substr((string) $msg, 0, 2000));
        } catch (Throwable $e) {
            return redirect()
                ->route('assinatura.configuracoes')
                ->with('erro', 'Falha no reparo: '.$e->getMessage());
        }
    }

    public function pingLibreSign(): RedirectResponse
    {
        try {
            if (! $this->libreSign->configurado()) {
                return redirect('/assinatura-digital/configuracoes')
                    ->with('erro', 'Configure URL, usuário e senha do LibreSign em Configurações → Módulos.');
            }
            $ok = $this->libreSign->ping();
            $rodapeMsg = '';
            if ($ok) {
                $rodape = $this->libreSign->garantirRodapeSimples(true);
                $rodapeMsg = ' · Rodapé: '.($rodape['mensagem'] ?? '');
            }

            return redirect('/assinatura-digital/configuracoes')
                ->with($ok ? 'ok' : 'erro', $ok
                    ? 'LibreSign respondeu em '.$this->libreSign->base().$rodapeMsg
                    : 'Instância não respondeu ao ping: '.$this->libreSign->base());
        } catch (Throwable $e) {
            return redirect('/assinatura-digital/configuracoes')
                ->with('erro', 'Falha ao contactar LibreSign: '.$e->getMessage());
        }
    }

    public function pedidos(): View
    {
        try {
            $this->pedidos->sincronizarPendentesRecentes(60);
        } catch (Throwable) {
            //
        }

        return view('assinatura::pedidos.index', [
            'pedidos' => $this->pedidos->listar(100),
        ]);
    }

    public function show(int $id): View
    {
        $pedido = $this->pedidos->obter($id);
        abort_if($pedido === [], 404);

        // Pedidos de módulos (não-CLI): sincroniza status real ao abrir o detalhe.
        $uuid = (string) ($pedido['libresign_uuid'] ?? '');
        $status = (string) ($pedido['status'] ?? '');
        if ($uuid !== '' && in_array($status, ['aguardando', 'parcial', ''], true)) {
            try {
                $this->pedidos->sincronizarAposAssinatura($id, $uuid);
                $pedido = $this->pedidos->obter($id);
            } catch (Throwable) {
                // Mantém o snapshot local se o LibreSign estiver indisponível.
            }
        }

        return view('assinatura::pedidos.show', [
            'pedido' => $pedido,
            'assinantes' => $this->pedidos->listaAssinantesExibicao($pedido),
        ]);
    }

    public function ver(int $id): View
    {
        $pedido = $this->pedidos->obter($id);
        abort_if($pedido === [], 404);

        $uuid = (string) ($pedido['libresign_uuid'] ?? '');
        $status = (string) ($pedido['status'] ?? '');
        if ($uuid !== '' && in_array($status, ['aguardando', 'parcial', ''], true)) {
            try {
                $this->pedidos->sincronizarAposAssinatura($id, $uuid);
                $pedido = $this->pedidos->obter($id);
            } catch (Throwable) {
                //
            }
        }

        $erroPdf = null;
        try {
            $bin = $this->pedidos->baixarPdfAssinado($id);
            if ($bin === '' || ! str_starts_with($bin, '%PDF')) {
                $erroPdf = 'O LibreSign não devolveu um PDF com visual válido.';
            }
        } catch (Throwable $e) {
            $erroPdf = $e->getMessage();
        }

        return view('assinatura::pedidos.ver', [
            'pedido' => $pedido,
            'assinantes' => $this->pedidos->listaAssinantesExibicao($pedido),
            'erroPdf' => $erroPdf,
        ]);
    }

    public function pdf(int $id): StreamedResponse|Response
    {
        $bin = $this->pedidos->baixarPdfAssinado($id);

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="documento-'.$id.'.pdf"',
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

    public function pendencias(): View
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        try {
            $this->pedidos->sincronizarPendentesRecentes(40);
        } catch (Throwable) {
            //
        }

        return view('assinatura::pendencias', [
            'itens' => $this->pendencias->listarParaUsuario($user, 80),
        ]);
    }

    public function assinados(Request $request): View
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        try {
            $this->pedidos->sincronizarPendentesRecentes(40);
        } catch (Throwable) {
            //
        }

        $filtro = (string) $request->query('filtro', 'andamento');
        $pagina = max(1, (int) $request->query('pagina', 1));
        $porPagina = max(5, min(20, (int) $request->query('por_pagina', 8)));

        $lista = $this->pendencias->listarAssinadosParaUsuario($user, 80, [
            'filtro' => $filtro,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'forcar_filtro' => $request->has('filtro'),
        ]);

        return view('assinatura::assinados', [
            'itens' => $lista['itens'],
            'totais' => $lista['totais'],
            'filtro' => $lista['filtro'],
            'pagina' => $lista['pagina'],
            'por_pagina' => $lista['por_pagina'],
            'total_filtrado' => $lista['total_filtrado'],
            'ultima_pagina' => $lista['ultima_pagina'],
        ]);
    }

    public function confirmarPendencia(Request $request, int $id): RedirectResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        // Usuário leigo: só clicar — senha LibreSign resolvida no servidor (mapa/signer/integrador).
        $res = $this->pendencias->confirmar($id, $user, null);

        $redir = trim((string) $request->input('_redirect', ''));
        if ($redir === '' || ! str_starts_with($redir, '/') || str_starts_with($redir, '//')) {
            $redir = url('/assinatura-digital/pendencias');
        }

        return redirect($redir)
            ->with($res['ok'] ? 'ok' : 'erro', $res['mensagem']);
    }
}
