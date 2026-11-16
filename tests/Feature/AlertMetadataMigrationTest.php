<?php

namespace Tests\Feature;

use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AlertMetadataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_refuses_long_metadata_before_altering_either_column(): void
    {
        $alert = Alert::create([
            'wazuh_alert_id' => 'metadata-rollback', 'agent_name' => str_repeat('endpoint-', 20),
            'rule_id' => str_repeat('9', 64), 'rule_level' => 12, 'status' => 'new',
        ]);
        $migration = require database_path('migrations/2026_11_16_000025_widen_alert_metadata.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse metadata that cannot fit the previous schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback metadata alert dibatalkan', $exception->getMessage());
        }
        $this->assertSame($alert->agent_name, $alert->fresh()->agent_name);
        $this->assertSame($alert->rule_id, $alert->fresh()->rule_id);
    }

    public function test_safe_rollback_and_upgrade_keep_existing_metadata(): void
    {
        $alert = Alert::create([
            'wazuh_alert_id' => 'metadata-upgrade', 'agent_name' => 'Existing endpoint',
            'rule_id' => '990001', 'rule_level' => 12, 'status' => 'new',
        ]);
        $migration = require database_path('migrations/2026_11_16_000025_widen_alert_metadata.php');
        $migration->down();
        $migration->up();
        $this->assertSame($alert->agent_name, $alert->fresh()->agent_name);
        $this->assertSame($alert->rule_id, $alert->fresh()->rule_id);
    }
}
