<?php

namespace Tests\Feature;

use App\Jobs\FetchWazuhAlerts;
use App\Models\Alert;
use App\Models\User;
use App\Services\AlertIngestionCoordinator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class AlertIngestionDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Queue::fake();
    }

    public function test_live_alerts_queues_a_unique_fetch_without_running_the_command_in_the_request(): void
    {
        Artisan::shouldReceive('call')->never();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertViewHas('ingestionStatus', fn (array $status): bool => $status['state'] === 'queued');

        Queue::assertPushed(FetchWazuhAlerts::class, function (FetchWazuhAlerts $job): bool {
            return $job->mode === 'latest'
                && $job->trigger === 'live-alerts'
                && $job->uniqueId() === 'wazuh-alerts:ingestion:latest';
        });
    }

    public function test_badge_polling_queues_only_once_per_cooldown_and_keeps_returning_local_count(): void
    {
        Alert::create([
            'wazuh_alert_id' => 'local-new-alert',
            'rule_description' => 'Already imported alert',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.alerts.new-count'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('ingestion.state', 'queued')
            ->assertJsonPath('ingestion.trigger', 'new-count');

        $this->getJson(route('api.alerts.new-count'))
            ->assertOk()
            ->assertJsonPath('count', 1);

        Queue::assertPushed(FetchWazuhAlerts::class, 1);
    }

    public function test_sync_queue_configuration_never_executes_fetch_during_the_web_request(): void
    {
        config(['queue.default' => 'sync']);
        Artisan::shouldReceive('call')->never();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.alerts.new-count'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('ingestion.state', 'worker_required')
            ->assertJsonPath('ingestion.stale', true);

        Queue::assertNothingPushed();
    }

    public function test_worker_records_success_and_exposes_time_since_success(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('siem:fetch-alerts', [])
            ->andReturn(Command::SUCCESS);

        $job = new FetchWazuhAlerts('latest', 'new-count');
        $ingestion = app(AlertIngestionCoordinator::class);
        $job->handle($ingestion);

        $status = $ingestion->status();

        $this->assertSame('succeeded', $status['state']);
        $this->assertSame('new-count', $status['trigger']);
        $this->assertSame(0, $status['seconds_since_success']);
        $this->assertArrayNotHasKey('lag_seconds', $status);
        $this->assertFalse($status['stale']);

        $this->travel(181)->seconds();

        $status = $ingestion->status();
        $this->assertSame(181, $status['seconds_since_success']);
        $this->assertTrue($status['stale']);
    }

    public function test_queue_enqueue_failure_does_not_break_the_badge_response(): void
    {
        $user = User::factory()->create();
        $this->mock(Dispatcher::class, function (MockInterface $dispatcher): void {
            $dispatcher->shouldReceive('dispatch')
                ->once()
                ->andThrow(new \RuntimeException('Queue backend unavailable.'));
        });

        $this->actingAs($user)
            ->getJson(route('api.alerts.new-count'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('ingestion.state', 'queue_failed');

        $this->assertFalse(Cache::has('siem_fetch_ran:latest'));
    }
}
