<?php

namespace App\Jobs;

use App\Models\ApplicationUpdate;
use App\Services\ApplicationUpdateAudit;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\ApplicationUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyApplicationUpdate implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $applicationUpdateId)
    {
        $this->onQueue((string) config('application_updates.queue', 'updates'));
    }

    public function uniqueId(): string
    {
        return 'application-update:global';
    }

    public function handle(ApplicationUpdateService $updates): void
    {
        $claimed = ApplicationUpdate::query()
            ->whereKey($this->applicationUpdateId)
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $updates->apply(ApplicationUpdate::findOrFail($this->applicationUpdateId));
    }

    public function failed(?Throwable $exception): void
    {
        $update = ApplicationUpdate::find($this->applicationUpdateId);
        if (! $update || ! in_array($update->status, ['queued', 'running'], true)) {
            return;
        }

        $safeBeforeMaintenance = in_array(
            $update->phase,
            ['requested', 'preflight', 'downloading', 'backing_up', 'backup_verified'],
            true,
        );

        if ($safeBeforeMaintenance) {
            try {
                app(ApplicationUpdateRuntimeGuard::class)->deactivate($update->uuid);
            } catch (Throwable $guardException) {
                $safeBeforeMaintenance = false;
                Log::critical('[Application Update] Failed job left runtime guard active', [
                    'update_id' => $update->id,
                    'exception_class' => $guardException::class,
                ]);
            }
        }

        try {
            app(ApplicationUpdateAudit::class)->transition(
                $update,
                $safeBeforeMaintenance ? 'failed' : 'manual_recovery_required',
                $safeBeforeMaintenance
                    ? 'Worker update berhenti sebelum maintenance mode aktif.'
                    : 'Worker update berhenti pada fase yang memerlukan pemeriksaan manual.',
                [
                    'status' => $safeBeforeMaintenance ? 'failed' : 'recovery_required',
                    'completed_at' => now(),
                    'error_message' => $safeBeforeMaintenance
                        ? 'Worker update berhenti sebelum aplikasi diubah.'
                        : 'Worker update berhenti setelah maintenance dimulai. Pulihkan secara manual.',
                ],
                'error',
            );
        } catch (Throwable $auditException) {
            Log::critical('[Application Update] Failed job status could not be persisted', [
                'update_id' => $update->id,
                'exception_class' => $auditException::class,
            ]);
        }
    }
}
