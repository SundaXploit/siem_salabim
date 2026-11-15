<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaderboardValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_validation_errors_instead_of_500(array $query, string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('api.leaderboard', $query))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            [['period' => 'custom'], 'date_from'],
            [['period' => 'custom', 'date_from' => 'not-a-date', 'date_to' => '2026-11-15'], 'date_from'],
            [['period' => 'custom', 'date_from' => '2026-02-30', 'date_to' => '2026-11-15'], 'date_from'],
            [['period' => 'custom', 'date_from' => 'yesterday', 'date_to' => 'tomorrow'], 'date_from'],
            [['period' => 'custom', 'date_from' => ['2026-11-15'], 'date_to' => '2026-11-15'], 'date_from'],
            [['period' => 'custom', 'date_from' => '2026-11-15', 'date_to' => '2026-11-01'], 'date_to'],
            [['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => 'garbage'], 'date_to'],
            [['period' => 'custom', 'date_from' => '0001-01-01', 'date_to' => '9999-12-31'], 'date_to'],
            [['period' => 'unknown'], 'period'], [['period' => ['week']], 'period'],
        ];
    }

    public function test_the_default_and_valid_custom_periods_still_work(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson(route('api.leaderboard'))->assertOk();
        $this->getJson(route('api.leaderboard', [
            'period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-15',
        ]))->assertOk();
    }
}
