<?php

namespace App\Http\Controllers;

use App\Exceptions\ApplicationUpdateException;
use App\Jobs\ApplyApplicationUpdate;
use App\Models\ApplicationUpdate;
use App\Services\ApplicationVersionService;
use App\Services\GitHubReleaseService;
use App\Services\QueueExecutionPolicy;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplicationUpdateController extends Controller
{
    public function check(Request $request, GitHubReleaseService $releases): JsonResponse
    {
        $this->assertAdmin($request);

        try {
            return response()->json($releases->check());
        } catch (ApplicationUpdateException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            Log::warning('[Application Update] Version check failed', [
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Pemeriksaan versi tidak dapat diselesaikan.',
            ], 502);
        }
    }

    public function store(
        Request $request,
        GitHubReleaseService $releases,
        ApplicationVersionService $versions,
        QueueExecutionPolicy $queuePolicy,
    ): RedirectResponse {
        $this->assertAdmin($request);

        $data = $request->validate([
            'target_version' => ['required', 'string', 'regex:/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D'],
            'confirmation' => ['required', 'string', 'max:100'],
            'current_password' => ['required', 'string', 'current_password:web'],
            'backup_acknowledged' => ['accepted'],
        ], [
            'confirmation.required' => 'Ketik frasa konfirmasi update.',
            'current_password.current_password' => 'Password administrator tidak benar.',
            'backup_acknowledged.accepted' => 'Konfirmasi backup penuh wajib dicentang.',
        ]);

        $expectedConfirmation = 'UPDATE v'.$data['target_version'];
        if (! hash_equals($expectedConfirmation, trim($data['confirmation']))) {
            throw ValidationException::withMessages([
                'confirmation' => "Ketik tepat: {$expectedConfirmation}",
            ]);
        }

        if (! config('application_updates.self_update_enabled', false)) {
            return back()->with('error', 'Self-update dinonaktifkan oleh administrator server.');
        }
        if (! config('application_updates.backup_confirmed', false)) {
            return back()->with(
                'error',
                'Update dibatalkan: server belum mengonfirmasi backup penuh aplikasi dan database.',
            );
        }
        if (! $queuePolicy->runsOutsideRequest()) {
            return back()->with(
                'error',
                'Update memerlukan queue asynchronous dan worker khusus queue updates.',
            );
        }

        try {
            $release = $releases->releaseForUpdate($data['target_version']);
        } catch (ApplicationUpdateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $asset = $release['asset'] ?? null;
        if (! is_array($asset) || ! is_string($asset['sha256'] ?? null)) {
            return back()->with('error', 'Metadata checksum asset release tidak lengkap.');
        }

        $submissionLock = Cache::lock('application-update:submission', 30);
        if (! $submissionLock->get()) {
            return back()->with('error', 'Permintaan update lain sedang diproses.');
        }

        try {
            if (ApplicationUpdate::query()
                ->whereIn('status', ['queued', 'running', 'recovery_required'])
                ->exists()) {
                return back()->with(
                    'error',
                    'Masih ada update aktif atau pemulihan yang belum diselesaikan.',
                );
            }

            $user = $request->user();
            $update = DB::transaction(function () use ($release, $asset, $versions, $user): ApplicationUpdate {
                $update = ApplicationUpdate::create([
                    'uuid' => (string) Str::uuid(),
                    'requested_by_user_id' => $user->id,
                    'requested_by_name' => $user->name,
                    'from_version' => $versions->current(),
                    'target_version' => $release['latest_version'],
                    'release_id' => $release['release_id'],
                    'release_tag' => $release['release_tag'],
                    'release_url' => $release['release_url'],
                    'asset_id' => $asset['id'],
                    'asset_name' => $asset['name'],
                    'asset_api_url' => $asset['api_url'],
                    'asset_size' => $asset['size'],
                    'asset_sha256' => $asset['sha256'],
                    'status' => 'queued',
                    'phase' => 'requested',
                ]);
                $update->events()->create([
                    'phase' => 'requested',
                    'level' => 'info',
                    'message' => 'Administrator meminta update aplikasi.',
                ]);

                return $update;
            }, 3);

            $job = new ApplyApplicationUpdate($update->id);
            $uniqueLock = new UniqueLock(app(CacheRepository::class));
            try {
                $uniqueLockAcquired = $uniqueLock->acquire($job);
            } catch (Throwable $exception) {
                $update->forceFill([
                    'status' => 'failed',
                    'phase' => 'dispatch_failed',
                    'completed_at' => now(),
                    'error_message' => 'Update tidak dapat dimasukkan ke antrean; aplikasi belum diubah.',
                ])->save();
                $update->events()->create([
                    'phase' => 'dispatch_failed',
                    'level' => 'error',
                    'message' => 'Kunci antrean update tidak dapat diperoleh dari cache.',
                ]);
                Log::error('[Application Update] Unique lock acquisition failed', [
                    'update_id' => $update->id,
                    'exception_class' => $exception::class,
                ]);

                return back()->with('error', 'Update gagal dimasukkan ke antrean; aplikasi belum diubah.');
            }

            if (! $uniqueLockAcquired) {
                $update->forceFill([
                    'status' => 'failed',
                    'phase' => 'dispatch_blocked',
                    'completed_at' => now(),
                    'error_message' => 'Kunci antrean update masih aktif; aplikasi belum diubah.',
                ])->save();
                $update->events()->create([
                    'phase' => 'dispatch_blocked',
                    'level' => 'error',
                    'message' => 'Permintaan update tidak diantrekan karena kunci worker sebelumnya masih aktif.',
                ]);

                return back()->with(
                    'error',
                    'Update tidak diantrekan karena worker sebelumnya masih memegang kunci. Periksa worker atau tunggu kunci kedaluwarsa.',
                );
            }

            try {
                app(Dispatcher::class)->dispatch($job);
            } catch (Throwable $exception) {
                $uniqueLock->release($job);
                $update->forceFill([
                    'status' => 'failed',
                    'phase' => 'dispatch_failed',
                    'completed_at' => now(),
                    'error_message' => 'Update tidak dapat dimasukkan ke antrean; aplikasi belum diubah.',
                ])->save();
                $update->events()->create([
                    'phase' => 'dispatch_failed',
                    'level' => 'error',
                    'message' => 'Permintaan update gagal dimasukkan ke queue.',
                ]);
                Log::error('[Application Update] Dispatch failed', [
                    'update_id' => $update->id,
                    'exception_class' => $exception::class,
                ]);

                return back()->with('error', 'Update gagal dimasukkan ke antrean; aplikasi belum diubah.');
            }
        } finally {
            $submissionLock->release();
        }

        return back()->with(
            'success',
            "Update ke v{$update->target_version} masuk antrean khusus updates.",
        );
    }

    public function status(Request $request, ApplicationUpdate $applicationUpdate): JsonResponse
    {
        $this->assertAdmin($request);
        $applicationUpdate->load(['events' => fn ($query) => $query->latest()->limit(30)]);

        return response()->json([
            'id' => $applicationUpdate->id,
            'from_version' => $applicationUpdate->from_version,
            'target_version' => $applicationUpdate->target_version,
            'status' => $applicationUpdate->status,
            'phase' => $applicationUpdate->phase,
            'started_at' => $applicationUpdate->started_at?->toIso8601String(),
            'completed_at' => $applicationUpdate->completed_at?->toIso8601String(),
            'error_message' => $applicationUpdate->error_message,
            'events' => $applicationUpdate->events->map(fn ($event): array => [
                'phase' => $event->phase,
                'level' => $event->level,
                'message' => $event->message,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Akses hanya untuk Admin.');
    }
}
