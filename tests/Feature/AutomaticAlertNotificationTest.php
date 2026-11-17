<?php

namespace Tests\Feature;

use App\Jobs\PrepareAutomaticAlertNotifications;
use App\Jobs\SendAutomaticAlertNotification;
use App\Jobs\SendBulkAlertNotification;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\PermanentAlertIgnore;
use App\Models\User;
use App\Services\AlertAiAnalysisService;
use App\Services\AutomaticAlertNotifier;
use App\Services\BulkAlertNotifier;
use App\Services\EncryptedSearchIndex;
use App\Services\OpenSearchService;
use App\Services\SensitiveDataCipher;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomaticAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['data_security.allow_legacy_plaintext' => false]);
        Queue::fake();
    }

    private function configure(): void
    {
        Configuration::set('telegram_bot_token', 'test-token');
        Configuration::set('telegram_auto_enabled', '1');
        Configuration::set('telegram_auto_above_level', 13);
        Configuration::set('telegram_auto_base_url', 'https://soc.example.test:8443/siem');
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'Developer', 'chat_id' => '-100111', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '007')]],
            ['label' => 'Developer personal', 'chat_id' => '123456', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '007')]],
            ['label' => 'Organisasi lain', 'chat_id' => '-100222', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '008')]],
        ], JSON_THROW_ON_ERROR));
    }

    private function hit(string $id, int $level = 14, string $agent = 'Agent-Demo-A', ?string $description = null): array
    {
        $source = json_decode(file_get_contents(base_path('tests/Fixtures/wazuh-pam-session.json')), true, 512, JSON_THROW_ON_ERROR);
        $source['id'] = $id;
        $source['rule']['level'] = $level;
        $source['agent']['name'] = $agent;
        $source['agent']['id'] = match ($agent) {
            'Agent-Demo-A' => '007', 'Production' => '008', default => '009'
        };
        $source['rule']['description'] = $description ?? $source['rule']['description'];

        return ['_id' => $id, '_index' => 'wazuh-alerts-4.x-2026.11.12', '_source' => $source];
    }

    private function alert(array $hit): Alert
    {
        return Alert::create(OpenSearchService::parseHit($hit))->refresh();
    }

    public function test_admin_saves_only_the_threshold_and_switch_with_the_actual_application_domain(): void
    {
        $this->configure();
        Configuration::set('telegram_auto_enabled', '0');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('https://soc.example.test:8443/settings/telegram/automatic-notifications', [
            'telegram_auto_enabled' => '1', 'telegram_auto_above_level' => '13',
            'telegram_auto_base_url' => 'https://forged.example',
        ])->assertSessionHasNoErrors()->assertSessionHas('settings_section', 'telegram');
        $this->assertSame(['enabled' => true, 'above_level' => 13, 'daily_limit' => 2, 'base_url' => 'https://soc.example.test:8443'], app(AutomaticAlertNotifier::class)->settings());
        $log = ActivityLog::where('action', 'automatic_notifications_updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['telegram_auto_enabled', 'telegram_auto_above_level'], $log->context['fields']);
        $this->assertSame('test-token', Configuration::get('telegram_bot_token'));
        $this->assertCount(3, Configuration::getTelegramDestinations());
        $this->get(route('settings.index', ['section' => 'telegram']))->assertOk()->assertSee('Notifikasi otomatis')->assertSee('name="telegram_auto_above_level"', false);
        Queue::assertPushed(PrepareAutomaticAlertNotifications::class, 1);
        Queue::assertNotPushed(SendAutomaticAlertNotification::class);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_only_admin_can_save_and_enabling_requires_a_saved_bot_and_agent_mapping(): void
    {
        $values = ['telegram_auto_enabled' => 1, 'telegram_auto_above_level' => 13];
        $this->actingAs(User::factory()->create(['role' => 'analyst']))->postJson(route('settings.telegram.automatic'), $values)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('settings.telegram.automatic'), $values)->assertUnprocessable()->assertJsonValidationErrors('telegram_auto_enabled');
        $this->configure();
        foreach ([-1, 15, '13.5', 'bad', []] as $level) {
            $this->postJson(route('settings.telegram.automatic'), ['telegram_auto_enabled' => 1, 'telegram_auto_above_level' => $level])->assertUnprocessable();
        }
        $this->assertSame(13, app(AutomaticAlertNotifier::class)->settings()['above_level']);
    }

    #[DataProvider('importModes')]
    public function test_new_imports_only_notify_strictly_higher_levels_for_the_correct_organization_and_never_replay(array $arguments): void
    {
        $this->configure();
        $old = $this->alert($this->hit('existing-high-level', 15));
        $oldRaw = $old->getRawOriginal('raw_data');
        PermanentAlertIgnore::create(['rule_id' => '99999', 'created_by_name' => 'Admin SOC']);
        $hits = [
            $this->hit('boundary-13', 13), $this->hit('new-level-14', 14), $this->hit('new-level-15', 15),
            $this->hit('unmapped', 15, 'Unmapped'), $this->hit('case-mismatch', 15, 'agent-demo-a'),
            $this->hit('excluded', 15, description: 'Permanently excluded'), $this->hit('existing-high-level', 15),
        ];
        $hits[5]['_source']['rule']['id'] = '99999';
        $method = $arguments === [] ? 'fetchIncrementalAlertBatches' : 'fetchAlertBatches';
        $this->mock(OpenSearchService::class)->shouldReceive($method)->twice()->andReturn([$hits]);
        for ($run = 0; $run < 2; $run++) {
            $this->artisan('siem:fetch-alerts', $arguments)->assertSuccessful();
        }
        $this->assertDatabaseCount('notification_logs', 4);
        $this->assertSame(['123456', '-100111'], NotificationLog::select('chat_id')->distinct()->orderByDesc('chat_id')->pluck('chat_id')->all());
        $this->assertSame([14, 14, 15, 15], NotificationLog::with('alert')->get()->map(fn ($log) => $log->alert->rule_level)->sort()->values()->all());
        $this->assertDatabaseMissing('alerts', ['source_id' => 'excluded']);
        $this->assertSame($oldRaw, $old->fresh()->getRawOriginal('raw_data'));
        $this->assertDatabaseMissing('notification_logs', ['alert_id' => $old->id]);
        Queue::assertPushed(SendAutomaticAlertNotification::class, 4);
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public static function importModes(): array
    {
        return ['incremental' => [[]], 'today' => [['--today' => true]], 'archive' => [['--all' => true]], 'reconcile' => [['--lookback-days' => 2]]];
    }

    public function test_disabled_default_and_dry_run_never_prepare_or_send_notifications(): void
    {
        $this->assertFalse(app(AutomaticAlertNotifier::class)->settings()['enabled']);
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->twice()->andReturn([[$this->hit('high-level')]]);
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertSuccessful();
        $this->configure();
        $this->artisan('siem:fetch-alerts', ['--all' => true, '--dry-run' => true])->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_delivery_contains_the_requested_fields_and_live_url_without_acknowledging_or_modifying_evidence(): void
    {
        $this->configure();
        $alert = $this->alert($this->hit('message-data', description: 'Session <opened> & "critical"'));
        $original = $alert->getRawOriginal('raw_data');
        $notifier = app(AutomaticAlertNotifier::class);
        $ids = $notifier->prepare([$alert]);
        // A worker must see a saved URL change without being restarted.
        Configuration::set('telegram_auto_base_url', 'https://new-soc.example.test:9443');
        $sent = [];
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice()->andReturnUsing(function ($chatId, $message) use (&$sent): array {
            $sent[$chatId] = $message;

            return ['success' => true, 'status' => 200];
        });
        foreach ([...$ids, ...$ids] as $id) {
            (new SendAutomaticAlertNotification($id))->handle($notifier, $telegram);
        }
        $this->assertSame(['-100111', '123456'], array_map('strval', array_keys($sent)));
        foreach ($sent as $message) {
            foreach (['Session &lt;opened&gt; &amp; &quot;critical&quot;', '40101', '14', 'Agent-Demo-A', '12/11/2026 12:22:03 WIB', 'https://new-soc.example.test:9443/alerts/'.$alert->id] as $value) {
                $this->assertStringContainsString($value, $message);
            }
            $this->assertStringNotContainsString('<opened>', $message);
        }
        $this->assertSame(2, NotificationLog::where('delivery_state', 'sent')->whereNull('user_id')->count());
        $this->assertSame('new', $alert->fresh()->status);
        $this->assertSame($original, $alert->fresh()->getRawOriginal('raw_data'));
        $this->assertDatabaseCount('alert_triages', 0);
        $this->actingAs(User::factory()->forAgent('007')->create())->get(route('analyst-log.index', ['search' => 'otomatis']))->assertOk()->assertSee('Sistem (otomatis)')->assertSee('SENT');
        $this->get(route('alerts.show', $alert))->assertOk()->assertSee('Sistem (otomatis)');
        foreach (NotificationLog::all() as $delivery) {
            $this->assertStringStartsWith(SensitiveDataCipher::PREFIX, $delivery->getRawOriginal('message'));
            $this->assertTrue(app(EncryptedSearchIndex::class)->matches('notification', $delivery->id, $alert->id, [$delivery->message]));
        }
    }

    #[DataProvider('detailViewerRoles')]
    public function test_automatic_delivery_preserves_view_analysis_and_manual_triage_for_authorized_users(string $role): void
    {
        $this->configure();
        $privileges = ['alerts.analyze', 'alerts.acknowledge', 'alerts.ignore', 'alerts.notify'];
        $user = $role === 'admin' ? User::factory()->create(['role' => 'admin'])
            : User::factory()->forAgent('007', $privileges)->create();
        $alert = $this->alert($this->hit('automatic-workflow'));
        $original = $alert->getRawOriginal('raw_data');
        $notifier = app(AutomaticAlertNotifier::class);
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->times(4)->andReturn(['success' => true, 'status' => 200]);
        foreach ($notifier->prepare([$alert]) as $id) {
            (new SendAutomaticAlertNotification($id))->handle($notifier, $telegram);
        }

        $this->assertSame('new', $alert->fresh()->status);
        $this->assertNull($alert->fresh()->acknowledged_by_user_id);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->actingAs($user)->get(route('alerts.show', $alert))->assertOk()
            ->assertSee('Sistem (otomatis)')->assertSee('Acknowledge alert')->assertSee('Analisis AI alert');
        $analysis = $this->mock(AlertAiAnalysisService::class);
        $analysis->shouldReceive('isConfigured')->once()->andReturnTrue();
        $analysis->shouldReceive('analyse')->once()->andReturn([
            'verdict' => 'suspicious', 'confidence' => 85, 'summary' => 'Investigasi setelah notifikasi otomatis.',
            'supporting_indicators' => ['Pola akses perlu diperiksa.'], 'legitimate_indicators' => [],
            'recommended_checks' => ['Periksa log sumber.'], 'limitations' => [], 'model' => 'test-model',
        ]);
        $this->postJson(route('alerts.ai-analysis', $alert))->assertOk()->assertJsonPath('success', true);
        $this->post(route('alerts.acknowledge', $alert))->assertSessionHas('success');
        $this->assertSame($user->id, $alert->fresh()->acknowledged_by_user_id);
        $this->postJson(route('alerts.notify', $alert), [
            'operation_id' => (string) Str::uuid(), 'message' => 'Tindak lanjut manual setelah investigasi.',
        ])->assertOk()->assertJsonPath('success', true);
        $this->post(route('alerts.ignore', $alert), ['reason' => 'Sudah divalidasi sebagai aktivitas resmi.'])->assertSessionHas('success');
        $this->assertSame('ignored', $alert->fresh()->status);
        $this->assertSame($original, $alert->fresh()->getRawOriginal('raw_data'));
        $this->assertSame(2, NotificationLog::whereNotNull('automatic_key')->whereNull('user_id')->count());
        $this->assertSame(2, NotificationLog::whereNull('automatic_key')->where('user_id', $user->id)->count());
        $this->get(route('alerts.show', $alert))->assertOk()->assertSee('Sistem (otomatis)');

        $this->actingAs(User::factory()->forAgent('008', $privileges)->create());
        $this->get(route('alerts.show', $alert))->assertNotFound();
        $this->postJson(route('alerts.ai-analysis', $alert))->assertNotFound();
        $this->post(route('alerts.acknowledge', $alert))->assertNotFound();
    }

    #[DataProvider('detailViewerRoles')]
    public function test_every_automatic_delivery_state_preserves_owned_bulk_ack_notification_and_ignore(string $role): void
    {
        $this->configure();
        config(['queue.default' => 'database']);
        $privileges = ['alerts.acknowledge', 'alerts.ignore', 'alerts.notify'];
        $user = $role === 'admin' ? User::factory()->create(['role' => 'admin'])
            : User::factory()->forAgent('007', $privileges)->create();
        $notifier = app(AutomaticAlertNotifier::class);
        $ids = [];
        foreach (['pending' => null, 'sending' => null, 'sent' => 200, 'failed' => 403,
            'unconfirmed' => 0, 'cancelled' => null, 'daily_limited' => null] as $state => $status) {
            $alert = $this->alert($this->hit('automatic-bulk-'.$state));
            $ids[] = $alert->id;
            // Include persisted/interrupted deliveries; none may claim the alert's ACK.
            NotificationLog::whereIn('id', $notifier->prepare([$alert]))->update([
                'delivery_state' => $state, 'response_status' => $status,
            ]);
        }
        $automaticLogs = NotificationLog::whereNotNull('automatic_key')->orderBy('id')->get()->toArray();
        $this->assertCount(14, $automaticLogs);
        $this->assertSame(7, Alert::whereIn('id', $ids)->where('status', 'new')->whereNull('acknowledged_by_user_id')->count());
        $this->assertDatabaseCount('alert_triages', 0);

        $buttonDisabled = static function ($response, string $attribute): bool {
            preg_match_all('~<button\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~s', $response->getContent(), $matches);
            $button = collect($matches[0])->first(fn ($tag) => str_contains($tag, ' '.$attribute));
            self::assertNotNull($button, 'Missing bulk action: '.$attribute);

            return preg_match('/\sdisabled(?:\s|=|>)/', $button) === 1;
        };
        $this->actingAs($user);
        $detail = $this->get(route('alerts.bulk-detail', ['alert_ids' => $ids]))->assertOk();
        $this->assertFalse($buttonDisabled($detail, 'data-bulk-detail-ack'));
        $this->assertTrue($buttonDisabled($detail, 'data-bulk-detail-ignore'));
        $this->assertTrue($buttonDisabled($detail, 'data-bulk-detail-notify'));
        $this->postJson(route('alerts.bulk-notify-preview'), ['alert_ids' => $ids])
            ->assertUnprocessable()->assertJsonValidationErrors('alert_ids');

        $this->postJson(route('alerts.bulk-triage'), ['alert_ids' => $ids, 'action' => 'acknowledge'])
            ->assertOk()->assertJsonPath('processed', 7)->assertJsonPath('owned_ids', $ids);
        $this->assertSame(7, Alert::whereIn('id', $ids)->where('status', 'acknowledged')->where('acknowledged_by_user_id', $user->id)->count());
        $detail = $this->get(route('alerts.bulk-detail', ['alert_ids' => $ids]))->assertOk();
        $this->assertFalse($buttonDisabled($detail, 'data-bulk-detail-ignore'));
        $this->assertFalse($buttonDisabled($detail, 'data-bulk-detail-notify'));

        // Even an admin must not take over an analyst's existing ACK (or vice versa).
        $other = $role === 'admin' ? User::factory()->forAgent('007', $privileges)->create()
            : User::factory()->create(['role' => 'admin']);
        $this->actingAs($other)->post(route('alerts.acknowledge', $ids[0]))->assertSessionHas('error');
        $this->post(route('alerts.ignore', $ids[0]), ['reason' => 'Percobaan pengguna lain.'])->assertForbidden();
        $this->postJson(route('alerts.notify', $ids[0]), [
            'operation_id' => (string) Str::uuid(), 'message' => 'Tidak memiliki ACK alert ini.',
        ])->assertForbidden();
        $this->postJson(route('alerts.bulk-notify-preview'), ['alert_ids' => $ids])
            ->assertUnprocessable()->assertJsonValidationErrors('alert_ids');
        $this->postJson(route('alerts.bulk-triage'), ['alert_ids' => $ids, 'action' => 'ignore', 'reason' => 'Percobaan pengguna lain.'])
            ->assertUnprocessable()->assertJsonValidationErrors('alert_ids');

        $this->actingAs($user)->postJson(route('alerts.bulk-notify-preview'), ['alert_ids' => $ids])
            ->assertOk()->assertJsonCount(2, 'default_messages');
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice()->andReturn(['success' => true, 'status' => 200]);
        $this->postJson(route('alerts.bulk-notify'), [
            'operation_id' => (string) Str::uuid(), 'alert_ids' => $ids,
            'custom_template' => 'Tindak lanjut manual untuk {alert_count} alert otomatis.',
        ])->assertOk()->assertJsonPath('notification_count', 2);
        Queue::assertPushed(SendBulkAlertNotification::class, 2);
        foreach (Queue::pushed(SendBulkAlertNotification::class) as $job) {
            $job->handle(app(BulkAlertNotifier::class));
        }
        $this->assertSame(14, NotificationLog::whereNull('automatic_key')->where('user_id', $user->id)->where('delivery_state', 'sent')->count());
        $this->assertSame(14, Alert::whereIn('id', $ids)->withCount(['triages' => fn ($query) => $query->where('action', 'notify')])->get()->sum('triages_count'));

        $this->postJson(route('alerts.bulk-triage'), [
            'alert_ids' => $ids, 'action' => 'ignore', 'reason' => 'Sudah ditinjau setelah notifikasi otomatis.',
        ])->assertOk()->assertJsonPath('processed', 7);
        $this->assertSame(7, Alert::whereIn('id', $ids)->where('status', 'ignored')->where('acknowledged_by_user_id', $user->id)->count());
        $this->assertSame($automaticLogs, NotificationLog::whereNotNull('automatic_key')->orderBy('id')->get()->toArray());
    }

    public static function detailViewerRoles(): array
    {
        return ['admin' => ['admin'], 'assigned analyst' => ['analyst']];
    }

    public function test_removed_route_or_disabled_policy_cancels_pending_delivery_and_does_not_use_legacy_chats(): void
    {
        $this->configure();
        $notifier = app(AutomaticAlertNotifier::class);
        $ids = $notifier->prepare([$this->alert($this->hit('cancel-delivery'))]);
        Configuration::set('telegram_destinations', '[]');
        Configuration::set('telegram_chat_ids', '["-100111"]');
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldNotReceive('sendMessage');
        foreach ($ids as $id) {
            (new SendAutomaticAlertNotification($id))->handle($notifier, $telegram);
        }
        $this->assertSame(2, NotificationLog::where('delivery_state', 'cancelled')->count());
        $this->configure();
        $notifier->prepare([$this->alert($this->hit('disable-delivery'))]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('settings.telegram.automatic'), ['telegram_auto_enabled' => 0, 'telegram_auto_above_level' => 13])->assertSessionHasNoErrors();
        $this->assertSame(4, NotificationLog::where('delivery_state', 'cancelled')->count());
    }

    public function test_each_destination_is_independent_and_ambiguous_delivery_is_never_automatically_repeated(): void
    {
        $this->configure();
        $notifier = app(AutomaticAlertNotifier::class);
        $alert = $this->alert($this->hit('partial-failure'));
        $ids = $notifier->prepare([$alert]);
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('-100111', \Mockery::type('string'))->andThrow(new \RuntimeException('Network result unknown'));
        $telegram->shouldReceive('sendMessage')->once()->with('123456', \Mockery::type('string'))->andReturn(['success' => true, 'status' => 200]);
        foreach ([...$ids, ...$ids] as $id) {
            (new SendAutomaticAlertNotification($id))->handle($notifier, $telegram);
        }
        $this->assertSame('unconfirmed', NotificationLog::where('chat_id', '-100111')->sole()->delivery_state);
        $this->assertSame('sent', NotificationLog::where('chat_id', '123456')->sole()->delivery_state);
        $this->actingAs(User::factory()->forAgent('007')->create())->get(route('alerts.show', $alert))->assertOk();
        $notifier->dispatchPending();
        Queue::assertNothingPushed();
    }

    public function test_scheduler_recovers_persisted_pending_intents_without_duplicating_them(): void
    {
        $this->configure();
        $notifier = app(AutomaticAlertNotifier::class);
        $alert = $this->alert($this->hit('recover-pending'));
        $ids = $notifier->prepare([$alert]);
        $this->assertSame([], $notifier->prepare([$alert]));
        $notifier->dispatchPending();
        $notifier->dispatchPending();
        Queue::assertPushed(SendAutomaticAlertNotification::class, 2);
        Queue::assertPushed(SendAutomaticAlertNotification::class, fn ($job) => $job->notificationId === $ids[0] && $job->queue === 'default');
        $this->assertDatabaseCount('notification_logs', 2);
    }

    public function test_import_rolls_back_if_delivery_intent_cannot_be_saved(): void
    {
        $this->configure();
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->once()->andReturn([[$this->hit('atomic-event')]]);
        $this->mock(AutomaticAlertNotifier::class)->shouldReceive('prepare')->once()->andThrow(new \RuntimeException('Cannot save notification intent'));
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertFailed();
        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('changedPolicies')]
    public function test_pending_jobs_recheck_policy_and_triage_before_contacting_telegram(string $change): void
    {
        $this->configure();
        $notifier = app(AutomaticAlertNotifier::class);
        $alert = $this->alert($this->hit('changed-policy'));
        $ids = $notifier->prepare([$alert]);
        match ($change) {
            'disabled' => Configuration::set('telegram_auto_enabled', '0'),
            'threshold' => Configuration::set('telegram_auto_above_level', 14),
            default => $alert->update(['status' => $change]),
        };
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldNotReceive('sendMessage');
        foreach ($ids as $id) {
            (new SendAutomaticAlertNotification($id))->handle($notifier, $telegram);
        }
        $this->assertSame(2, NotificationLog::where('delivery_state', 'cancelled')->count());
    }

    public static function changedPolicies(): array
    {
        return ['feature disabled' => ['disabled'], 'higher threshold' => ['threshold'], 'ACK already reviewed' => ['acknowledged'], 'ignored already reviewed' => ['ignored']];
    }

    public function test_message_limits_long_descriptions_without_truncating_the_detail_url_or_source_event(): void
    {
        $this->configure();
        $alert = $this->alert($this->hit('long-message', description: str_repeat('Long description <data> ', 500)));
        $message = app(AutomaticAlertNotifier::class)->message($alert, 'https://soc.example.test');
        $this->assertLessThanOrEqual(4096, mb_strlen(html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8')));
        $this->assertStringContainsString('https://soc.example.test/alerts/'.$alert->id, $message);
        $this->assertStringContainsString('Rule ID', $message);
        $this->assertSame($alert->rule_description, $alert->fresh()->raw_data['rule']['description']);
    }
}
