<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\DashboardInsight;
use App\Models\User;
use App\Services\SecurityInsightSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.amanai.api_key' => 'test-only-key',
            'services.amanai.base_url' => 'https://api.amanai.dev/v1',
            'services.amanai.reasoning_effort' => 'none',
        ]);
        Http::preventStrayRequests();
    }

    public function test_dashboard_is_a_separate_analytical_overview(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $actioned = $this->makeAlert('dashboard-actioned', 15, 'web-01');
        $this->makeAlert('dashboard-pending', 12, 'web-02');

        AlertTriage::create([
            'alert_id' => $actioned->id,
            'user_id' => $admin->id,
            'action' => 'acknowledge',
            'created_at' => now(),
        ]);

        $dashboardResponse = $this->actingAs($admin)
            ->get(route('dashboard.index'));

        $dashboardResponse
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Sudah ditindaklanjuti')
            ->assertSee('Belum dianalisis')
            ->assertSee('Kesimpulan analitis AI')
            ->assertSee('Brute Force')
            ->assertViewHas('dashboard', fn (array $dashboard): bool =>
                $dashboard['metrics']['total'] === 2
                && $dashboard['metrics']['actioned'] === 1
                && $dashboard['metrics']['pending'] === 1
            );

        $this->actingAs($admin)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Live Alerts');

        $this->assertSame(2, Alert::count());
    }

    public function test_analyst_receives_manual_monthly_ai_result_directly(): void
    {
        $summary = str_repeat(
            'Terjadi peningkatan alert autentikasi yang perlu diverifikasi oleh analis SOC. ',
            4
        );

        Http::fake([
            'https://api.amanai.dev/v1/responses' => Http::response([
                'output_text' => $summary,
            ]),
        ]);
        config([
            'services.amanai.api_key' => 'test-only-key',
            'services.amanai.base_url' => 'https://api.amanai.dev/v1',
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->makeAlert('dashboard-direct-ai', 12, 'web-01');

        $this->actingAs($analyst)
            ->postJson(route('dashboard.insight.refresh'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('insight.summary', trim($summary));

        $this->assertDatabaseHas('dashboard_insights', [
            'period_key' => now('Asia/Jakarta')->format('Y-m'),
            'status' => 'completed',
            'trigger' => 'manual',
            'requested_by_user_id' => $analyst->id,
        ]);

        $this->assertTrue(
            DashboardInsight::query()->latest('id')->first()->summary === trim($summary)
        );
        Http::assertSent(fn (Request $request): bool =>
            data_get($request->data(), 'reasoning.effort') === 'none'
        );
        Http::assertSentCount(1);
    }

    public function test_old_empty_period_summary_is_hidden_after_alerts_are_imported(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->storeEmptyInsight();
        $this->makeAlert('imported-after-insight', 5, 'test-agent');

        $this->actingAs($analyst)->get(route('dashboard.index'))
            ->assertOk()
            ->assertViewHas('insight', null)
            ->assertViewHas('insightSummary', null)
            ->assertViewHas('dashboard', fn (array $data): bool => $data['metrics']['total'] === 1);
        Http::assertNothingSent();
    }

    public function test_empty_period_summary_remains_valid_while_there_are_no_alerts(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $stored = $this->storeEmptyInsight();

        $this->actingAs($analyst)->get(route('dashboard.index'))
            ->assertOk()
            ->assertViewHas('insightSummary', $stored->summary);
        Http::assertNothingSent();
    }

    public function test_manual_refresh_reads_imported_alerts_even_when_snapshot_cache_is_empty(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $snapshots = app(SecurityInsightSnapshotService::class);
        $this->assertSame(0, $snapshots->build($snapshots->currentPeriod())['metrics']['total']);
        $this->makeAlert('newly-imported', 5, 'test-agent');
        $summary = str_repeat('Alert baru perlu diverifikasi dan ditindaklanjuti oleh analyst SOC. ', 3);
        Http::fake(['*/responses' => Http::response(['output_text' => $summary])]);

        $this->actingAs($analyst)->postJson(route('dashboard.insight.refresh'))
            ->assertOk()
            ->assertJsonPath('insight.summary', trim($summary));

        $stored = DashboardInsight::latest('id')->first();
        $this->assertSame(1, data_get($stored->source_snapshot, 'metrics.total'));
        Http::assertSentCount(1);
    }

    public function test_reasoning_only_response_reports_incomplete_output_instead_of_connection_error(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->makeAlert('empty-ai-reply', 5, 'test-agent');
        Http::fake(['*/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'reasoning', 'summary' => [['text' => 'private-provider-text']]]],
            'usage' => ['output_tokens' => 4096, 'output_tokens_details' => ['reasoning_tokens' => 4096]],
        ])]);

        $this->actingAs($analyst)->postJson(route('dashboard.insight.refresh'))
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Layanan AI merespons tanpa kesimpulan yang lengkap. Silakan analisis ulang.')
            ->assertDontSee('private-provider-text');
        $this->assertDatabaseCount('dashboard_insights', 0);
        Http::assertSentCount(1);
    }

    private function storeEmptyInsight(): DashboardInsight
    {
        return DashboardInsight::create([
            'period_key' => now('Asia/Jakarta')->format('Y-m'),
            'period_start' => now('Asia/Jakarta')->startOfMonth()->toDateString(),
            'period_end' => now('Asia/Jakarta')->endOfMonth()->toDateString(),
            'status' => 'completed',
            'summary' => 'Belum ada alert yang diimpor untuk periode ini. Kesimpulan tren akan tersedia setelah data alert masuk ke SIEM Salabim.',
            'source_snapshot' => ['metrics' => ['total' => 0]],
            'source_fingerprint' => str_repeat('a', 64),
            'provider' => 'amanai',
            'model' => 'test-model',
            'trigger' => 'scheduled',
            'generated_at' => now()->subDays(1),
        ]);
    }

    private function makeAlert(string $suffix, int $level, string $agent): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => 'test-' . $suffix,
            'rule_description' => 'Brute Force login pattern',
            'agent_name' => $agent,
            'rule_id' => '5710',
            'rule_level' => $level,
            'raw_data' => [
                'rule' => [
                    'mitre' => [
                        'id' => ['T1110'],
                        'technique' => ['Brute Force'],
                    ],
                ],
            ],
            'status' => 'new',
            'first_seen_at' => now(),
        ]);
    }
}
