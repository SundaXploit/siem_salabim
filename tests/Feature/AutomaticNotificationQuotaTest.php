<?php

namespace Tests\Feature;

use App\Jobs\SendAutomaticAlertNotification;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\AutomaticAlertNotifier;
use App\Services\TelegramService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomaticNotificationQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T05:00:00Z'));
        Configuration::set('telegram_auto_enabled', '1');
        Configuration::set('telegram_bot_token', 'test-token');
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'SOC', 'chat_id' => '-100111', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001'), Alert::agentScopeKey('wazuh-primary', '002'), Alert::agentScopeKey('wazuh-primary', '003'), Alert::agentScopeKey('wazuh-secondary', '001')]],
            ['label' => 'SOC personal', 'chat_id' => '123456', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001'), Alert::agentScopeKey('wazuh-primary', '002'), Alert::agentScopeKey('wazuh-primary', '003'), Alert::agentScopeKey('wazuh-secondary', '001')]],
        ]));
    }

    private function alert(string $sourceId, string $rule = '40101', string $agent = 'Staging', string $description = 'Security alert', string $cluster = 'wazuh-primary'): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => $sourceId, 'source_cluster' => $cluster,
            'rule_id' => $rule, 'agent_name' => $agent, 'rule_description' => $description,
            'rule_level' => 14, 'status' => 'new', 'first_seen_at' => now(),
            'raw_data' => ['rule' => ['id' => $rule, 'description' => $description], 'agent' => ['id' => match ($agent) { 'Staging' => '001', 'Production' => '002', default => '003' }, 'name' => $agent], '@timestamp' => '2026-11-01T05:00:00Z'],
        ])->refresh();
    }

    private function deliver(array $ids, TelegramService $telegram): void
    {
        foreach ($ids as $id) {
            (new SendAutomaticAlertNotification($id))->handle(app(AutomaticAlertNotifier::class), $telegram);
        }
    }

    private function successfulTelegram(int $calls): TelegramService
    {
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->times($calls)->andReturn(['success' => true, 'status' => 200]);

        return $telegram;
    }

    public function test_only_one_claim_can_send_a_delivery_and_destinations_share_one_quota_slot(): void
    {
        $notifier = app(AutomaticAlertNotifier::class);
        $alert = $this->alert('direct-claim');
        $ids = $notifier->prepare([$alert]);
        $first = NotificationLog::findOrFail($ids[0]);
        $otherDestination = NotificationLog::findOrFail($ids[1]);

        $this->assertTrue($notifier->claimDelivery($first, $alert, 'First message', 1));
        $this->assertFalse($notifier->claimDelivery($first, $alert, 'Duplicate message', 1));
        $this->assertTrue($notifier->claimDelivery($otherDestination, $alert, 'Other destination', 1));
        $this->assertSame('First message', $first->fresh()->message);
        $this->assertSame(2, NotificationLog::where('delivery_state', 'sending')->count());
        $this->assertSame([$alert->source_identity_hash], json_decode(DB::table('automatic_notification_quotas')->sole()->event_keys, true));

        $limitedAlert = $this->alert('direct-claim-limited');
        $limited = NotificationLog::findOrFail($notifier->prepare([$limitedAlert])[0]);
        $this->assertFalse($notifier->claimDelivery($limited, $limitedAlert, 'Limited message', 1));
        $this->assertSame('daily_limited', $limited->fresh()->delivery_state);
        $this->assertSame([$alert->source_identity_hash], json_decode(DB::table('automatic_notification_quotas')->sole()->event_keys, true));
    }

    public function test_two_distinct_alerts_per_rule_and_agent_reach_every_destination_while_later_alerts_stay_intact(): void
    {
        $notifier = app(AutomaticAlertNotifier::class);
        $alerts = [$this->alert('first'), $this->alert('second', description: 'Different description'), $this->alert('third')];
        $original = $alerts[2]->getRawOriginal();
        $ids = $notifier->prepare($alerts);
        $telegram = $this->successfulTelegram(4);
        $this->deliver([...$ids, ...$ids], $telegram);

        $this->assertSame(4, NotificationLog::where('delivery_state', 'sent')->count());
        foreach (['-100111', '123456'] as $chatId) {
            $this->assertSame(2, NotificationLog::where('chat_id', $chatId)->where('delivery_state', 'sent')->count());
        }
        $this->assertSame(2, NotificationLog::where('delivery_state', 'daily_limited')->count());
        $this->assertSame(2, count(json_decode(DB::table('automatic_notification_quotas')->sole()->event_keys, true)));
        $this->assertSame($original, $alerts[2]->fresh()->getRawOriginal());
        $this->assertDatabaseCount('alerts', 3);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->actingAs(User::factory()->forAgent('001', ['alerts.notify'])->create())->get(route('analyst-log.index'))->assertOk()->assertSee('Batas harian tercapai');
    }

    public function test_rule_agent_case_and_source_cluster_have_independent_quotas(): void
    {
        Configuration::set('telegram_auto_daily_limit', 1);
        $alerts = [
            $this->alert('base'), $this->alert('same-combination', description: 'Another description'),
            $this->alert('different-rule', rule: '40102'), $this->alert('different-agent', agent: 'Production'),
            $this->alert('different-case', agent: 'staging'), $this->alert('different-cluster', cluster: 'wazuh-secondary'),
        ];
        $this->deliver(app(AutomaticAlertNotifier::class)->prepare($alerts), $this->successfulTelegram(10));
        $this->assertDatabaseCount('automatic_notification_quotas', 5);
        $this->assertSame(2, NotificationLog::where('delivery_state', 'daily_limited')->count());
    }

    public function test_quota_uses_the_sending_day_and_resets_at_midnight_wib_even_for_older_events_and_pending_jobs(): void
    {
        Configuration::set('telegram_auto_daily_limit', 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-12T16:59:59Z'));
        $notifier = app(AutomaticAlertNotifier::class);
        $telegram = $this->successfulTelegram(4);
        $first = $notifier->prepare([$this->alert('before-midnight')]);
        $waiting = $notifier->prepare([$this->alert('queued-before-midnight')]);
        $this->deliver($first, $telegram);
        $this->travelTo(CarbonImmutable::parse('2026-11-12T17:00:00Z'));
        $this->deliver($waiting, $telegram);
        $this->deliver($notifier->prepare([$this->alert('after-quota')]), $telegram);
        $this->deliver($first, $telegram);

        $this->assertSame(['2026-11-12', '2026-11-13'], DB::table('automatic_notification_quotas')->orderBy('quota_day')->pluck('quota_day')->all());
        $this->assertSame(2, NotificationLog::where('delivery_state', 'daily_limited')->count());
        $this->assertSame(4, NotificationLog::where('delivery_state', 'sent')->count());
    }

    public function test_an_in_flight_delivery_reserves_its_slot_before_other_workers_can_send(): void
    {
        $notifier = app(AutomaticAlertNotifier::class);
        $first = $notifier->prepare([$this->alert('in-flight')]);
        $others = $notifier->prepare([$this->alert('second-in-flight'), $this->alert('must-be-limited')]);
        $entered = false;
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->times(4)->andReturnUsing(function () use (&$entered, $others, $telegram): array {
            if (! $entered) {
                $entered = true;
                $this->assertSame(1, NotificationLog::where('delivery_state', 'sending')->count());
                $this->deliver($others, $telegram);
            }

            return ['success' => true, 'status' => 200];
        });
        $this->deliver($first, $telegram);
        $this->assertSame(2, NotificationLog::where('delivery_state', 'daily_limited')->count());
        $this->assertSame(4, NotificationLog::where('delivery_state', 'sent')->count());
    }

    public function test_cache_flush_and_purging_alert_history_cannot_reset_today_quota(): void
    {
        $notifier = app(AutomaticAlertNotifier::class);
        $telegram = $this->successfulTelegram(4);
        $this->deliver($notifier->prepare([$this->alert('first'), $this->alert('second')]), $telegram);
        DB::table('alerts')->delete();
        Cache::flush();
        $this->deliver($notifier->prepare([$this->alert('after-purge')]), $telegram);
        $this->assertSame(2, NotificationLog::where('delivery_state', 'daily_limited')->count());
        $this->assertDatabaseCount('automatic_notification_quotas', 1);
    }

    public function test_manual_notifications_remain_available_after_the_automatic_quota_is_used(): void
    {
        $notifier = app(AutomaticAlertNotifier::class);
        $telegram = $this->successfulTelegram(6);
        $this->deliver($notifier->prepare([$this->alert('first'), $this->alert('second')]), $telegram);
        $third = $this->alert('manual-escalation');
        $this->deliver($notifier->prepare([$third]), $telegram);
        $owner = User::factory()->forAgent('001', ['alerts.notify'])->create();
        $this->assignAckOwner($third, $owner);
        $this->actingAs($owner)
            ->post(route('alerts.notify', $third), ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'message' => 'Escalate repeated alert manually.'])->assertSessionHas('success');
        $this->assertSame(2, NotificationLog::whereNull('automatic_key')->count());
        $this->assertSame(2, count(json_decode(DB::table('automatic_notification_quotas')->sole()->event_keys, true)));
    }

    public function test_admin_can_set_daily_limit_and_older_clients_preserve_it_while_invalid_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $values = ['telegram_auto_enabled' => 1, 'telegram_auto_above_level' => 13];
        $this->post(route('settings.telegram.automatic'), $values + ['telegram_auto_daily_limit' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, app(AutomaticAlertNotifier::class)->settings()['daily_limit']);
        $log = ActivityLog::where('action', 'automatic_notifications_updated')->latest('id')->firstOrFail();
        $this->assertContains('telegram_auto_daily_limit', $log->context['fields']);
        $this->post(route('settings.telegram.automatic'), $values)->assertSessionHasNoErrors();
        $this->assertSame(3, app(AutomaticAlertNotifier::class)->settings()['daily_limit']);
        foreach ([0, -1, 101, '2.5', [], null] as $limit) {
            $this->postJson(route('settings.telegram.automatic'), $values + ['telegram_auto_daily_limit' => $limit])->assertUnprocessable()->assertJsonValidationErrors('telegram_auto_daily_limit');
        }
        $this->assertSame(3, app(AutomaticAlertNotifier::class)->settings()['daily_limit']);
        $this->post(route('settings.telegram.automatic'), $values + ['telegram_auto_daily_limit' => ['invalid']])->assertSessionHasErrors('telegram_auto_daily_limit');
        $this->get(route('settings.index', ['section' => 'telegram']))->assertOk();
    }

    public function test_a_limit_change_is_read_by_the_running_worker_without_clearing_used_slots(): void
    {
        Configuration::set('telegram_auto_daily_limit', 1);
        $notifier = app(AutomaticAlertNotifier::class);
        $telegram = $this->successfulTelegram(4);
        $this->deliver($notifier->prepare([$this->alert('first')]), $telegram);
        $waiting = $notifier->prepare([$this->alert('second')]);
        Configuration::set('telegram_auto_daily_limit', 2);
        $this->deliver($waiting, $telegram);
        Configuration::set('telegram_auto_daily_limit', 1);
        $this->deliver($notifier->prepare([$this->alert('third')]), $telegram);
        $this->assertSame(2, NotificationLog::where('delivery_state', 'daily_limited')->count());
        $this->assertSame(2, count(json_decode(DB::table('automatic_notification_quotas')->sole()->event_keys, true)));
    }

    public function test_cancelled_jobs_never_consume_a_daily_slot_but_uncertain_attempts_do(): void
    {
        Configuration::set('telegram_auto_daily_limit', 1);
        $notifier = app(AutomaticAlertNotifier::class);
        $cancelled = $this->alert('already-ack');
        $ids = $notifier->prepare([$cancelled]);
        $cancelled->update(['status' => 'acknowledged']);
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice()->andReturn(['success' => false, 'status' => 502, 'delivery_unknown' => true]);
        $this->deliver($ids, $telegram);
        $this->assertDatabaseCount('automatic_notification_quotas', 0);
        $this->deliver($notifier->prepare([$this->alert('uncertain')]), $telegram);
        $this->deliver($notifier->prepare([$this->alert('limited')]), $telegram);
        $this->assertSame(2, NotificationLog::where('delivery_state', 'unconfirmed')->count());
        $this->assertSame(2, NotificationLog::where('delivery_state', 'daily_limited')->count());
    }
}
