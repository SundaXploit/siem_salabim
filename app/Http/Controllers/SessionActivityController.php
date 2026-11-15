<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnforceSessionSecurity;
use Illuminate\Http\Request;

class SessionActivityController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['idle_ms' => ['required', 'integer', 'min:0', 'max:30000']]);
        $activity = now()->timestamp - (int) ceil($data['idle_ms'] / 1000);
        $last = EnforceSessionSecurity::recordActivity($request, $activity);

        return response()->json([
            'expires_at' => $last + EnforceSessionSecurity::IDLE_SECONDS,
        ])->header('Cache-Control', 'no-store, private');
    }
}
