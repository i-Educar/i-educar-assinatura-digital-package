<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Providers;

use iEducar\Packages\BuritiAssinaturaDigital\Console\AssinaturaRepararPendentesCommand;
use iEducar\Packages\BuritiAssinaturaDigital\Console\AssinaturaTesteCommand;
use iEducar\Packages\BuritiAssinaturaDigital\Http\Middleware\EnsureAssinaturaAdministrador;
use iEducar\Packages\BuritiAssinaturaDigital\Services\AssinaturaOrquestrador;
use iEducar\Packages\BuritiAssinaturaDigital\Services\ConfigAssinatura;
use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use iEducar\Packages\BuritiAssinaturaDigital\Support\Identidade;
use iEducar\Packages\BuritiAssinaturaDigital\Support\MenuFluxo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AssinaturaDigitalServiceProvider extends ServiceProvider
{
    public const string PACKAGE = 'buriti/i-educar-assinatura-digital-package';

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/assinatura.php', 'assinatura');
        $this->app->singleton(LibreSignClient::class, static fn (): LibreSignClient => new LibreSignClient());
        $this->app->singleton(PedidoAssinaturaService::class, static fn ($app): PedidoAssinaturaService => new PedidoAssinaturaService(
            $app->make(LibreSignClient::class),
        ));
        $this->app->singleton(AssinaturaOrquestrador::class, static fn ($app): AssinaturaOrquestrador => new AssinaturaOrquestrador(
            $app->make(LibreSignClient::class),
            $app->make(PedidoAssinaturaService::class),
        ));
    }

    public function boot(): void
    {
        // Settings Laguna (Configurações → Módulos) sobrescrevem .env no runtime.
        ConfigAssinatura::hidratarDoAmbiente();

        $ativo = (bool) config('assinatura.ativo', true);
        if (class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\ModuloConfiguracao::class)) {
            $ativo = \iEducar\Packages\BuritiLagunaAmbiente\Support\ModuloConfiguracao::ativo('assinatura');
            config(['assinatura.ativo' => $ativo]);
        }

        $root = dirname(__DIR__, 2);
        $this->loadViewsFrom($root.'/resources/views', 'assinatura');

        // Consulta pública de autenticidade (LGPD) — disponível mesmo com módulo “interno”
        // ativo; se o pacote estiver desligado no tenant, ainda assim a verificação existe
        // para códigos já emitidos.
        Route::middleware(['web', 'ieducar.xssbypass'])->group($root.'/routes/public.php');

        if (! $ativo) {
            return;
        }

        if (class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\BuritiUi::class)) {
            \iEducar\Packages\BuritiLagunaAmbiente\Support\BuritiUi::registrar();
        }

        // Comandos sempre registrados — o diagnóstico Laguna (HTTP) consulta Artisan::all().
        $this->commands([
            AssinaturaTesteCommand::class,
            AssinaturaRepararPendentesCommand::class,
        ]);

        if ($this->app->runningInConsole()) {
            if (\App\Support\Tenancy\PackageTenant::allows(self::PACKAGE)) {
                $this->loadMigrationsFrom($root.'/database/migrations');
            }
        }

        $this->app['router']->aliasMiddleware('assinatura.admin', EnsureAssinaturaAdministrador::class);

        Route::middleware([
            'web',
            'ieducar.navigation',
            'ieducar.footer',
            'ieducar.xssbypass',
            'ieducar.suspended',
            'auth',
            'ieducar.checkresetpassword',
        ])->group($root.'/routes/web.php');

        View::composer(['assinatura::layout', 'assinatura::painel', 'assinatura::pendencias', 'assinatura::assinados', 'assinatura::pedidos.*', 'assinatura::testes', 'assinatura::configuracoes', 'assinatura::partials.*'], function ($view): void {
            $admin = Auth::check()
                && ($u = Auth::user()) !== null
                && method_exists($u, 'isAdmin')
                && $u->isAdmin();
            $view->with('identidade', Identidade::tokens());
            $view->with('isAssinaturaAdmin', $admin);
            $view->with('menuGrupos', MenuFluxo::grupos([
                'painel' => true,
                'pendencias' => true,
                'assinados' => true,
                'pedidos' => true,
                'testes' => $admin,
                'config' => $admin,
            ]));
        });
    }
}
