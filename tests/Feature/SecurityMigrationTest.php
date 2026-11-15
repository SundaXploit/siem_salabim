<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class SecurityMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function event(string $id, string $name, string $agentId): Alert
    {
        return Alert::create(['wazuh_alert_id' => $id, 'agent_name' => $name, 'rule_level' => 14,
            'status' => 'new', 'first_seen_at' => now(), 'raw_data' => ['agent' => ['id' => $agentId]],
        ]);
    }

    public function test_legacy_names_only_migrate_when_the_stable_identity_is_unambiguous(): void
    {
        $unique = $this->event('one', 'Unique name', '001');
        $this->event('duplicate-event-same-agent', 'Unique name', '001');
        $this->event('two', 'Ambiguous name', '002');
        $this->event('three', 'Ambiguous name', '003');
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'SOC', 'chat_id' => '-1001', 'agents' => ['Unique name', 'Ambiguous name', 'Absent name']],
        ]));
        $migration = require database_path('migrations/2026_11_15_000019_match_telegram_routes_to_agent_ids.php');
        $migration->up();
        $route = Configuration::getTelegramDestinations()[0];
        $this->assertSame([$unique->agent_scope_key], $route['agent_keys']);
        $this->assertSame(['Ambiguous name', 'Absent name'], $route['unresolved_agents']);
        $this->assertSame('001', $route['agent_details'][0]['id']);
        $migration->up();
        $this->assertSame($route, Configuration::getTelegramDestinations()[0]);
    }

    public function test_existing_ack_ownership_is_recovered_from_first_recorded_ack_without_changing_evidence(): void
    {
        $first = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'admin']);
        $alert = $this->event('legacy', 'Legacy', '001');
        $alert->update(['status' => 'acknowledged']);
        AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $first->id, 'action' => 'acknowledge', 'created_at' => now()->subMinutes(10)]);
        AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $second->id, 'action' => 'acknowledge', 'created_at' => now()]);
        $raw = $alert->getRawOriginal('raw_data');
        $migration = require database_path('migrations/2026_11_15_000018_add_account_security_and_alert_ownership.php');
        $migration->down();
        $this->assertDatabaseCount('alert_triages', 2);
        $migration->up();
        $this->assertSame($first->id, $alert->fresh()->acknowledged_by_user_id);
        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->assertSame($raw, $alert->fresh()->getRawOriginal('raw_data'));
        $this->assertDatabaseCount('alert_triages', 2);
        $this->assertTrue($first->fresh()->is_active);
        $this->assertNotEmpty($first->fresh()->session_version);
    }
}
