<?php

use App\Http\Middleware\EnsureApplicationInstalled;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\RedirectIfInstalled;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/install.php'));
            Route::middleware(['web', 'installed.only'])->group(base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'install.pending' => RedirectIfInstalled::class,
            'installed.only' => EnsureApplicationInstalled::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            EnsureApplicationInstalled::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'installation_token',
            'db_password',
            'opensearch_password',
            'telegram_bot_token',
            'ai_api_key',
            'mail_password',
            'admin_password',
            'admin_password_confirmation',
        ]);
    })->create();
