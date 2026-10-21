<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationConnectionTester;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('server-database')]
class ServerDatabaseTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Requires PostgreSQL, MySQL or MariaDB; covered by the server database CI matrix.');
        }
    }

    public function test_installer_connects_migrates_and_creates_admin_on_the_real_engine(): void
    {
        $original = DB::getDefaultConnection();
        $connection = config('database.connections.'.$original);
        $directory = sys_get_temp_dir().'/siem-real-install-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/.env.example', "APP_KEY=\nAPP_INSTALLED=false\n");
        $environment = new EnvironmentFileWriter($directory);
        $key = $environment->ensureEnvironmentAndKey()['app_key'];
        config(['app.key' => $key, 'installer.installed' => false]);
        $state = new InstallationState(
            environment: $environment,
            markerPath: $directory.'/installed.json',
            tokenPath: $directory.'/token.json',
            detectLegacyAdmin: false,
        );

        try {
            // Exercise first installation from an empty schema, not only a
            // connection to tables pre-created by the testing framework.
            DB::connection()->getSchemaBuilder()->dropAllTables();
            (new Installer($state, $environment, new InstallationConnectionTester))->install([
                'app_name' => 'Database audit',
                'app_url' => 'https://siem.example.test',
                'app_timezone' => 'Asia/Jakarta',
                'db_connection' => $connection['driver'],
                'db_host' => $connection['host'],
                'db_port' => $connection['port'],
                'db_database' => $connection['database'],
                'db_username' => $connection['username'],
                'db_password' => $connection['password'],
                'db_sslmode' => $connection['sslmode'] ?? 'prefer',
                'admin_name' => 'Test administrator',
                'admin_email' => 'admin@example.test',
                'admin_password' => 'Database-test-123!',
                'admin_password_confirmation' => 'Database-test-123!',
            ]);
            $this->assertSame($connection['driver'], DB::connection()->getDriverName());
            $this->assertSame('admin', User::sole()->role);
            $this->assertSame($connection['driver'], $environment->value('DB_CONNECTION'));
            $this->assertTrue($state->isInstalled());
        } finally {
            DB::disconnect('siem_installer');
            DB::setDefaultConnection($original);
            File::deleteDirectory($directory);
        }
    }

    public function test_two_worker_connections_reserve_different_jobs_while_a_row_is_locked(): void
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());
        foreach (['audit_worker_a', 'audit_worker_b'] as $name) {
            config([
                "database.connections.{$name}" => $connection,
                "queue.connections.{$name}" => [
                    'driver' => 'database', 'connection' => $name, 'table' => 'jobs',
                    'queue' => 'audit', 'retry_after' => 3660,
                ],
            ]);
        }
        $queueA = Queue::connection('audit_worker_a');
        $queueB = Queue::connection('audit_worker_b');
        $firstId = $queueA->pushRaw('{"displayName":"audit-one","job":"audit","data":{}}');
        $secondId = $queueA->pushRaw('{"displayName":"audit-two","job":"audit","data":{}}');
        $workerA = DB::connection('audit_worker_a');
        $workerB = DB::connection('audit_worker_b');
        $workerB->statement($connection['driver'] === 'pgsql'
            ? "SET lock_timeout = '2s'"
            : 'SET SESSION innodb_lock_wait_timeout = 2');

        $workerA->beginTransaction();
        try {
            $first = $queueA->pop();
            $second = $queueB->pop();
            $this->assertNotNull($first);
            $this->assertNotNull($second);
            $this->assertSame((string) $firstId, (string) $first->getJobId());
            $this->assertSame((string) $secondId, (string) $second->getJobId());
            $this->assertNull($queueB->pop());
            $workerA->commit();
            $first->delete();
            $second->delete();
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            if ($workerA->transactionLevel() > 0) {
                $workerA->rollBack();
            }
            DB::purge('audit_worker_a');
            DB::purge('audit_worker_b');
        }
    }
}
