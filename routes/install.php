<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

Route::prefix('install')->name('install.')->group(function (): void {
    Route::middleware('install.pending')->group(function (): void {
        Route::get('/', [InstallController::class, 'index'])->name('index');
        Route::post('/unlock', [InstallController::class, 'unlock'])
            ->middleware('throttle:5,1')
            ->name('unlock');
        Route::post('/', [InstallController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');
    });

    Route::get('/complete', [InstallController::class, 'complete'])
        ->middleware('installed.only')
        ->name('complete');
});
