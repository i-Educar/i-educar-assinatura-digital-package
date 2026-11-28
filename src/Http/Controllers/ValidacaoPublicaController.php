<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Http\Controllers;

use iEducar\Packages\BuritiAssinaturaDigital\Services\ValidacaoPublicaService;
use iEducar\Packages\BuritiAssinaturaDigital\Support\Identidade;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Tela pública de conferência de autenticidade (sem login).
 */
final class ValidacaoPublicaController extends Controller
{
    public function __construct(private readonly ValidacaoPublicaService $validacao)
    {
    }

    public function form(Request $request): View
    {
        $codigo = strtoupper(trim((string) $request->query('codigo', '')));
        $resultado = $codigo !== '' ? $this->validacao->consultar($codigo) : null;

        return view('assinatura::verificar', [
            'identidade' => Identidade::tokens(),
            'codigo' => $codigo,
            'resultado' => $resultado,
            'publico' => true,
        ]);
    }

    public function show(string $codigo): View
    {
        $codigo = strtoupper(trim($codigo));
        $resultado = $this->validacao->consultar($codigo);

        return view('assinatura::verificar', [
            'identidade' => Identidade::tokens(),
            'codigo' => $codigo,
            'resultado' => $resultado,
            'publico' => true,
        ]);
    }
}
