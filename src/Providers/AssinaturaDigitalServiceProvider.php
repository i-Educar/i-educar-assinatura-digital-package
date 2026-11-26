<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Providers;

use iEducar\Packages\BuritiAssinaturaDigital\Console\AssinaturaTesteCommand;
use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use iEducar\Packages\BuritiAssinaturaDigital\Support\Identidade;
use iEducar\Packages\BuritiAssinaturaDigital\Support\MenuFluxo;
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
    }

    public function boot(): void
    {
        if (! (bool) config('assinatura.ativo', true)) {
            return;
        }

        if (class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\BuritiUi::class)) {
            \iEducar\Packages\BuritiLagunaAmbiente\Support\BuritiUi::registrar();
        }

        $root = dirname(__DIR__, 2);
        $this->loadViewsFrom($root.'/resources/views', 'assinatura');

        if ($this->app->runningInConsole()) {
            if (\App\Support\Tenancy\PackageTenant::allows(self::PACKAGE)) {
                $this->loadMigrationsFrom($root.'/database/migrations');
            }
            $this->commands([AssinaturaTesteCommand::class]);
        }

        Route::middleware([
            'web',
            'ieducar.navigation',
            'ieducar.footer',
            'ieducar.xssbypass',
            'ieducar.suspended',
            'auth',
            'ieducar.checkresetpassword',
        ])->group($root.'/routes/web.php');

        View::composer('assinatura::*', function ($view): void {
            $view->with('identidade', Identidade::tokens());
            $view->with('menuGrupos', MenuFluxo::grupos([
                'painel' => true,
                'pedidos' => true,
                'testes' => true,
            ]));
        });
    }
}
