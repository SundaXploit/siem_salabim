<?php

namespace Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlTestDatabase;
use Tests\Support\PostgresTestDatabase;

class ConcurrentAckTest extends TestCase
{
    public static function competingSelections(): array
    {
        return [
            'single against single' => [[1], [1]],
            'bulk against single overlapping alert' => [[1, 2], [2]],
            'bulk against reversed bulk' => [[1, 2], [2, 1]],
        ];
    }

    #[DataProvider('competingSelections')]
    public function test_two_processes_create_exactly_one_owner_and_ack_event_per_alert(array $firstSelection, array $secondSelection): void
    {
        $directory = sys_get_temp_dir().'/siem-ack-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $root = dirname(__DIR__, 2);
        $driver = getenv('SIEM_ACK_TEST_DRIVER') ?: 'sqlite';
        $database = $directory.'/race.sqlite';
        $connectionEnvironment = [];
        $schema = null;
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $app = require $root.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            $configuration = config('database.connections.'.$driver);
            MySqlTestDatabase::assertSafe($configuration);
            restore_error_handler();
            restore_exception_handler();
            $this->assertContains($configuration['host'], ['127.0.0.1', 'localhost']);
            $database = 'siem_ack_test_'.bin2hex(random_bytes(6));
            $pdo = new PDO('mysql:host='.$configuration['host'].';port='.$configuration['port'].';charset=utf8mb4', $configuration['username'], $configuration['password']);
            $pdo->exec('CREATE DATABASE `'.$database.'`');
            $pdo->exec('USE `'.$database.'`');
            $connectionEnvironment = ['DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password']];
        } elseif ($driver === 'pgsql') {
            $app = require $root.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            $configuration = config('database.connections.pgsql');
            PostgresTestDatabase::assertSafe($configuration);
            $database = $configuration['database'];
            $pdo = $app['db']->connection('pgsql')->getPdo();
            restore_error_handler();
            restore_exception_handler();
            $schema = 'siem_ack_test_'.bin2hex(random_bytes(6));
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
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, is_active INTEGER, allowed_agents TEXT, privileges TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1,'First','admin',1,'[]','[]'),(2,'Second','admin',1,'[]','[]')");
        $pdo->exec('CREATE TABLE alerts (id INTEGER PRIMARY KEY, status TEXT, first_seen_at TEXT, acknowledged_by_user_id INTEGER, acknowledged_at TEXT, updated_at TEXT)');
        $alertIds = array_values(array_unique([...$firstSelection, ...$secondSelection]));
        foreach ($alertIds as $id) {
            $pdo->exec("INSERT INTO alerts VALUES ({$id},'new','2026-11-14 17:00:00',NULL,NULL,NULL)");
        }
        $primary = match ($driver) {
            'mysql', 'mariadb' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', 'pgsql' => 'BIGSERIAL PRIMARY KEY', default => 'INTEGER PRIMARY KEY',
        };
        $pdo->exec('CREATE TABLE alert_triages (id '.$primary.', alert_id INTEGER, user_id INTEGER, action TEXT, reason TEXT, created_at TEXT)');
        file_put_contents($directory.'/claim.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== $argv[5] || config('database.connections.'.$argv[5].'.database') !== $argv[6]) {
    throw new RuntimeException('Unexpected race-test database.');
}
if ($argv[5] === 'pgsql') {
    Tests\Support\PostgresTestDatabase::assertSafe(config('database.connections.pgsql'));
    if (!preg_match('/\Asiem_ack_test_[a-f0-9]{12}\z/', $argv[7])) throw new RuntimeException('Invalid test schema.');
    config(['database.connections.pgsql.search_path' => $argv[7]]);
    Illuminate\Support\Facades\DB::purge('pgsql');
}
touch($argv[2].'/ready-'.$argv[3]);
$deadline = microtime(true) + 10;
while (!is_file($argv[2].'/start')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Race start barrier timed out.');
    usleep(1000);
}
echo json_encode(app(App\Services\AlertTriageService::class)->apply(json_decode($argv[4], true), 'acknowledge', (int)$argv[3]));
PHP);
        $module = match ($driver) {
            'pgsql' => 'pdo_pgsql', 'mysql', 'mariadb' => 'pdo_mysql', default => 'pdo_sqlite',
        };
        $probe = new Process([PHP_BINARY, '-r', 'echo extension_loaded("'.$module.'") ? "yes" : "no";']);
        $probe->mustRun();
        $extensions = trim($probe->getOutput()) === 'yes' ? [] : ['-d', 'extension='.$module];
        $processes = [];
        try {
            foreach ([1, 2] as $actor) {
                $selection = $actor === 1 ? $firstSelection : $secondSelection;
                $process = new Process([PHP_BINARY, ...$extensions, $directory.'/claim.php', $root, $directory, (string) $actor, json_encode($selection), $driver, $database, $schema ?? ''], $root, [
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
            $processed = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $output = trim($process->getOutput());
                $this->assertJson($output, 'Unexpected race worker output: '.substr($output, 0, 1000));
                $processed[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['processed'];
            }
            $this->assertSame(count($alertIds), array_sum($processed));
            $this->assertSame(count($alertIds), (int) $pdo->query('SELECT COUNT(*) FROM alert_triages')->fetchColumn());
            foreach ($alertIds as $id) {
                $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM alert_triages WHERE alert_id = {$id}")->fetchColumn());
                $this->assertSame((int) $pdo->query("SELECT user_id FROM alert_triages WHERE alert_id = {$id}")->fetchColumn(), (int) $pdo->query("SELECT acknowledged_by_user_id FROM alerts WHERE id = {$id}")->fetchColumn());
            }
            if (! in_array(1, $secondSelection, true)) {
                $this->assertSame(1, (int) $pdo->query('SELECT acknowledged_by_user_id FROM alerts WHERE id = 1')->fetchColumn());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            if (in_array($driver, ['mysql', 'mariadb'], true) && preg_match('/\Asiem_ack_test_[a-f0-9]{12}\z/', $database)) {
                $pdo->exec('DROP DATABASE `'.$database.'`');
            }
            if ($schema !== null && preg_match('/\Asiem_ack_test_[a-f0-9]{12}\z/', $schema)) {
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
