<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function fakeIntegrationDns(): void
    {
        // Keep the real URL/IP checks and DNS pinning; replace only DNS resolution.
        $this->app->instance(\App\Services\OutboundEndpointPolicy::class, new class extends \App\Services\OutboundEndpointPolicy
        {
            protected function resolve(string $host): array
            {
                return [filter_var($host, FILTER_VALIDATE_IP) ? $host : '203.0.113.10'];
            }
        });
    }

    protected function assignAckOwner(\App\Models\Alert $alert, \App\Models\User $user): \App\Models\Alert
    {
        $alert->forceFill(['status' => 'acknowledged', 'acknowledged_by_user_id' => $user->id, 'acknowledged_at' => now()])->save();

        return $alert;
    }

    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        $this->withSession(['security' => [
            'version' => $user->session_version, 'activity_key' => \Illuminate\Support\Str::random(40),
            'last_activity_at' => now()->timestamp, 'started_at' => now()->timestamp,
        ]]);

        return $this;
    }
}
