<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertAiAnalysis;
use App\Models\AlertTriage;
use App\Models\DataExport;
use App\Models\ManualNotificationOperation;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\DataExportArchiveService;
use App\Services\DataExportPurgeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class SqlExportRoundTripTest extends TestCase
{
    private string $driver;

    private ?string $sandbox = null;

    private string $storageDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = getenv('SIEM_SQL_TEST_DRIVER') ?: 'sqlite';
        if ($this->driver === 'sqlite') {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        } elseif ($this->driver === 'pgsql') {
            // Opt-in integration checks use the disposable localhost PGlite server only.
            $this->sandbox = 'siem_export_test_'.bin2hex(random_bytes(6));
            config(['database.default' => 'sql_restore_test', 'database.connections.sql_restore_test' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'postgres',
                'username' => 'postgres', 'password' => 'postgres', 'sslmode' => 'disable',
                'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->sandbox,
            ]]);
            DB::statement('CREATE SCHEMA "'.$this->sandbox.'"');
        } elseif ($this->driver === 'mysql') {
            $configuration = config('database.connections.mysql');
            $this->assertContains($configuration['host'], ['127.0.0.1', 'localhost'], 'MySQL round-trip tests require localhost.');
            $this->sandbox = 'siem_export_test_'.bin2hex(random_bytes(6));
            $configuration['database'] = null;
            $configuration['url'] = null;
            config(['database.default' => 'sql_restore_test', 'database.connections.sql_restore_test' => $configuration]);
            DB::statement('CREATE DATABASE `'.$this->sandbox.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            config(['database.connections.sql_restore_test.database' => $this->sandbox]);
            DB::purge('sql_restore_test');
        } else {
            $this->fail('Unsupported SQL round-trip test driver.');
        }
        Artisan::call('migrate', ['--force' => true]);
        $this->storageDisk = 'sql-restore-'.bin2hex(random_bytes(6));
        config(['filesystems.disks.'.$this->storageDisk => [
            'driver' => 'local', 'root' => storage_path('framework/testing/disks/'.$this->storageDisk), 'visibility' => 'private',
        ]]);
        Storage::fake($this->storageDisk);
        config(['data_exports.chunk_size' => 1]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->sandbox !== null && preg_match('/\Asiem_export_test_[a-f0-9]{12}\z/', $this->sandbox)) {
                DB::statement($this->driver === 'pgsql'
                    ? 'DROP SCHEMA "'.$this->sandbox.'" CASCADE'
                    : 'DROP DATABASE `'.$this->sandbox.'`');
                DB::purge('sql_restore_test');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function fixture(string $format = 'sql', string $scope = 'alerts'): DataExport
    {
        $user = User::factory()->create(['role' => 'admin']);
        $text = "Bukti 'kutipan', \\path\\file, baris\nberikutnya; DROP TABLE users; -- 🛡️ 日本語";
        $alert = Alert::forceCreate([
            'id' => 9001, 'wazuh_alert_id' => 'restore-fixture', 'rule_description' => $text,
            'rule_level' => 15, 'status' => 'acknowledged', 'first_seen_at' => '2026-11-15 01:15:00',
            'src_ip' => '192.0.2.81', 'dst_ip' => '2001:db8::81',
            'raw_data' => ['agent' => ['id' => '001'], 'evidence' => $text, 'private_search' => 'native-protected-payload-only', 'bool' => true, 'null' => null],
        ]);
        $alert->forceFill(['acknowledged_by_user_id' => $user->id, 'acknowledged_at' => now()])->save();
        AlertAiAnalysis::forceCreate([
            'id' => 9002, 'alert_id' => $alert->id, 'requested_by_user_id' => $user->id,
            'verdict' => 'suspicious', 'confidence' => 95, 'summary' => $text,
            'supporting_indicators' => [$text], 'legitimate_indicators' => [],
            'recommended_checks' => [$text], 'limitations' => null,
            'model' => 'test-model', 'duration_ms' => 123, 'generated_at' => now(),
        ]);
        AlertTriage::forceCreate(['id' => 9003, 'alert_id' => $alert->id, 'user_id' => $user->id,
            'action' => 'acknowledge', 'reason' => $text, 'created_at' => now()]);
        // UUID ordering must work across page boundaries; these aren't integer IDs.
        foreach (['ffffffff-ffff-4fff-afff-ffffffffffff', '00000000-0000-4000-a000-000000000001'] as $operationId) {
            ManualNotificationOperation::create(['id' => $operationId, 'alert_id' => $alert->id,
                'user_id' => $user->id, 'payload_hash' => hash('sha256', $operationId)]);
        }
        NotificationLog::forceCreate(['id' => 9004, 'alert_id' => $alert->id, 'user_id' => $user->id,
            'chat_id' => '-1001', 'message' => $text, 'response_status' => 200, 'sent_at' => now(),
            'manual_operation_id' => 'ffffffff-ffff-4fff-afff-ffffffffffff', 'delivery_state' => 'sent',
            'delivery_details' => ['message' => ['state' => 'sent'], 'evidence' => [['name' => 'bukti.png', 'state' => 'sent']],
                'bulk_group' => ['id' => '9c64ac42-86e7-4c68-a10b-9aa5e038b09c', 'alert_ids' => [$alert->id], 'message' => $text]],
        ]);

        return DataExport::create([
            'uuid' => (string) Str::uuid(), 'requested_by_user_id' => $user->id,
            'requested_by_name' => $user->name, 'scope' => $scope, 'format' => $format,
            'status' => 'queued', 'period_label' => "Backup 'uji'\n-- embedded comment", 'storage_disk' => $this->storageDisk, 'filename' => 'backup.zip',
        ]);
    }

    private function payload(DataExport $export): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk($this->storageDisk)->path($export->storage_path)));
        try {
            $payload = $zip->getFromName('data.'.$export->format);
            $this->assertIsString($payload);

            return $payload;
        } finally {
            $zip->close();
        }
    }

    private function records(DataExport $export): array
    {
        $result = [];
        $archives = app(DataExportArchiveService::class);
        foreach ($archives->includedEntities($export) as $entity) {
            $result[$entity] = DB::table($entity)->orderBy('id')->get($archives->columnsFor($entity))
                ->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public function test_sql_restores_every_selected_record_and_advances_generated_ids(): void
    {
        $export = $this->fixture();
        $archives = app(DataExportArchiveService::class);
        $label = match ($this->driver) {
            'pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', default => 'SQLite'
        };
        $this->actingAs(User::first())->get(route('settings.index', ['section' => 'data']))->assertOk()->assertSee('SQL INSERT ('.$label.')');
        $before = $this->records($export);
        $manifest = $archives->generate($export);
        $sql = $this->payload($export->fresh());
        $this->assertStringContainsString('-- Database driver: '.$this->driver, $sql);
        $this->assertStringNotContainsString('FOREIGN_KEY_CHECKS', $sql);
        $this->assertStringNotContainsString('INSERT IGNORE', $sql);
        $this->assertSame($this->driver, $manifest['source']['database']['driver']);
        $this->assertContains('2026_11_15_000020_add_manual_notification_operations', $manifest['source']['database']['schema_migrations']);
        $this->assertSame(2, $manifest['source']['record_counts']['manual_notification_operations']);
        DB::table('alerts')->delete();
        if ($this->driver === 'pgsql') {
            foreach (['alerts', 'alert_ai_analyses', 'alert_triages', 'notification_logs'] as $entity) {
                DB::unprepared('ALTER SEQUENCE "'.$entity.'_id_seq" RESTART WITH 1');
            }
        }
        DB::unprepared($sql);
        $this->assertSame($before, $this->records($export), 'Restored records must match all original values and relationships.');
        config(['data_security.allow_legacy_plaintext' => false]);
        $restored = Alert::findOrFail(9001);
        $this->assertSame('192.0.2.81', $restored->src_ip);
        $this->assertSame('001', data_get($restored->raw_data, 'agent.id'));
        $this->assertSame(1, Alert::searchTerms('192.0.2.81')->count());
        $this->assertSame(1, Alert::searchTerms('native-protected-payload-only')->count());
        $this->assertSame(1, User::count());
        $newAlert = Alert::create(['wazuh_alert_id' => 'after-restore', 'rule_level' => 12, 'status' => 'new', 'first_seen_at' => now()]);
        $this->assertGreaterThan(9001, $newAlert->id);
        $newTriage = AlertTriage::create(['alert_id' => $newAlert->id, 'user_id' => User::first()->id, 'action' => 'acknowledge']);
        $this->assertGreaterThan(9003, $newTriage->id);
    }

    public function test_conflicting_ids_fail_and_leave_the_existing_records_intact(): void
    {
        $export = $this->fixture();
        app(DataExportArchiveService::class)->generate($export);
        $sql = $this->payload($export->fresh());
        Alert::whereKey(9001)->update(['rule_description' => 'Existing data must not be overwritten']);
        $before = $this->records($export);
        try {
            DB::unprepared($sql);
            $this->fail('Conflicting IDs must not be silently skipped.');
        } catch (QueryException $exception) {
            DB::unprepared('ROLLBACK');
        }
        $this->assertSame($before, $this->records($export));
    }

    public function test_missing_user_dependencies_stop_the_restore_without_disabling_foreign_keys(): void
    {
        $export = $this->fixture();
        app(DataExportArchiveService::class)->generate($export);
        $sql = $this->payload($export->fresh());
        DB::table('alerts')->delete();
        DB::table('users')->delete();
        try {
            DB::unprepared($sql);
            $this->fail('Missing referenced users must stop restoration.');
        } catch (QueryException) {
            DB::unprepared('ROLLBACK');
        }
        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_json_backups_preserve_uuid_operations_and_history_purge_retains_the_tombstones(): void
    {
        $export = $this->fixture('json', 'notif_logs');
        app(DataExportArchiveService::class)->generate($export);
        $export->refresh();
        $json = json_decode($this->payload($export), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($this->driver, $json['backup_info']['database']['driver']);
        $this->assertCount(2, $json['manual_notification_operations']);
        $this->assertSame('00000000-0000-4000-a000-000000000001', $json['manual_notification_operations'][0]['id']);
        $export->forceFill(['purge_status' => 'running'])->save();
        app(DataExportPurgeService::class)->purge($export);
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('manual_notification_operations', 2);
    }

    public function test_operation_changes_after_backup_block_purge(): void
    {
        $export = $this->fixture();
        app(DataExportArchiveService::class)->generate($export);
        ManualNotificationOperation::first()->update(['payload_hash' => hash('sha256', 'changed-after-backup')]);
        $export->forceFill(['purge_status' => 'running'])->save();
        try {
            app(DataExportPurgeService::class)->purge($export);
            $this->fail('Modified operation metadata must block purge.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Isi record sumber berubah', $exception->getMessage());
        }
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('manual_notification_operations', 2);
    }
}
