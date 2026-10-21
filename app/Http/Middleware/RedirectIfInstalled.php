<?php

namespace App\Http\Middleware;

use App\Services\Installation\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfInstalled
{
    public function __construct(
        private readonly InstallationState $installationState,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installationState->isInstalled()) {
            return redirect()
                ->route('install.complete')
                ->withHeaders($this->securityHeaders());
        }

        $response = $next($request);

        foreach ($this->securityHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
