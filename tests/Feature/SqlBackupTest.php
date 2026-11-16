<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

class SqlBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_export_round_trips_untrusted_text_nulls_json_and_timestamps(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create([
            'role' => 'admin',
            'name' => "SOC\nDROP TABLE export_guard;\r\n--",
        ]);
        $hostile = "O'Brien\\path\n'); DROP TABLE export_guard; --\r\n\0雪 🔎";
        $alert = Alert::create([
            'wazuh_alert_id' => "event'); DROP TABLE export_guard; --",
            'rule_description' => $hostile,
            'agent_name' => $hostile,
            'rule_id' => "rule'\\42",
            'src_ip' => null,
            'dst_ip' => '',
            'rule_level' => 5,
            'status' => 'new',
            'first_seen_at' => '2026-09-17 04:00:00',
            'raw_data' => ['text' => $hostile, 'empty' => '', 'null' => null, 'number' => 42],
        ]);
        Alert::create([
            'wazuh_alert_id' => 'nullable-alert',
            'rule_description' => '',
            'rule_level' => 0,
            'status' => 'new',
            'first_seen_at' => '2026-09-17 04:01:00',
            'raw_data' => null,
        ]);
        AlertTriage::create([
            'alert_id' => $alert->id,
            'user_id' => $admin->id,
            'action' => 'ignore',
            'reason' => $hostile,
        ]);
        AlertTriage::create([
            'alert_id' => $alert->id,
            'user_id' => $admin->id,
            'action' => 'acknowledge',
            'reason' => null,
        ]);
        NotificationLog::create([
            'alert_id' => $alert->id,
            'user_id' => $admin->id,
            'chat_id' => "chat'); DROP TABLE export_guard; --",
            'message' => $hostile,
            'response_status' => null,
            'sent_at' => '2026-09-17 04:02:00',
        ]);
        $alert->aiAnalyses()->create([
            'requested_by_user_id' => null,
            'verdict' => 'inconclusive',
            'confidence' => null,
            'summary' => $hostile,
            'supporting_indicators' => [$hostile],
            'legitimate_indicators' => [],
            'recommended_checks' => null,
            'limitations' => [$hostile],
            'model' => "model'\\example",
            'duration_ms' => null,
            'generated_at' => '2026-09-17 04:03:00',
        ]);

        $tables = ['alerts', 'alert_triages', 'notification_logs', 'alert_ai_analyses', 'manual_notification_operations', 'encrypted_search_tokens'];
        $expected = [];
        foreach ($tables as $table) {
            $expected[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $this->actingAs($admin)->post(route('settings.data.backup'), [
            'format' => 'sql',
            'scope' => 'all',
            'period_mode' => 'range',
            'date_from' => '2026-09-17',
            'date_to' => '2026-09-17',
        ])->assertRedirect(route('settings.index', ['section' => 'data']));

        $export = DataExport::query()->sole();
        $response = $this->actingAs($admin)
            ->get($this->authorizedDownloadUrl($export))
            ->assertOk()
            ->assertDownload($export->filename);
        $this->assertSame(Storage::disk('local')->get($export->storage_path), $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->storage_path)));
        $sql = $zip->getFromName('data.sql');
        $zip->close();
        $this->assertIsString($sql);

        if (in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            Schema::create('export_guard', fn ($table) => $table->integer('id')->primary());
            DB::table('export_guard')->insert(['id' => 1]);
            foreach ($tables as $table) {
                $this->assertSame($expected[$table], DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
            }
            DB::table('alerts')->delete();
            DB::unprepared($sql);
            $this->assertDatabaseHas('export_guard', ['id' => 1]);
            foreach ($tables as $table) {
                $this->assertSame($expected[$table], DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), "Export did not preserve {$table} values.");
            }

            return;
        }

        // Execute the generated SQLite SQL directly with FK checks enabled.
        // Account records are prerequisites, rather than part of this operational-data backup.
        $restored = new PDO('sqlite::memory:');
        $restored->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $restored->exec('CREATE TABLE export_guard (id INTEGER PRIMARY KEY)');
        $restored->exec('INSERT INTO export_guard VALUES (1)');
        $restored->exec(DB::table('sqlite_master')->where('type', 'table')->where('name', 'users')->value('sql'));
        $user = (array) DB::table('users')->where('id', $admin->id)->first();
        $columns = '"'.implode('","', array_keys($user)).'"';
        $restored->prepare('INSERT INTO users ('.$columns.') VALUES ('.implode(',', array_fill(0, count($user), '?')).')')
            ->execute(array_values($user));
        foreach ($tables as $table) {
            $schema = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
            $restored->exec($schema);
        }
        $restored->exec($sql);

        $this->assertSame(1, (int) $restored->query('SELECT COUNT(*) FROM export_guard')->fetchColumn());
        foreach ($tables as $table) {
            $actual = $restored->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame($expected[$table], $actual, "Export did not preserve {$table} values.");
            $this->assertSame(
                $expected[$table],
                DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
                "Export changed source rows in {$table}.",
            );
        }
    }

    private function authorizedDownloadUrl(DataExport $export): string
    {
        return $this->postJson(route('settings.data.exports.authorize-download', $export), ['current_password' => 'password'])
            ->assertOk()->json('download_url');
    }

    public function test_analyst_cannot_download_sql_backups(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->post(route('settings.data.backup'), [
                'format' => 'sql',
                'scope' => 'all',
                'period_mode' => 'range',
                'date_from' => '2026-09-17',
                'date_to' => '2026-09-17',
            ])
            ->assertForbidden();
    }
}
