<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\DashboardInsight;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\OpenSearchService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalystAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function event(string $id, string $title, string $cluster = 'wazuh-primary', ?string $agentId = '007'): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => $id, 'source_cluster' => $cluster, 'rule_id' => '40101', 'rule_level' => 14,
            'rule_description' => $title, 'agent_name' => 'Same agent name', 'status' => 'new', 'first_seen_at' => now(),
            'raw_data' => ['id' => $id, 'agent' => ['id' => $agentId, 'name' => 'Same agent name'], 'rule' => ['description' => $title]],
        ]);
    }

    private function analyst(array $privileges = []): User
    {
        return User::factory()->create([
            'role' => 'analyst', 'privileges' => $privileges,
            'allowed_agents' => [['key' => Alert::agentScopeKey('wazuh-primary', '007'), 'id' => '007', 'name' => 'OPD A', 'source_cluster' => 'wazuh-primary']],
        ]);
    }

    public function test_lists_counts_dashboard_and_history_never_disclose_other_agents_or_clusters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $allowed = $this->event('allowed', 'OPD A event');
        $other = $this->event('other', 'PRIVATE OPD B', agentId: '008');
        $cluster = $this->event('other-cluster', 'PRIVATE CLUSTER', 'another-wazuh');
        $legacy = $this->event('legacy', 'PRIVATE UNKNOWN AGENT', agentId: null);
        foreach ([$allowed, $other, $cluster, $legacy] as $alert) {
            AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $admin->id, 'action' => 'acknowledge', 'reason' => $alert->rule_description, 'created_at' => now()]);
            NotificationLog::create(['alert_id' => $alert->id, 'user_id' => $admin->id, 'chat_id' => '-1001', 'message' => $alert->rule_description, 'response_status' => 200, 'sent_at' => now()]);
        }
        // Warm the global cache first to catch accidental cross-tenant cache reuse.
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertViewHas('dashboard', fn ($d) => $d['metrics']['total'] === 4);
        $analyst = $this->analyst();
        $this->actingAs($analyst);
        foreach (['/live-alerts', '/history/alerts'] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSame([$allowed->id], $response->viewData('alerts')->modelKeys());
            $response->assertDontSee('PRIVATE OPD B')->assertDontSee('PRIVATE CLUSTER')->assertDontSee('PRIVATE UNKNOWN AGENT');
        }
        $this->getJson('/api/alerts/new-count')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/alerts/latest')->assertOk()->assertDontSee('PRIVATE OPD B')->assertDontSee('PRIVATE CLUSTER');
        $dashboard = $this->get('/dashboard')->assertOk()->viewData('dashboard');
        $this->assertSame(1, $dashboard['metrics']['total']);
        $this->assertSame(1, $dashboard['soc_activity']['triage_events']['total_actions']);
        $this->assertSame(1, $dashboard['soc_activity']['notification_delivery']['attempts']);
        $history = $this->get('/analyst-log')->assertOk()->viewData('logs');
        $this->assertSame([$allowed->id], $history->pluck('alert_id')->unique()->values()->all());
        $leaderboard = $this->getJson('/api/leaderboard')->assertOk()->json('by_total');
        $this->assertSame([$analyst->id], array_column($leaderboard, 'user_id'));
    }

    public function test_direct_urls_and_every_write_endpoint_reject_a_foreign_agent_even_with_all_action_privileges(): void
    {
        $user = $this->analyst(array_keys(User::PRIVILEGES));
        $this->actingAs($user);
        foreach ([$this->event('opd-b', 'Private', agentId: '008'), $this->event('cluster-b', 'Private', 'another-wazuh'), $this->event('unknown', 'Private', agentId: null)] as $alert) {
            $this->get(route('alerts.show', $alert))->assertNotFound();
            $this->getJson(route('alerts.template', $alert))->assertNotFound();
            foreach (['alerts.acknowledge' => [], 'alerts.ignore' => ['reason' => 'Valid reason'], 'alerts.ai-analysis' => [], 'alerts.notify' => ['message' => 'A valid message']] as $route => $body) {
                $this->postJson(route($route, $alert), $body)->assertNotFound();
            }
            $this->assertSame('new', $alert->fresh()->status);
        }
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('notification_logs', 0);
        Http::assertNothingSent();
    }

    public function test_read_only_analyst_can_view_allowed_alerts_but_cannot_act_or_generate_ai(): void
    {
        $alert = $this->event('allowed', 'Allowed');
        $user = $this->analyst();
        $this->actingAs($user)->get(route('alerts.show', $alert))->assertOk()->assertSee('hanya untuk membaca');
        foreach (['alerts.acknowledge' => [], 'alerts.ignore' => ['reason' => 'Valid reason'], 'alerts.ai-analysis' => [], 'alerts.notify' => ['message' => 'A valid message']] as $route => $body) {
            $this->postJson(route($route, $alert), $body)->assertForbidden();
        }
        $this->postJson(route('dashboard.insight.refresh'))->assertForbidden();
        $this->postJson(route('alerts.bulk-triage'), ['alert_ids' => [$alert->id], 'action' => 'acknowledge'])->assertForbidden();
        $this->get(route('alerts.bulk-detail', ['alert_ids' => [$alert->id]]))->assertOk();
        $this->assertSame('new', $alert->fresh()->status);
    }

    public function test_bulk_operations_reject_mixed_access_before_any_change_or_ai_request(): void
    {
        $allowed = $this->event('allowed', 'Allowed');
        $foreign = $this->event('foreign', 'Private', agentId: '008');
        $this->actingAs($this->analyst(array_keys(User::PRIVILEGES)));
        $ids = [$allowed->id, $foreign->id];
        $this->getJson(route('alerts.bulk-detail', ['alert_ids' => $ids]))->assertUnprocessable();
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertUnprocessable();
        $this->postJson(route('alerts.bulk-triage'), ['alert_ids' => $ids, 'action' => 'acknowledge'])->assertUnprocessable();
        $this->assertSame('new', $allowed->fresh()->status);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->post(route('alerts.acknowledge', $allowed))->assertRedirect();
        $this->assertSame('acknowledged', $allowed->fresh()->status);
    }

    public function test_no_agent_grants_fail_closed_and_revocation_changes_cached_counts_immediately(): void
    {
        $alert = $this->event('allowed', 'Allowed');
        $user = $this->analyst();
        $this->actingAs($user)->getJson('/api/alerts/new-count')->assertJsonPath('count', 1);
        $this->get('/dashboard')->assertOk()->assertViewHas('dashboard', fn ($d) => $d['metrics']['total'] === 1);
        $user->update(['allowed_agents' => []]);
        $this->getJson('/api/alerts/latest')->assertUnauthorized();
        $this->actingAs($user->fresh());
        $this->getJson('/api/alerts/new-count')->assertJsonPath('count', 0);
        $this->get('/dashboard')->assertOk()->assertViewHas('dashboard', fn ($d) => $d['metrics']['total'] === 0);
        $this->get(route('alerts.show', $alert))->assertNotFound();
        $this->get('/live-alerts')->assertOk()->assertViewHas('alerts', fn ($rows) => $rows->total() === 0);
        $this->get('/history/activity')->assertForbidden();
        $this->get('/settings')->assertForbidden();
    }

    public function test_admin_can_select_verified_agent_ids_and_privileges_and_clear_them_with_audit_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAgents')->twice()->andReturn(['agents' => [
            ['id' => '007', 'name' => 'Same name'], ['id' => '008', 'name' => 'Same name'],
        ]]);
        $options = $this->getJson(route('settings.users.agents'))->assertOk()->json('agents');
        $this->assertNotSame($options[0]['key'], $options[1]['key']);
        $this->post(route('settings.users.create'), [
            'name' => 'OPD A analyst', 'email' => 'opd-a@example.test', 'role' => 'analyst',
            'password' => 'private-password', 'password_confirmation' => 'private-password',
            '_access_settings' => '1', 'agent_keys' => [$options[0]['key']], 'privileges' => ['alerts.acknowledge'],
        ])->assertSessionHasNoErrors();
        $analyst = User::where('email', 'opd-a@example.test')->sole();
        $this->assertSame([$options[0]['key']], $analyst->agentAccessKeys());
        $this->assertTrue($analyst->hasPrivilege('alerts.acknowledge'));
        $this->assertFalse($analyst->hasPrivilege('alerts.ignore'));
        $this->patch(route('settings.users.update', $analyst), ['name' => $analyst->name, 'role' => 'analyst', '_access_settings' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([], $analyst->fresh()->agentAccessKeys());
        $this->assertSame([], $analyst->fresh()->privileges);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_updated', 'actor_id' => $admin->id, 'result' => 'success']);
        $this->assertStringNotContainsString('private-password', ActivityLog::all()->toJson());
    }

    public function test_analyst_cannot_self_assign_privileges_or_agent_grants_through_profile_or_settings(): void
    {
        $user = $this->analyst();
        $this->actingAs($user)->patch('/profile', [
            'name' => 'Own name', 'email' => $user->email, 'role' => 'admin',
            'allowed_agents' => [['key' => 'forged']], 'privileges' => array_keys(User::PRIVILEGES),
        ])->assertSessionHasNoErrors();
        $this->assertSame('analyst', $user->fresh()->role);
        $this->assertSame([], $user->fresh()->privileges);
        $this->patchJson(route('settings.users.update', $user), ['role' => 'admin'])->assertForbidden();
        $this->getJson(route('settings.users.agents'))->assertForbidden();
    }

    public function test_dashboard_insights_are_partitioned_by_agent_access_and_admin_retains_full_visibility(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->event('allowed', 'Allowed');
        $this->event('other', 'Private', agentId: '008');
        $summary = str_repeat('PRIVATE GLOBAL SOC FINDING. Review incident evidence before taking containment actions. ', 12);
        DashboardInsight::create([
            'period_key' => now('Asia/Jakarta')->format('Y-m'), 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'status' => 'completed', 'summary' => $summary, 'source_snapshot' => ['metrics' => ['total' => 2]],
            'source_fingerprint' => hash('sha256', $summary), 'generated_at' => now(), 'access_scope' => 'all',
        ]);
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('PRIVATE GLOBAL SOC FINDING');
        $this->actingAs($this->analyst())->get('/dashboard')->assertOk()->assertDontSee('PRIVATE GLOBAL SOC FINDING');
        $this->actingAs($admin)->get('/live-alerts')->assertOk()->assertViewHas('alerts', fn ($rows) => $rows->total() === 2);
    }

    public function test_dashboard_ai_receives_only_authorised_data_and_cannot_reuse_global_results_after_revocation(): void
    {
        $this->fakeIntegrationDns();
        $admin = User::factory()->create(['role' => 'admin']);
        $analyst = $this->analyst(['alerts.analyze']);
        $allowed = $this->event('allowed', 'Allowed authentication pattern');
        $foreign = $this->event('foreign', 'PRIVATE FOREIGN RULE', agentId: '008');
        $this->event('foreign-cluster', 'PRIVATE FOREIGN CLUSTER RULE', 'another-wazuh');
        foreach ([$allowed, $foreign] as $alert) {
            AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $admin->id, 'action' => 'acknowledge', 'created_at' => now()]);
            NotificationLog::create(['alert_id' => $alert->id, 'user_id' => $admin->id, 'chat_id' => '-1001', 'message' => $alert->rule_description, 'response_status' => 200, 'sent_at' => now()]);
        }
        // Exercise the actual provider payload, with the admin cache already populated.
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertViewHas('dashboard', fn ($d) => $d['metrics']['total'] === 3);
        config([
            'services.ai.api_key' => 'test-key', 'services.ai.base_url' => 'https://ai.example.test/v1',
            'services.ai.model' => 'test-model', 'services.ai.api_format' => 'responses',
        ]);
        $inputs = [];
        $limitedSummary = str_repeat('KESIMPULAN TERBATAS OPD A. Validasi bukti autentikasi serta tindak lanjut terhadap alert yang tersedia. ', 3);
        $globalSummary = str_repeat('KESIMPULAN GLOBAL SOC. Evaluasi seluruh sumber alert untuk operasi keamanan organisasi. ', 3);
        Http::fake(['https://ai.example.test/v1/responses' => function (Request $request) use (&$inputs, $limitedSummary, $globalSummary) {
            $prompt = data_get($request->data(), 'input.1.content.0.text');
            $inputs[] = json_decode(explode("\n\n", $prompt, 2)[1], true, 512, JSON_THROW_ON_ERROR);

            return Http::response(['output_text' => end($inputs)['metrics']['total'] === 1 ? $limitedSummary : $globalSummary]);
        }]);
        $this->actingAs($analyst)->postJson(route('dashboard.insight.refresh'))->assertOk()
            ->assertJsonPath('insight.summary', trim($limitedSummary));
        $input = $inputs[0];
        $this->assertSame(1, $input['metrics']['total']);
        $this->assertSame(1, $input['metrics']['triage_actions']);
        $this->assertSame(1, $input['metrics']['notification_attempts']);
        $this->assertSame(1, array_sum(array_column($input['daily_alert_trend'], 'total')));
        $this->assertSame(1, array_sum(array_column($input['top_affected_agents'], 'count')));
        $this->assertStringNotContainsString('PRIVATE FOREIGN', json_encode($input, JSON_THROW_ON_ERROR));
        $stored = DashboardInsight::where('requested_by_user_id', $analyst->id)->sole();
        $this->assertSame($analyst->alertAccessFingerprint(), $stored->access_scope);
        $this->assertSame($input, $stored->source_snapshot);
        $this->actingAs($admin)->postJson(route('dashboard.insight.refresh'))->assertOk()
            ->assertJsonPath('insight.summary', trim($globalSummary));
        $this->assertSame(3, $inputs[1]['metrics']['total']);
        $this->actingAs($analyst)->get('/dashboard')->assertOk()->assertSee('KESIMPULAN TERBATAS OPD A')->assertDontSee('KESIMPULAN GLOBAL SOC');
        $analyst->update(['allowed_agents' => []]);
        $this->getJson('/api/alerts/latest')->assertUnauthorized();
        $this->actingAs($analyst->fresh());
        $this->get('/dashboard')->assertOk()->assertViewHas('dashboard', fn ($d) => $d['metrics']['total'] === 0)
            ->assertDontSee('KESIMPULAN TERBATAS OPD A')->assertDontSee('KESIMPULAN GLOBAL SOC');
        $this->postJson(route('dashboard.insight.refresh'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_unknown_agent_keys_and_privileges_cannot_be_persisted_and_existing_grants_survive_inventory_failure(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        // MySQL normalizes JSON object key order when persisting metadata.
        $analyst = $this->analyst(['alerts.ignore'])->refresh();
        $before = $analyst->fresh()->getAttributes();
        $this->actingAs($admin);
        $service = $this->mock(OpenSearchService::class);
        $service->shouldReceive('fetchAgents')->once()->andReturn(['agents' => []]);
        $payload = ['name' => $analyst->name, 'role' => 'analyst', '_access_settings' => '1'];
        $this->patchJson(route('settings.users.update', $analyst), $payload + ['privileges' => ['settings.manage']])
            ->assertUnprocessable()->assertJsonValidationErrors('privileges.0');
        $this->patchJson(route('settings.users.update', $analyst), $payload + ['agent_keys' => [str_repeat('a', 64)]])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_keys');
        $this->assertSame($before, $analyst->fresh()->getAttributes());
        $service->shouldReceive('fetchAgents')->once()->andThrow(new \RuntimeException('private upstream credentials'));
        $this->getJson(route('settings.users.agents'))->assertStatus(502)->assertDontSee('private upstream');
        $this->patchJson(route('settings.users.update', $analyst), $payload + [
            'agent_keys' => $analyst->agentAccessKeys(), 'privileges' => ['alerts.ignore'],
        ])->assertRedirect();
        $this->assertSame($analyst->allowed_agents, $analyst->fresh()->allowed_agents);
        $this->assertSame('failed', ActivityLog::where('action', 'user_updated')->first()->result);
        $this->assertSame('success', ActivityLog::where('action', 'user_updated')->latest('id')->first()->result);
    }

    public function test_notification_privilege_does_not_grant_acknowledge_permission(): void
    {
        $user = $this->analyst(['alerts.notify']);
        $alert = $this->event('allowed', 'Allowed');
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'OPD A', 'chat_id' => '-1001', 'agent_keys' => [$alert->agent_scope_key]],
        ]));
        $this->assignAckOwner($alert, $user);
        $this->mock(TelegramService::class)->shouldReceive('sendMessage')->once()
            ->with('-1001', 'Investigasi alert diperlukan.')->andReturn(['success' => true, 'status' => 200]);
        $this->actingAs($user)->post(route('alerts.notify', $alert), ['operation_id' => (string) Str::uuid(), 'message' => 'Investigasi alert diperlukan.'])->assertRedirect();
        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->assertDatabaseHas('notification_logs', ['alert_id' => $alert->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('alert_triages', ['alert_id' => $alert->id, 'action' => 'acknowledge']);
        $this->postJson(route('alerts.acknowledge', $alert))->assertForbidden();
    }
}
