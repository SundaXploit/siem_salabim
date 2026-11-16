<?php

namespace Tests\Feature;

use App\Exceptions\AiAnalysisException;
use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\AiInsightService;
use App\Services\AiProviderService;
use App\Services\AlertTriageService;
use App\Services\OpenSearchService;
use App\Services\SecurityInsightSnapshotService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function event(string $identity = 'event', string $id = '001', string $cluster = 'wazuh-primary'): Alert
    {
        return Alert::create(['wazuh_alert_id' => $identity, 'source_cluster' => $cluster,
            'agent_name' => 'Same name', 'rule_id' => '40101', 'rule_level' => 14,
            'status' => 'new', 'first_seen_at' => now(), 'raw_data' => ['agent' => ['id' => $id]],
        ]);
    }

    private function claim(Alert $alert, User $user): void
    {
        app(AlertTriageService::class)->apply([$alert->id], 'acknowledge', $user->id);
        $alert->refresh();
    }

    public function test_agent_names_never_authorize_routing_and_agent_ids_are_cluster_scoped(): void
    {
        $a = $this->event('a');
        $b = $this->event('b', '002');
        $c = $this->event('c', '001', 'another-cluster');
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'A', 'chat_id' => '-1001', 'agent_keys' => [$a->agent_scope_key]],
            ['label' => 'B', 'chat_id' => '-1002', 'agent_keys' => [$b->agent_scope_key]],
            ['label' => 'Old name mapping', 'chat_id' => '-1003', 'agents' => ['Same name']],
        ]));
        $this->assertSame(['A'], array_column(Configuration::telegramDestinationsForAgent($a->agent_scope_key), 'label'));
        $this->assertSame(['B'], array_column(Configuration::telegramDestinationsForAgent($b->agent_scope_key), 'label'));
        $this->assertSame([], Configuration::telegramDestinationsForAgent($c->agent_scope_key));
        $this->assertSame([], Configuration::telegramDestinationsForAgent('Same name'));
    }

    public function test_new_telegram_keys_must_exist_in_wazuh_before_any_settings_are_saved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAgents')->twice()->andReturn([
            'agents' => [['id' => '001', 'name' => 'Same name'], ['id' => '002', 'name' => 'Same name']],
        ]);
        Configuration::set('telegram_bot_token', 'old-token');
        $this->actingAs($admin)->postJson(route('settings.telegram'), ['telegram_bot_token' => 'new-token',
            'telegram_destinations' => [['label' => 'A', 'chat_id' => '-1001', 'agent_keys' => [str_repeat('a', 64)]]],
        ])->assertUnprocessable();
        $this->assertSame('old-token', Configuration::get('telegram_bot_token'));
        $this->postJson(route('settings.telegram'), ['telegram_bot_token' => 'new-token',
            'telegram_destinations' => [['label' => 'A', 'chat_id' => '-1001', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '002')]]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('002', Configuration::getTelegramDestinations()[0]['agent_details'][0]['id']);
    }

    public function test_first_ack_wins_and_only_the_owner_can_ignore_or_notify_even_if_other_user_is_admin(): void
    {
        $owner = User::factory()->forAgent('001', ['alerts.acknowledge', 'alerts.ignore', 'alerts.notify'])->create();
        $other = User::factory()->create(['role' => 'admin']);
        $alert = $this->event();
        $this->claim($alert, $owner);
        $second = app(AlertTriageService::class)->apply([$alert->id], 'acknowledge', $other->id);
        $this->assertSame(0, $second['processed']);
        $this->assertSame($owner->id, $alert->fresh()->acknowledged_by_user_id);
        $this->assertDatabaseCount('alert_triages', 1);
        $this->actingAs($other)->postJson(route('alerts.ignore', $alert), ['reason' => 'Attempt by another user'])->assertForbidden();
        $this->postJson(route('alerts.notify', $alert), ['message' => 'Attempt by another user'])->assertForbidden();
        $this->actingAs($owner)->post(route('alerts.ignore', $alert), ['reason' => 'Owner verified maintenance'])->assertRedirect();
        $this->assertSame('ignored', $alert->fresh()->status);
    }

    public function test_new_alert_cannot_be_ignored_or_notified_before_ack(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $alert = $this->event();
        $this->mock(TelegramService::class)->shouldNotReceive('sendMessage');
        $this->actingAs($user)->postJson(route('alerts.ignore', $alert), ['reason' => 'Unclaimed alert'])->assertForbidden();
        $this->postJson(route('alerts.notify', $alert), ['message' => 'Unclaimed alert'])->assertForbidden();
        $this->postJson(route('alerts.bulk-triage'), ['action' => 'ignore', 'alert_ids' => [$alert->id], 'reason' => 'Unclaimed alert'])
            ->assertUnprocessable();
        $this->assertSame('new', $alert->fresh()->status);
    }

    public function test_notification_never_rewrites_status_changed_during_network_request(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $alert = $this->event();
        $this->claim($alert, $user);
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'A', 'chat_id' => '-1001', 'agent_keys' => [$alert->agent_scope_key]],
        ]));
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($user, $alert) {
            app(AlertTriageService::class)->apply([$alert->id], 'ignore', $user->id, 'Owner confirmed during network call');

            return ['success' => true, 'status' => 200];
        });
        $this->actingAs($user)->post(route('alerts.notify', $alert), ['operation_id' => (string) Str::uuid(), 'message' => 'Owner incident notification'])->assertRedirect();
        $this->assertSame('ignored', $alert->fresh()->status);
        $this->assertDatabaseHas('alert_triages', ['alert_id' => $alert->id, 'action' => 'ignore']);
    }

    public function test_deactivation_preserves_evidence_releases_open_claims_and_reactivation_does_not_restore_claims(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->forAgent('001', ['alerts.acknowledge'])->create();
        $alert = $this->event();
        $this->claim($alert, $owner);
        NotificationLog::create(['alert_id' => $alert->id, 'user_id' => $owner->id, 'chat_id' => '-1001', 'message' => 'Preserve history', 'sent_at' => now()]);
        $version = $owner->session_version;
        $this->actingAs($admin)->patch(route('settings.users.status', $owner), ['is_active' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($owner->fresh()->is_active);
        $this->assertNotSame($version, $owner->fresh()->session_version);
        $this->assertDatabaseCount('alert_triages', 1);
        $this->assertDatabaseCount('notification_logs', 1);
        $this->assertSame('new', $alert->fresh()->status);
        $this->assertNull($alert->fresh()->acknowledged_by_user_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'ack_released', 'subject' => 'Alert #'.$alert->id]);
        $this->patch(route('settings.users.status', $owner), ['is_active' => 1])->assertRedirect();
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame('new', $alert->fresh()->status);
    }

    public function test_inactive_account_cannot_login(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_active' => false])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_last_active_admin_cannot_demote_self_and_other_admin_profiles_cannot_be_changed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['name' => 'Updated', 'role' => 'analyst'];
        $this->actingAs($admin)->patchJson(route('settings.users.update', $admin), $payload)->assertUnprocessable()->assertJsonValidationErrors('role');
        $other = User::factory()->create(['role' => 'admin']);
        $this->patchJson(route('settings.users.update', $other), $payload)->assertForbidden();
        $this->assertSame('admin', $other->fresh()->role);
    }

    public function test_old_session_is_rejected_after_password_reset_by_admin(): void
    {
        $victim = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->post('/login', ['email' => $victim->email, 'password' => 'password'])->assertRedirect();
        $old = $this->app['session.store']->all();
        $this->actingAs($admin)->patch(route('settings.users.update', $victim), [
            'name' => $victim->name, 'role' => 'analyst', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
        ])->assertRedirect()->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $this->withSession($old)->getJson('/api/alerts/latest')->assertUnauthorized()->assertJsonPath('session_expired', true);
    }

    public function test_polling_never_extends_fifteen_minutes_of_human_inactivity(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->travel(14)->minutes();
        $this->getJson('/api/alerts/latest')->assertOk();
        $this->travel(61)->seconds();
        $this->getJson('/api/alerts/latest')->assertUnauthorized()->assertJsonPath('session_expired', true);
    }

    public function test_activity_extends_idle_deadline_but_not_absolute_lifetime(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->travel(14)->minutes();
        $this->postJson(route('session.activity'), ['idle_ms' => 0])->assertOk();
        $this->travel(2)->minutes();
        $this->getJson('/api/alerts/latest')->assertOk();
        $this->withSession(['security.started_at' => now()->subHours(8)->subSecond()->timestamp]);
        $this->postJson(route('session.activity'), ['idle_ms' => 0])->assertUnauthorized();
    }

    public function test_session_heartbeats_do_not_consume_other_actions_rate_limit(): void
    {
        $this->app['router']->post('/test/session-rate-limit', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'auth', 'throttle:5,1']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson(route('session.activity'), ['idle_ms' => 0])->assertOk();
        }

        $this->postJson('/test/session-rate-limit')->assertOk();
        $this->postJson(route('session.activity'), ['idle_ms' => 0])->assertTooManyRequests();
    }

    public function test_other_actions_do_not_exhaust_the_session_heartbeat_budget(): void
    {
        $this->app['router']->post('/test/session-rate-limit', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'auth', 'throttle:30,1']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson('/test/session-rate-limit')->assertOk();
        }

        $this->postJson(route('session.activity'), ['idle_ms' => 0])->assertOk();
    }

    public function test_wazuh_offsets_and_local_date_bounds_match_the_same_instant(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
        $make = fn ($id, $time) => Alert::create(OpenSearchService::parseHit([
            '_id' => $id, '_index' => 'wazuh-alerts-test', '_source' => ['@timestamp' => $time, 'agent' => ['id' => '001'], 'rule' => ['id' => '1', 'level' => 14]],
        ]));
        $included = $make('included', '2026-11-14T01:00:00+07:00');
        $excluded = $make('excluded', '2026-11-14T18:00:00Z');
        $this->assertSame('2026-11-13 18:00:00', $included->getRawOriginal('first_seen_at'));
        $this->assertSame('14/11/2026 01:00', $included->fresh()->first_seen_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'));
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/history/alerts?date_from=2026-11-14&date_to=2026-11-14')->assertOk();
        $this->assertSame([$included->id], $response->viewData('alerts')->modelKeys());
        $this->assertNotContains($excluded->id, $response->viewData('alerts')->modelKeys());
    }

    public function test_failed_dashboard_generation_remains_counted_and_fourth_attempt_is_blocked(): void
    {
        $this->event();
        $ai = $this->mock(AiInsightService::class);
        $ai->shouldReceive('isConfigured')->andReturn(true);
        $ai->shouldReceive('generate')->times(3)->andThrow(new \RuntimeException('Provider failure'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('dashboard.insight.refresh'))->assertStatus(502);
        }
        $this->postJson(route('dashboard.insight.refresh'))->assertStatus(429);
    }

    public function test_source_timezone_only_applies_when_wazuh_timestamp_has_no_explicit_offset(): void
    {
        config(['services.opensearch.timezone' => 'Asia/Jakarta']);
        $hit = ['_id' => 'timestamp-only', '_index' => 'wazuh-test', '_source' => ['timestamp' => '2026-11-14 01:00:00']];
        $this->assertSame('2026-11-13 18:00:00', OpenSearchService::parseHit($hit)['first_seen_at']);
        $hit['_source']['timestamp'] = '2026-11-13T18:00:00Z';
        $this->assertSame('2026-11-13 18:00:00', OpenSearchService::parseHit($hit)['first_seen_at']);
    }

    public function test_monthly_response_metrics_use_the_applications_storage_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
        $actor = User::factory()->create(['role' => 'admin']);
        $alert = $this->event();
        foreach (['2026-10-31 23:59:59', '2026-11-01 00:00:00'] as $time) {
            AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $actor->id, 'action' => 'acknowledge', 'created_at' => $time]);
        }
        $service = app(SecurityInsightSnapshotService::class);
        $snapshot = $service->build($service->periodFromKey('2026-11'), fresh: true, viewer: $actor);
        $this->assertSame(1, $snapshot['analysis_input']['metrics']['triage_actions']);
        $this->assertSame(1, array_sum(array_column($snapshot['soc_activity']['daily_response_trend'], 'acknowledge')));
    }

    public function test_provider_budget_counts_failures_and_releases_concurrency_slots(): void
    {
        $this->fakeIntegrationDns();
        config(['services.ai.requests_per_minute' => 2, 'services.ai.max_concurrent_requests' => 1,
            'services.ai.base_url' => 'https://ai.example.test']);
        $settings = ['provider' => 'custom', 'api_format' => 'chat_completions', 'base_url' => 'https://ai.example.test', 'api_key' => 'test', 'model' => 'test'];
        Http::fake(['https://ai.example.test/*' => Http::response([], 503)]);
        for ($i = 0; $i < 3; $i++) {
            try {
                app(AiProviderService::class)->generate('system', 'prompt', 200, 5, settings: $settings);
                $this->fail('Must reject');
            } catch (AiAnalysisException $exception) {
                $this->assertStringContainsString($i < 2 ? 'menolak' : 'per menit', $exception->getMessage());
            }
        }
        Http::assertSentCount(2);
    }
}
