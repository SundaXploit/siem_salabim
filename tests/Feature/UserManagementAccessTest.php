<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_cannot_read_create_change_or_delete_users_even_with_all_alert_privileges(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $analyst = User::factory()->forAgent('007', array_keys(User::PRIVILEGES))->create();
        $other = User::factory()->forAgent('008')->create();
        $before = User::orderBy('id')->get()->toJson();
        $this->mock(OpenSearchService::class)->shouldNotReceive('fetchAgents');
        $this->actingAs($analyst)->getJson('/settings?section=users')->assertForbidden();
        $this->getJson(route('settings.users.agents'))->assertForbidden();
        $payload = ['name' => 'Forged admin', 'email' => 'forged@example.test', 'role' => 'admin', 'password' => 'secret-forged-password', 'password_confirmation' => 'secret-forged-password'];
        $this->postJson(route('settings.users.create'), $payload)->assertForbidden();
        foreach ([$analyst->id, $admin->id, $other->id, 999999, 'not-an-id'] as $id) {
            foreach (['PATCH', 'DELETE'] as $method) {
                $this->json($method, '/settings/users/'.$id, $payload)->assertForbidden()
                    ->assertJsonPath('message', 'Akses hanya untuk Admin.')
                    ->assertDontSee($admin->email)->assertDontSee($other->email)->assertDontSee('secret-forged-password');
            }
        }
        $this->assertSame($before, User::orderBy('id')->get()->toJson());
        $this->assertSame(5, ActivityLog::where('action', 'user_updated')->where('result', 'failed')->where('actor_id', $analyst->id)->count());
        $this->assertSame(5, ActivityLog::where('action', 'user_status_changed')->where('result', 'failed')->where('actor_id', $analyst->id)->count());
        $this->assertStringNotContainsString('secret-forged-password', ActivityLog::all()->toJson());
    }

    public function test_guest_object_requests_do_not_reveal_whether_a_user_exists(): void
    {
        $user = User::factory()->create();
        foreach ([$user->id, 999999] as $id) {
            $this->patchJson('/settings/users/'.$id, ['role' => 'admin'])->assertUnauthorized();
            $this->deleteJson('/settings/users/'.$id)->assertUnauthorized();
        }
        $this->getJson(route('settings.users.agents'))->assertUnauthorized();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_body_ids_and_raw_grant_fields_cannot_override_the_authorised_route_target(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->forAgent('007')->create();
        $other = User::factory()->forAgent('008')->create();
        $original = $other->fresh()->getAttributes();
        $this->actingAs($admin)->patch(route('settings.users.update', $target), [
            'id' => $other->id, 'user_id' => $other->id, '_editing_user' => $other->id,
            'name' => 'Updated target', 'email' => 'ignored@example.test', 'role' => 'analyst',
            'allowed_agents' => $other->allowed_agents, '_access_settings' => 1, 'agent_keys' => [],
            'privileges' => ['alerts.acknowledge'],
        ])->assertSessionHasNoErrors();
        $this->assertSame($original, $other->fresh()->getAttributes());
        $target->refresh();
        $this->assertSame('Updated target', $target->name);
        $this->assertNotSame('ignored@example.test', $target->email);
        $this->assertSame([], $target->agentAccessKeys());
        $this->assertSame(['alerts.acknowledge'], $target->privileges);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_updated', 'actor_id' => $admin->id, 'subject' => 'User #'.$target->id.' · Updated target', 'result' => 'success']);
        $this->delete(route('settings.users.delete', $admin))->assertSessionHasErrors('is_active');
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
    }

    public function test_admin_can_load_user_management_without_disclosing_password_hashes_in_the_editor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $analyst = User::factory()->forAgent('007', ['alerts.ignore'])->create();
        $this->actingAs($admin)->get('/settings?section=users')->assertOk()
            ->assertSee('user-actions-menu')->assertSee('settings-user-dialog')->assertSee('ellipsis-vertical')
            ->assertDontSee($admin->password)->assertDontSee($analyst->password)
            ->assertDontSee('settings-user-create')->assertDontSee('settings-user-grid');
    }
}
