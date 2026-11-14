<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalystAgentAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_adds_access_keys_without_rewriting_source_evidence_timestamps_or_granting_legacy_accounts(): void
    {
        $migration = require database_path('migrations/2026_11_13_000017_add_analyst_agent_access.php');
        $migration->down();
        $user = DB::table('users')->insertGetId(['name' => 'Existing analyst', 'email' => 'legacy@example.test', 'password' => 'unused', 'role' => 'analyst']);
        $raw = '{ "agent": {"id":"007", "name":"Original name"}, "unknown": [false, null, "  Exact.  "] }';
        foreach (['wazuh-primary', 'another-wazuh'] as $index => $cluster) {
            DB::table('alerts')->insert([
                'wazuh_alert_id' => 'legacy-'.$index, 'source_cluster' => $cluster, 'source_index' => 'original', 'source_id' => (string) $index,
                'source_identity_hash' => Alert::sourceIdentityHash($cluster, 'original', (string) $index),
                'raw_data' => $raw, 'status' => 'ignored', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-02 00:00:00',
            ]);
        }
        $before = DB::table('alerts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        foreach (DB::table('alerts')->orderBy('id')->get() as $index => $row) {
            $this->assertSame(Alert::agentScopeKey($row->source_cluster, '007'), $row->agent_scope_key);
            $attributes = (array) $row;
            unset($attributes['agent_scope_key']);
            $this->assertSame($before[$index], $attributes);
        }
        $analyst = User::findOrFail($user);
        $this->assertSame([], $analyst->agentAccessKeys());
        $this->assertFalse($analyst->hasPrivilege('alerts.acknowledge'));
        $this->assertSame(0, Alert::visibleTo($analyst)->count());
    }
}
