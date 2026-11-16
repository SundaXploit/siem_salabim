<?php

namespace Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestDatabase;

class ConcurrentManualNotificationTest extends TestCase
{
    public static function deliveryModes(): array
    {
        return ['inline requests' => ['inline'], 'queued deliveries' => ['queued'], 'bulk summary workers' => ['bulk']];
    }

    #[DataProvider('deliveryModes')]
    public function test_simultaneous_retries_send_each_message_and_photo_once(string $mode): void
    {
        $directory = sys_get_temp_dir().'/siem-notify-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/race.sqlite';
        $driver = getenv('SIEM_NOTIFY_TEST_DRIVER') ?: 'sqlite';
        $schema = null;
        $connectionEnvironment = [];
        if ($driver === 'pgsql') {
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            $configuration = config('database.connections.pgsql');
            PostgresTestDatabase::assertSafe($configuration);
            $database = $configuration['database'];
            $pdo = $app['db']->connection('pgsql')->getPdo();
            restore_error_handler();
            restore_exception_handler();
            $schema = 'siem_notify_test_'.bin2hex(random_bytes(6));
            $pdo->exec('CREATE SCHEMA "'.$schema.'"');
            $pdo->exec('SET search_path TO "'.$schema.'"');
            $connectionEnvironment = ['DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                'DB_SSLMODE' => $configuration['sslmode'], 'DB_SSLROOTCERT' => $configuration['sslrootcert'] ?? ''];
        } else {
            $this->assertSame('sqlite', $driver);
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA busy_timeout=5000');
        }
        $primary = $driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY';
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, is_active INTEGER, allowed_agents TEXT, privileges TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1,'Owner','admin',1,'[]','[]')");
        $pdo->exec('CREATE TABLE alerts (id INTEGER PRIMARY KEY, status TEXT, acknowledged_by_user_id INTEGER, agent_scope_key TEXT)');
        $key = hash('sha256', 'agent-fixture');
        $pdo->prepare("INSERT INTO alerts VALUES (1,'acknowledged',1,?)")->execute([$key]);
        $pdo->exec('CREATE TABLE configurations (id '.$primary.', key TEXT, value TEXT)');
        $pdo->prepare("INSERT INTO configurations (key,value) VALUES ('telegram_destinations',?)")->execute([json_encode([
            ['label' => 'SOC', 'chat_id' => '-1001', 'agent_keys' => [$key]],
            ['label' => 'Tim insiden', 'chat_id' => '-1002', 'agent_keys' => [$key]],
        ])]);
        $pdo->exec('CREATE TABLE manual_notification_operations (id TEXT PRIMARY KEY, alert_id INTEGER, user_id INTEGER, payload_hash TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE notification_logs (id '.$primary.', alert_id INTEGER, user_id INTEGER, chat_id TEXT, message TEXT, response_status INTEGER, sent_at TEXT, manual_operation_id TEXT, automatic_key TEXT, delivery_state TEXT, delivery_details TEXT, UNIQUE(manual_operation_id,chat_id))');
        $pdo->exec('CREATE TABLE alert_triages (id '.$primary.', alert_id INTEGER, user_id INTEGER, action TEXT, reason TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE telegram_calls (id '.$primary.', chat_id TEXT, kind TEXT)');
        if ($mode === 'queued') {
            $pdo->exec("INSERT INTO manual_notification_operations (id,alert_id,user_id,payload_hash) VALUES ('86b501b0-e26f-4974-a763-a9989a67162c',1,1,'queued-fixture')");
            foreach (['-1001', '-1002'] as $chat) {
                $pdo->prepare("INSERT INTO notification_logs (alert_id,user_id,chat_id,message,manual_operation_id,delivery_state,delivery_details) VALUES (1,1,?,'Queued notification','86b501b0-e26f-4974-a763-a9989a67162c','pending',?)")
                    ->execute([$chat, json_encode(['message' => ['state' => 'pending'], 'evidence' => []])]);
            }
        }
        if ($mode === 'bulk') {
            $pdo->prepare("INSERT INTO alerts VALUES (2,'acknowledged',1,?)")->execute([$key]);
            $group = ['id' => 'race-bulk-group', 'alert_ids' => [1, 2], 'message' => 'Ringkasan dua alert dalam satu pesan.'];
            foreach ([1, 2] as $alertId) {
                $operationId = 'bulk-operation-'.$alertId;
                $pdo->prepare("INSERT INTO manual_notification_operations (id,alert_id,user_id,payload_hash) VALUES (?,?,1,'bulk-fixture')")->execute([$operationId, $alertId]);
                $pdo->prepare("INSERT INTO notification_logs (alert_id,user_id,chat_id,message,manual_operation_id,delivery_state,delivery_details) VALUES (?,1,'-1001','Alert context',?,'pending',?)")
                    ->execute([$alertId, $operationId, json_encode(['message' => ['state' => 'pending'], 'evidence' => [], 'bulk_group' => $group])]);
            }
        }
        file_put_contents($directory.'/proof.png', 'mock-image-content');
        file_put_contents($directory.'/send.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== $argv[5] || config('database.connections.'.$argv[5].'.database') !== $argv[6]) {
    throw new RuntimeException('Unexpected notification race-test database.');
}
if ($argv[5] === 'pgsql') {
    Tests\Support\PostgresTestDatabase::assertSafe(config('database.connections.pgsql'));
    if (!preg_match('/\Asiem_notify_test_[a-f0-9]{12}\z/', $argv[7])) throw new RuntimeException('Invalid test schema.');
    config(['database.connections.pgsql.search_path' => $argv[7]]);
    Illuminate\Support\Facades\DB::purge('pgsql');
}
$app->instance(App\Services\TelegramService::class, new class extends App\Services\TelegramService {
    public function __construct() {}
    public function sendMessage(string $chatId, string $message, ?string $botToken = null): array {
        Illuminate\Support\Facades\DB::table('telegram_calls')->insert(['chat_id' => $chatId, 'kind' => 'message']);
        usleep(200000);
        return ['success' => true, 'status' => 200];
    }
    public function sendPhoto(string $chatId, string $photoPath, ?string $filename = null): array {
        Illuminate\Support\Facades\DB::table('telegram_calls')->insert(['chat_id' => $chatId, 'kind' => 'photo']);
        return ['success' => true, 'status' => 200];
    }
});
touch($argv[2].'/ready-'.$argv[3]);
$deadline = microtime(true) + 10;
while (!is_file($argv[2].'/start')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Notification race barrier timed out.');
    usleep(1000);
}
$notifier = app(App\Services\ManualAlertNotifier::class);
if ($argv[4] === 'bulk') {
    app(App\Services\BulkAlertNotifier::class)->deliver('race-bulk-group', [1, 2]);
    $result = ['replayed' => true];
} elseif ($argv[4] === 'queued') {
    $notifier->deliverQueued(1);
    $notifier->deliverQueued(2);
    $result = ['replayed' => true];
} else {
$result = $notifier->send(
    App\Models\Alert::findOrFail(1), App\Models\User::findOrFail(1),
    '86b501b0-e26f-4974-a763-a9989a67162c', 'Investigasi insiden diperlukan.',
    [new Illuminate\Http\UploadedFile($argv[2].'/proof.png', 'proof.png', 'image/png', null, true)],
);
}
echo json_encode(['replayed' => $result['replayed']]);
PHP);
        $root = dirname(__DIR__, 2);
        $module = $driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_sqlite';
        $probe = new Process([PHP_BINARY, '-r', 'echo extension_loaded("'.$module.'") ? "yes" : "no";']);
        $probe->mustRun();
        $extensions = trim($probe->getOutput()) === 'yes' ? [] : ['-d', 'extension='.$module];
        $processes = [];
        try {
            foreach ([1, 2] as $number) {
                $process = new Process([PHP_BINARY, ...$extensions, $directory.'/send.php', $root, $directory, (string) $number, $mode, $driver, $database, $schema ?? ''], $root, [
                    ...$connectionEnvironment,
                    'APP_ENV' => 'testing', 'APP_INSTALLED' => 'true', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $database,
                    'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null',
                ]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/ready-1');
            $this->assertFileExists($directory.'/ready-2');
            touch($directory.'/start');
            $replayed = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $this->assertJson(trim($process->getOutput()), substr($process->getOutput(), 0, 1500));
                $replayed[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR)['replayed'];
            }
            sort($replayed);
            if ($mode === 'inline') {
                $this->assertSame([false, true], $replayed);
            }
            $this->assertSame($mode === 'bulk' ? 2 : 1, (int) $pdo->query('SELECT COUNT(*) FROM manual_notification_operations')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM notification_logs')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM alert_triages')->fetchColumn());
            $expectedCalls = match ($mode) {
                'inline' => 4,
                'queued' => 2,
                'bulk' => 1,
            };
            $this->assertSame($expectedCalls, (int) $pdo->query('SELECT COUNT(*) FROM telegram_calls')->fetchColumn());
            $this->assertSame($expectedCalls, (int) $pdo->query('SELECT COUNT(*) FROM (SELECT DISTINCT chat_id, kind FROM telegram_calls)')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM notification_logs WHERE delivery_state = 'sent'")->fetchColumn());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            if ($schema !== null && preg_match('/\Asiem_notify_test_[a-f0-9]{12}\z/', $schema)) {
                $pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');
            }
            $pdo = null;
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
