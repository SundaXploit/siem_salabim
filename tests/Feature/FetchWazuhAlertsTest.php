<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\User;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FetchWazuhAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_fetch_continues_past_a_page_of_100_existing_alerts_and_preserves_triage(): void
    {
        Configuration::set('min_alert_level', 12);
        $existingHits = [];

        for ($index = 1; $index <= 100; $index++) {
            $hit = $this->hit('existing-'.$index);
            Alert::create([
                ...OpenSearchService::parseHit($hit),
                'status' => $index % 2 === 0 ? 'acknowledged' : 'ignored',
                'rule_description' => 'Previously imported description',
            ]);
            $existingHits[] = $hit;
        }

        $this->mockBatches([
            $existingHits,
            [$this->hit('older-unseen-101'), $this->hit('older-unseen-102')],
        ]);

        $this->artisan('siem:fetch-alerts', ['--all' => true])
            ->expectsOutput('[SIEM] Done. New: 2, Skipped (already exists): 100')
            ->assertSuccessful();

        $this->assertDatabaseCount('alerts', 102);
        $this->assertDatabaseHas('alerts', [
            'wazuh_alert_id' => 'older-unseen-101',
            'status' => 'new',
        ]);
        $this->assertDatabaseHas('alerts', [
            'wazuh_alert_id' => 'older-unseen-102',
            'status' => 'new',
        ]);
        $this->assertSame(50, Alert::where('status', 'acknowledged')->count());
        $this->assertSame(50, Alert::where('status', 'ignored')->count());
        $this->assertSame(100, Alert::where('rule_description', 'Previously imported description')->count());
    }

    public function test_manual_fetch_checks_all_of_today_after_100_existing_alerts(): void
    {
        Configuration::set('min_alert_level', 12);
        $this->travelTo(Carbon::parse('2026-09-16T17:30:00Z'));
        $existingHits = [];

        for ($index = 1; $index <= 100; $index++) {
            $hit = $this->hit('manual-existing-'.$index);
            Alert::create(OpenSearchService::parseHit($hit));
            $existingHits[] = $hit;
        }

        $this->mockTodayBatches([$existingHits, [$this->hit('manual-unseen')]], [
            'gte' => '2026-09-16T17:00:00+00:00',
            'lt' => '2026-09-17T17:00:00+00:00',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('settings.fetch-now'))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', fn (string $message) =>
                str_contains($message, '[SIEM] Done. New: 1, Skipped (already exists): 100')
            );

        $this->assertDatabaseHas('alerts', ['wazuh_alert_id' => 'manual-unseen']);
        $this->assertDatabaseCount('alerts', 101);
    }

    #[DataProvider('jakartaDayBoundaries')]
    public function test_today_uses_the_jakarta_calendar_day_even_when_the_app_timezone_is_utc(
        string $instant,
        string $start,
        string $end,
    ): void {
        config(['app.timezone' => 'UTC']);
        Configuration::set('min_alert_level', 12);
        $this->travelTo(Carbon::parse($instant));
        $this->mockTodayBatches([], ['gte' => $start, 'lt' => $end]);

        $this->artisan('siem:fetch-alerts', ['--today' => true])
            ->expectsOutput('[SIEM] Done. New: 0, Skipped (already exists): 0')
            ->assertSuccessful();
    }

    public static function jakartaDayBoundaries(): iterable
    {
        yield 'one second before local midnight' => [
            '2026-09-16T16:59:59Z',
            '2026-09-15T17:00:00+00:00',
            '2026-09-16T17:00:00+00:00',
        ];
        yield 'local midnight starts the new day' => [
            '2026-09-16T17:00:00Z',
            '2026-09-16T17:00:00+00:00',
            '2026-09-17T17:00:00+00:00',
        ];
        yield 'last second of the local day' => [
            '2026-09-17T16:59:59Z',
            '2026-09-16T17:00:00+00:00',
            '2026-09-17T17:00:00+00:00',
        ];
        yield 'next local midnight is exclusive' => [
            '2026-09-17T17:00:00Z',
            '2026-09-17T17:00:00+00:00',
            '2026-09-18T17:00:00+00:00',
        ];
    }

    public function test_default_fetch_reads_only_the_latest_alerts_in_the_current_jakarta_day(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-09-16T17:30:00Z'));
        Configuration::set('min_alert_level', 7);
        $hit = $this->hit('default-fetch');

        $this->mock(OpenSearchService::class, function (MockInterface $service) use ($hit): void {
            $service->shouldReceive('fetchAlerts')->once()->with(7, 100, [
                'gte' => '2026-09-16T17:00:00+00:00',
                'lt' => '2026-09-17T17:00:00+00:00',
            ])->andReturnUsing(function () use ($hit): array {
                $competingLock = Cache::lock('siem_fetch_running', 600);
                $this->assertFalse($competingLock->get(), 'The fetch lock must cover the OpenSearch request.');

                return [$hit];
            });
            $service->shouldNotReceive('fetchAlertBatches');
        });

        $this->artisan('siem:fetch-alerts')
            ->expectsOutput('[SIEM] Done. New: 1, Skipped (already exists): 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['wazuh_alert_id' => 'default-fetch']);
        $this->assertFetchLockIsAvailable();
    }

    public function test_dry_run_distinguishes_existing_alerts_and_counts_unseen_ids_only_once_without_saving(): void
    {
        Configuration::set('min_alert_level', 12);
        $existingHit = $this->hit('dry-existing');
        Alert::create([...OpenSearchService::parseHit($existingHit), 'status' => 'acknowledged']);
        $newHit = $this->hit('dry-unseen');
        $this->mockBatches([[$existingHit, $newHit], [$newHit]]);

        $this->artisan('siem:fetch-alerts', ['--all' => true, '--dry-run' => true])
            ->expectsOutput('[SIEM] Dry run. Would import: 1, Skipped (already exists): 2')
            ->assertSuccessful();

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseMissing('alerts', ['wazuh_alert_id' => 'dry-unseen']);
        $this->assertDatabaseHas('alerts', [
            'wazuh_alert_id' => 'dry-existing',
            'status' => 'acknowledged',
        ]);
    }

    public function test_full_fetch_reports_no_alerts_when_the_iterator_has_no_pages(): void
    {
        Configuration::set('min_alert_level', 12);
        $this->mockBatches([]);

        $this->artisan('siem:fetch-alerts', ['--all' => true])
            ->expectsOutputToContain('Tidak ada alert yang cocok dengan filter level >= 12')
            ->expectsOutput('[SIEM] Done. New: 0, Skipped (already exists): 0')
            ->assertSuccessful();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_full_fetch_explains_that_all_matching_alerts_are_already_stored(): void
    {
        Configuration::set('min_alert_level', 12);
        $firstHit = $this->hit('already-stored-first');
        $secondHit = $this->hit('already-stored-second');
        Alert::create([...OpenSearchService::parseHit($firstHit), 'status' => 'acknowledged']);
        Alert::create([...OpenSearchService::parseHit($secondHit), 'status' => 'ignored']);
        $this->mockBatches([[$firstHit], [$secondHit]]);

        $this->artisan('siem:fetch-alerts', ['--all' => true])
            ->expectsOutput('[SIEM] Semua 2 alert yang diperiksa sudah tersimpan. Tidak ada alert baru untuk diimpor.')
            ->expectsOutput('[SIEM] Done. New: 0, Skipped (already exists): 2')
            ->assertSuccessful();

        $this->assertDatabaseCount('alerts', 2);
        $this->assertDatabaseHas('alerts', [
            'wazuh_alert_id' => 'already-stored-first',
            'status' => 'acknowledged',
        ]);
        $this->assertDatabaseHas('alerts', [
            'wazuh_alert_id' => 'already-stored-second',
            'status' => 'ignored',
        ]);
    }

    public function test_default_fetch_returns_failure_when_opensearch_is_unavailable(): void
    {
        Configuration::set('min_alert_level', 12);

        $this->mock(OpenSearchService::class, function (MockInterface $service): void {
            $service->shouldReceive('fetchAlerts')->once()->with(12, 100, \Mockery::type('array'))
                ->andThrow(new \RuntimeException('OpenSearch tidak tersedia.'));
        });

        $this->artisan('siem:fetch-alerts')
            ->expectsOutput('[SIEM] Fetch gagal: OpenSearch tidak tersedia.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('alerts', 0);
        $this->assertFetchLockIsAvailable();
        $this->assertFalse(Cache::has('siem_fetch_cooldown_until'));
    }

    public function test_full_fetch_returns_failure_when_a_later_page_fails_and_keeps_imported_alerts(): void
    {
        Configuration::set('min_alert_level', 12);
        $firstHit = $this->hit('imported-before-failure');
        $pages = (function () use ($firstHit): \Generator {
            yield [$firstHit];

            throw new \RuntimeException('Halaman berikutnya tidak tersedia.');
        })();

        $this->mock(OpenSearchService::class, function (MockInterface $service) use ($pages): void {
            $service->shouldReceive('fetchAlertBatches')->once()->with(12, 100)->andReturn($pages);
        });

        $this->artisan('siem:fetch-alerts', ['--all' => true])
            ->expectsOutput('[SIEM] Fetch gagal: Halaman berikutnya tidak tersedia.')
            ->doesntExpectOutput('[SIEM] Done. New: 1, Skipped (already exists): 0')
            ->assertExitCode(1);

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', ['wazuh_alert_id' => 'imported-before-failure']);
        $this->assertFetchLockIsAvailable();
    }

    #[DataProvider('fetchModes')]
    public function test_all_fetch_modes_skip_opensearch_while_another_fetch_owns_the_lock(array $options): void
    {
        $ownerLock = Cache::lock('siem_fetch_running', 600);
        $this->assertTrue($ownerLock->get());
        $this->mock(OpenSearchService::class, function (MockInterface $service): void {
            $service->shouldNotReceive('fetchAlerts');
            $service->shouldNotReceive('fetchAlertBatches');
        });

        try {
            $this->artisan('siem:fetch-alerts', $options)
                ->expectsOutputToContain('Fetch lain masih berjalan')
                ->assertSuccessful();

            $this->assertTrue($ownerLock->isOwnedByCurrentProcess());
            $this->assertDatabaseCount('alerts', 0);
        } finally {
            $ownerLock->release();
        }
    }

    public static function fetchModes(): iterable
    {
        yield 'automatic latest alerts' => [[]];
        yield 'manual today import' => [['--today' => true]];
        yield 'archive import' => [['--all' => true]];
    }

    public function test_memory_failure_preserves_imports_and_pauses_all_fetches_until_cooldown_expires(): void
    {
        Configuration::set('min_alert_level', 12);
        $this->travelTo(Carbon::parse('2026-09-17T02:00:00Z'));
        $firstHit = $this->hit('saved-before-memory-limit');
        $pages = (function () use ($firstHit): \Generator {
            yield [$firstHit];

            throw new \RuntimeException('Memori OpenSearch mencapai batas circuit breaker.');
        })();
        $this->mock(OpenSearchService::class, function (MockInterface $service) use ($pages): void {
            $service->shouldReceive('fetchAlertBatches')->once()
                ->with(12, 100, \Mockery::type('array'))->andReturn($pages);
            // This request can only occur after the shared cooldown expires.
            $service->shouldReceive('fetchAlerts')->once()
                ->with(12, 100, \Mockery::type('array'))->andReturnUsing(function (): array {
                    $this->assertGreaterThanOrEqual(Carbon::parse('2026-09-17T02:02:00Z')->timestamp, now()->timestamp);

                    return [];
                });
        });

        $this->artisan('siem:fetch-alerts', ['--today' => true])
            ->expectsOutputToContain('Semua fetch dijeda selama 120 detik')
            ->assertExitCode(1);

        $cooldownUntil = Cache::get('siem_fetch_cooldown_until');
        $this->assertSame(now()->timestamp + 120, $cooldownUntil);
        $this->assertDatabaseHas('alerts', ['wazuh_alert_id' => 'saved-before-memory-limit']);
        $this->assertFetchLockIsAvailable();

        $this->travel(30)->seconds();
        foreach ([[], ['--today' => true], ['--all' => true]] as $options) {
            $this->artisan('siem:fetch-alerts', $options)
                ->expectsOutputToContain('Tunggu 90 detik sebelum mencoba kembali')
                ->assertExitCode(1);
            $this->assertSame($cooldownUntil, Cache::get('siem_fetch_cooldown_until'));
            $this->assertFetchLockIsAvailable();
        }

        $this->travel(91)->seconds();
        $this->artisan('siem:fetch-alerts')->assertSuccessful();
        $this->assertDatabaseCount('alerts', 1);
        $this->assertFetchLockIsAvailable();
    }

    private function assertFetchLockIsAvailable(): void
    {
        $nextLock = Cache::lock('siem_fetch_running', 600);
        $this->assertTrue($nextLock->get(), 'The fetch must release its lock before returning.');
        $nextLock->release();
    }

    private function mockBatches(array $batches): void
    {
        $pages = (function () use ($batches): \Generator {
            yield from $batches;
        })();

        $this->mock(OpenSearchService::class, function (MockInterface $service) use ($pages): void {
            $service->shouldReceive('fetchAlertBatches')->once()->with(12, 100)->andReturn($pages);
            $service->shouldNotReceive('fetchAlerts');
        });
    }

    private function mockTodayBatches(array $batches, array $expectedRange): void
    {
        $pages = (function () use ($batches): \Generator {
            yield from $batches;
        })();

        $this->mock(OpenSearchService::class, function (MockInterface $service) use ($pages, $expectedRange): void {
            $service->shouldReceive('fetchAlertBatches')->once()->withArgs(function (
                int $minLevel,
                int $size,
                array $timeRange,
            ) use ($expectedRange): bool {
                $this->assertSame(12, $minLevel);
                $this->assertSame(100, $size);
                $this->assertSame(['gte', 'lt'], array_keys($timeRange));
                foreach ($expectedRange as $bound => $expected) {
                    $this->assertSame($expected, Carbon::parse($timeRange[$bound])->utc()->toIso8601String());
                }

                return true;
            })->andReturn($pages);
            $service->shouldNotReceive('fetchAlerts');
        });
    }

    private function hit(string $id): array
    {
        return [
            '_id' => $id,
            '_source' => [
                '@timestamp' => '2026-09-17T10:00:00.000Z',
                'rule' => [
                    'id' => '990001',
                    'level' => 12,
                    'description' => 'Wazuh pagination regression test',
                ],
                'agent' => ['name' => 'test-endpoint'],
                'data' => ['srcip' => '192.0.2.10'],
            ],
        ];
    }
}
