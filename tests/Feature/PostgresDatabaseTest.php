<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DataExport;
use App\Models\User;
use App\Services\DataExportArchiveService;
use App\Services\Installation\InstallationConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PostgresDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Run with phpunit.pgsql.xml.dist against a disposable PostgreSQL database.');
        }
    }

    public function test_all_migrations_can_roll_back_and_run_again(): void
    {
        $count = DB::table('migrations')->count();
        $this->assertGreaterThan(0, $count);
        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('alerts'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($count, DB::table('migrations')->count());
        $this->assertTrue(Schema::hasTable('manual_notification_operations'));
    }

    public function test_installer_connects_using_the_application_role_and_preserves_the_tls_ca(): void
    {
        $this->requirePostgres();
        $configuration = config('database.connections.pgsql');
        $input = [
            'db_connection' => 'pgsql', 'db_host' => $configuration['host'], 'db_port' => $configuration['port'],
            'db_database' => $configuration['database'], 'db_username' => $configuration['username'],
            'db_password' => $configuration['password'], 'db_sslmode' => $configuration['sslmode'],
        ];
        $tester = app(InstallationConnectionTester::class);
        $result = $tester->testDatabase($input);
        $this->assertSame($configuration['username'], $result['username']);
        $role = DB::selectOne('SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse($role->rolsuper, 'Use a non-superuser test role.');
        $this->assertFalse($role->rolcreatedb);
        $this->assertFalse($role->rolcreaterole);
        config(['database.connections.pgsql.sslrootcert' => '/private/test-postgresql-ca.crt']);
        $result = $tester->databaseConfiguration([...$input, 'db_sslmode' => 'verify-full']);
        $this->assertSame('verify-full', $result['sslmode']);
        $this->assertSame('/private/test-postgresql-ca.crt', $result['sslrootcert']);
    }

    public function test_database_cache_locks_queue_and_sessions_work(): void
    {
        $cache = Cache::store('database');
        $cache->put('pgsql-audit', ['ok' => true], 60);
        $this->assertSame(['ok' => true], $cache->get('pgsql-audit'));
        $first = $cache->lock('pgsql-audit-lock', 60);
        $second = $cache->lock('pgsql-audit-lock', 60);
        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $this->assertTrue($first->release());
        $this->assertTrue($second->get());
        $second->release();

        $queue = Queue::connection('database');
        $payload = json_encode(['displayName' => 'pgsql-audit', 'job' => 'test-only', 'data' => []], JSON_THROW_ON_ERROR);
        $id = $queue->pushRaw($payload, 'pgsql-audit');
        $job = $queue->pop('pgsql-audit');
        $this->assertSame((int) $id, (int) $job->getJobId());
        $this->assertSame($payload, $job->getRawBody());
        $job->delete();
        $this->assertSame(0, $queue->size('pgsql-audit'));

        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
        $sessionId = Str::random(40);
        $handler->write($sessionId, 'session-audit-payload');
        $this->assertSame('session-audit-payload', $handler->read($sessionId));
        $handler->destroy($sessionId);
        $this->assertSame('', $handler->read($sessionId));
    }

    public function test_nested_export_is_rejected_without_aborting_the_callers_transaction(): void
    {
        $this->requirePostgres();
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        Alert::create(['wazuh_alert_id' => 'nested-snapshot', 'rule_level' => 12, 'status' => 'new', 'first_seen_at' => now()]);
        $export = DataExport::create([
            'uuid' => (string) Str::uuid(), 'requested_by_user_id' => $user->id, 'requested_by_name' => $user->name,
            'scope' => 'alerts', 'format' => 'json', 'status' => 'queued', 'period_label' => 'Audit',
            'storage_disk' => 'local', 'filename' => 'audit.zip',
        ]);
        DB::transaction(function () use ($export): void {
            $this->assertSame(1, User::count());
            try {
                app(DataExportArchiveService::class)->generate($export);
                $this->fail('An existing transaction cannot provide a new consistent snapshot.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('di dalam transaksi lain', $exception->getMessage());
            }
            $this->assertSame(1, User::count(), 'The caller must still be able to query after rejection.');
        });
        $this->assertNull($export->fresh()->storage_path);
    }
}
