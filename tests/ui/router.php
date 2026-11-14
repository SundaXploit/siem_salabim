<?php

use App\Models\Alert;
use App\Models\ActivityLog;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Models\PermanentAlertIgnore;
use App\Models\User;
use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationState;
use App\Services\OpenSearchService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Isolated visual QA only. Never routed by the application or included in releases.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root.'/public'.$path)) {
    return false;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.ui-preview-does-not-exist');
$request = Request::capture();
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->make(Vite::class)->useHotFile(storage_path('framework/testing/ui-preview-no-hot'));
config([
    'app.env' => 'testing',
    'app.debug' => true,
    'app.key' => 'base64:'.base64_encode(str_repeat('q', 32)),
    'app.url' => 'http://127.0.0.1:8787',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'cache.default' => 'array',
    'session.driver' => 'array',
    'session.secure' => false,
    'queue.default' => 'database',
    'mail.default' => 'array',
    'hashing.bcrypt.rounds' => 4,
    'installer.installed' => $path !== '/install',
    'installer.marker_path' => storage_path('framework/testing/ui-preview-no-marker'),
    'installer.detect_legacy_admin' => false,
    'services.ai.api_key' => null,
    'services.opensearch.password' => '',
]);
if (str_starts_with($path, '/__qa/error/')) {
    config(['app.debug' => false]);
    $app['router']->get('/__qa/error/{status}', function (int $status) {
        abort(in_array($status, [403, 404, 419, 429, 500, 503], true) ? $status : 404);
    });
}
$previewEnvironment = new EnvironmentFileWriter(storage_path('framework/testing/ui-preview-empty'));
$app->instance(EnvironmentFileWriter::class, $previewEnvironment);
$app->instance(InstallationState::class, new InstallationState(
    $previewEnvironment,
    markerPath: storage_path('framework/testing/ui-preview-no-marker'),
    detectLegacyAdmin: false,
));
Carbon::setTestNow('2026-11-11 10:24:00');
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
Queue::fake();
Http::preventStrayRequests();
$app->instance(OpenSearchService::class, new class extends OpenSearchService
{
    public function __construct() {}

    public function fetchAgents(): array
    {
        return [
            'agents' => [
                ['id' => '001', 'name' => 'web-prod-02', 'status' => 'active'],
                ['id' => '002', 'name' => 'staging', 'status' => 'active'],
                ['id' => '003', 'name' => 'database-01', 'status' => 'disconnected'],
                ['id' => '004', 'name' => 'new-without-alerts', 'status' => 'never_connected'],
            ],
            'total' => 4, 'updated_at' => '2026-11-12T03:30:00Z',
        ];
    }
});

$admin = User::factory()->create(['name' => 'Nadia Putri', 'email' => 'admin@example.test', 'role' => 'admin']);
$analyst = User::factory()->forAgent('001')->create(['name' => 'Raka Pratama', 'email' => 'analyst@example.test']);
PermanentAlertIgnore::create(['rule_id' => '10001', 'created_by_user_id' => $admin->id, 'created_by_name' => $admin->name]);
foreach (['login', 'logout', 'opensearch_updated', 'telegram_updated', 'ai_updated', 'password_changed', 'user_created', 'user_updated', 'user_deleted', 'opensearch_tested'] as $index => $action) {
    $activityActor = in_array($action, ['login', 'logout', 'password_changed'], true) ? $analyst : $admin;
    ActivityLog::create([
        'actor_id' => $activityActor->id,
        'actor_name' => $activityActor->name,
        'actor_role' => $activityActor->role,
        'action' => $action, 'result' => $action === 'opensearch_tested' ? 'failed' : 'success',
        'subject' => str_starts_with($action, 'user_') ? 'User #8 · Tim SOC' : null,
        'ip_address' => '192.0.2.'.($index + 1),
        'context' => ['fields' => $action === 'opensearch_updated' ? ['opensearch_host', 'opensearch_password'] : []],
        'created_at' => now()->subMinutes($index * 7),
    ]);
}
Configuration::set('telegram_destinations', json_encode([
    ['label' => 'Developer', 'chat_id' => '-100111', 'agents' => ['web-prod-02', 'staging']],
    ['label' => 'Developer personal', 'chat_id' => '123456', 'agents' => ['web-prod-02', 'staging']],
    ['label' => 'Organisasi B', 'chat_id' => '-100222', 'agents' => ['database-01']],
]));
if ($path === '/verify-email') {
    $admin->forceFill(['email_verified_at' => null])->save();
}
if ($path === '/settings' && ($_GET['section'] ?? '') === 'data') {
    $export = DataExport::create([
        'uuid' => '00000000-0000-4000-8000-000000000001',
        'requested_by_user_id' => $admin->id,
        'requested_by_name' => $admin->name,
        'scope' => 'alerts', 'format' => 'json',
        'status' => isset($_GET['ready']) ? 'verified' : 'queued',
        'period_label' => 'Contoh ekspor untuk pengujian UI',
        'storage_disk' => 'local', 'filename' => 'contoh-ekspor.zip',
    ]);
    if (isset($_GET['ready'])) {
        $export->forceFill(['verified_at' => now(), 'checksum' => str_repeat('a', 64), 'file_size' => 2048])->save();
    }
}
if (! isset($_GET['empty'])) {
    for ($i = 1; $i <= 42; $i++) {
        $level = [15, 12, 10, 7, 5][$i % 5];
        $alert = Alert::create([
            'wazuh_alert_id' => 'preview-'.$i,
            'rule_id' => (string) (5710 + $i % 4),
            'rule_description' => ['SSH authentication failed', 'Suspicious process execution', 'Web request blocked', 'File integrity change'][$i % 4],
            'rule_level' => $level,
            'agent_name' => ['gateway-01', 'web-prod-02', 'database-01', 'workstation-03'][$i % 4],
            'src_ip' => '192.0.2.'.($i % 15 + 1),
            'dst_ip' => '198.51.100.'.($i % 4 + 1),
            'status' => $i % 7 === 0 ? 'ignored' : ($i % 3 === 0 ? 'acknowledged' : 'new'),
            'first_seen_at' => now()->subHours($i * 5),
            'raw_data' => ['agent' => ['id' => ['005', '001', '003', '006'][$i % 4]], 'rule' => ['mitre' => ['id' => ['T1110'], 'technique' => ['Brute Force']]], 'event' => ['action' => 'blocked'], 'location' => '/var/log/auth.log'],
        ]);
        if ($i % 3 === 0) {
            AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $i % 2 ? $admin->id : $analyst->id, 'action' => 'acknowledge', 'created_at' => now()->subHours($i)]);
        }
        if ($i % 5 === 0) {
            NotificationLog::create(['alert_id' => $alert->id, 'user_id' => $admin->id, 'chat_id' => '-100000000', 'message' => 'QA fixture: investigasi alert keamanan.', 'response_status' => $i === 10 ? 500 : 200, 'sent_at' => now()->subHours($i)]);
        }
        if ($i % 7 === 0) {
            AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $analyst->id, 'action' => 'ignore', 'reason' => 'Pemeliharaan terjadwal sudah divalidasi.', 'created_at' => now()->subHours($i)]);
        }
    }
}
if (isset($_GET['automatic'])) {
    foreach (['pending' => null, 'sent' => 200, 'unconfirmed' => 0, 'failed' => 403, 'daily_limited' => null] as $state => $status) {
        NotificationLog::create([
            'alert_id' => 1, 'user_id' => null, 'chat_id' => '-100111',
            'message' => 'Notifikasi otomatis untuk analisis SOC.',
            'automatic_key' => hash('sha256', 'qa-automatic-'.$state),
            'delivery_state' => $state, 'response_status' => $status, 'sent_at' => now(),
        ]);
    }
}
if (! in_array($path, ['/login', '/install', '/install/complete'], true)) {
    Auth::setUser(($_GET['_role'] ?? '') === 'analyst' ? $analyst : $admin);
}
if ($path === '/settings' && isset($_GET['_validation_user'])) {
    // Inject a validation redirect after StartSession for this isolated preview.
    class UserValidationPreview
    {
        public function handle(Request $request, \Closure $next)
        {
            $request->session()->flashInput([
                '_section' => 'users', '_user_form' => '1', '_editing_user' => 2,
                'name' => 'Draft analyst', 'email' => 'analyst@example.test', 'role' => 'analyst',
                'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001'), Alert::agentScopeKey('wazuh-primary', '004')],
                'privileges' => ['alerts.ignore'],
            ]);
            $errors = (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag([
                'password' => ['Konfirmasi password tidak cocok.'],
            ]));
            $request->session()->flash('errors', $errors);
            view()->share('errors', $errors);

            return $next($request);
        }
    }
    $app['router']->pushMiddlewareToGroup('web', UserValidationPreview::class);
}
if ($path === '/install' && isset($_GET['wizard'])) {
    $app['session']->driver()->put(['installer.unlocked' => true, 'installer.unlocked_at' => now()->timestamp]);
}
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
