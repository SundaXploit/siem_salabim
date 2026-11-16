<?php

namespace Tests;

use App\Models\Alert;
use App\Models\User;
use App\Services\OutboundEndpointPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Tests\Support\PostgresTestDatabase;

abstract class TestCase extends BaseTestCase
{
    protected $connectionsToTransact = [null];

    public function createApplication()
    {
        $app = parent::createApplication();

        if (getenv('SIEM_TEST_PGSQL') === '1'
            && ($app->configurationIsCached() || $app['config']->get('database.default') !== 'pgsql')) {
            throw new \RuntimeException('PostgreSQL tests require uncached pgsql test configuration.');
        }

        if ($app['config']->get('database.default') === 'pgsql') {
            PostgresTestDatabase::assertSafe($app['config']->get('database.connections.pgsql'));
            // Real commits are needed for snapshot exports, locking, and failed
            // statements. Rebuild this disposable database before each test.
            $this->connectionsToTransact = [];
            RefreshDatabaseState::$migrated = false;
        }

        return $app;
    }

    protected function fakeIntegrationDns(): void
    {
        // Keep the real URL/IP checks and DNS pinning; replace only DNS resolution.
        $this->app->instance(OutboundEndpointPolicy::class, new class extends OutboundEndpointPolicy
        {
            protected function resolve(string $host): array
            {
                return [filter_var($host, FILTER_VALIDATE_IP) ? $host : '203.0.113.10'];
            }
        });
    }

    protected function assignAckOwner(Alert $alert, User $user): Alert
    {
        $alert->forceFill(['status' => 'acknowledged', 'acknowledged_by_user_id' => $user->id, 'acknowledged_at' => now()])->save();

        return $alert;
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        $this->withSession(['security' => [
            'version' => $user->session_version, 'activity_key' => Str::random(40),
            'last_activity_at' => now()->timestamp, 'started_at' => now()->timestamp,
        ]]);

        return $this;
    }
}
