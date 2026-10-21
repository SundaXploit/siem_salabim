<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SqlBackupTest extends TestCase
{
    use DatabaseMigrations;

    public function test_sql_export_round_trips_untrusted_text_nulls_json_and_timestamps(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create([
            'role' => 'admin',
            'name' => "SOC\nDROP TABLE export_guard;\r\n--",
        ]);
        $hostile = "O'Brien\\path\n'); DROP TABLE export_guard; --\r\n\0雪 🔎";
        // PostgreSQL text rejects NUL bytes; preserve them in the JSON payload.
        $rawHostile = $hostile;
        if (DB::connection()->getDriverName() === 'pgsql') {
            $hostile = str_replace("\0", '', $hostile);
        }
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
            'raw_data' => ['text' => $rawHostile, 'empty' => '', 'null' => null, 'number' => 42],
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

        $this->actingAs($admin)->post(route('settings.data.backup'), [
            'format' => 'sql',
            'scope' => 'all',
            'period_mode' => 'range',
            'date_from' => '2026-09-17',
            'date_to' => '2026-09-17',
        ])->assertRedirect(route('settings.index'));

        $export = DataExport::query()->sole();
        $response = $this->actingAs($admin)
            ->get(route('settings.data.exports.download', $export))
            ->assertOk()
            ->assertDownload($export->filename);
        $sql = $response->streamedContent();

        // Check source preservation, then restore the exact SQL through the
        // actual engine in this disposable test database, without translation.
        foreach ($tables as $table) {
            $this->assertSame($expected[$table], DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
        DB::statement('CREATE TABLE export_guard (id INTEGER PRIMARY KEY)');
        DB::table('export_guard')->insert(['id' => 1]);
        foreach (['alert_ai_analyses', 'alert_triages', 'notification_logs', 'alerts'] as $table) {
            DB::table($table)->delete();
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach ($tables as $table) {
                DB::statement("ALTER SEQUENCE {$table}_id_seq RESTART WITH 1");
            }
        }
        try {
            DB::unprepared($sql);
            DB::unprepared($sql);
            $this->assertSame(1, DB::table('export_guard')->count());
            foreach ($tables as $table) {
                $actual = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
                $this->assertSame($expected[$table], $actual, "Export did not preserve {$table} values.");
            }
            $newAlert = Alert::create(['wazuh_alert_id' => 'after-restore', 'rule_level' => 1]);
            $this->assertGreaterThan(max(array_column($expected['alerts'], 'id')), $newAlert->id);
        } finally {
            DB::statement('DROP TABLE export_guard');
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
