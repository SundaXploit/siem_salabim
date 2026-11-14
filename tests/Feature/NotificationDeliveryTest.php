<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('notificationHistoryStates')]
    public function test_detail_history_uses_the_saved_destination_label_for_every_delivery_state(?string $state, int $status, string $statusLabel): void
    {
        [$user, $alert] = $this->notificationFixture();
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'SOC Diskominfo', 'chat_id' => '-1001234567890', 'agents' => ['endpoint-test']],
        ]));
        $log = NotificationLog::create([
            'alert_id' => $alert->id, 'user_id' => $user->id, 'chat_id' => '-1001234567890',
            'message' => 'History fixture', 'response_status' => $status, 'delivery_state' => $state, 'sent_at' => now(),
        ]);

        $this->actingAs($user)->get(route('alerts.show', $alert))->assertOk()
            ->assertSee('SOC Diskominfo')->assertSee($statusLabel)->assertDontSee('-1001234567890');
        $this->assertSame('-1001234567890', $log->fresh()->chat_id);
    }

    public static function notificationHistoryStates(): array
    {
        return [
            'daily limit' => ['daily_limited', 0, 'Batas harian tercapai'],
            'sent' => ['sent', 200, 'SENT'],
            'failed' => ['failed', 500, 'FAILED (500)'],
            'legacy delivery' => [null, 200, 'SENT'],
        ];
    }

    public function test_old_history_follows_label_renames_and_does_not_expose_ids_when_a_destination_is_removed(): void
    {
        [$user, $alert] = $this->notificationFixture();
        NotificationLog::create([
            'alert_id' => $alert->id, 'user_id' => $user->id, 'chat_id' => '-1001234567890',
            'message' => 'History fixture', 'response_status' => 200, 'sent_at' => now()->subDay(),
        ]);
        $this->actingAs($user)->get(route('alerts.show', $alert))->assertOk()->assertSee('SOC');
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'SOC Diskominfo baru', 'chat_id' => '-1001234567890', 'agents' => ['endpoint-test']],
        ]));
        $this->get(route('alerts.show', $alert))->assertOk()->assertSee('SOC Diskominfo baru')->assertDontSee('-1001234567890');
        Configuration::set('telegram_destinations', '[]');
        $this->get(route('alerts.show', $alert))->assertOk()
            ->assertSee('Tujuan Telegram tidak lagi terdaftar')->assertDontSee('-1001234567890');
    }

    public function test_failed_delivery_is_logged_but_does_not_acknowledge_or_triage_the_alert(): void
    {
        [$user, $alert] = $this->notificationFixture();

        $this->mock(TelegramService::class, function (MockInterface $service): void {
            $service->shouldReceive('sendMessage')->once()->andReturn([
                'success' => false,
                'status' => 0,
                'error' => 'DNS gagal menemukan api.telegram.org.',
                'error_type' => 'dns_resolution_failed',
                'attempts' => 3,
            ]);
        });

        $this->actingAs($user)
            ->from(route('alerts.show', $alert))
            ->post(route('alerts.notify', $alert), [
                'chat_id' => ' -1001234567890 ',
                'message' => 'SOC notification test',
            ])
            ->assertRedirect(route('alerts.show', $alert))
            ->assertSessionHas('error');

        $this->assertSame('new', $alert->fresh()->status);
        $this->assertDatabaseMissing('alert_triages', [
            'alert_id' => $alert->id,
            'action' => 'notify',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'alert_id' => $alert->id,
            'chat_id' => '-1001234567890',
            'response_status' => 0,
        ]);
    }

    public function test_confirmed_delivery_is_logged_triaged_and_acknowledged(): void
    {
        [$user, $alert] = $this->notificationFixture();

        $this->mock(TelegramService::class, function (MockInterface $service): void {
            $service->shouldReceive('sendMessage')->once()->andReturn([
                'success' => true,
                'status' => 200,
                'error' => null,
                'attempts' => 1,
            ]);
        });

        $this->actingAs($user)
            ->from(route('alerts.show', $alert))
            ->post(route('alerts.notify', $alert), [
                'chat_id' => '-1001234567890',
                'message' => 'SOC notification test',
            ])
            ->assertRedirect(route('alerts.show', $alert))
            ->assertSessionHas('success');

        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->assertDatabaseHas('alert_triages', [
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'action' => 'notify',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'alert_id' => $alert->id,
            'chat_id' => '-1001234567890',
            'response_status' => 200,
        ]);
    }

    /** @return array{0: User, 1: Alert} */
    private function notificationFixture(): array
    {
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'SOC', 'chat_id' => '-1001234567890', 'agents' => ['endpoint-test']],
        ]));
        $user = User::factory()->forAgent('001', ['alerts.notify', 'alerts.acknowledge'])->create();
        $alert = Alert::create([
            'wazuh_alert_id' => 'notification-test-'.fake()->uuid(),
            'rule_description' => 'Notification delivery test',
            'agent_name' => 'endpoint-test',
            'rule_id' => '990001',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => now(),
            'raw_data' => ['agent' => ['id' => '001'], 'test' => true],
        ]);

        return [$user, $alert];
    }
}
