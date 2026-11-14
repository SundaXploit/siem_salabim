<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function forAgent(string $id, array $privileges = [], string $cluster = 'wazuh-primary'): static
    {
        return $this->state(fn () => [
            'role' => 'analyst',
            'allowed_agents' => [[
                'key' => Alert::agentScopeKey($cluster, $id),
                'id' => $id,
                'name' => 'Test agent '.$id,
                'source_cluster' => $cluster,
            ]],
            'privileges' => $privileges,
        ]);
    }
}
