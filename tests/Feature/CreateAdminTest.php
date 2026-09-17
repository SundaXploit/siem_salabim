<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_admin_with_a_hashed_password_and_normalised_email(): void
    {
        $this->artisan('siem:create-admin')
            ->expectsQuestion('Nama administrator', 'SOC Administrator')
            ->expectsQuestion('Email administrator', ' SOC@EXAMPLE.TEST ')
            ->expectsQuestion('Password (minimal 12 karakter)', 'Unique-password-123!')
            ->expectsQuestion('Ulangi password', 'Unique-password-123!')
            ->doesntExpectOutputToContain('Unique-password-123!')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('soc@example.test', $user->email);
        $this->assertSame('SOC Administrator', $user->name);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('Unique-password-123!', $user->password));
        $this->assertNotSame('Unique-password-123!', $user->password);
    }

    public function test_existing_account_cannot_be_overwritten_or_promoted(): void
    {
        $user = User::factory()->create(['email' => 'analyst@example.test', 'role' => 'analyst']);
        $original = $user->fresh()->getAttributes();

        $this->artisan('siem:create-admin')
            ->expectsQuestion('Nama administrator', 'Replacement')
            ->expectsQuestion('Email administrator', 'analyst@example.test')
            ->expectsOutput('Email sudah terdaftar. Akun yang ada tidak akan diubah.')
            ->assertFailed();

        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_confirmation_mismatch_creates_no_account(): void
    {
        $this->artisan('siem:create-admin')
            ->expectsQuestion('Nama administrator', 'SOC Administrator')
            ->expectsQuestion('Email administrator', 'soc@example.test')
            ->expectsQuestion('Password (minimal 12 karakter)', 'Unique-password-123!')
            ->expectsQuestion('Ulangi password', 'Different-password!')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_short_password_creates_no_account(): void
    {
        $this->artisan('siem:create-admin')
            ->expectsQuestion('Nama administrator', 'SOC Administrator')
            ->expectsQuestion('Email administrator', 'soc@example.test')
            ->expectsQuestion('Password (minimal 12 karakter)', 'short')
            ->expectsQuestion('Ulangi password', 'short')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_non_interactive_invocation_creates_no_account(): void
    {
        $this->artisan('siem:create-admin', ['--no-interaction' => true])
            ->expectsOutput('Jalankan perintah ini secara interaktif agar password tidak masuk riwayat shell.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_seeding_does_not_publish_default_accounts_or_reset_existing_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 0);

        $existing = User::factory()->create(['email' => 'admin@siem.local', 'role' => 'admin']);
        $original = $existing->fresh()->getAttributes();
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $existing->fresh()->getAttributes());
    }
}
