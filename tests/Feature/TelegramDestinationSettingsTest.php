<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\Alert;
use App\Models\User;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramDestinationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function setupAdmin(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Configuration::set('telegram_bot_token', 'saved-token');

        return [
            ['label' => 'Developer', 'chat_id' => '-100111', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001')]],
            ['label' => 'Personal', 'chat_id' => '123456', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001'), Alert::agentScopeKey('wazuh-primary', '002')]],
        ];
    }

    public function test_admin_can_save_labeled_destinations_and_route_one_agent_to_multiple_chats(): void
    {
        $destinations = $this->setupAdmin();
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAgents')->once()->andReturn(['agents' => [['id' => '001', 'name' => 'staging'], ['id' => '002', 'name' => 'new-agent']]]);
        $this->post(route('settings.telegram'), ['telegram_bot_token' => '', 'update_telegram_destinations' => 1, 'telegram_destinations' => $destinations])
            ->assertSessionHasNoErrors()->assertSessionHas('settings_section', 'telegram');
        $actual = array_map(fn ($destination) => array_intersect_key($destination, array_flip(['label', 'chat_id', 'agent_keys'])), Configuration::getTelegramDestinations());
        $this->assertSame($destinations, $actual);
        $this->assertSame(['-100111', '123456'], Configuration::getTelegramChatIds());
        $this->assertCount(2, Configuration::telegramDestinationsForAgent(Alert::agentScopeKey('wazuh-primary', '001')));
        $this->assertSame('saved-token', Configuration::get('telegram_bot_token'));
    }

    public function test_invalid_routes_cannot_change_the_token_or_existing_destinations(): void
    {
        $destinations = $this->setupAdmin();
        Configuration::set('telegram_destinations', json_encode($destinations));
        $missingAgents = $destinations;
        $missingAgents[0]['agent_keys'] = [];
        $duplicateId = $destinations;
        $duplicateId[1]['chat_id'] = '-100111';
        $invalidId = $destinations;
        $invalidId[0]['chat_id'] = 'not-an-id';
        foreach ([[$missingAgents, 'telegram_destinations.0.agent_keys'], [$duplicateId, 'telegram_destinations.1.chat_id'], [$invalidId, 'telegram_destinations.0.chat_id']] as [$routes, $field]) {
            $this->postJson(route('settings.telegram'), ['telegram_bot_token' => 'must-not-save', 'telegram_destinations' => $routes])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($destinations, Configuration::getTelegramDestinations());
            $this->assertSame('saved-token', Configuration::get('telegram_bot_token'));
        }
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->post(route('settings.telegram'), ['telegram_destinations' => $destinations])->assertForbidden();
    }

    public function test_legacy_chat_ids_remain_visible_but_cannot_receive_alerts_without_an_agent_rule(): void
    {
        $this->setupAdmin();
        Configuration::set('telegram_chat_ids', json_encode(['-100999']));
        $this->assertSame('-100999', Configuration::getTelegramDestinations()[0]['chat_id']);
        $this->assertSame([], Configuration::telegramDestinationsForAgent(Alert::agentScopeKey('wazuh-primary', '001')));
        $this->get(route('settings.index'))->assertOk()->assertSee('-100999')->assertSee('Manajemen tujuan Telegram');
    }

    public function test_removing_all_routes_does_not_restore_legacy_recipients_and_token_rotation_keeps_routes(): void
    {
        $destinations = $this->setupAdmin();
        Configuration::set('telegram_destinations', json_encode($destinations));
        Configuration::set('telegram_chat_ids', json_encode(['-100999']));
        $this->post(route('settings.telegram'), ['telegram_bot_token' => 'rotated-token'])->assertSessionHasNoErrors();
        $this->assertSame($destinations, Configuration::getTelegramDestinations());
        $this->post(route('settings.telegram'), ['update_telegram_destinations' => 1])->assertSessionHasNoErrors();
        $this->assertSame([], Configuration::getTelegramDestinations());
        $this->assertSame([], Configuration::getTelegramChatIds());
        $this->assertSame('rotated-token', Configuration::get('telegram_bot_token'));
    }

    public function test_inventory_loads_agents_without_alerts_and_settings_does_not_build_a_local_agent_list(): void
    {
        $this->setupAdmin();
        Alert::create([
            'wazuh_alert_id' => 'old-alert', 'agent_name' => 'deleted-local-agent',
            'rule_description' => 'Old alert', 'rule_id' => '10001', 'rule_level' => 7,
            'status' => 'new', 'first_seen_at' => now(),
        ]);
        $inventory = ['agents' => [['id' => '005', 'name' => 'AGENT-DEMO-10', 'status' => 'active']], 'total' => 1, 'updated_at' => '2026-11-12T03:30:00Z'];
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAgents')->once()->andReturn($inventory);
        $this->get(route('settings.index'))->assertOk()->assertDontSee('deleted-local-agent')->assertDontSee('telegram-new-agent');
        $inventory['agents'][0] += ['key' => Alert::agentScopeKey('wazuh-primary', '005'), 'source_cluster' => 'wazuh-primary'];
        $this->getJson(route('settings.telegram.agents'))->assertOk()->assertExactJson(['success' => true, ...$inventory])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_inventory_failure_keeps_saved_routes_and_does_not_return_alert_agents(): void
    {
        $routes = $this->setupAdmin();
        Configuration::set('telegram_destinations', json_encode($routes));
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAgents')->once()
            ->andThrow(new \RuntimeException('Akses ke indeks OpenSearch ditolak.'));
        $this->getJson(route('settings.telegram.agents'))->assertStatus(502)
            ->assertExactJson(['success' => false, 'error' => 'Akses ke indeks OpenSearch ditolak.']);
        $this->assertSame($routes, Configuration::getTelegramDestinations());
    }

    public function test_inventory_is_only_available_to_admins(): void
    {
        $this->mock(OpenSearchService::class)->shouldNotReceive('fetchAgents');
        $this->getJson(route('settings.telegram.agents'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->getJson(route('settings.telegram.agents'))->assertForbidden();
    }
}
