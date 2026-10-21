<?php

namespace App\Http\Middleware;

use App\Services\Installation\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationInstalled
{
    public function __construct(
        private readonly InstallationState $installationState,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installationState->isInstalled()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Aplikasi belum diinstal.',
                'setup_url' => route('install.index'),
            ], 503, [
                'Cache-Control' => 'no-store, private',
                'Retry-After' => '60',
            ]);
        }

        return redirect()->route('install.index');
    }
}
