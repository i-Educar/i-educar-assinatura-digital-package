<?php

use iEducar\Packages\BuritiAssinaturaDigital\Http\Controllers\AssinaturaController;
use iEducar\Packages\BuritiAssinaturaDigital\Http\Controllers\TesteAssinaturaController;
use Illuminate\Support\Facades\Route;

Route::prefix('assinatura-digital')->name('assinatura.')->group(function (): void {
    Route::get('/', [AssinaturaController::class, 'painel'])->name('painel');
    Route::get('/pedidos', [AssinaturaController::class, 'pedidos'])->name('pedidos');
    Route::get('/pedidos/{id}', [AssinaturaController::class, 'show'])->whereNumber('id')->name('pedidos.show');
    Route::get('/pedidos/{id}/ver', [AssinaturaController::class, 'ver'])->whereNumber('id')->name('pedidos.ver');
    Route::get('/pedidos/{id}/pdf', [AssinaturaController::class, 'pdf'])->whereNumber('id')->name('pedidos.pdf');
    Route::get('/pedidos/{id}/baixar', [AssinaturaController::class, 'baixar'])->whereNumber('id')->name('pedidos.baixar');
    Route::post('/pedidos/{id}/sincronizar', [AssinaturaController::class, 'sincronizar'])->whereNumber('id')->name('pedidos.sincronizar');

    Route::get('/pendencias', [AssinaturaController::class, 'pendencias'])->name('pendencias');
    Route::get('/assinados', [AssinaturaController::class, 'assinados'])->name('assinados');
    Route::post('/pendencias/{id}/confirmar', [AssinaturaController::class, 'confirmarPendencia'])
        ->whereNumber('id')
        ->name('pendencias.confirmar');

    Route::middleware(['assinatura.admin'])->group(function (): void {
        Route::get('/testes', [TesteAssinaturaController::class, 'index'])->name('testes');
        Route::post('/testes/unica', [TesteAssinaturaController::class, 'unica'])->name('testes.unica');
        Route::post('/testes/multipla', [TesteAssinaturaController::class, 'multipla'])->name('testes.multipla');

        Route::get('/configuracoes', [AssinaturaController::class, 'configuracoes'])->name('configuracoes');
        Route::post('/configuracoes/ping', [AssinaturaController::class, 'pingLibreSign'])->name('configuracoes.ping');
        Route::post('/configuracoes/reparar-pendentes', [AssinaturaController::class, 'repararPendentes'])->name('configuracoes.reparar-pendentes');
    });
});
