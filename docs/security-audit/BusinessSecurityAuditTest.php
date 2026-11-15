<?php

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\AiInsightService;
use App\Services\AlertTriageService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// These checks assert current risky behavior, not desired security behavior.
final class BusinessSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        // Refuse unsafe process settings before any application provider boots.
        if (getenv('APP_ENV') !== 'testing'
            || ! in_array(getenv('APP_INSTALLED'), ['true', '1'], true)
            || getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
            || ! in_array(getenv('DB_URL'), [false, ''], true)
            || is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Audit refused: uncached testing configuration and SQLite :memory: are required.');
        }
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Audit refused: only SQLite :memory: is permitted.');
        }
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function event(string $identity = 'audit-event', string $cluster = 'wazuh-primary', string $agentId = '001'): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => $identity, 'source_cluster' => $cluster,
            'agent_name' => 'shared-endpoint', 'rule_id' => '40101', 'rule_level' => 14,
            'rule_description' => 'Audit fixture only', 'status' => 'new', 'first_seen_at' => now(),
            'raw_data' => ['agent' => ['id' => $agentId, 'name' => 'shared-endpoint']],
        ]);
    }

    private function routing(array $extra = []): void
    {
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'Organization A', 'chat_id' => '-100111', 'agents' => ['shared-endpoint']],
            ...$extra,
        ]));
    }

    public function test_same_agent_name_routes_one_organizations_message_to_both_destinations(): void
    {
        $actor = User::factory()->forAgent('001', ['alerts.notify'], 'cluster-a')->create();
        $allowed = $this->event('a', 'cluster-a');
        $foreign = $this->event('b', 'cluster-b', '009');
        $this->assertTrue($actor->canAccessAlert($allowed));
        $this->assertFalse($actor->canAccessAlert($foreign));
        $this->routing([['label' => 'Organization B', 'chat_id' => '-100222', 'agents' => ['shared-endpoint']]]);
        $telegram = $this->mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('-100111', 'Organization A confidential incident')->andReturn(['success' => true, 'status' => 200]);
        $telegram->shouldReceive('sendMessage')->once()->with('-100222', 'Organization A confidential incident')->andReturn(['success' => true, 'status' => 200]);
        $this->actingAs($actor)->post(route('alerts.notify', $allowed), ['message' => 'Organization A confidential incident'])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('notification_logs', 2);
    }

    public function test_deleting_user_erases_triage_and_notification_history_but_keeps_alert(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $actor = User::factory()->forAgent('001')->create();
        $alert = $this->event();
        $alert->update(['status' => 'ignored']);
        AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $actor->id, 'action' => 'ignore', 'reason' => 'Original investigation reason', 'created_at' => now()]);
        NotificationLog::create(['alert_id' => $alert->id, 'user_id' => $actor->id, 'chat_id' => '-100111', 'message' => 'Original notification', 'response_status' => 200, 'sent_at' => now()]);
        $this->assertDatabaseCount('alert_triages', 1);
        $this->assertDatabaseCount('notification_logs', 1);
        $this->actingAs($admin)->delete(route('settings.users.delete', $actor))->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertSame('ignored', $alert->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_deleted', 'result' => 'success']);
    }

    public function test_old_authenticated_session_still_works_after_admin_changes_password(): void
    {
        $victim = User::factory()->forAgent('001')->create();
        $admin = User::factory()->create(['role' => 'admin']);
        Auth::guard('web')->login($victim);
        $oldSession = $this->app['session.store']->all();
        $this->actingAs($admin)->patch(route('settings.users.update', $victim), [
            'name' => $victim->name, 'role' => 'analyst',
            'password' => 'replacement-password-1234', 'password_confirmation' => 'replacement-password-1234',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('replacement-password-1234', $victim->fresh()->password));
        Auth::forgetGuards();
        $this->withSession($oldSession)->getJson('/api/alerts/latest')->assertOk();
        $this->assertSame($victim->id, Auth::id());
    }

    public function test_notification_overwrites_a_concurrent_ignore_with_acknowledged(): void
    {
        $actor = User::factory()->forAgent('001', ['alerts.notify', 'alerts.acknowledge'])->create();
        $other = User::factory()->create(['role' => 'admin']);
        $alert = $this->event();
        $this->routing();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($alert, $other) {
            app(AlertTriageService::class)->apply([$alert->id], 'ignore', $other->id, 'Confirmed benign during network call');
            $this->assertSame('ignored', $alert->fresh()->status);
            return ['success' => true, 'status' => 200];
        });
        $this->actingAs($actor)->post(route('alerts.notify', $alert), ['message' => 'Concurrent investigation notification'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->assertDatabaseHas('alert_triages', ['alert_id' => $alert->id, 'action' => 'ignore']);
        $this->assertDatabaseMissing('alert_triages', ['alert_id' => $alert->id, 'action' => 'acknowledge']);
    }

    public function test_only_admin_can_demote_self_leaving_no_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->actingAs($admin)->patch(route('settings.users.update', $admin), [
            'name' => $admin->name, 'role' => 'analyst', '_access_settings' => '1', 'agent_keys' => [], 'privileges' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, User::where('role', 'admin')->count());
        $this->actingAs($admin->fresh())->get('/settings')->assertForbidden();
    }

    public function test_repeated_manual_notification_posts_send_three_identical_messages(): void
    {
        $actor = User::factory()->forAgent('001', ['alerts.notify'])->create();
        $alert = $this->event();
        $this->routing();
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->times(3)
            ->with('-100111', 'Identical incident message')->andReturn(['success' => true, 'status' => 200]);
        $this->actingAs($actor);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('alerts.notify', $alert), ['message' => 'Identical incident message'])->assertRedirect()->assertSessionHas('success');
        }
        $this->assertDatabaseCount('notification_logs', 3);
    }

    public function test_dashboard_ai_failure_clears_budget_and_allows_five_calls_in_one_window(): void
    {
        $actor = User::factory()->forAgent('001', ['alerts.analyze'])->create();
        $this->event();
        $ai = $this->mock(AiInsightService::class);
        $ai->shouldReceive('isConfigured')->times(5)->andReturn(true);
        $ai->shouldReceive('generate')->times(5)->andThrow(new RuntimeException('Simulated provider failure'));
        $this->actingAs($actor);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('dashboard.insight.refresh'))->assertStatus(502);
        }
        $key = 'dashboard-insight-manual:'.$actor->id.':'.now('Asia/Jakarta')->format('Y-m');
        $this->assertSame(0, RateLimiter::attempts($key));
    }
    public function test_utc_imported_alerts_are_filtered_by_wrong_local_day_and_displayed_seven_hours_early(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
        $admin = User::factory()->create(['role' => 'admin']);
        $import = function (string $id, string $timestamp): Alert {
            return Alert::create(App\Services\OpenSearchService::parseHit([
                '_id' => $id, '_index' => 'wazuh-alerts-2026.11.14',
                '_source' => [
                    '@timestamp' => $timestamp, 'agent' => ['id' => '001', 'name' => 'shared-endpoint'],
                    'rule' => ['id' => '40101', 'level' => 14, 'description' => 'Imported audit fixture'],
                ],
            ]));
        };
        // 14 November at 01:00 WIB: should be in the requested local day.
        $early = $import('early-on-requested-local-day', '2026-11-13T18:00:00Z');
        // 15 November at 01:00 WIB: should be outside the requested local day.
        $nextDay = $import('early-on-next-local-day', '2026-11-14T18:00:00Z');
        $response = $this->actingAs($admin)->get('/history/alerts?date_from=2026-11-14&date_to=2026-11-14')->assertOk();
        $this->assertSame([$nextDay->id], $response->viewData('alerts')->modelKeys());
        $this->assertSame('13/11/2026 18:00:00', $early->fresh()->first_seen_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s'));
    }
}
