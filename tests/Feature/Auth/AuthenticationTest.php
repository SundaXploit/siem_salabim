<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Alamat email')
            ->assertSee('name="email"', false)
            ->assertSee('type="email"', false)
            ->assertDontSee('name="login"', false);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard.index', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[DataProvider('defaultEmails')]
    public function test_default_accounts_can_authenticate_using_their_email(string $email, string $role): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', $email)->sole();
        $this->post('/login', ['email' => ' '.strtoupper($email).' ', 'password' => 'bhapp'])
            ->assertRedirect(route('dashboard.index', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($role, auth()->user()->role);
    }

    public static function defaultEmails(): iterable
    {
        yield 'administrator' => ['adminsoc@siem.local', 'admin'];
        yield 'analyst' => ['analissoc@siem.local', 'analyst'];
    }

    public function test_existing_mixed_case_email_accounts_can_still_authenticate(): void
    {
        $user = User::factory()->create(['email' => 'SOC.Operator@Example.test']);
        $this->post('/login', ['email' => ' soc.operator@example.test ', 'password' => 'password'])
            ->assertRedirect(route('dashboard.index', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('SOC.Operator@Example.test', $user->fresh()->email);
    }

    #[DataProvider('nonEmailLogins')]
    public function test_a_valid_email_field_is_required_to_authenticate(array $identity): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->post('/login', [...$identity, 'password' => 'bhapp'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public static function nonEmailLogins(): iterable
    {
        yield 'username in email field' => [['email' => 'adminsoc']];
        yield 'former login field' => [['login' => 'adminsoc@siem.local']];
        yield 'username field' => [['username' => 'adminsoc']];
    }

    public function test_the_default_password_does_not_bypass_a_changed_password(): void
    {
        $user = User::factory()->create(['email' => 'adminsoc@siem.local', 'password' => 'Changed-password-123!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'bhapp'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Changed-password-123!'])
            ->assertRedirect(route('dashboard.index', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_email_attempts_are_rate_limited_across_case_and_whitespace_variations(): void
    {
        Event::fake([Lockout::class]);
        $user = User::factory()->create(['email' => 'adminsoc@siem.local']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => $attempt % 2 ? $user->email : ' ADMINSOC@SIEM.LOCAL ',
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        Event::assertDispatched(Lockout::class);
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard.index', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
