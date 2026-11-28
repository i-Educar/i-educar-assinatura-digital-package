<?php

use iEducar\Packages\BuritiAssinaturaDigital\Http\Controllers\ValidacaoPublicaController;
use Illuminate\Support\Facades\Route;

Route::prefix('assinatura-digital')->name('assinatura.')->group(function (): void {
    Route::get('/verificar', [ValidacaoPublicaController::class, 'form'])->name('verificar');
    Route::get('/verificar/{codigo}', [ValidacaoPublicaController::class, 'show'])
        ->where('codigo', '[A-Za-z0-9]+')
        ->name('verificar.codigo');
});
