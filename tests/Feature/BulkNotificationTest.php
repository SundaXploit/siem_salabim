<?php

namespace Tests\Feature;

use App\Jobs\SendBulkAlertNotification;
use App\Models\Alert;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\BulkAlertNotifier;
use App\Services\ManualAlertNotifier;
use App\Services\SensitiveDataCipher;
use App\Services\TelegramService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class BulkNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_composer_preview_renders_shared_templates_per_destination_and_requires_owned_ack(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $response = $this->postJson(route('alerts.bulk-notify-preview'), ['alert_ids' => $payload['alert_ids']])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $template = collect($response->json('templates'))->firstWhere('id', $payload['template_id']);
        $this->assertSame(['Tim A', 'Tim B'], array_column($template['messages'], 'label'));
        $this->assertStringContainsString('agent 001 rule 9900001', $template['messages'][0]['message']);
        $this->assertStringNotContainsString('9900002', $template['messages'][0]['message']);
        $custom = collect($response->json('templates'))->firstWhere('name', 'Custom / Pesan Bebas');
        $this->assertSame(NotificationTemplate::CUSTOM_BODY, $custom['messages'][0]['message']);
        $single = $this->getJson(route('alerts.template', $alerts[0]))->assertOk()->json('templates');
        $this->assertSame(array_column($single, 'id'), array_column($response->json('templates'), 'id'));
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNothingPushed();
        $alerts[1]->forceFill(['status' => 'new', 'acknowledged_by_user_id' => null])->save();
        $this->postJson(route('alerts.bulk-notify-preview'), ['alert_ids' => $payload['alert_ids']])->assertUnprocessable()->assertJsonValidationErrors('alert_ids');
    }

    public function test_edited_destination_messages_are_literal_snapshots_and_survive_template_deletion(): void
    {
        [$actor, , $payload] = $this->fixture();
        $preview = $this->postJson(route('alerts.bulk-notify-preview'), ['alert_ids' => $payload['alert_ids']])->assertOk()->json('default_messages');
        $messages = array_map(fn ($item) => ['destination_key' => $item['destination_key'], 'message' => 'Manual '.$item['label'].' {agent_id}'], $preview);
        $templateId = $payload['template_id'];
        unset($payload['template_id']);
        $payload['messages'] = $messages;
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('notification_count', 2);
        $this->deleteJson(route('settings.templates.delete', $templateId))->assertOk();
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $telegram = $this->mock(TelegramService::class);
        foreach (['-1001' => 'Tim A', '-1002' => 'Tim B'] as $chat => $label) {
            $telegram->shouldReceive('sendMessage')->once()->with((string) $chat, 'Manual '.$label.' {agent_id}')->andReturn(['success' => true, 'status' => 200]);
        }
        $this->runDeliveries();
        $this->assertSame('Manual Tim A {agent_id}', NotificationLog::orderBy('id')->first()->visibleMessage($actor));
        $this->get(route('analyst-log.index'))->assertOk()->assertSee('Manual Tim A {agent_id}');
        $payload['messages'][0]['message'] = 'Changed after send';
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertConflict();
    }

    public function test_changed_destination_membership_rejects_stale_edited_messages_before_creating_operations(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $messages = $this->postJson(route('alerts.bulk-notify-preview'), ['alert_ids' => $payload['alert_ids']])->assertOk()->json('default_messages');
        unset($payload['template_id']);
        $this->sharedDestination($alerts);
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'messages' => $messages])->assertUnprocessable()->assertJsonValidationErrors('messages');
        $this->assertDatabaseCount('manual_notification_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_bulk_evidence_is_encrypted_queued_and_sent_once_then_cleaned_up(): void
    {
        Storage::fake('local');
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        $file = UploadedFile::fake()->image('bukti.png', 2, 2);
        $digest = hash_file('sha256', $file->getRealPath());
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturn(['success' => true, 'status' => 200]);
        $temporary = null;
        $telegram->shouldReceive('sendPhoto')->once()->withArgs(function ($chat, $path, $name) use ($digest, &$temporary): bool {
            $temporary = $path;

            return $chat === '-1000' && $name === 'bukti.png' && hash_file('sha256', $path) === $digest;
        })->andReturn(['success' => true, 'status' => 200]);
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'evidence' => [$file]])->assertOk();
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'evidence' => [$file]])->assertOk();
        $log = NotificationLog::firstOrFail();
        $path = $log->delivery_details['bulk_group']['files'][0]['path'];
        $encrypted = Storage::disk('local')->get($path);
        $this->assertStringStartsWith(SensitiveDataCipher::PREFIX, $encrypted);
        $this->assertSame($digest, hash('sha256', SensitiveDataCipher::decryptString($encrypted)));
        $this->runDeliveries();
        Storage::disk('local')->assertMissing($path);
        $this->assertFileDoesNotExist($temporary);
        $this->assertSame('sent', $log->fresh()->delivery_details['evidence'][0]['state']);
        $this->assertSame(['sent', 'sent'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
    }

    public function test_cancelled_bulk_evidence_is_never_sent_and_is_cleaned_up(): void
    {
        Storage::fake('local');
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'evidence' => [UploadedFile::fake()->image('bukti.png')]])->assertOk();
        $log = NotificationLog::firstOrFail();
        $path = $log->delivery_details['bulk_group']['files'][0]['path'];
        $alerts[0]->update(['status' => 'ignored']);
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage', 'sendPhoto');
        $this->runDeliveries();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('cancelled', $log->fresh()->delivery_state);
        $this->assertSame('not_sent', $log->fresh()->delivery_details['evidence'][0]['state']);
    }

    public function test_corrupt_bulk_evidence_fails_closed_without_resending_the_text(): void
    {
        Storage::fake('local');
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'evidence' => [UploadedFile::fake()->image('bukti.png')]])->assertOk();
        $log = NotificationLog::firstOrFail();
        $path = $log->delivery_details['bulk_group']['files'][0]['path'];
        Storage::disk('local')->put($path, 'tampered evidence');
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldNotReceive('sendPhoto');
        $this->runDeliveries();
        $this->assertSame('partial', $log->fresh()->delivery_state);
        $this->assertSame('failed', $log->fresh()->delivery_details['evidence'][0]['state']);
        Storage::disk('local')->assertMissing($path);
    }

    private function fixture(): array
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $actor = User::factory()->create(['role' => 'admin']);
        $alerts = collect(['001', '002'])->map(fn ($id) => $this->assignAckOwner(Alert::create([
            'wazuh_alert_id' => 'bulk-notify-'.$id, 'agent_name' => 'same-name', 'rule_id' => '9900'.$id,
            'rule_level' => 14, 'status' => 'new', 'first_seen_at' => now(), 'raw_data' => ['agent' => ['id' => $id]],
        ]), $actor));
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'Tim A', 'chat_id' => '-1001', 'agent_keys' => [$alerts[0]->agent_scope_key]],
            ['label' => 'Tim B', 'chat_id' => '-1002', 'agent_keys' => [$alerts[1]->agent_scope_key]],
        ]));
        $template = NotificationTemplate::create(['name' => 'Bulk fixture', 'is_active' => true,
            'body' => '<b>Alert {alert_id}</b> agent {agent_id} rule {rule_id}']);
        $this->actingAs($actor);

        return [$actor, $alerts, ['operation_id' => (string) Str::uuid(), 'alert_ids' => $alerts->pluck('id')->all(), 'template_id' => $template->id]];
    }

    private function runDeliveries(): void
    {
        $notifier = app(BulkAlertNotifier::class);
        foreach (Queue::pushed(SendBulkAlertNotification::class) as $job) {
            $job->handle($notifier);
            $job->handle($notifier);
        }
    }

    private function sharedDestination($alerts): void
    {
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'SOC gabungan', 'chat_id' => '-1000', 'agent_keys' => $alerts->pluck('agent_scope_key')->all()],
        ]));
    }

    public function test_multiple_alerts_to_the_same_chat_are_one_combined_message_and_keep_per_alert_audits(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('-1000', '<b>Alert '.$alerts->pluck('id')->implode(', ').'</b> agent 001, 002 rule 9900001, 9900002')
            ->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldNotReceive('sendPhoto');
        foreach ([$payload['alert_ids'], array_reverse($payload['alert_ids'])] as $ids) {
            $this->postJson(route('alerts.bulk-notify'), [...$payload, 'alert_ids' => $ids])->assertOk()
                ->assertJsonPath('notification_count', 1)->assertJsonPath('pending_deliveries', 2);
        }
        Queue::assertPushed(SendBulkAlertNotification::class, 1);
        $this->runDeliveries();
        $this->assertDatabaseCount('manual_notification_operations', 2);
        $this->assertDatabaseCount('alert_triages', 2);
        $this->assertSame(['sent', 'sent'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('notification_count', 0);
    }

    public function test_default_summary_and_custom_aggregate_variables_describe_the_whole_group(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        unset($payload['template_id']);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('notification_count', 1);
        $summary = NotificationLog::first()->delivery_details['bulk_group']['message'];
        $this->assertStringContainsString("<b>Ringkasan triage massal</b>\nAlert: 2\nID: ".$alerts->pluck('id')->implode(', '), $summary);
        $this->assertStringContainsString('same-name (wazuh-primary/001), same-name (wazuh-primary/002)', $summary);
        $this->assertStringContainsString('Level maksimum: 14', $summary);
        $payload['operation_id'] = (string) Str::uuid();
        $payload['custom_template'] = '<b>{alert_count} alert / {agent_count} agent</b> ID: {alert_ids}; agents: {agent_ids}; rules: {rule_ids}; max: {max_level}'."\n{alerts}";
        $alerts[0]->update(['rule_description' => '<script>untrusted</script>']);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('notification_count', 1);
        $custom = NotificationLog::latest('id')->first()->delivery_details['bulk_group']['message'];
        $this->assertStringContainsString('<b>2 alert / 2 agent</b>', $custom);
        $this->assertStringContainsString('agents: 001, 002; rules: 9900001, 9900002; max: 14', $custom);
        $this->assertStringContainsString('&lt;script&gt;untrusted&lt;/script&gt;', $custom);
        $this->assertStringContainsString('#'.$alerts[1]->id.' | same-name (wazuh-primary/002)', $custom);
    }

    public function test_custom_text_without_variables_is_sent_once_without_repeating_the_body(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        unset($payload['template_id']);
        $payload['custom_template'] = '<b>Investigasi terkoordinasi oleh SOC.</b>';
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->once()->with('-1000', $payload['custom_template'])
            ->andReturn(['success' => true, 'status' => 200]);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $this->runDeliveries();
    }

    public function test_shared_and_private_destinations_receive_one_summary_containing_only_their_mapped_agents(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $destinations = Configuration::getTelegramDestinations();
        $destinations[] = ['label' => 'SOC', 'chat_id' => '-1000', 'agent_keys' => $alerts->pluck('agent_scope_key')->all()];
        Configuration::set('telegram_destinations', json_encode($destinations));
        unset($payload['template_id']);
        $payload['custom_template'] = '{alert_count} alert; agent {agent_ids}';
        $telegram = $this->mock(TelegramService::class);
        foreach (['-1000' => '2 alert; agent 001, 002', '-1001' => '1 alert; agent 001', '-1002' => '1 alert; agent 002'] as $chat => $message) {
            $telegram->shouldReceive('sendMessage')->once()->with((string) $chat, $message)->andReturn(['success' => true, 'status' => 200]);
        }
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('notification_count', 3);
        Queue::assertPushed(SendBulkAlertNotification::class, 3);
        $this->runDeliveries();
        $this->assertDatabaseCount('notification_logs', 4);
        $this->assertDatabaseCount('alert_triages', 4);
    }

    public static function groupInvalidations(): array
    {
        return ['ACK changed' => ['owner'], 'route removed' => ['route'], 'access removed' => ['access'], 'member purged' => ['purge']];
    }

    #[DataProvider('groupInvalidations')]
    public function test_one_invalid_member_cancels_the_entire_pending_summary(string $change): void
    {
        [$actor, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage');
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        if ($change === 'owner') {
            $this->assignAckOwner($alerts[1], User::factory()->create(['role' => 'admin']));
        } elseif ($change === 'route') {
            $this->sharedDestination(collect([$alerts[0]]));
        } elseif ($change === 'access') {
            $actor->update(['role' => 'analyst', 'privileges' => ['alerts.notify'],
                'allowed_agents' => [['key' => $alerts[0]->agent_scope_key, 'id' => '001', 'name' => 'same-name', 'source_cluster' => 'wazuh-primary']]]);
        } else {
            NotificationLog::where('alert_id', $alerts[1]->id)->delete();
        }
        $this->runDeliveries();
        $this->assertSame(['cancelled'], NotificationLog::distinct()->pluck('delivery_state')->all());
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_too_long_combined_message_rejects_the_entire_batch_without_splitting_it(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        foreach ($alerts as $alert) {
            $alert->update(['rule_description' => str_repeat('x', 2200)]);
        }
        unset($payload['template_id']);
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'custom_template' => '{alerts}'])->assertUnprocessable()->assertJsonValidationErrors('template_id');
        $this->assertDatabaseCount('manual_notification_operations', 0);
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_shared_timeout_marks_all_members_uncertain_and_retries_never_resend(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->once()
            ->andThrow(new \RuntimeException('https://private-token-must-not-leak'));
        $this->runDeliveries();
        $this->assertSame(['unconfirmed', 'unconfirmed'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
        $this->assertStringNotContainsString('private-token', json_encode(NotificationLog::first()->delivery_details));
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('notification_count', 0);
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_per_alert_history_and_status_do_not_expose_other_agents_in_the_summary(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->sharedDestination($alerts);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $first = NotificationLog::where('alert_id', $alerts[0]->id)->firstOrFail();
        $this->assertStringContainsString('9900002', $first->delivery_details['bulk_group']['message']);
        $this->assertStringNotContainsString('9900002', $first->message);
        $this->assertStringNotContainsString('9900002', json_encode($first->toArray()));
        $this->assertArrayNotHasKey('bulk_group', app(ManualAlertNotifier::class)->result($first->manual_operation_id)['deliveries'][0]['details']);
        $restricted = User::factory()->forAgent('001')->create();
        $this->assertStringNotContainsString('9900002', $first->visibleMessage($restricted));
        $this->actingAs($restricted)->get(route('analyst-log.index'))->assertOk()->assertSee('9900001')->assertDontSee('9900002');
        $this->get(route('alerts.show', $alerts[0]))->assertOk()->assertDontSee('9900002');
        // The legacy per-alert worker cannot accidentally send one member of a summary.
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage');
        app(ManualAlertNotifier::class)->deliverQueued($first->id);
        $this->assertSame('pending', $first->fresh()->delivery_state);
    }

    public function test_retrying_an_old_per_alert_operation_cannot_create_a_second_combined_send(): void
    {
        [$actor, $alerts, $payload] = $this->fixture();
        $operationId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'siem-bulk-notify:'.$actor->id.':'.$payload['operation_id'].':'.$alerts[0]->id)->toString();
        app(ManualAlertNotifier::class)->prepare($alerts[0], $actor, $operationId, 'Old per-alert notification');
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertConflict();
        $this->assertDatabaseCount('notification_logs', 1);
        $this->assertDatabaseCount('manual_notification_operations', 1);
        Queue::assertNothingPushed();
    }

    public function test_owned_ack_batch_is_durable_queued_and_routed_by_agent_id_without_duplicate_sends(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('-1001', '<b>Alert '.$alerts[0]->id.'</b> agent 001 rule 9900001')->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldReceive('sendMessage')->once()->with('-1002', '<b>Alert '.$alerts[1]->id.'</b> agent 002 rule 9900002')->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldNotReceive('sendPhoto');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson(route('alerts.bulk-notify'), [...$payload, 'alert_ids' => $attempt % 2 ? array_reverse($payload['alert_ids']) : $payload['alert_ids'], 'chat_id' => '-9999'])
                ->assertOk()->assertJsonPath('success', true)->assertJsonPath('pending_deliveries', 2);
        }
        $telegram->shouldNotHaveReceived('sendMessage');
        Queue::assertPushed(SendBulkAlertNotification::class, 2);
        $this->assertDatabaseCount('manual_notification_operations', 2);
        $this->assertSame(['pending', 'pending'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
        $this->runDeliveries();
        $this->assertSame(['sent', 'sent'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
        $this->assertDatabaseCount('alert_triages', 2);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('pending_deliveries', 0);
        $this->assertDatabaseCount('notification_logs', 2);
    }

    public function test_custom_template_renders_per_alert_and_escapes_untrusted_values_without_saving_it(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $alerts[0]->update(['agent_name' => '<b>fake-admin</b>']);
        $before = NotificationTemplate::count();
        unset($payload['template_id']);
        $payload['custom_template'] = '<b>Custom #{alert_id}</b> {agent_name} (ID {agent_id}) [{source_cluster}]';
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $logs = NotificationLog::orderBy('id')->get();
        $this->assertStringContainsString('<b>Custom #'.$alerts[0]->id.'</b> &lt;b&gt;fake-admin&lt;/b&gt; (ID 001) [wazuh-primary]', $logs[0]->message);
        $this->assertStringContainsString('same-name (ID 002)', $logs[1]->message);
        $this->assertSame($before, NotificationTemplate::count());
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'custom_template' => 'Changed custom body'])->assertConflict();
        $this->assertDatabaseCount('notification_logs', 2);
    }

    public function test_new_and_mixed_owner_batches_are_rejected_before_any_delivery_intent_is_created(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage');
        $alerts[0]->forceFill(['status' => 'new', 'acknowledged_by_user_id' => null])->save();
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertUnprocessable()->assertJsonValidationErrors('alert_ids');
        $other = User::factory()->create(['role' => 'admin']);
        $this->assignAckOwner($alerts[0]->fresh(), $other);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertUnprocessable()->assertJsonValidationErrors('alert_ids');
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('manual_notification_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_missing_route_rolls_back_all_prepared_alerts(): void
    {
        [, , $payload] = $this->fixture();
        $destinations = Configuration::getTelegramDestinations();
        Configuration::set('telegram_destinations', json_encode([$destinations[0]]));
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('manual_notification_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_worker_cancels_pending_delivery_if_ack_owner_changes_before_it_runs(): void
    {
        [, $alerts, $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage');
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $other = User::factory()->create(['role' => 'admin']);
        foreach ($alerts as $alert) {
            $this->assignAckOwner($alert, $other);
        }
        $this->runDeliveries();
        $this->assertSame(['cancelled', 'cancelled'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_worker_cancels_pending_deliveries_for_a_deactivated_user(): void
    {
        [$actor, , $payload] = $this->fixture();
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage');
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $actor->forceFill(['is_active' => false])->save();
        $this->runDeliveries();
        $this->assertSame(['cancelled', 'cancelled'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_retrying_a_delivery_after_timeout_never_resends_or_overwrites_its_uncertain_state(): void
    {
        [, , $payload] = $this->fixture();
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->twice()
            ->andReturn(['success' => false, 'status' => 0, 'delivery_unknown' => true, 'error' => 'Lost response']);
        $this->runDeliveries();
        $this->assertSame(['unconfirmed', 'unconfirmed'], NotificationLog::orderBy('id')->pluck('delivery_state')->all());
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('pending_deliveries', 0);
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_bulk_notifications_require_an_async_queue_and_valid_exclusive_template_input(): void
    {
        [, , $payload] = $this->fixture();
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'custom_template' => 'Custom {alert_id}'])->assertUnprocessable()->assertJsonValidationErrors('template_id');
        $this->postJson(route('alerts.bulk-notify'), [...$payload, 'alert_ids' => range(1, 101)])->assertUnprocessable()->assertJsonValidationErrors('alert_ids');
        config(['queue.default' => 'sync']);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertUnprocessable()->assertJsonValidationErrors('alert_ids');
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_failure_releases_the_unique_lock_and_preserves_intents_for_a_safe_retry(): void
    {
        [, , $payload] = $this->fixture();
        $dispatcher = app(Dispatcher::class);
        $failing = \Mockery::mock(Dispatcher::class);
        $failing->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('private queue connection details'));
        $this->app->instance(Dispatcher::class, $failing);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertStatus(503)->assertJsonPath('success', false)->assertDontSee('private queue');
        $this->assertDatabaseCount('notification_logs', 2);
        $this->app->instance(Dispatcher::class, $dispatcher);
        $this->postJson(route('alerts.bulk-notify'), $payload)->assertOk()->assertJsonPath('pending_deliveries', 2);
        Queue::assertPushed(SendBulkAlertNotification::class, 2);
        $this->assertDatabaseCount('manual_notification_operations', 2);
    }
}
