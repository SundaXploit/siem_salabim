<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
