<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\User;
use App\Services\AmanaiInsightService;
use App\Services\OpenSearchService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    protected function adminOnly(): void
    {
        if (!auth()->check() || !auth()->user()->isAdmin()) {
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
        $users   = User::orderBy('name')->get();
        $aiConfigured = app(AmanaiInsightService::class)->isConfigured();
        $aiKeyStoredInSettings = Configuration::query()
            ->where('key', 'ai_api_key')
            ->exists();

        return view('settings.index', compact('configs', 'users', 'aiConfigured', 'aiKeyStoredInSettings'));
    }

    public function updateOpenSearch(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'opensearch_host'     => ['required', 'url', 'max:2048'],
            'opensearch_username' => ['required', 'string', 'max:255'],
            'opensearch_password' => ['nullable', 'string', 'max:4096'],
            'opensearch_index'    => ['required', 'string', 'max:255'],
        ]);

        Configuration::set('opensearch_host',     trim($data['opensearch_host']));
        Configuration::set('opensearch_username', trim($data['opensearch_username']));
        Configuration::set('opensearch_index',    trim($data['opensearch_index']));
        if (filled($data['opensearch_password'] ?? null)) {
            Configuration::set('opensearch_password', $data['opensearch_password']);
        }

        return back()->with('success', 'Konfigurasi OpenSearch berhasil disimpan.');
    }

    public function updateTelegram(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'telegram_bot_token'  => 'nullable|string|max:4096',
            'telegram_chat_ids'   => 'nullable|array',
            'telegram_chat_ids.*' => 'nullable|string|max:100',
        ]);

        if (filled($data['telegram_bot_token'] ?? null)) {
            Configuration::set('telegram_bot_token', trim($data['telegram_bot_token']));
        } elseif (blank(Configuration::get('telegram_bot_token', config('services.telegram.bot_token', '')))) {
            throw ValidationException::withMessages([
                'telegram_bot_token' => 'Masukkan token bot Telegram sebelum menyimpan konfigurasi pertama.',
            ]);
        }

        Configuration::set('telegram_chat_ids',  json_encode(
            array_values(array_filter(array_map('trim', $data['telegram_chat_ids'] ?? [])))
        ));

        return back()->with('success', 'Konfigurasi Telegram berhasil disimpan.');
    }

    public function updateAiInsight(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'ai_api_key'       => ['nullable', 'string', 'max:4096'],
            'ai_base_url'      => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'ai_model'         => ['required', 'string', 'max:255'],
            'ai_snapshot_limit'=> ['required', 'integer', 'min:1000', 'max:10000'],
            'min_alert_level'  => ['required', 'integer', Rule::in([3, 5, 7, 10, 12, 13, 14, 15])],
            'ai_refresh_days'  => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        // Only update API key if a new value was provided
        if (filled($data['ai_api_key'] ?? null)) {
            Configuration::set('ai_api_key', $data['ai_api_key']);
        } elseif (blank(Configuration::get('ai_api_key', config('services.amanai.api_key', '')))) {
            throw ValidationException::withMessages([
                'ai_api_key' => 'Masukkan API key sebelum menyimpan konfigurasi pertama.',
            ]);
        }

        Configuration::set('ai_base_url',       rtrim(trim($data['ai_base_url']), '/'));
        Configuration::set('ai_model',           trim($data['ai_model']));
        Configuration::set('ai_snapshot_limit',  (int) $data['ai_snapshot_limit']);
        Configuration::set('min_alert_level',    (int) $data['min_alert_level']);
        Configuration::set('ai_refresh_days',    (int) $data['ai_refresh_days']);

        Cache::forget('security-insight-snapshot:v2:' . now('Asia/Jakarta')->format('Y-m'));

        return back()->with('success', 'Konfigurasi Analisis AI berhasil disimpan.');
    }

    public function testAiInsight(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'ai_api_key'  => ['nullable', 'string', 'max:4096'],
            'ai_base_url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'ai_model'    => ['nullable', 'string', 'max:255'],
        ]);

        $apiKey  = filled($data['ai_api_key'] ?? null)
            ? $data['ai_api_key']
            : Configuration::get('ai_api_key', config('services.amanai.api_key', ''));

        $baseUrl = rtrim(trim($data['ai_base_url']), '/');
        $model   = trim($data['ai_model'] ?? Configuration::get('ai_model', config('services.amanai.model', 'amanai/deepseek-v4.1-flash')));

        if (blank($apiKey)) {
            return response()->json([
                'success' => false,
                'error'   => 'Masukkan API key untuk menguji koneksi.',
            ], 422);
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) $apiKey)
                ->connectTimeout(10)
                ->timeout(30)
                ->post($baseUrl . '/responses', [
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
                    'model'   => $model,
                    'reply'   => mb_substr(trim((string) $reply), 0, 200),
                ]);
            }

            return response()->json([
                'success' => false,
                'error'   => 'Server AI menolak permintaan (HTTP ' . $response->status() . ').',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AmanAI] Connection test failed', [
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Tidak dapat terhubung ke endpoint AI. Periksa URL, jaringan, TLS, dan API key.',
            ]);
        }
    }

    public function testOpenSearch(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'opensearch_host'     => ['required', 'url', 'max:2048'],
            'opensearch_username' => ['required', 'string', 'max:255'],
            'opensearch_password' => ['nullable', 'string', 'max:4096'],
            'opensearch_index'    => ['required', 'string', 'max:255'],
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
        $result  = $service->testConfiguration($data);

        return response()->json($result);
    }

    public function testTelegram(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'chat_id'            => ['required', 'string', 'max:100'],
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
        $result  = $service->sendMessage(
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
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role'     => 'required|in:admin,analyst',
        ]);

        User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
        ]);

        return back()->with('success', "User {$request->name} berhasil dibuat.");
    }

    public function updateUser(Request $request, User $user)
    {
        $this->adminOnly();
        $request->validate([
            'name'     => 'required|string|max:255',
            'role'     => 'required|in:admin,analyst',
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
        return back()->with('success', "User berhasil dihapus.");
    }

    /**
     * Manually trigger the Wazuh alert fetch via web button.
     */
    public function manualFetch()
    {
        $this->adminOnly();

        try {
            // Paginate today's alerts; the web action never scans the archive.
            set_time_limit(300);
            $exitCode = \Illuminate\Support\Facades\Artisan::call('siem:fetch-alerts', ['--today' => true]);
            $output   = \Illuminate\Support\Facades\Artisan::output();

            if ($exitCode === 0) {
                return redirect()->route('settings.index')
                    ->with('success', 'Status fetch hari ini: ' . trim($output));
            } else {
                return redirect()->route('settings.index')
                    ->with('error', '❌ Fetch selesai dengan error. Output: ' . trim($output));
            }
        } catch (\Throwable $e) {
            Log::error('[SIEM] Manual fetch could not be started', [
                'exception_class' => $e::class,
            ]);

            return redirect()->route('settings.index')
                ->with('error', 'Fetch tidak dapat dijalankan. Periksa koneksi OpenSearch lalu coba kembali.');
        }
    }

    // ─── Data Management ─────────────────────────────────────────────────────

    /**
     * Preview — how many records will be affected?
     */
    public function previewData(Request $request)
    {
        $this->adminOnly();

        $data     = $this->validateDataSelection($request);
        $period   = $this->resolveDataPeriod($data);
        $scope    = $data['scope'];
        $alertIds = $this->alertsForPeriod($period)->pluck('id');

        $counts = ['alerts' => $alertIds->count()];

        if (in_array($scope, ['triages', 'all'])) {
            $counts['triages'] = \App\Models\AlertTriage::whereIn('alert_id', $alertIds)->count();
        }
        if (in_array($scope, ['notif_logs', 'all'])) {
            $counts['notif_logs'] = \App\Models\NotificationLog::whereIn('alert_id', $alertIds)->count();
        }

        return response()->json([
            'counts' => $counts,
            'period' => [
                'from'  => $period['from']?->toIso8601String(),
                'to'    => $period['to']?->toIso8601String(),
                'label' => $period['label'],
            ],
        ]);
    }

    /**
     * Backup alert data — flexible period, format, scope.
     */
    public function backupData(Request $request)
    {
        $this->adminOnly();

        $data     = $this->validateDataSelection($request, true);
        $period   = $this->resolveDataPeriod($data);
        $label    = $period['label'];
        $format   = $data['format'];
        $scope    = $data['scope'];
        $alertIds = $this->alertsForPeriod($period)->pluck('id');

        if ($alertIds->isEmpty()) {
            return redirect()->route('settings.index')
                ->with('error', "Tidak ada data untuk di-backup ({$label}).");
        }

        $timestamp = now()->format('Ymd_His');
        $filename  = "siem_backup_{$scope}_{$timestamp}.{$format}";

        if ($format === 'json') {
            $data = $this->buildJsonPayload($alertIds, $scope, $label, $period, 'backup');
            return response()->streamDownload(
                fn() => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                $filename,
                ['Content-Type' => 'application/json']
            );
        }

        $sql = $this->buildSqlDump($alertIds, $scope, $label, $period);
        return response()->streamDownload(
            fn() => print($sql),
            $filename,
            ['Content-Type' => 'application/sql']
        );
    }

    /**
     * Delete alert data — flexible period + scope.
     */
    public function deleteData(Request $request)
    {
        $this->adminOnly();

        $data     = $this->validateDataSelection($request);
        $period   = $this->resolveDataPeriod($data);
        $label    = $period['label'];
        $scope    = $data['scope'];
        $alertIds = $this->alertsForPeriod($period)->pluck('id');

        if ($alertIds->isEmpty()) {
            return redirect()->route('settings.index')
                ->with('error', "Tidak ada data untuk dihapus ({$label}).");
        }

        $deleted = $this->deleteByScope($alertIds, $scope);

        Log::info("[SIEM] Data cleanup: scope={$scope}, deleted={$deleted['summary']}, oleh " . auth()->user()->name);

        return redirect()->route('settings.index')
            ->with('success', "🗑️ Berhasil menghapus data ({$label}): " . $deleted['summary']);
    }

    /**
     * Backup THEN delete in one action.
     */
    public function backupAndDelete(Request $request)
    {
        $this->adminOnly();

        $data     = $this->validateDataSelection($request, true);
        $period   = $this->resolveDataPeriod($data);
        $label    = $period['label'];
        $format   = $data['format'];
        $scope    = $data['scope'];
        $alertIds = $this->alertsForPeriod($period)->pluck('id');

        if ($alertIds->isEmpty()) {
            return redirect()->route('settings.index')
                ->with('error', "Tidak ada data ({$label}).");
        }

        $timestamp = now()->format('Ymd_His');
        $filename  = "siem_backup_delete_{$scope}_{$timestamp}.{$format}";

        if ($format === 'json') {
            $data    = $this->buildJsonPayload($alertIds, $scope, $label, $period, 'backup+delete');
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $content = $this->buildSqlDump($alertIds, $scope, $label, $period);
        }

        $deleted = $this->deleteByScope($alertIds, $scope);
        Log::info("[SIEM] Backup+Delete: scope={$scope}, {$deleted['summary']}, oleh " . auth()->user()->name);

        return response()->streamDownload(
            fn() => print($content),
            $filename,
            ['Content-Type' => $format === 'json' ? 'application/json' : 'application/sql']
        );
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function validateDataSelection(Request $request, bool $needsFormat = false): array
    {
        $rules = [
            'scope'       => ['required', Rule::in(['alerts', 'triages', 'notif_logs', 'all'])],
            'period_mode' => ['required', Rule::in(['relative', 'range'])],
            'amount'      => ['nullable', 'required_if:period_mode,relative', 'integer', 'min:1', 'max:9999'],
            'unit'        => ['nullable', 'required_if:period_mode,relative', Rule::in(['days', 'weeks', 'months', 'years'])],
            'date_from'   => ['nullable', 'required_if:period_mode,range', 'date', 'before_or_equal:date_to'],
            'date_to'     => ['nullable', 'required_if:period_mode,range', 'date', 'after_or_equal:date_from'],
        ];

        if ($needsFormat) {
            $rules['format'] = ['required', Rule::in(['json', 'sql'])];
        }

        return $request->validate($rules);
    }

    private function resolveDataPeriod(array $data): array
    {
        if ($data['period_mode'] === 'range') {
            $from = \Carbon\Carbon::parse($data['date_from'])->startOfDay();
            $to   = \Carbon\Carbon::parse($data['date_to'])->endOfDay();

            return [
                'from'  => $from,
                'to'    => $to,
                'label' => $data['date_from'] . ' s/d ' . $data['date_to'],
            ];
        }

        $units  = ['days' => 'hari', 'weeks' => 'minggu', 'months' => 'bulan', 'years' => 'tahun'];
        $amount = (int) $data['amount'];
        $cutoff = now()->sub($data['unit'], $amount);

        return [
            'from'  => null,
            'to'    => $cutoff,
            'label' => 'lebih lama dari ' . $amount . ' ' . $units[$data['unit']],
        ];
    }

    private function alertsForPeriod(array $period)
    {
        return \App\Models\Alert::query()
            ->when($period['from'], fn ($query, $from) => $query->where('first_seen_at', '>=', $from))
            ->when($period['to'], fn ($query, $to) => $query->where('first_seen_at', '<=', $to));
    }

    private function buildJsonPayload($alertIds, string $scope, string $label, array $period, string $action): array
    {
        $alerts = \App\Models\Alert::whereIn('id', $alertIds)
            ->with(['triages.user', 'notificationLogs.user', 'aiAnalyses.requestedBy'])
            ->orderBy('first_seen_at')
            ->get();

        return [
            'backup_info' => [
                'action'        => $action,
                'generated_at'  => now()->toIso8601String(),
                'generated_by'  => auth()->user()->name,
                'scope'         => $scope,
                'period_label'  => $label,
                'period_start'  => $period['from']?->toIso8601String(),
                'period_end'    => $period['to']?->toIso8601String(),
                'total_alerts'  => $alertIds->count(),
                'total_ai_analyses' => \App\Models\AlertAiAnalysis::whereIn('alert_id', $alertIds)->count(),
            ],
            'alerts' => $alerts->map(fn($a) => [
                'id'               => $a->id,
                'wazuh_alert_id'   => $a->wazuh_alert_id,
                'rule_description' => $a->rule_description,
                'src_ip'           => $a->src_ip,
                'dst_ip'           => $a->dst_ip,
                'agent_name'       => $a->agent_name,
                'rule_id'          => $a->rule_id,
                'rule_level'       => $a->rule_level,
                'status'           => $a->status,
                'first_seen_at'    => $a->first_seen_at?->toIso8601String(),
                'raw_data'         => $a->raw_data,
                'ai_analyses' => in_array($scope, ['alerts', 'all'])
                    ? $a->aiAnalyses->map(fn($analysis) => [
                        'verdict' => $analysis->verdict,
                        'confidence' => $analysis->confidence,
                        'summary' => $analysis->summary,
                        'supporting_indicators' => $analysis->supporting_indicators,
                        'legitimate_indicators' => $analysis->legitimate_indicators,
                        'recommended_checks' => $analysis->recommended_checks,
                        'limitations' => $analysis->limitations,
                        'model' => $analysis->model,
                        'duration_ms' => $analysis->duration_ms,
                        'generated_at' => $analysis->generated_at?->toIso8601String(),
                        'requested_by' => $analysis->requestedBy?->name,
                    ])->toArray()
                    : [],
                'triages' => in_array($scope, ['triages', 'all'])
                    ? $a->triages->map(fn($t) => [
                        'action'     => $t->action,
                        'reason'     => $t->reason,
                        'user'       => $t->user?->name,
                        'created_at' => $t->created_at?->toIso8601String(),
                    ])->toArray()
                    : [],
                'notifications' => in_array($scope, ['notif_logs', 'all'])
                    ? $a->notificationLogs->map(fn($n) => [
                        'chat_id'         => $n->chat_id,
                        'message'         => $n->message,
                        'response_status' => $n->response_status,
                        'sent_at'         => $n->sent_at?->toIso8601String(),
                        'user'            => $n->user?->name,
                    ])->toArray()
                    : [],
            ])->toArray(),
        ];
    }

    private function buildSqlDump($alertIds, string $scope, string $label, array $period): string
    {
        $lines   = [];
        $lines[] = "-- ================================================================";
        $lines[] = "-- SIEM Salabim SQL Backup";
        $lines[] = "-- Generated : " . now()->toIso8601String();
        // JSON keeps newlines in a user-controlled name inside this comment.
        $lines[] = "-- By        : " . json_encode(auth()->user()->name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lines[] = "-- Scope     : {$scope}";
        $lines[] = "-- Period    : {$label}";
        $lines[] = "-- Start     : " . ($period['from']?->toIso8601String() ?? '-');
        $lines[] = "-- End       : " . ($period['to']?->toIso8601String() ?? '-');
        $lines[] = "-- ================================================================";
        $lines[] = "";
        $lines[] = "SET NAMES utf8mb4;";
        $lines[] = "SET FOREIGN_KEY_CHECKS=0;";
        $lines[] = "";

        $alerts = \App\Models\Alert::whereIn('id', $alertIds)
            ->with(['triages', 'notificationLogs', 'aiAnalyses'])
            ->orderBy('first_seen_at')
            ->get();

        $lines[] = "-- Alerts (" . $alerts->count() . " rows)";
        foreach ($alerts as $a) {
            $lines[] = $this->sqlInsert('alerts', $a->getAttributes(), [
                'id', 'wazuh_alert_id', 'rule_description', 'src_ip', 'dst_ip',
                'agent_name', 'rule_id', 'rule_level', 'status', 'first_seen_at',
                'raw_data', 'created_at', 'updated_at',
            ]);
        }
        $lines[] = "";

        if (in_array($scope, ['alerts', 'all'])) {
            $analyses = \App\Models\AlertAiAnalysis::whereIn('alert_id', $alertIds)->get();
            $lines[] = "-- Alert AI Analyses (" . $analyses->count() . " rows)";
            foreach ($analyses as $analysis) {
                $lines[] = $this->sqlInsert('alert_ai_analyses', $analysis->getAttributes(), [
                    'id', 'alert_id', 'requested_by_user_id', 'verdict', 'confidence',
                    'summary', 'supporting_indicators', 'legitimate_indicators',
                    'recommended_checks', 'limitations', 'model', 'duration_ms',
                    'generated_at', 'created_at', 'updated_at',
                ]);
            }
            $lines[] = "";
        }

        if (in_array($scope, ['triages', 'all'])) {
            $triages = \App\Models\AlertTriage::whereIn('alert_id', $alertIds)->get();
            $lines[] = "-- Alert Triages (" . $triages->count() . " rows)";
            foreach ($triages as $t) {
                $lines[] = $this->sqlInsert('alert_triages', $t->getAttributes(), [
                    'id', 'alert_id', 'user_id', 'action', 'reason', 'created_at',
                ]);
            }
            $lines[] = "";
        }

        if (in_array($scope, ['notif_logs', 'all'])) {
            $logs    = \App\Models\NotificationLog::whereIn('alert_id', $alertIds)->get();
            $lines[] = "-- Notification Logs (" . $logs->count() . " rows)";
            foreach ($logs as $n) {
                $lines[] = $this->sqlInsert('notification_logs', $n->getAttributes(), [
                    'id', 'alert_id', 'user_id', 'chat_id', 'message', 'response_status', 'sent_at',
                ]);
            }
            $lines[] = "";
        }

        $lines[] = "SET FOREIGN_KEY_CHECKS=1;";
        $lines[] = "-- End of backup";

        return implode("\n", $lines);
    }

    private function sqlInsert(string $table, array $attributes, array $columns): string
    {
        // Table and column names are fixed by the caller, never imported values.
        $names = '`' . implode('`,`', $columns) . '`';
        $values = array_map(fn (string $column): string => $this->sqlLiteral($attributes[$column] ?? null), $columns);

        return "INSERT IGNORE INTO `{$table}` ({$names}) VALUES (" . implode(',', $values) . ');';
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // MySQL text hex literals preserve quotes, backslashes, newlines and
        // NUL independently of NO_BACKSLASH_ESCAPES. The UTF-8 introducer also
        // keeps JSON input textual instead of a binary string.
        return "_utf8mb4 X'" . bin2hex((string) $value) . "'";
    }

    private function deleteByScope($alertIds, string $scope): array
    {
        $summary = [];

        if (in_array($scope, ['triages', 'all'])) {
            $cnt       = \App\Models\AlertTriage::whereIn('alert_id', $alertIds)->delete();
            $summary[] = "{$cnt} triage";
        }

        if (in_array($scope, ['notif_logs', 'all'])) {
            $cnt       = \App\Models\NotificationLog::whereIn('alert_id', $alertIds)->delete();
            $summary[] = "{$cnt} log notifikasi";
        }

        if (in_array($scope, ['alerts', 'all'])) {
            $aiAnalysisCount = \App\Models\AlertAiAnalysis::whereIn('alert_id', $alertIds)->count();

            if ($scope !== 'all') {
                // Clean dependencies first if not already done
                \App\Models\AlertTriage::whereIn('alert_id', $alertIds)->delete();
                \App\Models\NotificationLog::whereIn('alert_id', $alertIds)->delete();
            }
            $cnt       = \App\Models\Alert::whereIn('id', $alertIds)->delete();
            $summary[] = "{$cnt} alert";
            $summary[] = "{$aiAnalysisCount} analisis AI terkait";
        }

        return ['summary' => implode(', ', $summary)];
    }
}
