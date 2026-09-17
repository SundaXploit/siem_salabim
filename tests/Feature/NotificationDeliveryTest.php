<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

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
        $user = User::factory()->create(['role' => 'analyst']);
        $alert = Alert::create([
            'wazuh_alert_id' => 'notification-test-'.fake()->uuid(),
            'rule_description' => 'Notification delivery test',
            'agent_name' => 'endpoint-test',
            'rule_id' => '990001',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => now(),
            'raw_data' => ['test' => true],
        ]);

        return [$user, $alert];
    }
}
