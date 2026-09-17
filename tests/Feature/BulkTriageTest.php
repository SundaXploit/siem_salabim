<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BulkTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::put('siem_fetch_ran', true, now()->addMinute());
    }

    public function test_bulk_ack_changes_only_selected_new_alerts_and_records_the_actor(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $first = $this->alert();
        $second = $this->alert();
        $ack = $this->alert('acknowledged');
        $ignored = $this->alert('ignored');
        $unselected = $this->alert();
        $payload = ['action' => 'acknowledge', 'alert_ids' => [$first->id, $second->id, $ack->id, $ignored->id]];

        $this->actingAs($user)->postJson(route('alerts.bulk-triage'), $payload)
            ->assertOk()->assertJsonPath('processed', 2)->assertJsonPath('skipped', 2);
        foreach ([$first, $second] as $alert) {
            $this->assertSame('acknowledged', $alert->fresh()->status);
            $this->assertDatabaseHas('alert_triages', [
                'alert_id' => $alert->id, 'user_id' => $user->id, 'action' => 'acknowledge', 'reason' => null,
            ]);
        }
        $this->assertSame('ignored', $ignored->fresh()->status);
        $this->assertSame('new', $unselected->fresh()->status);
        $this->assertDatabaseCount('alert_triages', 2);

        // A retry after a lost response must not inflate activity/history counts.
        $this->postJson(route('alerts.bulk-triage'), $payload)
            ->assertOk()->assertJsonPath('processed', 0)->assertJsonPath('skipped', 4);
        $this->assertDatabaseCount('alert_triages', 2);
        Http::assertNothingSent();
    }

    public function test_bulk_ignore_records_one_reason_per_changed_alert_and_invalidates_metrics(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $new = $this->alert();
        $ack = $this->alert('acknowledged');
        $ignored = $this->alert('ignored');
        $cacheKey = 'security-insight-snapshot:v2:' . now('Asia/Jakarta')->format('Y-m');
        Cache::put($cacheKey, ['stale' => true], 60);

        $this->actingAs($user)->postJson(route('alerts.bulk-triage'), [
            'action' => 'ignore', 'alert_ids' => [$new->id, $ack->id, $ignored->id],
            'reason' => 'Pemeliharaan terjadwal telah dikonfirmasi.',
        ])->assertOk()->assertJsonPath('processed', 2)->assertJsonPath('skipped', 1);

        foreach ([$new, $ack] as $alert) {
            $this->assertSame('ignored', $alert->fresh()->status);
            $this->assertDatabaseHas('alert_triages', [
                'alert_id' => $alert->id, 'user_id' => $user->id, 'action' => 'ignore',
                'reason' => 'Pemeliharaan terjadwal telah dikonfirmasi.',
            ]);
        }
        $this->assertDatabaseCount('alert_triages', 2);
        $this->assertNull(Cache::get($cacheKey));
        Http::assertNothingSent();
    }

    #[DataProvider('invalidBatches')]
    public function test_invalid_batch_does_not_change_alerts(array $overrides, string $error): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $alert = $this->alert();
        $this->actingAs($user)->postJson(route('alerts.bulk-triage'), array_replace([
            'action' => 'acknowledge', 'alert_ids' => [$alert->id],
        ], $overrides))->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame('new', $alert->fresh()->status);
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public static function invalidBatches(): array
    {
        return [
            'empty selection' => [['alert_ids' => []], 'alert_ids'],
            'not an array' => [['alert_ids' => 'all'], 'alert_ids'],
            'over limit' => [['alert_ids' => range(1, 101)], 'alert_ids'],
            'duplicate IDs' => [['alert_ids' => [1, 1]], 'alert_ids.0'],
            'invalid ID' => [['alert_ids' => ['invalid']], 'alert_ids.0'],
            'unsupported action' => [['action' => 'notify'], 'action'],
            'ignore without reason' => [['action' => 'ignore'], 'reason'],
            'blank reason' => [['action' => 'ignore', 'reason' => '      '], 'reason'],
            'short reason' => [['action' => 'ignore', 'reason' => 'test'], 'reason'],
            'long reason' => [['action' => 'ignore', 'reason' => str_repeat('a', 1001)], 'reason'],
        ];
    }

    public function test_missing_alert_rejects_the_entire_batch(): void
    {
        $alert = $this->alert();
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson(route('alerts.bulk-triage'), [
                'action' => 'acknowledge', 'alert_ids' => [$alert->id, $alert->id + 100],
            ])->assertUnprocessable()->assertJsonValidationErrors('alert_ids');
        $this->assertSame('new', $alert->fresh()->status);
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_history_failure_rolls_back_every_status_change(): void
    {
        $first = $this->alert();
        $second = $this->alert();
        DB::unprepared("CREATE TRIGGER fail_triage BEFORE INSERT ON alert_triages WHEN NEW.alert_id = {$second->id} BEGIN SELECT RAISE(ABORT, 'test history failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->actingAs(User::factory()->create(['role' => 'analyst']))
                ->postJson(route('alerts.bulk-triage'), [
                    'action' => 'acknowledge', 'alert_ids' => [$first->id, $second->id],
                ]);
            $this->fail('Expected history insert to fail.');
        } catch (QueryException) {
            $this->assertSame('new', $first->fresh()->status);
            $this->assertSame('new', $second->fresh()->status);
            $this->assertDatabaseCount('alert_triages', 0);
        }
    }

    public function test_guest_cannot_triage(): void
    {
        $alert = $this->alert();
        $this->postJson(route('alerts.bulk-triage'), ['action' => 'acknowledge', 'alert_ids' => [$alert->id]])
            ->assertUnauthorized();
        $this->assertSame('new', $alert->fresh()->status);
    }

    public function test_individual_triage_shares_idempotency_with_bulk_triage(): void
    {
        $alert = $this->alert();
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->post(route('alerts.acknowledge', $alert))->assertSessionHas('success');
        $this->postJson(route('alerts.bulk-triage'), ['action' => 'acknowledge', 'alert_ids' => [$alert->id]])
            ->assertOk()->assertJsonPath('processed', 0);
        $this->post(route('alerts.ignore', $alert), ['reason' => 'Aktivitas valid.'])->assertSessionHas('success');
        $this->post(route('alerts.acknowledge', $alert))->assertSessionHas('error');
        $this->post(route('alerts.ignore', $alert), ['reason' => 'Aktivitas valid.'])->assertSessionHas('error');
        $this->assertSame('ignored', $alert->fresh()->status);
        $this->assertDatabaseCount('alert_triages', 2);
    }

    public function test_live_alerts_supports_a_bounded_page_size_and_bulk_selection_controls(): void
    {
        for ($i = 0; $i < 27; $i++) $this->alert();
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->get(route('alerts.index', ['per_page' => 50]))->assertOk()
            ->assertSee('ACK massal')->assertSee('Abaikan massal')
            ->assertSee('Pilih semua alert yang dapat ditangani pada halaman ini')
            ->assertViewHas('alerts', fn ($alerts) => $alerts->perPage() === 50 && $alerts->count() === 27);
        $this->get(route('alerts.index', ['per_page' => 10000]))->assertOk()
            ->assertViewHas('alerts', fn ($alerts) => $alerts->perPage() === 25 && $alerts->count() === 25);
    }

    private function alert(string $status = 'new'): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => 'bulk-test-' . fake()->uuid(),
            'rule_description' => 'Synthetic triage event',
            'rule_level' => 5, 'status' => $status, 'first_seen_at' => now(), 'raw_data' => [],
        ]);
    }
}
