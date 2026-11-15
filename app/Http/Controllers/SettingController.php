<?php

namespace App\Http\Controllers;

use App\Exceptions\AiAnalysisException;
use App\Jobs\GenerateDataExport;
use App\Jobs\PrepareAutomaticAlertNotifications;
use App\Jobs\PurgeDataExport;
use App\Models\Alert;
use App\Models\AlertAiAnalysis;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\PermanentAlertIgnore;
use App\Models\User;
use App\Services\AiInsightService;
use App\Services\AiProviderService;
use App\Services\AlertIngestionCoordinator;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\AutomaticAlertNotifier;
use App\Services\DataExportArchiveService;
use App\Services\OpenSearchService;
use App\Services\QueueExecutionPolicy;
use App\Services\TelegramService;
use App\Services\UserManagementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

    public function index(Request $request)
    {
        $this->adminOnly();
        $settingsSections = [
            'opensearch' => ['title' => 'Konfigurasi OpenSearch & Alert', 'description' => 'Koneksi Wazuh Indexer, kredensial, pola indeks, ambang level, dan fetch alert manual.', 'icon' => 'database'],
            'permanent-ignore' => ['title' => 'Ignore permanen', 'description' => 'Hentikan impor alert baru berdasarkan Rule ID.', 'icon' => 'filter'],
            'ai' => ['title' => 'Konfigurasi AI', 'description' => 'Provider, endpoint, model, serta jadwal analisis AI.', 'icon' => 'sparkles'],
            'telegram' => ['title' => 'Konfigurasi Telegram', 'description' => 'Token bot, manajemen tujuan, dan aturan pengiriman berdasarkan agent.', 'icon' => 'send'],
            'templates' => ['title' => 'Template notifikasi', 'description' => 'Tambah, edit, nonaktifkan, dan hapus template Telegram untuk notifikasi tunggal maupun massal.', 'icon' => 'clipboard-list'],
            'users' => ['title' => 'Manajemen user', 'description' => 'Akun, role, dan akses anggota tim SOC.', 'icon' => 'users'],
            'data' => ['title' => 'Manajemen data', 'description' => 'Ekspor ZIP, riwayat backup, dan retensi data.', 'icon' => 'archive'],
        ];
        $activeSettingsSection = old('_section', session('settings_section', $request->query('section')));
        if ($activeSettingsSection === 'alerts') {
            $activeSettingsSection = 'opensearch';
        }
        abort_unless($activeSettingsSection === null || (is_string($activeSettingsSection) && isset($settingsSections[$activeSettingsSection])), 404);
        $configKeys = [
            'opensearch_host',
            'opensearch_username',
            'opensearch_index',
            'opensearch_timezone',
            'opensearch_verify_ssl',
            'min_alert_level',
            'ai_provider',
            'ai_api_format',
            'ai_base_url',
            'ai_model',
            'ai_snapshot_limit',
            'ai_refresh_days',
        ];
        $defaults = [
            'opensearch_host' => config('services.opensearch.host'),
            'opensearch_username' => config('services.opensearch.username'),
            'opensearch_index' => config('services.opensearch.index'),
            'opensearch_timezone' => config('services.opensearch.timezone', 'UTC'),
            'opensearch_verify_ssl' => config('services.opensearch.verify_ssl', true) ? 'true' : 'false',
            'min_alert_level' => config('services.opensearch.min_level'),
            'ai_base_url' => config('services.ai.base_url'),
            'ai_model' => config('services.ai.model'),
            'ai_snapshot_limit' => config('services.ai.snapshot_limit'),
            'ai_refresh_days' => config('services.ai.refresh_days'),
        ];
        $configs = collect($configKeys)->mapWithKeys(
            fn (string $key) => [$key => Configuration::get($key, $defaults[$key] ?? null)]
        );
        $aiClient = app(AiProviderService::class);
        $aiSettings = $aiClient->settings();
        $aiKeyAvailable = filled($aiSettings['api_key']);
        $configs->put('ai_provider', $aiSettings['provider']);
        $configs->put('ai_api_format', $aiSettings['api_format']);
        $aiProviders = config('ai_providers');
        $telegramDestinations = Configuration::getTelegramDestinations();
        $automaticNotifications = app(AutomaticAlertNotifier::class)->settings();
        $users = User::orderBy('name')->get();
        $notificationTemplates = NotificationTemplate::orderBy('category')->orderBy('name')->get();
        $permanentIgnores = PermanentAlertIgnore::whereNotNull('rule_id')->orderByDesc('id')->paginate(15, ['*'], 'ignored_page')->appends(['section' => 'permanent-ignore']);
        $legacyPermanentIgnores = PermanentAlertIgnore::whereNull('rule_id')->orderByDesc('id')->paginate(15, ['*'], 'legacy_ignored_page')->appends(['section' => 'permanent-ignore']);
        $dataExportsReady = Schema::hasTable('data_exports') && Schema::hasColumn('data_exports', 'archive_password');
        $dataExports = $dataExportsReady ? DataExport::query()
            ->with(['requestedBy', 'purgeRequestedBy'])
            ->latest()
            ->limit(20)
            ->get() : collect();
        $aiConfigured = app(AiInsightService::class)->isConfigured();

        return response()->view('settings.index', compact(
            'configs',
            'users',
            'notificationTemplates',
            'permanentIgnores',
            'legacyPermanentIgnores',
            'dataExports',
            'dataExportsReady',
            'aiConfigured',
            'aiProviders',
            'aiKeyAvailable',
            'telegramDestinations',
            'automaticNotifications',
            'settingsSections',
            'activeSettingsSection',
        ))->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function updateOpenSearch(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'opensearch_host' => ['required', 'url', 'max:2048'],
            'opensearch_username' => ['required', 'string', 'max:255'],
            'opensearch_password' => ['nullable', 'string', 'max:4096'],
            'opensearch_index' => ['required', 'string', 'max:255'],
            'opensearch_timezone' => ['sometimes', 'required', 'timezone'],
            'opensearch_verify_ssl' => ['sometimes', 'required', 'boolean'],
            'min_alert_level' => ['sometimes', 'required', 'integer', Rule::in([3, 5, 7, 10, 12, 13, 14, 15])],
        ]);

        Configuration::set('opensearch_host', trim($data['opensearch_host']));
        Configuration::set('opensearch_username', trim($data['opensearch_username']));
        Configuration::set('opensearch_index', trim($data['opensearch_index']));
        if (array_key_exists('opensearch_verify_ssl', $data)) {
            Configuration::set('opensearch_verify_ssl', $request->boolean('opensearch_verify_ssl') ? 'true' : 'false');
        }
        if (isset($data['opensearch_timezone'])) {
            Configuration::set('opensearch_timezone', $data['opensearch_timezone']);
        }
        if (filled($data['opensearch_password'] ?? null)) {
            Configuration::set('opensearch_password', $data['opensearch_password']);
        }
        if (array_key_exists('min_alert_level', $data)) {
            Configuration::set('min_alert_level', (int) $data['min_alert_level']);
            Cache::forget('security-insight-snapshot:v2:'.now('Asia/Jakarta')->format('Y-m'));
        }

        return back()->with('settings_section', 'opensearch')->with('success', 'Konfigurasi OpenSearch & Alert berhasil disimpan.');
    }

    public function telegramAgents()
    {
        $this->adminOnly();
        try {
            $inventory = app(OpenSearchService::class)->fetchAgents();
            $cluster = (string) config('services.opensearch.source_cluster', 'wazuh-primary');
            $inventory['agents'] = array_map(fn ($agent) => $agent + [
                'source_cluster' => $cluster, 'key' => Alert::agentScopeKey($cluster, $agent['id']),
            ], $inventory['agents']);

            return response()->json(['success' => true, ...$inventory])
                ->withHeaders(['Cache-Control' => 'no-store, private']);
        } catch (\Throwable $e) {
            Log::warning('[OpenSearch] agent inventory failed', ['exception_class' => $e::class]);

            return response()->json([
                'success' => false,
                'error' => $e instanceof \RuntimeException ? $e->getMessage() : 'Daftar agent belum dapat dimuat dari OpenSearch. Coba muat ulang.',
            ], 502)->withHeaders(['Cache-Control' => 'no-store, private']);
        }
    }

    public function userAgents()
    {
        $this->adminOnly();
        try {
            return response()->json(['success' => true, 'agents' => $this->userAgentInventory()])
                ->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'error' => 'Daftar agent belum dapat dimuat dari monitoring Wazuh. Coba muat ulang.'], 502)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    private function userAgentInventory(): array
    {
        $cluster = trim((string) config('services.opensearch.source_cluster', 'wazuh-primary'));

        return collect(app(OpenSearchService::class)->fetchAgents()['agents'])->map(fn (array $agent) => [
            'key' => Alert::agentScopeKey($cluster, $agent['id']), 'id' => $agent['id'],
            'name' => $agent['name'], 'source_cluster' => $cluster,
        ])->all();
    }

    private function userAccessData(Request $request, ?User $current = null): array
    {
        $data = $request->validate([
            'agent_keys' => ['nullable', 'array', 'max:1000'],
            'agent_keys.*' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/', 'distinct'],
            'privileges' => ['nullable', 'array', 'max:5'],
            'privileges.*' => ['required', 'string', Rule::in(array_keys(User::PRIVILEGES)), 'distinct'],
        ]);
        if ($request->role === 'admin') {
            return ['allowed_agents' => [], 'privileges' => []];
        }
        if ($current?->isAnalyst() && ! $request->hasAny(['_access_settings', 'agent_keys', 'privileges'])) {
            return ['allowed_agents' => $current->allowed_agents ?? [], 'privileges' => $current->privileges ?? []];
        }
        $keys = $data['agent_keys'] ?? [];
        $available = collect($current?->allowed_agents ?? [])->keyBy('key');
        if (array_diff($keys, $available->keys()->all()) !== []) {
            try {
                $available = $available->merge(collect($this->userAgentInventory())->keyBy('key'));
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['agent_keys' => 'Agent baru belum dapat diverifikasi. Muat ulang daftar agent lalu coba simpan kembali.']);
            }
        }
        foreach ($keys as $key) {
            if (! $available->has($key)) {
                throw ValidationException::withMessages(['agent_keys' => 'Pilihan agent tidak tersedia di monitoring Wazuh. Muat ulang dan pilih kembali.']);
            }
        }

        return [
            'allowed_agents' => $available->only($keys)->values()->all(),
            'privileges' => array_values(array_diff($data['privileges'] ?? [], ['alerts.view'])),
        ];
    }

    public function updateTelegram(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'telegram_bot_token' => 'nullable|string|max:4096',
            'telegram_chat_ids' => 'nullable|array',
            'telegram_chat_ids.*' => 'nullable|string|max:100',
            'update_telegram_destinations' => ['sometimes', 'boolean'],
            'telegram_destinations' => ['sometimes', 'array', 'max:100'],
            'telegram_destinations.*' => ['array:label,chat_id,agent_keys'],
            'telegram_destinations.*.label' => ['required', 'string', 'max:100'],
            'telegram_destinations.*.chat_id' => ['required', 'string', 'max:100', 'regex:/^-?[1-9][0-9]*$/', 'distinct:strict'],
            'telegram_destinations.*.agent_keys' => ['required', 'array', 'min:1', 'max:1000'],
            'telegram_destinations.*.agent_keys.*' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ], [
            'telegram_destinations.*.agent_keys.required' => 'Pilih minimal satu Agent ID untuk setiap tujuan Telegram.',
            'telegram_destinations.*.agent_keys.min' => 'Pilih minimal satu Agent ID untuk setiap tujuan Telegram.',
            'telegram_destinations.*.chat_id.distinct' => 'Chat ID tidak boleh digunakan pada dua baris tujuan.',
            'telegram_destinations.*.chat_id.regex' => 'Chat ID harus berupa angka; ID grup boleh diawali tanda minus.',
        ]);

        $destinations = null;
        if ($request->boolean('update_telegram_destinations') || array_key_exists('telegram_destinations', $data)) {
            $saved = collect(Configuration::getTelegramDestinations());
            $knownKeys = $saved->flatMap(fn ($destination) => $destination['agent_keys'] ?? [])->unique()->all();
            $keys = collect($data['telegram_destinations'] ?? [])->flatMap(fn ($destination) => $destination['agent_keys'])->unique()->all();
            $inventory = $saved->flatMap(fn ($destination) => $destination['agent_details'] ?? [])->keyBy('key');
            if (array_diff($keys, $knownKeys) !== []) {
                try {
                    $inventory = $inventory->merge(collect($this->userAgentInventory())->keyBy('key'));
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['telegram_destinations' => 'Agent ID baru belum dapat diverifikasi. Muat ulang monitoring Wazuh terlebih dahulu.']);
                }
                if (array_diff($keys, $inventory->keys()->all()) !== []) {
                    throw ValidationException::withMessages(['telegram_destinations' => 'Pilihan Agent ID tidak tersedia pada monitoring Wazuh.']);
                }
            }
            $destinations = array_map(static fn (array $destination): array => [
                'label' => trim($destination['label']), 'chat_id' => trim($destination['chat_id']),
                'agent_keys' => array_values(array_unique($destination['agent_keys'])),
                'agent_details' => $inventory->only($destination['agent_keys'])->values()->all(),
            ], $data['telegram_destinations'] ?? []);
        }

        if (filled($data['telegram_bot_token'] ?? null)) {
            Configuration::set('telegram_bot_token', trim($data['telegram_bot_token']));
        } elseif (blank(Configuration::get('telegram_bot_token', config('services.telegram.bot_token', '')))) {
            throw ValidationException::withMessages([
                'telegram_bot_token' => 'Masukkan token bot Telegram sebelum menyimpan konfigurasi pertama.',
            ]);
        }

        if ($destinations !== null) {
            Configuration::set('telegram_destinations', json_encode(array_values($destinations), JSON_THROW_ON_ERROR));
        } elseif (array_key_exists('telegram_chat_ids', $data)) {
            Configuration::set('telegram_chat_ids', json_encode(
                array_values(array_unique(array_filter(array_map('trim', $data['telegram_chat_ids'] ?? []))))
            ));
        }

        return back()->with('settings_section', 'telegram')->with('success', 'Konfigurasi Telegram berhasil disimpan.');
    }

    public function updateAutomaticNotifications(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'telegram_auto_enabled' => ['required', 'boolean'],
            'telegram_auto_above_level' => ['required', 'integer', 'min:0', 'max:14'],
            'telegram_auto_daily_limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ]);
        $enabled = $request->boolean('telegram_auto_enabled');
        if ($enabled && (blank(Configuration::get('telegram_bot_token', config('services.telegram.bot_token')))
            || ! collect(Configuration::getTelegramDestinations())->contains(fn ($destination) => ! empty($destination['agent_keys'])))) {
            throw ValidationException::withMessages([
                'telegram_auto_enabled' => 'Simpan token bot dan minimal satu tujuan dengan pemetaan agent terlebih dahulu.',
            ]);
        }
        DB::transaction(function () use ($request, $data, $enabled): void {
            Configuration::set('telegram_auto_enabled', $enabled ? '1' : '0');
            Configuration::set('telegram_auto_above_level', (int) $data['telegram_auto_above_level']);
            if (array_key_exists('telegram_auto_daily_limit', $data)) {
                Configuration::set('telegram_auto_daily_limit', (int) $data['telegram_auto_daily_limit']);
            }
            // A worker has no browser request. Capture the authenticated admin's application URL automatically.
            Configuration::set('telegram_auto_base_url', rtrim($request->root(), '/'));
            if (! $enabled) {
                NotificationLog::whereNotNull('automatic_key')->where('delivery_state', 'pending')->update(['delivery_state' => 'cancelled']);
            }
        });

        if ($enabled) {
            PrepareAutomaticAlertNotifications::dispatch();
        }

        return back()->with('settings_section', 'telegram')->with('success', 'Konfigurasi notifikasi otomatis berhasil disimpan.'.($enabled ? ' Notifikasi hanya untuk alert baru yang diimpor sejak fitur aktif.' : ''));
    }

    private function prepareAiConfiguration(Request $request): string
    {
        $saved = app(AiProviderService::class)->settings();
        $provider = (string) $request->input('ai_provider', $saved['provider']);
        $request->merge([
            'ai_provider' => $provider,
            'ai_api_format' => $request->input('ai_api_format', $provider === $saved['provider']
                ? $saved['api_format'] : AiProviderService::defaultFormat($provider)),
        ]);

        return $provider;
    }

    public function updateAiInsight(Request $request)
    {
        $this->adminOnly();
        $provider = $this->prepareAiConfiguration($request);
        $data = $request->validate(AiProviderService::configurationRules($provider, false) + [
            'ai_snapshot_limit' => ['required', 'integer', 'min:1000', 'max:10000'],
            'ai_refresh_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
        $client = app(AiProviderService::class);
        $settings = $client->settings($data);
        if (! $client->isConfigured($settings)) {
            throw ValidationException::withMessages([
                'ai_api_key' => 'Masukkan API key untuk provider dan endpoint ini. Token tersimpan hanya dipakai untuk tujuan yang sama.',
            ]);
        }

        foreach (['provider', 'api_format', 'base_url', 'model', 'api_key'] as $key) {
            Configuration::set('ai_'.$key, $settings[$key]);
        }
        Configuration::set('ai_snapshot_limit', (int) $data['ai_snapshot_limit']);
        Configuration::set('ai_refresh_days', (int) $data['ai_refresh_days']);
        Cache::forget('security-insight-snapshot:v2:'.now('Asia/Jakarta')->format('Y-m'));

        return back()->with('settings_section', 'ai')->with('success', 'Konfigurasi Analisis AI berhasil disimpan.');
    }

    public function updateAlerts(Request $request)
    {
        $this->adminOnly();
        $data = $request->validate([
            'min_alert_level' => ['required', 'integer', Rule::in([3, 5, 7, 10, 12, 13, 14, 15])],
        ]);
        Configuration::set('min_alert_level', (int) $data['min_alert_level']);
        Cache::forget('security-insight-snapshot:v2:'.now('Asia/Jakarta')->format('Y-m'));

        return back()->with('settings_section', 'opensearch')->with('success', 'Konfigurasi Alert berhasil disimpan.');
    }

    public function testAiInsight(Request $request)
    {
        $this->adminOnly();
        $provider = $this->prepareAiConfiguration($request);
        $data = $request->validate(AiProviderService::configurationRules($provider, false));
        $client = app(AiProviderService::class);
        $settings = $client->settings($data);
        if (! $client->isConfigured($settings)) {
            return response()->json(['success' => false, 'error' => 'Masukkan API key untuk provider dan endpoint ini. LLM lokal boleh tanpa API key.'], 422);
        }

        try {
            $reply = $client->generate(
                'Jawab singkat untuk tes koneksi.', 'Jawab tepat satu kata: pong', 256, 30,
                reasoningEffort: config('services.ai.reasoning_effort'), settings: $settings,
            );

            return response()->json([
                'success' => true, 'provider' => $settings['provider'],
                'model' => $settings['model'], 'reply' => mb_substr($reply, 0, 200),
            ]);
        } catch (AiAnalysisException $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            Log::warning('[AI] Connection test failed', ['exception_class' => $exception::class]);

            return response()->json(['success' => false, 'error' => 'Tidak dapat terhubung ke endpoint AI. Periksa konfigurasi dan jaringan server.']);
        }
    }

    public function aiModels(Request $request)
    {
        $this->adminOnly();
        $provider = $this->prepareAiConfiguration($request);
        $data = $request->validate(AiProviderService::modelDiscoveryRules($provider));
        $client = app(AiProviderService::class);

        try {
            return response()->json(['success' => true, ...$client->models($client->settings($data))])->header('Cache-Control', 'no-store, private');
        } catch (AiAnalysisException $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            Log::warning('[AI] Model discovery failed', ['exception_class' => $exception::class]);

            return response()->json(['success' => false, 'error' => 'Daftar model belum dapat diambil. Model tetap dapat diisi manual.']);
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
            'opensearch_verify_ssl' => ['sometimes', 'required', 'boolean'],
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
            'test_type' => ['nullable', Rule::in(['bot', 'message'])],
            'chat_id' => ['required_unless:test_type,bot', 'nullable', 'string', 'max:100'],
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
        if (($data['test_type'] ?? 'message') === 'bot') {
            $result = $service->testConfiguration($botToken, []);
            if ($result['success']) {
                $result['message'] = 'Bot Telegram berhasil terhubung. Gunakan Kirim pesan tes untuk memeriksa akses ke Chat ID tujuan.';
            }
        } else {
            $result = $service->sendMessage(
                trim($data['chat_id']),
                '✅ Test koneksi dari SIEM Salabim berhasil!',
                $botToken,
            );
        }

        $httpStatus = $result['success'] ? 200 : match ((int) ($result['status'] ?? 0)) {
            400, 401, 403, 404 => 422,
            429 => 429,
            default => 503,
        };

        $response = response()->json($result, $httpStatus);
        if ($httpStatus === 429 && ($result['retry_after'] ?? 0) > 0) {
            $response->header('Retry-After', (string) $result['retry_after']);
        }

        return $response;
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
        ] + $this->userAccessData($request));

        return back()->with('settings_section', 'users')->with('success', "User {$request->name} berhasil dibuat.");
    }

    public function updateUser(Request $request, User $user)
    {
        $this->adminOnly();
        abort_if($user->isAdmin() && $request->user()->id !== $user->id, 403, 'Profil admin lain tidak dapat diubah.');
        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|in:admin,analyst',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $data = ['name' => $request->name, 'role' => $request->role] + $this->userAccessData($request, $user);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        app(UserManagementService::class)->update($request->user(), $user, $data);
        $user->refresh();

        return back()->with('settings_section', 'users')->with('success', "User {$user->name} berhasil diupdate.");
    }

    public function deleteUser(User $user)
    {
        $this->adminOnly();
        // Legacy DELETE links deactivate instead of erasing incident history.
        app(UserManagementService::class)->setActive(auth()->user(), $user, false);

        return back()->with('settings_section', 'users')->with('success', 'Akun dinonaktifkan. Riwayat penanganan tetap tersimpan.');
    }

    public function updateUserStatus(Request $request, User $user)
    {
        $this->adminOnly();
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        app(UserManagementService::class)->setActive($request->user(), $user, (bool) $data['is_active']);

        return back()->with('settings_section', 'users')->with('success', $data['is_active']
            ? 'Akun diaktifkan kembali. Pengguna perlu masuk ulang.'
            : 'Akun dinonaktifkan; sesi dicabut dan alert ACK dikembalikan ke NEW. Riwayat tetap tersimpan.');
    }

    /**
     * Manually trigger the Wazuh alert fetch via web button.
     */
    public function manualFetch(AlertIngestionCoordinator $ingestion)
    {
        $this->adminOnly();
        session()->flash('settings_section', 'opensearch');

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
            $counts['notification_operations'] = DB::table('manual_notification_operations')->whereIn(
                'alert_id', (clone $alerts)->select('id'),
            )->count();
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
        Request $request,
        DataExport $dataExport,
        DataExportArchiveService $archives,
    ) {
        $this->adminOnly();

        abort_unless($request->integer('user') === (int) $request->user()->id, 403);
        abort_unless($dataExport->isVerified(), 409, 'Ekspor belum siap diunduh.');
        try {
            $archives->verifyArchive($dataExport);
            if (filled($dataExport->archive_password)) {
                $downloadPath = $archives->prepareStandardZipDownload($dataExport);
            }
        } catch (\Throwable $exception) {
            Log::warning('[Data Export] Download blocked by integrity verification', [
                'export_id' => $dataExport->id,
                'exception_class' => $exception::class,
            ]);

            abort(409, 'Integritas file ekspor tidak dapat diverifikasi. Download dibatalkan.');
        }

        $headers = [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (isset($downloadPath)) {
            return response()->download($downloadPath, $dataExport->filename, $headers)->deleteFileAfterSend(true);
        }

        return Storage::disk($dataExport->storage_disk)->download(
            $dataExport->storage_path,
            $dataExport->filename,
            $headers,
        );
    }

    public function purgeDataExport(
        DataExport $dataExport,
        QueueExecutionPolicy $queueExecutionPolicy,
    ) {
        $this->adminOnly();
        session()->flash('settings_section', 'data');

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
            'file_size' => $dataExport->file_size,
            'error_message' => $dataExport->error_message,
            'download_available' => $dataExport->isVerified(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function authorizeDataExportDownload(Request $request, DataExport $dataExport)
    {
        $this->adminOnly();
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
        ], [
            'current_password.current_password' => 'Password akun tidak benar.',
            'current_password.required' => 'Masukkan password akun Anda.',
        ]);
        abort_unless($dataExport->isVerified(), 409, 'Ekspor belum siap diunduh.');

        return response()->json([
            'download_url' => URL::temporarySignedRoute('settings.data.exports.download', now()->addMinutes(2), [
                'dataExport' => $dataExport->id, 'user' => $request->user()->id,
            ]),
        ])->header('Cache-Control', 'no-store, private');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function queueDataExport(
        Request $request,
        DataExportArchiveService $archives,
        QueueExecutionPolicy $queueExecutionPolicy,
        bool $requestedFromLegacyCombinedAction = false,
    ) {
        $request->session()->flash('settings_section', 'data');
        if (! Schema::hasTable('data_exports') || ! Schema::hasColumn('data_exports', 'archive_password')) {
            return redirect()->route('settings.index')->with(
                'error',
                'Backup belum tersedia. Jalankan migrasi database terlebih dahulu.',
            );
        }

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
            $archives->assertZipAvailable();
        } catch (\RuntimeException) {
            return back()->with('error', 'Ekspor memerlukan ekstensi PHP Zip.');
        }

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
                'siem_backup_%s_%s_%s.zip',
                $data['scope'],
                now()->format('Ymd_His'),
                substr($uuid, 0, 8),
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

        return redirect()->route('settings.index', ['section' => 'data'])->with('success', $message);
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
