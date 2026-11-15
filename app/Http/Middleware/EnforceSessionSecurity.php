<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionSecurity
{
    public const IDLE_SECONDS = 15 * 60;

    public const ABSOLUTE_SECONDS = 8 * 60 * 60;

    public static function start(Request $request, User $user): void
    {
        $request->session()->put('security', [
            'version' => $user->session_version,
            'activity_key' => Str::random(40),
            'started_at' => now()->timestamp,
            'last_activity_at' => now()->timestamp,
        ]);
    }

    public static function lastActivity(Request $request): int
    {
        $key = $request->session()->get('security.activity_key');

        return max((int) $request->session()->get('security.last_activity_at', 0),
            $key ? (int) Cache::get('session-activity:'.$key, 0) : 0);
    }

    public static function recordActivity(Request $request, int $timestamp): int
    {
        $key = 'session-activity:'.$request->session()->get('security.activity_key');

        // Long polling requests may save an older session snapshot. Keep activity separate.
        return Cache::lock($key.':lock', 5)->block(2, function () use ($key, $request, $timestamp): int {
            $last = max(self::lastActivity($request), $timestamp);
            Cache::put($key, $last, self::ABSOLUTE_SECONDS);

            return $last;
        });
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('install.*') || ! ($user = $request->user())) {
            return $next($request);
        }
        $user = $user->fresh();
        if ($user) {
            Auth::guard('web')->setUser($user);
        }
        $security = $request->session()->get('security', []);
        $now = now()->timestamp;
        $valid = $user && $user->is_active && is_string($security['version'] ?? null)
            && filled($user->session_version) && hash_equals($user->session_version, $security['version'])
            && $now - self::lastActivity($request) <= self::IDLE_SECONDS
            && $now - (int) ($security['started_at'] ?? 0) <= self::ABSOLUTE_SECONDS;
        if ($valid) {
            // Polling and ordinary HTTP requests deliberately do not renew human activity.
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = 'Sesi berakhir atau akses akun berubah. Silakan masuk kembali.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'session_expired' => true], 401)
            : redirect()->route('login')->with('status', $message);
    }
}
