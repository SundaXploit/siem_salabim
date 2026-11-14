<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_has_no_forgot_password_link(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('/forgot-password')->assertDontSee('Lupa kata sandi?');
    }

    public function test_guests_cannot_open_or_submit_forgot_and_reset_password_endpoints(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();
        $this->get('/reset-password/old-token')->assertNotFound();
        $this->post('/reset-password', ['email' => $user->email, 'token' => 'old-token'])->assertNotFound();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_authenticated_users_cannot_use_the_removed_reset_endpoints(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();
        $this->get('/reset-password/old-token')->assertNotFound();
        $this->post('/reset-password', ['email' => $user->email, 'token' => 'old-token'])->assertNotFound();
        $this->get('/profile')->assertOk()->assertSee(route('password.update'), false);
        Notification::assertNothingSent();
    }

    public function test_previously_issued_valid_token_cannot_reset_a_password(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $token = Password::createToken($user);
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'forged-new-password', 'password_confirmation' => 'forged-new-password',
        ])->assertNotFound();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertFalse(Hash::check('forged-new-password', $user->fresh()->password));
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'password_reset']);
    }
}
