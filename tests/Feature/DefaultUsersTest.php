<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DefaultUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_database_seeding_never_creates_predictable_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_default_user_seeder_refuses_to_create_accounts(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('kredensial bawaan tidak aman');

        $this->seed(DefaultUsersSeeder::class);
    }
}
