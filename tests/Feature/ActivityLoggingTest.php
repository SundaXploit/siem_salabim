<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DataExport;
use App\Models\User;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Admin SOC', 'role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    private function configuration(): array
    {
        return ['opensearch_host' => 'https://indexer.example.test:9200', 'opensearch_username' => 'service-user', 'opensearch_password' => 'private-opensearch-password', 'opensearch_index' => 'wazuh-alerts-*', 'min_alert_level' => 5];
    }

    public function test_template_changes_on_both_routes_are_audited_with_target_status_and_safe_fields(): void
    {
        $admin = $this->admin();
        $payload = ['name' => 'Template audit', 'category' => 'SOC', 'body' => 'private-template-evidence', 'is_active' => true];
        $this->postJson('/notification-templates', $payload)->assertStatus(307)->assertRedirect(route('settings.templates.save'));
        $id = $this->postJson(route('settings.templates.save'), $payload)->assertOk()->json('template.id');
        $this->postJson(route('settings.templates.save'), [...$payload, 'id' => $id, 'is_active' => false])->assertOk();
        $this->postJson(route('settings.templates.save'), ['id' => $id, 'body' => 'short'])->assertUnprocessable();
        $this->deleteJson('/notification-templates/'.$id)->assertStatus(307)->assertRedirect(route('settings.templates.delete', $id));
        $this->deleteJson(route('settings.templates.delete', $id))->assertOk();
        $logs = ActivityLog::orderBy('id')->get();
        $this->assertSame(['notification_template_saved', 'notification_template_saved', 'notification_template_saved', 'notification_template_deleted'], $logs->pluck('action')->all());
        $this->assertSame(['success', 'success', 'failed', 'success'], $logs->pluck('result')->all());
        $this->assertSame(['Template notifikasi #'.$id], $logs->pluck('subject')->unique()->values()->all());
        $this->assertSame(false, $logs[1]->context['is_active']);
        $this->assertSame($admin->id, $logs[0]->actor_id);
        $this->assertStringNotContainsString('private-template-evidence', $logs->toJson());
        $this->get(route('history.activity', ['action' => 'notification_template_saved']))->assertOk()
            ->assertSee('Simpan template notifikasi')->assertSee('Template notifikasi #'.$id)
            ->assertSee('3 aktivitas tercatat')->assertSee('Status aktif: Nonaktif');
    }

    public function test_ssl_verification_change_is_included_in_the_activity_fields(): void
    {
        $this->admin();
        $this->postJson(route('settings.opensearch'), [...$this->configuration(), 'opensearch_verify_ssl' => false])->assertRedirect()->assertSessionHasNoErrors();
        $log = ActivityLog::where('action', 'opensearch_updated')->sole();
        $this->assertContains('opensearch_verify_ssl', $log->context['fields']);
        $this->assertSame('success', $log->result);
    }

    public function test_login_logout_and_rejected_login_are_logged_without_credentials(): void
    {
        $user = User::factory()->create(['name' => 'Analyst SOC', 'role' => 'analyst']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.45']);
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect-private-password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/logout')->assertRedirect('/');
        $this->assertDatabaseHas('activity_logs', ['action' => 'login', 'result' => 'failed', 'actor_id' => $user->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'login', 'result' => 'success', 'actor_name' => 'Analyst SOC', 'ip_address' => '203.0.113.45']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'logout', 'result' => 'success', 'actor_id' => $user->id]);
        $this->assertStringNotContainsString('incorrect-private-password', ActivityLog::all()->toJson());
        $this->assertStringNotContainsString($user->getAuthPassword(), ActivityLog::all()->toJson());
    }

    public function test_password_changes_are_audited_without_values(): void
    {
        $user = $this->admin();
        $this->put(route('password.update'), ['current_password' => 'password', 'password' => 'new-private-password', 'password_confirmation' => 'new-private-password'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'password_changed', 'actor_id' => $user->id]);
        $this->assertSame(1, ActivityLog::where('action', 'password_changed')->count());
        $logs = ActivityLog::all()->toJson();
        foreach (['new-private-password', $user->fresh()->getAuthPassword()] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    public function test_unknown_login_only_records_the_attempt_and_ip_without_submitted_identity_or_password(): void
    {
        $this->post('/login', ['email' => 'unknown-private@example.test', 'password' => 'private-attempt'])->assertSessionHasErrors('email');
        $log = ActivityLog::where('action', 'login')->firstOrFail();
        $this->assertNull($log->actor_id);
        $this->assertSame('Pengguna tidak dikenal', $log->actor_name);
        $this->assertSame('failed', $log->result);
        $this->assertStringNotContainsString('unknown-private@example.test', $log->toJson());
        $this->assertStringNotContainsString('private-attempt', $log->toJson());
    }

    public function test_settings_records_actual_outcomes_and_field_names_without_request_values(): void
    {
        $admin = $this->admin();
        $this->post(route('settings.opensearch'), $this->configuration())->assertSessionHasNoErrors();
        $log = ActivityLog::where('action', 'opensearch_updated')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('success', $log->result);
        $this->assertContains('opensearch_password', $log->context['fields']);
        $this->assertStringNotContainsString('private-opensearch-password', $log->toJson());
        $this->assertStringNotContainsString('service-user', $log->toJson());
        $this->postJson(route('settings.opensearch'), ['opensearch_host' => 'invalid', 'opensearch_password' => 'another-secret'])->assertUnprocessable();
        $this->assertSame('failed', ActivityLog::where('action', 'opensearch_updated')->latest('id')->first()->result);
        $this->mock(OpenSearchService::class)->shouldReceive('testConfiguration')->once()->andReturn(['success' => false, 'error' => 'private-response-token']);
        $this->postJson(route('settings.test.opensearch'), $this->configuration())->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'opensearch_tested', 'result' => 'failed']);
        $this->assertStringNotContainsString('private-response-token', ActivityLog::all()->toJson());
    }

    public function test_failed_user_delete_is_not_reported_as_success_and_does_not_taint_the_next_request(): void
    {
        $admin = $this->admin();
        $this->delete(route('settings.users.delete', $admin))->assertSessionHasErrors('is_active');
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_status_changed', 'result' => 'failed']);
        $this->post(route('settings.opensearch'), $this->configuration())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'opensearch_updated', 'result' => 'success']);
        $this->assertNotNull($admin->fresh());
    }

    public function test_user_management_records_target_and_keeps_actor_snapshot_after_account_deletion(): void
    {
        $admin = $this->admin();
        $this->post(route('settings.users.create'), ['name' => 'New analyst', 'email' => 'new@example.test', 'role' => 'analyst', 'password' => 'private-new-user-password', 'password_confirmation' => 'private-new-user-password'])->assertSessionHasNoErrors();
        $target = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_created', 'subject' => 'User #'.$target->id.' · New analyst', 'result' => 'success']);
        $this->patch(route('settings.users.update', $target), ['name' => 'Updated analyst', 'role' => 'analyst', 'password' => 'private-updated-password', 'password_confirmation' => 'private-updated-password'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_updated', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'password_changed', 'actor_id' => $admin->id]);
        $this->delete(route('settings.users.delete', $target))->assertSessionHas('success');
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_status_changed', 'subject' => 'User #'.$target->id.' · Updated analyst', 'result' => 'success']);
        $this->assertStringNotContainsString('private-updated-password', ActivityLog::all()->toJson());
        $admin->delete();
        $this->assertSame(0, ActivityLog::whereNotNull('actor_id')->count());
        $this->assertSame(4, ActivityLog::where('actor_name', 'Admin SOC')->count());
    }

    public function test_profile_edit_is_logged_once_with_actor_and_route_without_field_values(): void
    {
        $user = $this->admin();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.45'])
            ->patch(route('profile.update'), ['name' => 'Renamed actor', 'email' => 'private-profile@example.test'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::query()->sole();
        $this->assertSame('profile_updated', $log->action);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('Renamed actor', $log->actor_name);
        $this->assertSame('Akun sendiri · User #'.$user->id, $log->subject);
        $this->assertSame('success', $log->result);
        $this->assertSame('203.0.113.45', $log->ip_address);
        $this->assertSame(['route' => 'profile.update', 'fields' => ['name', 'email']], $log->context);
        $this->assertStringNotContainsString('private-profile@example.test', $log->toJson());
    }

    public function test_rejected_profile_and_password_changes_are_logged_and_do_not_modify_the_account(): void
    {
        $user = $this->admin();
        $originalEmail = $user->email;
        $this->patchJson(route('profile.update'), ['name' => 'Invalid attempt', 'email' => 'invalid-private-email'])
            ->assertUnprocessable();
        $this->from('/profile')->patch(route('profile.update'), ['name' => '', 'email' => $originalEmail])
            ->assertSessionHasErrors('name')->assertRedirect('/profile');
        $this->putJson(route('password.update'), [
            'current_password' => 'private-wrong-password', 'password' => 'private-new-password', 'password_confirmation' => 'private-new-password',
        ])->assertUnprocessable();
        $this->from('/profile')->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'private-new-password', 'password_confirmation' => 'private-mismatch',
        ])->assertSessionHasErrorsIn('updatePassword', 'password')->assertRedirect('/profile');

        $this->assertSame('Admin SOC', $user->fresh()->name);
        $this->assertSame($originalEmail, $user->fresh()->email);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertDatabaseCount('activity_logs', 4);
        $this->assertSame(2, ActivityLog::where('action', 'profile_updated')->where('result', 'failed')->count());
        $this->assertSame(2, ActivityLog::where('action', 'password_changed')->where('result', 'failed')->count());
        $this->assertSame(4, ActivityLog::where('actor_id', $user->id)->count());
        foreach (['invalid-private-email', 'private-wrong-password', 'private-new-password', 'private-mismatch', $user->fresh()->password] as $secret) {
            $this->assertStringNotContainsString($secret, ActivityLog::all()->toJson());
        }
    }

    public function test_analyst_profile_and_password_changes_are_logged_but_activity_history_remains_admin_only(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Analyst Renamed', 'email' => $user->email])
            ->assertSessionHasNoErrors();
        $this->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'private-analyst-password', 'password_confirmation' => 'private-analyst-password',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['profile_updated', 'password_changed'], ActivityLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(2, ActivityLog::where('actor_id', $user->id)->where('actor_role', 'analyst')->where('result', 'success')->count());
        $this->get(route('history.activity'))->assertForbidden();
        $this->assertStringNotContainsString('private-analyst-password', ActivityLog::all()->toJson());
    }

    #[DataProvider('settingsConfigurationChanges')]
    public function test_other_settings_changes_are_logged_once_without_credentials(string $route, array $payload, string $action): void
    {
        $admin = $this->admin();
        if ($route === 'settings.telegram') {
            $this->mock(OpenSearchService::class)->shouldReceive('fetchAgents')->once()->andReturn(['agents' => [['id' => '001', 'name' => 'staging']]]);
        }
        $this->post(route($route), $payload)->assertSessionHasNoErrors();

        $log = ActivityLog::query()->sole();
        $this->assertSame($action, $log->action);
        $this->assertSame('success', $log->result);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($route, $log->context['route']);
        $this->assertEqualsCanonicalizing(array_keys($payload), $log->context['fields']);
        $this->assertStringNotContainsString('private-settings-secret', $log->toJson());
    }

    public static function settingsConfigurationChanges(): array
    {
        return [
            'telegram destinations' => ['settings.telegram', [
                'telegram_bot_token' => 'private-settings-secret',
                'telegram_destinations' => [['label' => 'Developer', 'chat_id' => '-100123', 'agent_keys' => [\App\Models\Alert::agentScopeKey('wazuh-primary', '001')]]],
            ], 'telegram_updated'],
            'ai configuration' => ['settings.ai', [
                'ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions', 'ai_base_url' => 'http://localhost:11434/v1',
                'ai_api_key' => 'private-settings-secret', 'ai_model' => 'local-model', 'ai_snapshot_limit' => 1000, 'ai_refresh_days' => 7,
            ], 'ai_updated'],
            'alert threshold' => ['settings.alerts', ['min_alert_level' => 7], 'alerts_updated'],
        ];
    }

    public function test_background_status_polling_is_not_logged_and_denied_settings_access_is_failed(): void
    {
        $admin = $this->admin();
        $export = DataExport::create(['uuid' => fake()->uuid(), 'requested_by_user_id' => $admin->id, 'requested_by_name' => $admin->name, 'scope' => 'alerts', 'format' => 'json', 'status' => 'queued', 'period_label' => 'Test export', 'storage_disk' => 'local', 'filename' => 'test-export.zip']);
        $this->get(route('settings.data.exports.status', $export))->assertOk();
        $this->get(route('settings.data.exports.status', $export))->assertOk();
        $this->assertDatabaseCount('activity_logs', 0);
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->postJson(route('settings.opensearch'), $this->configuration())->assertForbidden();
        $this->assertDatabaseHas('activity_logs', ['action' => 'opensearch_updated', 'result' => 'failed', 'actor_id' => $analyst->id]);
    }
}
