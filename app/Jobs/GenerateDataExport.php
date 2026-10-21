<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\DataExportArchiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateDataExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $dataExportId)
    {
        $this->onQueue((string) config('data_exports.queue', 'exports'));
    }

    public function uniqueId(): string
    {
        return 'data-export:'.$this->dataExportId;
    }

    public function handle(
        DataExportArchiveService $archives,
        ?ApplicationUpdateRuntimeGuard $updateGuard = null,
    ): void {
        $updateGuard ??= app(ApplicationUpdateRuntimeGuard::class);

        if ($updateGuard->isActive()) {
            $this->release($this->updateDeferralSeconds());

            return;
        }

        $claimed = DataExport::query()
            ->whereKey($this->dataExportId)
            ->where(function ($query): void {
                $query->whereIn('status', ['queued', 'failed'])
                    ->orWhere(function ($stale): void {
                        $stale->where('status', 'running')
                            ->where('updated_at', '<=', now()->subSeconds($this->timeout));
                    });
            })
            ->update([
                'status' => 'running',
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        // Close the small race between the initial marker check and the
        // database claim. The updater will see a running export, or this job
        // will put itself back into the queued state before doing any I/O.
        if ($updateGuard->isActive()) {
            DataExport::query()
                ->whereKey($this->dataExportId)
                ->where('status', 'running')
                ->update([
                    'status' => 'queued',
                    'updated_at' => now(),
                ]);
            $this->release($this->updateDeferralSeconds());

            return;
        }

        $export = DataExport::findOrFail($this->dataExportId);
        try {
            $archives->generate($export);
        } catch (Throwable $exception) {
            DataExport::query()
                ->whereKey($this->dataExportId)
                ->whereIn('status', ['queued', 'running'])
                ->update([
                    'status' => 'failed',
                    'error_message' => 'Ekspor gagal dibuat atau diverifikasi. Periksa log aplikasi sebelum mencoba kembali.',
                    'updated_at' => now(),
                ]);

            Log::error('[Data Export] Generation failed', [
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
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error_message' => 'Worker ekspor berhenti sebelum backup dapat diverifikasi.',
                'updated_at' => now(),
            ]);
    }

    private function updateDeferralSeconds(): int
    {
        $connection = (string) config('queue.default', 'database');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 3660);

        return max(3660, $retryAfter);
    }
}
