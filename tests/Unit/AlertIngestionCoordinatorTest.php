<?php

namespace Tests\Unit;

use App\Console\Commands\FetchWazuhAlerts as FetchWazuhAlertsCommand;
use App\Jobs\FetchWazuhAlerts;
use App\Services\AlertIngestionCoordinator;
use App\Services\ApplicationUpdateRuntimeGuard;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class AlertIngestionCoordinatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Queue::fake();
    }

    public function test_web_requests_queue_only_one_fetch_during_the_cooldown(): void
    {
        $ingestion = app(AlertIngestionCoordinator::class);

        $first = $ingestion->requestLatestFetch('live-alerts');
        $second = $ingestion->requestLatestFetch('new-count');

        $this->assertSame('queued', $first['state']);
        $this->assertSame('live-alerts', $first['trigger']);
        $this->assertSame($first, $second);
        Queue::assertPushed(FetchWazuhAlerts::class, 1);
    }

    public function test_sync_queue_is_reported_without_dispatching_a_job(): void
    {
        config(['queue.default' => 'sync']);

        $status = app(AlertIngestionCoordinator::class)->requestLatestFetch('new-count');

        $this->assertSame('worker_required', $status['state']);
        $this->assertNull($status['last_success_at']);
        $this->assertTrue($status['stale']);
        Queue::assertNothingPushed();
    }

    public function test_failover_queue_with_a_deferred_fallback_is_rejected_for_web_fetches(): void
    {
        config(['queue.default' => 'failover']);

        $status = app(AlertIngestionCoordinator::class)->requestLatestFetch('new-count');

        $this->assertSame('worker_required', $status['state']);
        Queue::assertNothingPushed();
    }

    public function test_worker_success_is_reflected_in_freshness_status(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('siem:fetch-alerts', [])
            ->andReturn(Command::SUCCESS);

        $ingestion = app(AlertIngestionCoordinator::class);
        (new FetchWazuhAlerts('latest', 'new-count'))->handle($ingestion);

        $status = $ingestion->status();
        $this->assertSame('succeeded', $status['state']);
        $this->assertSame(0, $status['seconds_since_success']);
        $this->assertArrayNotHasKey('lag_seconds', $status);
        $this->assertFalse($status['stale']);

        $this->travel(181)->seconds();

        $status = $ingestion->status();
        $this->assertSame(181, $status['seconds_since_success']);
        $this->assertTrue($status['stale']);
    }

    public function test_today_worker_calls_the_paginated_today_command(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('siem:fetch-alerts', ['--today' => true])
            ->andReturn(Command::SUCCESS);

        $ingestion = app(AlertIngestionCoordinator::class);
        (new FetchWazuhAlerts('today', 'settings-manual'))->handle($ingestion);

        $status = $ingestion->status();
        $this->assertSame('succeeded', $status['state']);
        $this->assertSame('today', $status['mode']);
        $this->assertSame('settings-manual', $status['trigger']);
    }

    public function test_manual_today_request_queues_the_today_mode(): void
    {
        $status = app(AlertIngestionCoordinator::class)->requestTodayFetch('settings-manual');

        $this->assertSame('queued', $status['state']);
        $this->assertSame('today', $status['mode']);
        Queue::assertPushed(FetchWazuhAlerts::class, function (FetchWazuhAlerts $job): bool {
            return $job->mode === 'today'
                && $job->trigger === 'settings-manual'
                && $job->uniqueId() === 'wazuh-alerts:ingestion:today';
        });
    }

    public function test_reconciliation_request_queues_a_distinct_mode(): void
    {
        $status = app(AlertIngestionCoordinator::class)
            ->requestReconciliationFetch('scheduler-reconcile');

        $this->assertSame('queued', $status['state']);
        $this->assertSame('reconcile', $status['mode']);
        Queue::assertPushed(FetchWazuhAlerts::class, function (FetchWazuhAlerts $job): bool {
            return $job->mode === 'reconcile'
                && $job->trigger === 'scheduler-reconcile'
                && $job->uniqueId() === 'wazuh-alerts:ingestion:reconcile';
        });
    }

    public function test_reconciliation_worker_calls_the_bounded_lookback_command(): void
    {
        config(['services.opensearch.reconcile_lookback_days' => 5]);
        Artisan::shouldReceive('call')
            ->once()
            ->with('siem:fetch-alerts', ['--lookback-days' => 5])
            ->andReturn(Command::SUCCESS);

        $ingestion = app(AlertIngestionCoordinator::class);
        (new FetchWazuhAlerts('reconcile', 'scheduler-reconcile'))->handle($ingestion);

        $status = $ingestion->status('reconcile');
        $this->assertSame('succeeded', $status['state']);
        $this->assertSame('scheduler-reconcile', $status['trigger']);
    }

    public function test_latest_and_today_requests_keep_distinct_unique_intents(): void
    {
        $ingestion = app(AlertIngestionCoordinator::class);

        $ingestion->requestLatestFetch('scheduler');
        $ingestion->requestTodayFetch('settings-manual');

        Queue::assertPushed(FetchWazuhAlerts::class, 2);
        Queue::assertPushed(FetchWazuhAlerts::class, fn (FetchWazuhAlerts $job): bool => $job->mode === 'latest'
            && $job->uniqueId() === 'wazuh-alerts:ingestion:latest'
        );
        Queue::assertPushed(FetchWazuhAlerts::class, fn (FetchWazuhAlerts $job): bool => $job->mode === 'today'
            && $job->uniqueId() === 'wazuh-alerts:ingestion:today'
        );

        $latestJob = new FetchWazuhAlerts('latest');
        $todayJob = new FetchWazuhAlerts('today');
        $latestMiddleware = $latestJob->middleware()[0];
        $todayMiddleware = $todayJob->middleware()[0];
        $this->assertSame(1800, $latestJob->timeout);
        $this->assertSame(0, $latestJob->tries);
        $this->assertSame(3, $latestJob->maxExceptions);
        $this->assertGreaterThan(1860 + $latestJob->timeout, $latestJob->uniqueFor);
        $this->assertTrue($latestMiddleware->shareKey);
        $this->assertSame(
            $latestMiddleware->getLockKey($latestJob),
            $todayMiddleware->getLockKey($todayJob),
        );
        $this->assertSame(30, $latestMiddleware->releaseAfter);
        $this->assertSame(1860, $latestMiddleware->expiresAfter);
    }

    public function test_each_mode_keeps_its_own_status_when_the_other_mode_changes(): void
    {
        $ingestion = app(AlertIngestionCoordinator::class);

        $ingestion->requestLatestFetch('scheduler');
        $ingestion->requestTodayFetch('settings-manual');
        $ingestion->markRunning('latest', 'scheduler');

        $today = $ingestion->requestTodayFetch('settings-manual');
        $this->assertSame('queued', $today['state']);
        $this->assertSame('today', $today['mode']);
        $this->assertSame('settings-manual', $today['trigger']);
        Queue::assertPushed(FetchWazuhAlerts::class, 2);
    }

    public function test_same_mode_request_does_not_overwrite_running_status_after_cooldown(): void
    {
        $ingestion = app(AlertIngestionCoordinator::class);
        $ingestion->requestLatestFetch('live-alerts');
        $ingestion->markRunning('latest', 'live-alerts');
        $this->travel(61)->seconds();

        $status = $ingestion->requestLatestFetch('new-count');

        Queue::assertPushed(FetchWazuhAlerts::class, 1);
        $this->assertSame('running', $status['state']);
        $this->assertSame('live-alerts', $status['trigger']);
    }

    public function test_unique_lock_coalesces_same_mode_but_allows_the_other_mode(): void
    {
        $uniqueLock = new UniqueLock(app(CacheRepository::class));
        $latest = new FetchWazuhAlerts('latest');
        $duplicateLatest = new FetchWazuhAlerts('latest');
        $today = new FetchWazuhAlerts('today');

        $this->assertTrue($uniqueLock->acquire($latest));
        try {
            $this->assertFalse($uniqueLock->acquire($duplicateLatest));
            $this->assertTrue($uniqueLock->acquire($today));
        } finally {
            $uniqueLock->release($latest);
            $uniqueLock->release($today);
        }
    }

    public function test_shared_overlap_lock_releases_a_competing_mode_for_retry(): void
    {
        $running = new FetchWazuhAlerts('latest');
        $waiting = (new FetchWazuhAlerts('today'))->withFakeQueueInteractions();
        $middleware = $waiting->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($running), 1860);
        $this->assertTrue($lock->get());
        $continued = false;

        try {
            $middleware->handle($waiting, function () use (&$continued): void {
                $continued = true;
            });
        } finally {
            $lock->release();
        }

        $this->assertFalse($continued);
        $waiting->assertReleased(30);
    }

    public function test_lock_busy_worker_is_released_without_recording_success(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('siem:fetch-alerts', ['--today' => true])
            ->andReturn(FetchWazuhAlertsCommand::EXIT_LOCK_BUSY);

        $ingestion = app(AlertIngestionCoordinator::class);
        $ingestion->markSucceeded('today', 'previous-run');
        $lastSuccess = $ingestion->status('today')['last_success_at'];
        $this->travel(45)->seconds();
        $job = (new FetchWazuhAlerts('today', 'settings-manual'))->withFakeQueueInteractions();
        $job->handle($ingestion);

        $job->assertReleased(30)->assertNotFailed();
        $status = $ingestion->status('today');
        $this->assertSame('waiting', $status['state']);
        $this->assertSame('today', $status['mode']);
        $this->assertSame($lastSuccess, $status['last_success_at']);
        $this->assertSame(45, $status['seconds_since_success']);
    }

    public function test_worker_stops_if_update_starts_after_its_initial_guard_check(): void
    {
        Artisan::shouldReceive('call')->never();
        $guard = \Mockery::mock(ApplicationUpdateRuntimeGuard::class);
        $guard->shouldReceive('isActive')->twice()->andReturn(false, true);

        $ingestion = app(AlertIngestionCoordinator::class);
        $job = (new FetchWazuhAlerts('latest', 'scheduler'))->withFakeQueueInteractions();
        $job->handle($ingestion, $guard);

        $job->assertNotFailed();
        $status = $ingestion->status('latest');
        $this->assertSame('maintenance', $status['state']);
        $this->assertSame('scheduler', $status['trigger']);
    }

    public function test_command_maintenance_exit_is_not_recorded_as_an_ingestion_failure(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('siem:fetch-alerts', [])
            ->andReturn(FetchWazuhAlertsCommand::EXIT_MAINTENANCE);

        $guard = \Mockery::mock(ApplicationUpdateRuntimeGuard::class);
        $guard->shouldReceive('isActive')->twice()->andReturnFalse();

        $ingestion = app(AlertIngestionCoordinator::class);
        $job = (new FetchWazuhAlerts('latest', 'scheduler'))->withFakeQueueInteractions();
        $job->handle($ingestion, $guard);

        $job->assertNotFailed();
        $this->assertSame('maintenance', $ingestion->status('latest')['state']);
    }

    public function test_queue_failure_is_reported_and_releases_the_web_cooldown(): void
    {
        $this->mock(Dispatcher::class, function (MockInterface $dispatcher): void {
            $dispatcher->shouldReceive('dispatch')
                ->once()
                ->andThrow(new \RuntimeException('Queue backend unavailable.'));
        });

        $status = app(AlertIngestionCoordinator::class)->requestLatestFetch('new-count');

        $this->assertSame('queue_failed', $status['state']);
        $this->assertTrue($status['stale']);
        $this->assertFalse(Cache::has('siem_fetch_ran:latest'));

        $job = new FetchWazuhAlerts;
        $lock = new UniqueLock(app(CacheRepository::class));
        $this->assertTrue($lock->acquire($job));
        $lock->release($job);
    }

    public function test_a_first_fetch_stuck_in_the_queue_becomes_stale(): void
    {
        $ingestion = app(AlertIngestionCoordinator::class);
        $initial = $ingestion->requestLatestFetch('new-count');

        $this->assertNull($initial['seconds_since_success']);
        $this->assertNull($initial['stale']);

        $this->travel(181)->seconds();

        $stuck = $ingestion->status();
        $this->assertSame('queued', $stuck['state']);
        $this->assertSame(181, $stuck['status_age_seconds']);
        $this->assertTrue($stuck['stale']);
    }
}
