<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('arrayDateFilters')]
    public function test_date_filters_reject_arrays_before_querying(string $route, array $query, string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route($route, $query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public static function arrayDateFilters(): iterable
    {
        $routes = [
            'api.leaderboard' => ['period' => 'custom'],
            'history.alerts' => [],
            'analyst-log.index' => [],
            'history.activity' => [],
            'settings.data.preview' => ['scope' => 'alerts', 'period_mode' => 'range'],
        ];
        foreach ($routes as $route => $base) {
            foreach (['date_from', 'date_to'] as $field) {
                yield $route.' '.$field => [$route, [
                    ...$base,
                    'date_from' => '2026-11-15',
                    'date_to' => '2026-11-15',
                    $field => ['2026-11-15'],
                ], $field];
            }
        }
    }
}
