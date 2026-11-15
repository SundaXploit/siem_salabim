<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\ManualAlertNotifier;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ManualNotificationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $alert = $this->assignAckOwner(Alert::create([
            'wazuh_alert_id' => fake()->uuid(), 'agent_name' => 'Endpoint', 'rule_level' => 14,
            'status' => 'new', 'first_seen_at' => now(), 'raw_data' => ['agent' => ['id' => '001']],
        ]), $user);
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'SOC', 'chat_id' => '-1001', 'agent_keys' => [$alert->agent_scope_key]],
            ['label' => 'Tim insiden', 'chat_id' => '-1002', 'agent_keys' => [$alert->agent_scope_key]],
        ]));
        $this->actingAs($user);

        return [$user, $alert, ['operation_id' => (string) Str::uuid(), 'message' => 'Investigasi insiden diperlukan.']];
    }

    public function test_three_identical_posts_send_each_message_and_image_once_and_keep_one_audit_per_destination(): void
    {
        [, $alert, $payload] = $this->fixture();
        $payload['evidence'] = [UploadedFile::fake()->image('bukti-satu.png'), UploadedFile::fake()->image('bukti-dua.png')];
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice()->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldReceive('sendPhoto')->times(4)->andReturn(['success' => true, 'status' => 200]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()
                ->assertJsonPath('success', true)->assertJsonPath('replayed', $attempt > 0)
                ->assertJsonCount(2, 'deliveries')->assertDontSee('-1001')->assertDontSee('-1002');
        }
        $this->assertDatabaseCount('manual_notification_operations', 1);
        $this->assertDatabaseCount('notification_logs', 2);
        $this->assertDatabaseCount('alert_triages', 2);
        $this->assertSame(['sent', 'sent'], array_column(NotificationLog::first()->delivery_details['evidence'], 'state'));
        $this->get(route('alerts.show', $alert))->assertOk()->assertSee('bukti-satu.png')->assertSee($payload['operation_id']);
    }

    public function test_partial_message_and_photo_failures_are_preserved_without_resending_confirmed_parts(): void
    {
        [, $alert, $payload] = $this->fixture();
        $payload['evidence'] = [UploadedFile::fake()->image('bukti.png')];
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('-1001', $payload['message'])
            ->andReturn(['success' => false, 'status' => 403, 'error' => 'Tidak memiliki akses.']);
        $telegram->shouldReceive('sendMessage')->once()->with('-1002', $payload['message'])
            ->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldReceive('sendPhoto')->once()->with('-1002', Mockery::any(), 'bukti.png')
            ->andReturn(['success' => false, 'status' => 400, 'error' => 'Gambar ditolak.']);
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('deliveries.0.state', 'failed')->assertJsonPath('deliveries.1.state', 'partial')
            ->assertJsonPath('deliveries.0.details.evidence.0.state', 'not_sent')
            ->assertJsonPath('deliveries.1.details.message.state', 'sent');
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('alert_triages', 1);
        $this->get(route('alerts.show', $alert))->assertOk()->assertSee('Sebagian evidence gagal')->assertSee('Belum dikirim');
    }

    public function test_a_retry_while_the_first_request_is_in_flight_does_not_claim_pending_destinations(): void
    {
        [$user, $alert, $payload] = $this->fixture();
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('-1001', $payload['message'])->andReturnUsing(function () use ($user, $alert, $payload): array {
            $retry = app(ManualAlertNotifier::class)->send($alert, $user, $payload['operation_id'], $payload['message']);
            $this->assertTrue($retry['replayed']);
            $this->assertSame(['sending', 'pending'], array_column($retry['deliveries'], 'state'));
            $this->assertSame('sending', $retry['deliveries'][0]['details']['message']['state']);

            return ['success' => true, 'status' => 200];
        });
        $telegram->shouldReceive('sendMessage')->once()->with('-1002', $payload['message'])->andReturn(['success' => true, 'status' => 200]);
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseCount('alert_triages', 2);
    }

    public function test_uncertain_delivery_is_not_repeated_even_when_the_connection_response_was_lost(): void
    {
        [, $alert, $payload] = $this->fixture();
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice()->andReturn([
            'success' => false, 'status' => 0, 'delivery_unknown' => true, 'error' => 'Respons terputus.',
        ]);
        $telegram->shouldNotReceive('sendPhoto');
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('deliveries.0.state', 'unconfirmed');
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson(route('alerts.notification-status', [$alert, $payload['operation_id']]))
            ->assertOk()->assertJsonPath('deliveries.1.state', 'unconfirmed')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_uncertain_photo_and_client_exceptions_are_not_repeated_or_exposed(): void
    {
        [, $alert, $payload] = $this->fixture();
        $payload['evidence'] = [UploadedFile::fake()->image('bukti.png')];
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice()->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldReceive('sendPhoto')->twice()->andThrow(new \RuntimeException('private-bot-token-in-client-url'));
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('deliveries.0.state', 'unconfirmed')
            ->assertJsonPath('deliveries.0.details.message.state', 'sent')->assertDontSee('private-bot-token');
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('replayed', true);
    }

    public function test_operation_is_bound_to_the_original_user_alert_message_and_image_bytes(): void
    {
        [$user, $alert, $payload] = $this->fixture();
        $payload['evidence'] = [UploadedFile::fake()->image('bukti.png', 10, 10)];
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice()->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldReceive('sendPhoto')->twice()->andReturn(['success' => true, 'status' => 200]);
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk();
        $this->postJson(route('alerts.notify', $alert), [...$payload, 'message' => 'Pesan yang berbeda.'])->assertConflict();
        $this->postJson(route('alerts.notify', $alert), [...$payload, 'evidence' => [UploadedFile::fake()->image('bukti.png', 20, 20)]])->assertConflict();
        $otherAlert = $alert->replicate(['wazuh_alert_id', 'source_id', 'source_identity_hash']);
        $otherAlert->wazuh_alert_id = fake()->uuid();
        $otherAlert->save();
        $this->postJson(route('alerts.notify', $otherAlert), $payload)->assertConflict();
        $otherUser = User::factory()->create(['role' => 'admin']);
        $this->assignAckOwner($otherAlert, $otherUser);
        $this->actingAs($otherUser)->postJson(route('alerts.notify', $otherAlert), $payload)->assertConflict();
        $this->getJson(route('alerts.notification-status', [$otherAlert, $payload['operation_id']]))->assertNotFound();
        $this->assertDatabaseCount('notification_logs', 2);
    }

    public function test_replay_after_history_purge_or_routing_changes_never_creates_new_deliveries(): void
    {
        [, $alert, $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->twice()->andReturn(['success' => true, 'status' => 200]);
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk();
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'Tujuan baru', 'chat_id' => '-1003', 'agent_keys' => [$alert->agent_scope_key]],
        ]));
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonCount(2, 'deliveries');
        NotificationLog::query()->delete();
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonCount(0, 'deliveries')
            ->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('manual_notification_operations', 1);
    }

    public function test_a_removed_destination_is_cancelled_before_its_network_call(): void
    {
        [, $alert, $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->once()->with('-1001', $payload['message'])
            ->andReturnUsing(function (): array {
                Configuration::set('telegram_destinations', '[]');

                return ['success' => true, 'status' => 200];
            });
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('deliveries.1.state', 'cancelled');
        $this->assertDatabaseCount('alert_triages', 1);
    }

    public function test_request_validation_requires_an_operation_and_bounds_message_and_image_count(): void
    {
        [, $alert, $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage');
        $this->postJson(route('alerts.notify', $alert), ['message' => $payload['message']])->assertUnprocessable()->assertJsonValidationErrors('operation_id');
        $this->postJson(route('alerts.notify', $alert), [...$payload, 'operation_id' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('operation_id');
        $this->postJson(route('alerts.notify', $alert), [...$payload, 'message' => str_repeat('x', 4097)])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson(route('alerts.notify', $alert), [...$payload, 'evidence' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.png"), range(1, 6))])
            ->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->assertDatabaseCount('manual_notification_operations', 0);
    }

    public function test_more_than_six_manual_requests_per_minute_are_throttled_even_with_new_operation_ids(): void
    {
        [, $alert, $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->times(12)->andReturn(['success' => true, 'status' => 200]);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('alerts.notify', $alert), [...$payload, 'operation_id' => (string) Str::uuid()])->assertOk();
        }
        $this->postJson(route('alerts.notify', $alert), [...$payload, 'operation_id' => (string) Str::uuid()])->assertTooManyRequests();
        $this->assertDatabaseCount('manual_notification_operations', 6);
    }

    public function test_session_heartbeats_do_not_consume_the_manual_notification_rate_limit(): void
    {
        [, $alert, $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->twice()->andReturn(['success' => true, 'status' => 200]);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('session.activity'), ['idle_ms' => 0])->assertOk();
        }
        $this->postJson(route('alerts.notify', $alert), $payload)->assertOk()->assertJsonPath('success', true);
    }
}
