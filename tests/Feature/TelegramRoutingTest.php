<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use Tests\TestCase;

class TelegramRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function destinations(): array
    {
        return [
            ['label' => 'Developer', 'chat_id' => '-100111', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001'), Alert::agentScopeKey('wazuh-primary', '002')]],
            ['label' => 'Developer personal', 'chat_id' => '123456', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001')]],
            ['label' => 'Organisasi B', 'chat_id' => '-100222', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '003')]],
        ];
    }

    private function alert(string $agent = 'staging'): Alert
    {
        $agentId = match ($agent) { 'staging' => '001', 'staging-api' => '002', 'production' => '003', default => '004' };
        $user = User::factory()->forAgent($agentId, ['alerts.notify', 'alerts.acknowledge'])->create();
        $this->actingAs($user);
        Configuration::set('telegram_destinations', json_encode($this->destinations()));

        return $this->assignAckOwner(Alert::create([
            'wazuh_alert_id' => fake()->uuid(), 'agent_name' => $agent,
            'rule_description' => 'Routing test', 'rule_id' => '10001', 'rule_level' => 7,
            'status' => 'new', 'first_seen_at' => now(), 'raw_data' => ['agent' => ['id' => $agentId]],
        ]), $user);
    }

    public function test_alert_and_evidence_reach_all_matching_groups_and_personal_chats_without_request_recipient_override(): void
    {
        $alert = $this->alert();
        $this->mock(TelegramService::class, function (MockInterface $service): void {
            foreach (['-100111', '123456'] as $chatId) {
                $service->shouldReceive('sendMessage')->once()->with($chatId, 'Staging notification')
                    ->andReturn(['success' => true, 'status' => 200]);
                $service->shouldReceive('sendPhoto')->once()->withArgs(fn ($actualChat, $path, $name) => $actualChat === $chatId && is_file($path) && $name === 'evidence.png')
                    ->andReturn(['success' => true, 'status' => 200]);
            }
        });

        $this->post(route('alerts.notify', $alert), [
            'operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'chat_id' => '-100222', 'agent_name' => 'production',
            'message' => 'Staging notification', 'evidence' => [UploadedFile::fake()->image('evidence.png')],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('notification_logs', 2);
        $this->assertDatabaseCount('alert_triages', 2);
        $this->assertDatabaseMissing('notification_logs', ['chat_id' => '-100222']);
        $this->assertSame('acknowledged', $alert->fresh()->status);
    }

    public function test_unmapped_agent_never_falls_back_to_legacy_chat_ids_or_other_organizations(): void
    {
        $alert = $this->alert('staging-other');
        Configuration::set('telegram_chat_ids', json_encode(['-100999']));
        $this->mock(TelegramService::class, function (MockInterface $service): void {
            $service->shouldNotReceive('sendMessage');
            $service->shouldNotReceive('sendPhoto');
        });
        $this->post(route('alerts.notify', $alert), ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'message' => 'Unmapped notification', 'chat_id' => '-100999'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertSame('acknowledged', $alert->fresh()->status);
    }

    public function test_one_failed_destination_does_not_block_others_or_acknowledge_an_incomplete_delivery(): void
    {
        $alert = $this->alert();
        $this->mock(TelegramService::class, function (MockInterface $service): void {
            $service->shouldReceive('sendMessage')->once()->with('-100111', 'Partial notification')
                ->andReturn(['success' => false, 'status' => 403, 'error' => 'Bot tidak memiliki akses.']);
            $service->shouldReceive('sendMessage')->once()->with('123456', 'Partial notification')
                ->andReturn(['success' => true, 'status' => 200]);
            $service->shouldReceive('sendPhoto')->once()->withArgs(fn ($chatId) => $chatId === '123456')
                ->andReturn(['success' => true, 'status' => 200]);
        });
        $this->post(route('alerts.notify', $alert), [
            'operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'message' => 'Partial notification', 'evidence' => [UploadedFile::fake()->image('evidence.png')],
        ])->assertRedirect()->assertSessionHas('error', fn ($message) => str_contains($message, 'Developer personal') && str_contains($message, 'Gagal: Developer'));
        $this->assertDatabaseHas('notification_logs', ['chat_id' => '-100111', 'response_status' => 403]);
        $this->assertDatabaseHas('notification_logs', ['chat_id' => '123456', 'response_status' => 200]);
        $this->assertDatabaseCount('alert_triages', 1);
        $this->assertSame('acknowledged', $alert->fresh()->status);
    }

    public function test_notification_preview_exposes_only_matching_labels_and_has_no_chat_selector(): void
    {
        $alert = $this->alert();
        $this->getJson(route('alerts.template', $alert))->assertOk()
            ->assertJsonPath('destinations', ['Developer', 'Developer personal'])
            ->assertJsonMissingPath('chat_ids')->assertDontSee('-100222')->assertDontSee('Organisasi B');
        $this->get(route('alerts.show', $alert))->assertOk()->assertDontSee('name="chat_id"', false)->assertDontSee('__siemChatIds');
        $this->get(route('alerts.index'))->assertOk()->assertDontSee('name="chat_id"', false)->assertDontSee('__siemChatIds');
    }

    public function test_agent_names_are_exact_and_routing_is_resolved_again_when_sending(): void
    {
        $alert = $this->alert();
        $this->getJson(route('alerts.template', $alert))->assertJsonCount(2, 'destinations');
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'Tujuan baru', 'chat_id' => '-100333', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001')]],
            ['label' => 'Nama berbeda', 'chat_id' => '-100444', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '004'), Alert::agentScopeKey('wazuh-primary', '002')]],
        ]));
        $this->mock(TelegramService::class, function (MockInterface $service): void {
            $service->shouldReceive('sendMessage')->once()->with('-100333', 'Updated routing')
                ->andReturn(['success' => true, 'status' => 200]);
        });
        $this->post(route('alerts.notify', $alert), ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'message' => 'Updated routing'])->assertSessionHas('success');
        $this->assertDatabaseCount('notification_logs', 1);
        $this->assertDatabaseHas('notification_logs', ['chat_id' => '-100333']);
    }
}
