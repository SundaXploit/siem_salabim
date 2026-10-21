<?php

namespace App\Services;

use App\Jobs\FetchWazuhAlerts;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AlertIngestionCoordinator
{
    private const DISPATCH_COOLDOWN_KEY = 'siem_fetch_ran';

    private const STATUS_KEY = 'siem_fetch_status';

    private const LAST_SUCCESS_KEY = 'siem_fetch_last_success_at';

    private const QUEUE_WARNING_KEY = 'siem_fetch_queue_warning';

    private const DISPATCH_COOLDOWN_SECONDS = 60;

    private const STALE_AFTER_SECONDS = 180;

    public function __construct(private readonly QueueExecutionPolicy $queueExecutionPolicy) {}

    /**
     * Ask a queue worker to refresh the latest alerts without ever running the
     * OpenSearch request in the web process.
     *
     * @return array<string, bool|int|string|null>
     */
    public function requestLatestFetch(string $trigger): array
    {
        return $this->requestFetch('latest', $trigger);
    }

    /**
     * Queue a complete scan of today's alert pages. Intended for the manual
     * Settings action so that it can use the same asynchronous path.
     *
     * @return array<string, bool|int|string|null>
     */
    public function requestTodayFetch(string $trigger): array
    {
        return $this->requestFetch('today', $trigger);
    }

    /**
     * Queue a bounded calendar-day reconciliation for alerts that were indexed
     * after the normal incremental overlap had already passed.
     *
     * @return array<string, bool|int|string|null>
     */
    public function requestReconciliationFetch(string $trigger): array
    {
        return $this->requestFetch('reconcile', $trigger);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function requestFetch(string $mode, string $trigger): array
    {
        try {
            if (app(ApplicationUpdateRuntimeGuard::class)->isActive()) {
                $this->mark('maintenance', $trigger, null, $mode);

                return $this->status($mode);
            }

            if (! $this->queueExecutionPolicy->runsOutsideRequest()) {
                $this->mark('worker_required', $trigger, null, $mode);

                if (Cache::add(self::QUEUE_WARNING_KEY, true, now()->addMinutes(5))) {
                    Log::warning('[SIEM Ingestion] Automatic web fetch was not queued because the queue connection is not asynchronous.', [
                        'connection' => config('queue.default'),
                    ]);
                }

                return $this->status($mode);
            }

            // This request-level cooldown avoids adding a new job on every
            // badge poll. The job itself is also unique as a second guard.
            $cooldownKey = $this->dispatchCooldownKey($mode);
            if (! Cache::add(
                $cooldownKey,
                true,
                now()->addSeconds(self::DISPATCH_COOLDOWN_SECONDS),
            )) {
                return $this->status($mode);
            }

            $job = new FetchWazuhAlerts($mode, $trigger);
            $uniqueLock = new UniqueLock(app(CacheRepository::class));
            if (! $uniqueLock->acquire($job)) {
                return $this->status($mode);
            }

            try {
                $this->mark('queued', $trigger, null, $mode);
                app(Dispatcher::class)->dispatch($job);
            } catch (\Throwable $exception) {
                // Let a later request retry instead of hiding a failed enqueue
                // behind the normal one-minute dispatch cooldown.
                Cache::forget($cooldownKey);
                $uniqueLock->release($job);
                $this->mark('queue_failed', $trigger, null, $mode);

                Log::error('[SIEM Ingestion] Unable to enqueue the automatic alert fetch.', [
                    'exception_class' => $exception::class,
                ]);
            }

            return $this->status($mode);
        } catch (\Throwable $exception) {
            // Ingestion observability must never make Live Alerts unavailable.
            Log::error('[SIEM Ingestion] Unable to read or update ingestion state.', [
                'exception_class' => $exception::class,
            ]);

            return $this->fallbackStatus('status_unavailable', $mode);
        }
    }

    public function markRunning(string $mode, string $trigger): void
    {
        $this->markFromWorker('running', $mode, $trigger);
    }

    public function markWaiting(string $mode, string $trigger): void
    {
        $this->markFromWorker('waiting', $mode, $trigger);
    }

    public function markSucceeded(string $mode, string $trigger): void
    {
        try {
            $timestamp = now()->timestamp;

            Cache::forever(self::LAST_SUCCESS_KEY, $timestamp);
            Cache::forever($this->lastSuccessKey($mode), $timestamp);
            $this->mark('succeeded', $trigger, $timestamp, $mode);
        } catch (\Throwable $exception) {
            $this->logWorkerStatusFailure('succeeded', $exception);
        }
    }

    public function markFailed(string $mode, string $trigger): void
    {
        $this->markFromWorker('failed', $mode, $trigger);
    }

    public function markMaintenance(string $mode, string $trigger): void
    {
        $this->markFromWorker('maintenance', $mode, $trigger);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function status(?string $mode = null): array
    {
        try {
            $stored = Cache::get($this->statusKey($mode));
            $stored = is_array($stored) ? $stored : [];
            $lastSuccess = Cache::get($this->lastSuccessKey($mode));
            $lastSuccess = is_numeric($lastSuccess) ? (int) $lastSuccess : null;
            $updatedAt = isset($stored['updated_at']) && is_numeric($stored['updated_at'])
                ? (int) $stored['updated_at']
                : null;
            $secondsSinceSuccess = $lastSuccess === null
                ? null
                : max(0, now()->timestamp - $lastSuccess);
            $statusAge = $updatedAt === null
                ? null
                : max(0, now()->timestamp - $updatedAt);
            $state = is_string($stored['state'] ?? null) ? $stored['state'] : 'unknown';

            return [
                'state' => $state,
                'mode' => is_string($stored['mode'] ?? null) ? $stored['mode'] : null,
                'trigger' => is_string($stored['trigger'] ?? null) ? $stored['trigger'] : null,
                'updated_at' => $this->asIso8601($updatedAt),
                'last_success_at' => $this->asIso8601($lastSuccess),
                'seconds_since_success' => $secondsSinceSuccess,
                'status_age_seconds' => $statusAge,
                'stale' => $this->isStale($state, $secondsSinceSuccess, $statusAge),
            ];
        } catch (\Throwable $exception) {
            Log::warning('[SIEM Ingestion] Unable to read ingestion status.', [
                'exception_class' => $exception::class,
            ]);

            return $this->fallbackStatus('status_unavailable', $mode);
        }
    }

    private function dispatchCooldownKey(string $mode): string
    {
        return self::DISPATCH_COOLDOWN_KEY.':'.$mode;
    }

    private function statusKey(?string $mode): string
    {
        return $mode === null ? self::STATUS_KEY : self::STATUS_KEY.':'.$mode;
    }

    private function lastSuccessKey(?string $mode): string
    {
        return $mode === null ? self::LAST_SUCCESS_KEY : self::LAST_SUCCESS_KEY.':'.$mode;
    }

    private function mark(
        string $state,
        string $trigger,
        ?int $timestamp = null,
        ?string $mode = null,
    ): void {
        $status = [
            'state' => $state,
            'mode' => $mode,
            'trigger' => $trigger,
            'updated_at' => $timestamp ?? now()->timestamp,
        ];

        Cache::forever(self::STATUS_KEY, $status);
        if ($mode !== null) {
            Cache::forever($this->statusKey($mode), $status);
        }
    }

    private function asIso8601(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : Carbon::createFromTimestampUTC($timestamp)->toIso8601String();
    }

    private function isStale(string $state, ?int $secondsSinceSuccess, ?int $statusAge): ?bool
    {
        if (in_array($state, ['worker_required', 'queue_failed', 'failed'], true)) {
            return true;
        }

        if ($secondsSinceSuccess !== null) {
            return $secondsSinceSuccess > self::STALE_AFTER_SECONDS;
        }

        // A newly queued first run has no baseline yet. If it remains queued
        // or running for too long, report it as stale so operators can detect
        // a missing or wedged worker without breaking the badge response.
        if (in_array($state, ['queued', 'running', 'waiting'], true) && $statusAge !== null) {
            return $statusAge > self::STALE_AFTER_SECONDS ? true : null;
        }

        // Unknown is safer than claiming stale data after a cache flush.
        return null;
    }

    private function markFromWorker(string $state, string $mode, string $trigger): void
    {
        try {
            $this->mark($state, $trigger, null, $mode);
        } catch (\Throwable $exception) {
            $this->logWorkerStatusFailure($state, $exception);
        }
    }

    private function logWorkerStatusFailure(string $state, \Throwable $exception): void
    {
        Log::warning('[SIEM Ingestion] Fetch continued without updating ingestion status.', [
            'state' => $state,
            'exception_class' => $exception::class,
        ]);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function fallbackStatus(string $state, ?string $mode = null): array
    {
        return [
            'state' => $state,
            'mode' => $mode,
            'trigger' => null,
            'updated_at' => null,
            'last_success_at' => null,
            'seconds_since_success' => null,
            'status_age_seconds' => null,
            'stale' => null,
        ];
    }
}
