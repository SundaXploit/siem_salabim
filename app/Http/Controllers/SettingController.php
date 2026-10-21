<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateDataExport;
use App\Jobs\PurgeDataExport;
use App\Models\Alert;
use App\Models\AlertAiAnalysis;
use App\Models\AlertTriage;
use App\Models\ApplicationUpdate;
use App\Models\Configuration;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\AlertIngestionCoordinator;
use App\Services\AmanaiInsightService;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\ApplicationVersionService;
use App\Services\DataExportArchiveService;
use App\Services\GitHubReleaseService;
use App\Services\OpenSearchService;
use App\Services\QueueExecutionPolicy;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    protected function adminOnly(): void
    {
        if (! auth()->check() || ! auth()->user()->isAdmin()) {
            abort(403, 'Akses hanya untuk Admin.');
        }
    }

    public function index()
    {
        $this->adminOnly();
        $configKeys = [
            'opensearch_host',
            'opensearch_username',
            'opensearch_index',
            'min_alert_level',
            'ai_base_url',
            'ai_model',
            'ai_snapshot_limit',
            'ai_refresh_days',
        ];
        $defaults = [
            'opensearch_host' => config('services.opensearch.host'),
            'opensearch_username' => config('services.opensearch.username'),
            'opensearch_index' => config('services.opensearch.index'),
            'min_alert_level' => config('services.opensearch.min_level'),
            'ai_base_url' => config('services.amanai.base_url'),
            'ai_model' => config('services.amanai.model'),
            'ai_snapshot_limit' => config('services.amanai.snapshot_limit'),
            'ai_refresh_days' => config('services.amanai.refresh_days'),
        ];
        $configs = collect($configKeys)->mapWithKeys(
            fn (string $key) => [$key => Configuration::get($key, $defaults[$key] ?? null)]
        );
        $users = User::orderBy('name')->get();
        $dataExports = DataExport::query()
            ->with(['requestedBy', 'purgeRequestedBy'])
            ->latest()
            ->limit(20)
            ->get();
        $aiConfigured = app(AmanaiInsightService::class)->isConfigured();
        $aiKeyStoredInSettings = Configuration::query()
            ->where('key', 'ai_api_key')
            ->exists();
        $applicationVersion = app(ApplicationVersionService::class)->current();
        $latestRelease = app(GitHubReleaseService::class)->cached();
        $applicationUpdates = ApplicationUpdate::query()
            ->latest()
            ->limit(10)
            ->get();
        $applicationUpdateEnabled = (bool) config('application_updates.self_update_enabled', false);
        $applicationUpdateBackupConfirmed = (bool) config('application_updates.backup_confirmed', false);
        $applicationUpdateQueueReady = app(QueueExecutionPolicy::class)->runsOutsideRequest();

        return view('settings.index', compact(
            'configs',
            'users',
            'dataExports',
            'aiConfigured',
            'aiKeyStoredInSettings',
            'applicationVersion',
            'latestRelease',
            'applicationUpdates',
            'applicationUpdateEnabled',
            'applicationUpdateBackupConfirmed',
            'applicationUpdateQueueReady',
        ));
    }

    public function updateOpenSearch(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'opensearch_host' => ['required', 'url', 'max:2048'],
            'opensearch_username' => ['required', 'string', 'max:255'],
            'opensearch_password' => ['nullable', 'string', 'max:4096'],
            'opensearch_index' => ['required', 'string', 'max:255'],
        ]);

        Configuration::set('opensearch_host', trim($data['opensearch_host']));
        Configuration::set('opensearch_username', trim($data['opensearch_username']));
        Configuration::set('opensearch_index', trim($data['opensearch_index']));
        if (filled($data['opensearch_password'] ?? null)) {
            Configuration::set('opensearch_password', $data['opensearch_password']);
        }

        return back()->with('success', 'Konfigurasi OpenSearch berhasil disimpan.');
    }

    public function updateTelegram(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'telegram_bot_token' => 'nullable|string|max:4096',
            'telegram_chat_ids' => 'nullable|array',
            'telegram_chat_ids.*' => 'nullable|string|max:100',
        ]);

        if (filled($data['telegram_bot_token'] ?? null)) {
            Configuration::set('telegram_bot_token', trim($data['telegram_bot_token']));
        } elseif (blank(Configuration::get('telegram_bot_token', config('services.telegram.bot_token', '')))) {
            throw ValidationException::withMessages([
                'telegram_bot_token' => 'Masukkan token bot Telegram sebelum menyimpan konfigurasi pertama.',
            ]);
        }

        Configuration::set('telegram_chat_ids', json_encode(
            array_values(array_filter(array_map('trim', $data['telegram_chat_ids'] ?? [])))
        ));

        return back()->with('success', 'Konfigurasi Telegram berhasil disimpan.');
    }

    public function updateAiInsight(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'ai_api_key' => ['nullable', 'string', 'max:4096'],
            'ai_base_url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'ai_model' => ['required', 'string', 'max:255'],
            'ai_snapshot_limit' => ['required', 'integer', 'min:1000', 'max:10000'],
            'min_alert_level' => ['required', 'integer', Rule::in([3, 5, 7, 10, 12, 13, 14, 15])],
            'ai_refresh_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        // Only update API key if a new value was provided
        if (filled($data['ai_api_key'] ?? null)) {
            Configuration::set('ai_api_key', $data['ai_api_key']);
        } elseif (blank(Configuration::get('ai_api_key', config('services.amanai.api_key', '')))) {
            throw ValidationException::withMessages([
                'ai_api_key' => 'Masukkan API key sebelum menyimpan konfigurasi pertama.',
            ]);
        }

        Configuration::set('ai_base_url', rtrim(trim($data['ai_base_url']), '/'));
        Configuration::set('ai_model', trim($data['ai_model']));
        Configuration::set('ai_snapshot_limit', (int) $data['ai_snapshot_limit']);
        Configuration::set('min_alert_level', (int) $data['min_alert_level']);
        Configuration::set('ai_refresh_days', (int) $data['ai_refresh_days']);

        Cache::forget('security-insight-snapshot:v2:'.now('Asia/Jakarta')->format('Y-m'));

        return back()->with('success', 'Konfigurasi Analisis AI berhasil disimpan.');
    }

    public function testAiInsight(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'ai_api_key' => ['nullable', 'string', 'max:4096'],
            'ai_base_url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'ai_model' => ['nullable', 'string', 'max:255'],
        ]);

        $apiKey = filled($data['ai_api_key'] ?? null)
            ? $data['ai_api_key']
            : Configuration::get('ai_api_key', config('services.amanai.api_key', ''));

        $baseUrl = rtrim(trim($data['ai_base_url']), '/');
        $model = trim($data['ai_model'] ?? Configuration::get('ai_model', config('services.amanai.model', 'amanai/deepseek-v4.1-flash')));

        if (blank($apiKey)) {
            return response()->json([
                'success' => false,
                'error' => 'Masukkan API key untuk menguji koneksi.',
            ], 422);
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) $apiKey)
                ->connectTimeout(10)
                ->timeout(30)
                ->post($baseUrl.'/responses', [
                    'model' => $model,
                    'input' => 'Jawab tepat satu kata: pong',
                    'reasoning' => ['effort' => 'none'],
                    'max_output_tokens' => 256,
                ]);

            if ($response->successful()) {
                $reply = data_get($response->json(), 'output_text', '');
                if (is_array($reply)) {
                    $reply = implode('', $reply);
                }
                if (blank($reply)) {
                    $reply = collect(data_get($response->json(), 'output', []))
                        ->flatMap(fn ($item) => is_array($item) ? ($item['content'] ?? []) : [])
                        ->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : '')
                        ->implode('');
                }

                if (blank(trim((string) $reply))) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Endpoint AI terhubung, tetapi tidak mengembalikan teks jawaban. Periksa dukungan model untuk reasoning none.',
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'model' => $model,
                    'reply' => mb_substr(trim((string) $reply), 0, 200),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Server AI menolak permintaan (HTTP '.$response->status().').',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AmanAI] Connection test failed', [
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Tidak dapat terhubung ke endpoint AI. Periksa URL, jaringan, TLS, dan API key.',
            ]);
        }
    }

    public function testOpenSearch(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'opensearch_host' => ['required', 'url', 'max:2048'],
            'opensearch_username' => ['required', 'string', 'max:255'],
            'opensearch_password' => ['nullable', 'string', 'max:4096'],
            'opensearch_index' => ['required', 'string', 'max:255'],
        ]);

        $data['opensearch_password'] = filled($data['opensearch_password'] ?? null)
            ? $data['opensearch_password']
            : Configuration::get('opensearch_password', config('services.opensearch.password', ''));

        if (blank($data['opensearch_password'])) {
            return response()->json([
                'success' => false,
                'error' => 'Masukkan password OpenSearch untuk menguji koneksi pertama.',
            ], 422);
        }

        $service = app(OpenSearchService::class);
        $result = $service->testConfiguration($data);

        return response()->json($result);
    }

    public function testTelegram(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'chat_id' => ['required', 'string', 'max:100'],
            'telegram_bot_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $botToken = filled($data['telegram_bot_token'] ?? null)
            ? trim($data['telegram_bot_token'])
            : Configuration::get('telegram_bot_token', config('services.telegram.bot_token', ''));

        if (blank($botToken)) {
            return response()->json([
                'success' => false,
                'error' => 'Masukkan token bot Telegram untuk menguji koneksi pertama.',
            ], 422);
        }

        $service = app(TelegramService::class);
        $result = $service->sendMessage(
            trim($data['chat_id']),
            '✅ Test koneksi dari SIEM Salabim berhasil!',
            $botToken,
        );

        $httpStatus = $result['success']
            ? 200
            : (in_array((int) ($result['status'] ?? 0), [400, 401, 403, 404], true) ? 422 : 503);

        return response()->json($result, $httpStatus);
    }

    // ─── User Management ─────────────────────────────────────────────────────

    public function createUser(Request $request)
    {
        $this->adminOnly();
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:admin,analyst',
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        return back()->with('success', "User {$request->name} berhasil dibuat.");
    }

    public function updateUser(Request $request, User $user)
    {
        $this->adminOnly();
        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|in:admin,analyst',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $data = ['name' => $request->name, 'role' => $request->role];
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return back()->with('success', "User {$user->name} berhasil diupdate.");
    }

    public function deleteUser(User $user)
    {
        $this->adminOnly();
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        $user->delete();

        return back()->with('success', 'User berhasil dihapus.');
    }

    /**
     * Manually trigger the Wazuh alert fetch via web button.
     */
    public function manualFetch(AlertIngestionCoordinator $ingestion)
    {
        $this->adminOnly();

        $status = $ingestion->requestTodayFetch('settings-manual');

        if ($status['state'] === 'queued' && $status['mode'] === 'today') {
            return redirect()->route('settings.index')->with(
                'success',
                'Fetch seluruh alert hari ini masuk antrean. Halaman tidak perlu menunggu OpenSearch selesai.',
            );
        }

        if ($status['mode'] === 'today' && in_array($status['state'], ['queued', 'running', 'waiting'], true)) {
            return redirect()->route('settings.index')->with(
                'success',
                'Fetch seluruh alert hari ini sudah berada di antrean atau sedang berjalan.',
            );
        }

        $message = match ($status['state']) {
            'worker_required' => 'Fetch tidak diantrekan. Gunakan queue database/Redis dan jalankan queue worker.',
            'queue_failed', 'status_unavailable' => 'Fetch tidak dapat dimasukkan ke antrean. Periksa queue worker dan log aplikasi.',
            default => 'Fetch lain sedang antre atau berjalan. Coba kembali setelah proses tersebut selesai.',
        };

        return redirect()->route('settings.index')->with('error', $message);
    }

    // ─── Data Management ─────────────────────────────────────────────────────

    /**
     * Preview — how many records will be affected?
     */
    public function previewData(Request $request)
    {
        $this->adminOnly();

        $data = $this->validateDataSelection($request);
        $period = $this->resolveDataPeriod($data);
        $scope = $data['scope'];
        $alerts = $this->alertsForPeriod($period);
        $counts = ['alerts' => (clone $alerts)->count()];

        if (in_array($scope, ['alerts', 'all'], true)) {
            $counts['alert_ai_analyses'] = AlertAiAnalysis::whereIn(
                'alert_id',
                (clone $alerts)->select('id'),
            )->count();
        }
        if (in_array($scope, ['alerts', 'triages', 'all'], true)) {
            $counts['triages'] = AlertTriage::whereIn(
                'alert_id',
                (clone $alerts)->select('id'),
            )->count();
        }
        if (in_array($scope, ['alerts', 'notif_logs', 'all'], true)) {
            $counts['notif_logs'] = NotificationLog::whereIn(
                'alert_id',
                (clone $alerts)->select('id'),
            )->count();
        }

        return response()->json([
            'counts' => $counts,
            'period' => [
                'from' => $period['from']?->toIso8601String(),
                'to_exclusive' => $period['to_exclusive']?->toIso8601String(),
                'label' => $period['label'],
            ],
        ]);
    }

    /**
     * Queue a bounded-memory export to durable private storage.
     */
    public function backupData(
        Request $request,
        DataExportArchiveService $archives,
        QueueExecutionPolicy $queueExecutionPolicy,
    ) {
        $this->adminOnly();

        return $this->queueDataExport($request, $archives, $queueExecutionPolicy);
    }

    /**
     * Direct deletion is intentionally disabled. A purge must reference a
     * completed export whose manifest and checksum have been verified.
     */
    public function deleteData(Request $request)
    {
        $this->adminOnly();

        return redirect()->route('settings.index')->with(
            'error',
            'Penghapusan langsung dinonaktifkan. Buat backup, tunggu status Terverifikasi, lalu gunakan tombol Purge.',
        );
    }

    /**
     * Backwards-compatible endpoint: it now queues only the safe export. The
     * destructive phase always requires a second, explicit request.
     */
    public function backupAndDelete(
        Request $request,
        DataExportArchiveService $archives,
        QueueExecutionPolicy $queueExecutionPolicy,
    ) {
        $this->adminOnly();

        return $this->queueDataExport($request, $archives, $queueExecutionPolicy, true);
    }

    public function downloadDataExport(
        DataExport $dataExport,
        DataExportArchiveService $archives,
    ) {
        $this->adminOnly();

        try {
            $archives->verifyArchive($dataExport);
        } catch (\Throwable $exception) {
            Log::warning('[Data Export] Download blocked by integrity verification', [
                'export_id' => $dataExport->id,
                'exception_class' => $exception::class,
            ]);

            abort(409, 'Integritas file ekspor tidak dapat diverifikasi. Download dibatalkan.');
        }

        return Storage::disk($dataExport->storage_disk)->download(
            $dataExport->storage_path,
            $dataExport->filename,
            [
                'Content-Type' => $dataExport->format === 'json' ? 'application/json' : 'application/sql',
                'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function purgeDataExport(
        DataExport $dataExport,
        QueueExecutionPolicy $queueExecutionPolicy,
    ) {
        $this->adminOnly();

        if (app(ApplicationUpdateRuntimeGuard::class)->isActive()) {
            return back()->with(
                'error',
                'Purge ditunda karena update aplikasi sedang berjalan atau menunggu pemulihan.',
            );
        }

        if ($this->requiresAsyncExportQueue() && ! $queueExecutionPolicy->runsOutsideRequest()) {
            return back()->with(
                'error',
                'Purge dibatalkan karena queue belum asynchronous. Gunakan queue database/Redis dan jalankan worker exports.',
            );
        }

        if (! $dataExport->isVerified()) {
            return back()->with('error', 'Purge hanya dapat dilakukan dari backup terverifikasi.');
        }

        $claimed = DataExport::query()
            ->whereKey($dataExport->id)
            ->where('status', 'verified')
            ->where(function ($query): void {
                $query->whereNull('purge_status')->orWhere('purge_status', 'failed');
            })
            ->update([
                'purge_status' => 'queued',
                'purge_requested_by_user_id' => auth()->id(),
                'purge_requested_by_name' => auth()->user()->name,
                'purge_requested_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return back()->with('error', 'Purge untuk backup ini sudah diproses atau sedang berjalan.');
        }

        try {
            PurgeDataExport::dispatch($dataExport->id);
        } catch (\Throwable $exception) {
            DataExport::query()
                ->whereKey($dataExport->id)
                ->whereIn('purge_status', ['queued', 'running'])
                ->update([
                    'purge_status' => 'failed',
                    'error_message' => 'Purge tidak dapat dimasukkan ke antrean; tidak ada data yang dihapus.',
                    'updated_at' => now(),
                ]);

            Log::warning('[Data Export] Purge dispatch failed safely', [
                'export_id' => $dataExport->id,
                'exception_class' => $exception::class,
            ]);

            return back()->with('error', 'Purge dibatalkan: verifikasi backup atau snapshot sumber gagal.');
        }

        return back()->with(
            'success',
            'Purge masuk antrean. Worker akan memverifikasi ulang manifest, checksum, dan sumber sebelum menghapus.',
        );
    }

    public function dataExportStatus(DataExport $dataExport)
    {
        $this->adminOnly();

        return response()->json([
            'id' => $dataExport->id,
            'status' => $dataExport->status,
            'purge_status' => $dataExport->purge_status,
            'verified_at' => $dataExport->verified_at?->toIso8601String(),
            'purged_at' => $dataExport->purged_at?->toIso8601String(),
            'record_counts' => $dataExport->record_counts,
            'checksum' => $dataExport->checksum,
            'error_message' => $dataExport->error_message,
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function queueDataExport(
        Request $request,
        DataExportArchiveService $archives,
        QueueExecutionPolicy $queueExecutionPolicy,
        bool $requestedFromLegacyCombinedAction = false,
    ) {
        if (app(ApplicationUpdateRuntimeGuard::class)->isActive()) {
            return redirect()->route('settings.index')->with(
                'error',
                'Backup ditunda karena update aplikasi sedang berjalan atau menunggu pemulihan.',
            );
        }

        if ($this->requiresAsyncExportQueue() && ! $queueExecutionPolicy->runsOutsideRequest()) {
            return redirect()->route('settings.index')->with(
                'error',
                'Backup dibatalkan karena queue belum asynchronous. Gunakan queue database/Redis dan jalankan worker exports.',
            );
        }

        $data = $this->validateDataSelection($request, true);
        $period = $this->resolveDataPeriod($data);
        $diskName = (string) config('data_exports.disk', 'local');

        try {
            $archives->assertPrivateStorageDisk($diskName);
        } catch (\Throwable $exception) {
            Log::warning('[Data Export] Export disk rejected', [
                'disk' => $diskName,
                'exception_class' => $exception::class,
            ]);

            return redirect()->route('settings.index')->with(
                'error',
                'Backup dibatalkan karena storage ekspor tidak terkonfigurasi sebagai storage privat.',
            );
        }

        if (! $this->alertsForPeriod($period)->exists()) {
            return redirect()->route('settings.index')
                ->with('error', "Tidak ada data untuk di-backup ({$period['label']}).");
        }

        $submissionLock = Cache::lock('data-export:submission', 15);
        if (! $submissionLock->get()) {
            return redirect()->route('settings.index')->with(
                'error',
                'Permintaan backup lain sedang diproses. Coba kembali beberapa saat lagi.',
            );
        }

        try {
            if (DataExport::query()->whereIn('status', ['queued', 'running'])->exists()) {
                return redirect()->route('settings.index')->with(
                    'error',
                    'Masih ada backup aktif. Tunggu sampai selesai sebelum membuat backup baru.',
                );
            }

            $uuid = (string) Str::uuid();
            $filename = sprintf(
                'siem_backup_%s_%s_%s.%s',
                $data['scope'],
                now()->format('Ymd_His'),
                substr($uuid, 0, 8),
                $data['format'],
            );

            $export = DataExport::create([
                'uuid' => $uuid,
                'requested_by_user_id' => auth()->id(),
                'requested_by_name' => auth()->user()->name,
                'scope' => $data['scope'],
                'format' => $data['format'],
                'status' => 'queued',
                'period_start' => $period['from'],
                // The legacy column stores an exclusive upper bound.
                'period_end' => $period['to_exclusive'],
                'period_label' => $period['label'],
                'storage_disk' => $diskName,
                'filename' => $filename,
            ]);

            try {
                GenerateDataExport::dispatch($export->id);
            } catch (\Throwable $exception) {
                $export->forceFill([
                    'status' => 'failed',
                    'error_message' => 'Backup tidak dapat dimasukkan ke antrean; tidak ada data yang dihapus.',
                ])->save();

                Log::warning('[Data Export] Dispatch or synchronous generation failed', [
                    'export_id' => $export->id,
                    'exception_class' => $exception::class,
                ]);

                return redirect()->route('settings.index')->with(
                    'error',
                    'Backup tidak dapat diselesaikan. Tidak ada data yang dihapus; periksa status ekspor dan log aplikasi.',
                );
            }
        } finally {
            $submissionLock->release();
        }

        $message = $requestedFromLegacyCombinedAction
            ? 'Backup masuk antrean. Data belum dihapus; setelah status Terverifikasi, lakukan Purge secara terpisah.'
            : 'Backup masuk antrean dan akan disimpan di storage privat. Muat ulang halaman untuk melihat statusnya.';

        return redirect()->route('settings.index')->with('success', $message);
    }

    private function validateDataSelection(Request $request, bool $needsFormat = false): array
    {
        $rules = [
            'scope' => ['required', Rule::in(['alerts', 'triages', 'notif_logs', 'all'])],
            'period_mode' => ['required', Rule::in(['relative', 'range'])],
            'amount' => ['nullable', 'required_if:period_mode,relative', 'integer', 'min:1', 'max:9999'],
            'unit' => ['nullable', 'required_if:period_mode,relative', Rule::in(['days', 'weeks', 'months', 'years'])],
            'date_from' => ['nullable', 'required_if:period_mode,range', 'date', 'before_or_equal:date_to'],
            'date_to' => ['nullable', 'required_if:period_mode,range', 'date', 'after_or_equal:date_from'],
        ];

        if ($needsFormat) {
            $rules['format'] = ['required', Rule::in(['json', 'sql'])];
        }

        return $request->validate($rules);
    }

    private function resolveDataPeriod(array $data): array
    {
        if ($data['period_mode'] === 'range') {
            $fromLocal = Carbon::parse($data['date_from'], 'Asia/Jakarta')->startOfDay();
            $toExclusiveLocal = Carbon::parse($data['date_to'], 'Asia/Jakarta')->addDay()->startOfDay();

            return [
                'from' => $fromLocal->copy()->utc(),
                'to_exclusive' => $toExclusiveLocal->copy()->utc(),
                'label' => $data['date_from'].' s/d '.$data['date_to'],
            ];
        }

        $units = ['days' => 'hari', 'weeks' => 'minggu', 'months' => 'bulan', 'years' => 'tahun'];
        $amount = (int) $data['amount'];
        $cutoff = now()->sub($data['unit'], $amount);

        return [
            'from' => null,
            'to_exclusive' => $cutoff,
            'label' => 'lebih lama dari '.$amount.' '.$units[$data['unit']],
        ];
    }

    private function alertsForPeriod(array $period)
    {
        return Alert::query()
            ->when($period['from'], fn ($query, $from) => $query->where('first_seen_at', '>=', $from))
            ->when(
                $period['to_exclusive'],
                fn ($query, $toExclusive) => $query->where('first_seen_at', '<', $toExclusive),
            );
    }

    private function requiresAsyncExportQueue(): bool
    {
        return (bool) config('data_exports.require_async_queue', true);
    }
}
