<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LiveAlertSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_rule_description_when_other_filters_are_blank(): void
    {
        Cache::put('siem_fetch_ran', true, now()->addMinute());
        $user = User::factory()->create(['role' => 'analyst']);
        $target = Alert::create([
            'wazuh_alert_id' => 'file-event-unique',
            'rule_description' => '[File creation on web directory]: New file detected',
            'agent_name' => 'endpoint-file-test',
            'rule_id' => '554001',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => now(),
            'raw_data' => ['data' => ['message' => 'created object']],
        ]);

        $response = $this->actingAs($user)
            ->get(route('alerts.index', [
                'search' => 'file',
                'status' => '',
                'level' => '',
                'date_from' => '',
                'date_to' => '',
            ]));

        $response->assertRedirect(route('alerts.index', ['search' => 'file']));

        $this->actingAs($user)
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->assertViewHas('alerts', fn ($alerts) =>
                $alerts->total() === 1
                && $alerts->first()?->is($target)
            );
    }

    public function test_search_finds_wazuh_id_raw_event_content_and_local_alert_id(): void
    {
        Cache::put('siem_fetch_ran', true, now()->addMinute());
        $user = User::factory()->create(['role' => 'analyst']);
        $target = Alert::create([
            'wazuh_alert_id' => 'wazuh-event-unique-4f91',
            'rule_description' => 'Generic process event',
            'agent_name' => 'endpoint-one',
            'rule_id' => '100100',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => now(),
            'raw_data' => [
                'data' => [
                    'commandLine' => 'powershell.exe -EncodedCommand unique-payload-marker',
                ],
            ],
        ]);
        Alert::create([
            'wazuh_alert_id' => 'unrelated-event',
            'rule_description' => 'Unrelated alert',
            'agent_name' => 'endpoint-two',
            'rule_id' => '200200',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => now()->subMinute(),
            'raw_data' => ['data' => ['message' => 'ordinary event']],
        ]);

        foreach (['wazuh-event-unique-4f91', 'EncodedCommand unique-payload-marker', (string) $target->id] as $search) {
            $this->actingAs($user)
                ->get(route('alerts.index', ['status' => 'all', 'search' => $search]))
                ->assertOk()
                ->assertViewHas('alerts', fn ($alerts) =>
                    $alerts->total() === 1
                    && $alerts->first()?->is($target)
                );
        }
    }
}
