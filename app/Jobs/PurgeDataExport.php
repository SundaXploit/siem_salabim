<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\DataExportPurgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurgeDataExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    // A second attempt lets the queue reclaim a reservation after a worker is
    // hard-killed. Application-level verification failures remain fail-closed.
    public int $tries = 2;

    public array $backoff = [60];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $dataExportId)
    {
        $this->onQueue((string) config('data_exports.queue', 'exports'));
    }

    public function uniqueId(): string
    {
        return 'data-export-purge:'.$this->dataExportId;
    }

    public function handle(
        DataExportPurgeService $purger,
        ?ApplicationUpdateRuntimeGuard $updateGuard = null,
    ): void {
        $updateGuard ??= app(ApplicationUpdateRuntimeGuard::class);

        if ($updateGuard->isActive()) {
            $this->release($this->updateDeferralSeconds());

            return;
        }

        $staleBefore = now()->subSeconds($this->staleAfterSeconds());
        $claimed = DataExport::query()
            ->whereKey($this->dataExportId)
            ->where('status', 'verified')
            ->where(function ($query) use ($staleBefore): void {
                $query->where('purge_status', 'queued')
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('purge_status', 'running')
                            ->where(function ($timestamps) use ($staleBefore): void {
                                $timestamps->where('purge_started_at', '<=', $staleBefore)
                                    ->orWhere(function ($missingStart) use ($staleBefore): void {
                                        $missingStart->whereNull('purge_started_at')
                                            ->where('updated_at', '<=', $staleBefore);
                                    });
                            });
                    });
            })
            ->update([
                'purge_status' => 'running',
                'purge_started_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        // Recheck after claiming so an update that starts between the first
        // check and this write cannot race a destructive purge.
        if ($updateGuard->isActive()) {
            DataExport::query()
                ->whereKey($this->dataExportId)
                ->where('purge_status', 'running')
                ->update([
                    'purge_status' => 'queued',
                    'purge_started_at' => null,
                    'updated_at' => now(),
                ]);
            $this->release($this->updateDeferralSeconds());

            return;
        }

        $export = DataExport::findOrFail($this->dataExportId);
        try {
            $purger->purge($export);
        } catch (Throwable $exception) {
            DataExport::query()
                ->whereKey($this->dataExportId)
                ->whereIn('purge_status', ['queued', 'running'])
                ->update([
                    'purge_status' => 'failed',
                    'error_message' => 'Purge dibatalkan karena arsip atau snapshot sumber tidak lolos verifikasi.',
                    'updated_at' => now(),
                ]);

            Log::error('[Data Export] Purge failed safely', [
                'export_id' => $export->id,
                'export_uuid' => $export->uuid,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DataExport::query()
            ->whereKey($this->dataExportId)
            ->whereIn('purge_status', ['queued', 'running'])
            ->update([
                'purge_status' => 'failed',
                'error_message' => 'Worker purge berhenti; transaksi penghapusan dibatalkan.',
                'updated_at' => now(),
            ]);
    }

    private function staleAfterSeconds(): int
    {
        $connection = (string) config('queue.default', 'database');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", $this->timeout);

        return max($this->timeout, $retryAfter);
    }

    private function updateDeferralSeconds(): int
    {
        $connection = (string) config('queue.default', 'database');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 3660);

        return max(3660, $retryAfter);
    }
}
