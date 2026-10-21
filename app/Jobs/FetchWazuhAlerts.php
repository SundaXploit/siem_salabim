<?php

namespace App\Jobs;

use App\Console\Commands\FetchWazuhAlerts as FetchWazuhAlertsCommand;
use App\Services\AlertIngestionCoordinator;
use App\Services\ApplicationUpdateRuntimeGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class FetchWazuhAlerts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    // Lock-contention releases count as attempts. Leave attempts unbounded and
    // cap only real exceptions so a long-running sibling mode is retry-safe.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 30;

    // Covers the worst-case overlap wait plus the full execution timeout, so
    // another same-mode intent cannot enter while this job is still alive.
    public int $uniqueFor = 7200;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $mode = 'latest',
        public readonly string $trigger = 'web',
    ) {
        if (! in_array($mode, ['latest', 'today', 'reconcile'], true)) {
            throw new InvalidArgumentException('Unsupported Wazuh fetch mode.');
        }

        // The existing worker already consumes the default queue. Keeping
        // ingestion there avoids silently requiring another worker process.
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        // Coalesce duplicate requests for the same mode without dropping a
        // manual today scan merely because a latest refresh is already queued.
        return 'wazuh-alerts:ingestion:'.$this->mode;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // The modes have separate uniqueness keys so both intents survive,
        // while this shared execution lock keeps OpenSearch scans serialized.
        return [
            (new WithoutOverlapping('wazuh-alerts:ingestion'))
                ->shared()
                ->releaseAfter($this->backoff)
                ->expireAfter(1860),
        ];
    }

    public function handle(
        AlertIngestionCoordinator $ingestion,
        ?ApplicationUpdateRuntimeGuard $updateGuard = null,
    ): void {
        $updateGuard ??= app(ApplicationUpdateRuntimeGuard::class);
        if ($updateGuard->isActive()) {
            $ingestion->markMaintenance($this->mode, $this->trigger);

            return;
        }

        $ingestion->markRunning($this->mode, $this->trigger);

        // Close the race between the first marker check and publishing the
        // running state. Once the updater has created its marker it will
        // either observe this running state and abort, or this worker will
        // stop here before opening an OpenSearch request.
        if ($updateGuard->isActive()) {
            $ingestion->markMaintenance($this->mode, $this->trigger);

            return;
        }

        try {
            $arguments = match ($this->mode) {
                'today' => ['--today' => true],
                'reconcile' => [
                    '--lookback-days' => max(
                        2,
                        min(30, (int) config('services.opensearch.reconcile_lookback_days', 2)),
                    ),
                ],
                default => [],
            };
            $exitCode = Artisan::call('siem:fetch-alerts', $arguments);

            if ($exitCode === FetchWazuhAlertsCommand::EXIT_LOCK_BUSY) {
                $ingestion->markWaiting($this->mode, $this->trigger);
                $this->release($this->backoff);

                return;
            }

            if ($exitCode === FetchWazuhAlertsCommand::EXIT_MAINTENANCE) {
                $ingestion->markMaintenance($this->mode, $this->trigger);

                return;
            }

            if ($exitCode !== Command::SUCCESS) {
                throw new RuntimeException('The Wazuh alert fetch command did not complete successfully.');
            }

            $ingestion->markSucceeded($this->mode, $this->trigger);
        } catch (\Throwable $exception) {
            $ingestion->markFailed($this->mode, $this->trigger);

            Log::warning('[SIEM Ingestion] Queued alert fetch failed.', [
                'mode' => $this->mode,
                'trigger' => $this->trigger,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(AlertIngestionCoordinator::class)->markFailed($this->mode, $this->trigger);
    }
}
