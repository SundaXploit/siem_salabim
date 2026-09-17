<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class SqlBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_export_round_trips_untrusted_text_nulls_json_and_timestamps(): void
    {
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

        $tables = ['alerts', 'alert_triages', 'notification_logs', 'alert_ai_analyses'];
        $expected = [];
        foreach ($tables as $table) {
            $expected[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $response = $this->actingAs($admin)->post(route('settings.data.backup'), [
            'format' => 'sql',
            'scope' => 'all',
            'period_mode' => 'range',
            'date_from' => '2026-09-17',
            'date_to' => '2026-09-17',
        ])->assertOk()->assertDownload();
        $sql = $response->streamedContent();

        // Restore into a separate in-memory database using its actual SQL
        // parser. Translate only MySQL syntax, not the exported string data.
        $restored = new PDO('sqlite::memory:');
        $restored->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $restored->exec('CREATE TABLE export_guard (id INTEGER PRIMARY KEY)');
        $restored->exec('INSERT INTO export_guard VALUES (1)');
        foreach ($tables as $table) {
            $schema = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
            $restored->exec($schema);
        }
        $sqlite = preg_replace('/^SET [^\r\n]*;$/m', '', $sql);
        $sqlite = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sqlite);
        $sqlite = preg_replace("/_utf8mb4 (X'[0-9a-f]*')/", 'CAST($1 AS TEXT)', $sqlite);
        $restored->exec($sqlite);

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
