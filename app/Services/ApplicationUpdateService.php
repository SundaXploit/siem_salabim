<?php

namespace App\Services;

use App\Exceptions\ApplicationUpdateException;
use App\Models\ApplicationUpdate;
use App\Models\DataExport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class ApplicationUpdateService
{
    public function __construct(
        private readonly ApplicationUpdateArtifact $artifacts,
        private readonly ApplicationUpdateFilesystem $filesystem,
        private readonly ApplicationUpdateAudit $audit,
        private readonly ApplicationVersionService $versions,
        private readonly ApplicationUpdateRuntimeGuard $runtimeGuard,
        private readonly QueueExecutionPolicy $queuePolicy,
        private readonly AlertIngestionCoordinator $ingestion,
    ) {}

    public function apply(ApplicationUpdate $update): void
    {
        $plan = null;
        $guardActive = false;
        $maintenanceMode = false;
        $deploymentStarted = false;
        $migrationStarted = false;

        try {
            $this->audit->transition($update, 'preflight', 'Pemeriksaan awal update dimulai.');
            $this->assertPreconditions($update);

            $this->runtimeGuard->activate($update->uuid);
            $guardActive = true;
            $this->assertNoConcurrentOperations($update);

            $this->audit->transition($update, 'downloading', 'Paket release sedang diunduh dan diverifikasi.');
            $artifact = $this->artifacts->prepare($update);

            $this->audit->transition($update, 'backing_up', 'Backup kode lokal sedang dibuat.');
            $plan = $this->filesystem->backup(
                $update,
                $artifact['release_root'],
                $artifact['manifest'],
            );
            $update = $this->audit->transition(
                $update,
                'backup_verified',
                'Backup kode lokal selesai dan lolos verifikasi.',
                [
                    'backup_path' => $plan['backup_root'],
                    'metadata' => [
                        'release_commit' => $artifact['manifest']['commit'],
                        'backup_manifest' => $plan['manifest_path'],
                    ],
                ],
            );

            $this->audit->transition($update, 'maintenance', 'Aplikasi memasuki maintenance mode.');
            $this->runArtisan(['down', '--retry=60'], 120);
            $maintenanceMode = true;

            $this->audit->transition($update, 'deploying', 'File release terverifikasi sedang dipasang.');
            $this->filesystem->deploy($plan, static function () use (&$deploymentStarted): void {
                $deploymentStarted = true;
            });
            if (! hash_equals($update->target_version, $this->versions->current())) {
                throw new ApplicationUpdateException('VERSION setelah pemasangan tidak sesuai target update.');
            }

            // From this point onward, an automatic code-only rollback could
            // leave migrated data incompatible with old code. Fail closed and
            // require an operator to restore the full backup instead.
            $migrationStarted = true;
            $this->audit->transition($update, 'migrating', 'Migrasi database release sedang dijalankan.');
            $this->runArtisan(['migrate', '--force', '--no-interaction']);

            $this->audit->transition($update, 'rebuilding_cache', 'Cache aplikasi sedang dibangun ulang.');
            $this->runArtisan(['optimize:clear', '--no-interaction']);
            $this->runArtisan(['config:cache', '--no-interaction']);
            $this->runArtisan(['view:cache', '--no-interaction']);
            $this->runArtisan(['queue:restart', '--no-interaction'], 120);

            $this->runArtisan(['up', '--no-interaction'], 120);
            $maintenanceMode = false;
            $this->runtimeGuard->deactivate($update->uuid);
            $guardActive = false;

            $this->audit->transition(
                $update,
                'completed',
                'Update aplikasi selesai.',
                [
                    'status' => 'succeeded',
                    'completed_at' => now(),
                    'error_message' => null,
                ],
            );
        } catch (Throwable $exception) {
            if (! $maintenanceMode && $guardActive && is_file($this->maintenanceFile())) {
                $maintenanceMode = true;
            }
            $recoveryRequired = $migrationStarted;

            if ($deploymentStarted && ! $migrationStarted && is_array($plan)) {
                try {
                    $this->filesystem->rollback($plan);
                    if (! hash_equals($update->from_version, $this->versions->current())) {
                        throw new ApplicationUpdateException('VERSION hasil rollback tidak sesuai versi awal.');
                    }
                } catch (Throwable $rollbackException) {
                    $recoveryRequired = true;
                    Log::critical('[Application Update] Code rollback failed', [
                        'update_id' => $update->id,
                        'update_uuid' => $update->uuid,
                        'exception_class' => $rollbackException::class,
                    ]);
                }
            }

            if (! $recoveryRequired) {
                if ($maintenanceMode) {
                    try {
                        $this->runArtisan(['up', '--no-interaction'], 120);
                        $maintenanceMode = false;
                    } catch (Throwable $upException) {
                        $recoveryRequired = true;
                        Log::critical('[Application Update] Maintenance mode could not be disabled', [
                            'update_id' => $update->id,
                            'exception_class' => $upException::class,
                        ]);
                    }
                }

                if ($guardActive && ! $recoveryRequired) {
                    try {
                        $this->runtimeGuard->deactivate($update->uuid);
                        $guardActive = false;
                    } catch (Throwable $guardException) {
                        $recoveryRequired = true;
                        Log::critical('[Application Update] Runtime guard could not be removed', [
                            'update_id' => $update->id,
                            'exception_class' => $guardException::class,
                        ]);
                    }
                }
            }

            if ($recoveryRequired && ! $maintenanceMode) {
                try {
                    $this->runArtisan(['down', '--retry=60'], 120);
                    $maintenanceMode = true;
                } catch (Throwable $downException) {
                    Log::critical('[Application Update] Recovery maintenance mode could not be enabled', [
                        'update_id' => $update->id,
                        'exception_class' => $downException::class,
                    ]);
                }
            }

            $this->recordFailure($update, $recoveryRequired);
            Log::error('[Application Update] Update did not complete', [
                'update_id' => $update->id,
                'update_uuid' => $update->uuid,
                'phase' => $update->fresh()?->phase,
                'recovery_required' => $recoveryRequired,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function assertPreconditions(ApplicationUpdate $update): void
    {
        if (! config('application_updates.self_update_enabled', false)) {
            throw new ApplicationUpdateException('Self-update dinonaktifkan oleh konfigurasi server.');
        }
        if (! config('application_updates.backup_confirmed', false)) {
            throw new ApplicationUpdateException('Konfirmasi backup penuh server belum diaktifkan.');
        }
        if (! $this->queuePolicy->runsOutsideRequest()) {
            throw new ApplicationUpdateException('Update memerlukan queue worker asynchronous.');
        }
        if (is_file($this->maintenanceFile())) {
            throw new ApplicationUpdateException('Aplikasi sudah berada dalam maintenance mode sebelum update.');
        }

        $current = $this->versions->current();
        if (! hash_equals($update->from_version, $current)
            || version_compare($update->target_version, $current, '<=')) {
            throw new ApplicationUpdateException('Versi aplikasi berubah atau target bukan versi yang lebih baru.');
        }

        $root = rtrim((string) config('application_updates.application_root', base_path()), '/\\');
        if (config('application_updates.refuse_dirty_worktree', true)) {
            // Releases installed by this updater intentionally no longer
            // match the original Git checkout. Their verified package manifest
            // becomes the cleanliness proof for subsequent updates.
            $hasPristineRelease = $this->filesystem->hasPristineInstalledRelease($current);
            if ($hasPristineRelease) {
                return;
            }

            $gitMetadata = $root.DIRECTORY_SEPARATOR.'.git';
            if (! file_exists($gitMetadata) && ! is_link($gitMetadata)) {
                throw new ApplicationUpdateException(
                    'Instalasi tidak memiliki manifest release terverifikasi atau metadata Git yang dapat diperiksa.',
                );
            }

            try {
                $result = Process::path($root)
                    ->timeout(30)
                    ->run(['git', 'status', '--porcelain', '--untracked-files=normal']);
            } catch (Throwable $exception) {
                throw new ApplicationUpdateException(
                    'Status Git worktree tidak dapat diverifikasi; update dibatalkan.',
                    0,
                    $exception,
                );
            }

            if ($result->failed() || trim($result->output()) !== '') {
                throw new ApplicationUpdateException(
                    'Ada perubahan file terlacak atau tidak terlacak pada Git worktree. Gunakan deployment bersih sebelum self-update.',
                );
            }
        }
    }

    private function assertNoConcurrentOperations(ApplicationUpdate $update): void
    {
        $otherUpdateExists = ApplicationUpdate::query()
            ->whereKeyNot($update->getKey())
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($otherUpdateExists) {
            throw new ApplicationUpdateException('Masih ada update aplikasi lain yang aktif.');
        }

        $exportActive = DataExport::query()
            ->where(function ($query): void {
                $query->whereIn('status', ['queued', 'running'])
                    ->orWhereIn('purge_status', ['queued', 'running']);
            })
            ->exists();
        if ($exportActive) {
            throw new ApplicationUpdateException('Tunggu proses backup atau purge data selesai sebelum update.');
        }

        // The coordinator status covers queued jobs, while this shared command
        // lock also detects an ingestion command started directly from CLI.
        // The update marker was created before this check. A command that
        // starts after we release the lock will therefore see the marker and
        // stop before OpenSearch I/O, closing the check/start race.
        $ingestionLock = Cache::lock(
            'siem_fetch_running',
            max(600, (int) config('services.opensearch.fetch_lock_seconds', 3600)),
        );
        if (! $ingestionLock->get()) {
            throw new ApplicationUpdateException('Tunggu proses ingestion Wazuh selesai sebelum update.');
        }

        try {
            $ingestionStatus = $this->ingestion->status();
            if (in_array($ingestionStatus['state'] ?? null, ['queued', 'running', 'waiting'], true)) {
                throw new ApplicationUpdateException('Tunggu proses ingestion Wazuh selesai sebelum update.');
            }
        } finally {
            $ingestionLock->release();
        }
    }

    /** @param list<string> $arguments */
    private function runArtisan(array $arguments, ?int $timeout = null): void
    {
        $root = rtrim((string) config('application_updates.application_root', base_path()), '/\\');
        $timeout ??= (int) config('application_updates.process_timeout_seconds', 900);
        $result = Process::path($root)
            ->timeout($timeout)
            ->quietly()
            ->run([PHP_BINARY, 'artisan', ...$arguments]);

        if ($result->failed()) {
            throw new ApplicationUpdateException(
                'Perintah pemeliharaan aplikasi gagal. Tinjau log server untuk pemulihan.',
            );
        }
    }

    private function recordFailure(ApplicationUpdate $update, bool $recoveryRequired): void
    {
        try {
            $this->audit->transition(
                $update,
                $recoveryRequired ? 'manual_recovery_required' : 'failed',
                $recoveryRequired
                    ? 'Update berhenti setelah perubahan berisiko; pulihkan dari backup penuh secara manual.'
                    : 'Update dibatalkan dengan aman sebelum migrasi database.',
                [
                    'status' => $recoveryRequired ? 'recovery_required' : 'failed',
                    'completed_at' => now(),
                    'error_message' => $recoveryRequired
                        ? 'Pemulihan manual diperlukan. Jangan jalankan update ulang sebelum aplikasi dan database diperiksa.'
                        : 'Update dibatalkan. Versi sebelumnya dipertahankan atau dipulihkan.',
                ],
                'error',
            );
        } catch (Throwable $auditException) {
            Log::critical('[Application Update] Failure state could not be persisted', [
                'update_id' => $update->id,
                'exception_class' => $auditException::class,
            ]);
        }
    }

    private function maintenanceFile(): string
    {
        $root = rtrim((string) config('application_updates.application_root', base_path()), '/\\');

        return $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'down';
    }
}
