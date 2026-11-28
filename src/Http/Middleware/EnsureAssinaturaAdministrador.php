<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAssinaturaAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user === null || ! method_exists($user, 'isAdmin') || ! $user->isAdmin()) {
            abort(403, 'Acesso restrito ao administrador do sistema.');
        }

        return $next($request);
    }
}