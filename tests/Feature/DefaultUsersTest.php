<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DefaultUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_accounts_have_the_requested_emails_roles_and_hashed_passwords(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        foreach (['adminsoc@siem.local' => 'admin', 'analissoc@siem.local' => 'analyst'] as $email => $role) {
            $user = User::where('email', $email)->sole();
            $this->assertSame($role, $user->role);
            $this->assertTrue(Hash::check('bhapp', $user->password));
            $this->assertNotSame('bhapp', $user->password);
        }
    }

    public function test_seeding_again_preserves_changed_passwords_roles_and_account_details(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'adminsoc@siem.local')->sole();
        $user->update([
            'name' => 'Existing SOC operator',
            'role' => 'analyst',
            'password' => 'Changed-password-123!',
        ]);
        $before = User::orderBy('id')->get()->map->getAttributes()->all();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($before, User::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertFalse(Hash::check('bhapp', $user->fresh()->password));
    }

    #[DataProvider('existingEmails')]
    public function test_seeding_never_takes_over_an_existing_email(string $email): void
    {
        $existing = User::factory()->create(['email' => $email, 'role' => 'analyst']);
        $before = $existing->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseHas('users', ['email' => 'analissoc@siem.local', 'role' => 'analyst']);
        $this->assertSame(0, User::where('role', 'admin')->count());
    }

    public static function existingEmails(): iterable
    {
        yield 'same email' => ['adminsoc@siem.local'];
        yield 'case insensitive email' => ['ADMINSOC@SIEM.LOCAL'];
    }
}
