<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkAlertAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $this->fakeIntegrationDns();
        $user = User::factory()->forAgent('001', ['alerts.analyze', 'alerts.acknowledge'])->create();
        $this->actingAs($user);
        Configuration::set('ai_provider', 'custom');
        Configuration::set('ai_api_format', 'responses');
        Configuration::set('ai_api_key', 'test-key');
        Configuration::set('ai_base_url', 'https://ai.example.test/v1');
        Configuration::set('ai_model', 'test-model');

        return $user;
    }

    private function alert(array $overrides = []): Alert
    {
        if (isset($overrides['raw_data'])) {
            $overrides['raw_data']['agent']['id'] ??= '001';
        }

        return Alert::create(array_merge([
            'wazuh_alert_id' => fake()->uuid(), 'agent_name' => 'staging-private-host',
            'rule_description' => 'Authentication activity', 'rule_id' => '5710', 'rule_level' => 12,
            'src_ip' => '192.0.2.10', 'dst_ip' => '198.51.100.1', 'status' => 'new', 'first_seen_at' => now(),
            'raw_data' => ['agent' => ['id' => '001'], 'data' => ['username' => 'private-account', 'password' => 'super-secret', 'message' => 'Contact analyst@example.test']],
        ], $overrides));
    }

    private function provider(array $ids, bool $failureFirst = false): void
    {
        $payload = ['output_text' => json_encode([
            'verdict' => 'suspicious', 'confidence' => 72, 'summary' => 'Login dan aktivitas lanjutan perlu divalidasi.',
            'correlations' => [['alert_ids' => $ids, 'strength' => 'moderate', 'evidence' => 'Sumber IP sama dan kejadian berurutan.', 'explanation' => 'Periksa sesi autentikasi.', 'alternative' => 'Kemungkinan pemeliharaan yang sah.']],
            'supporting_indicators' => ['Aktivitas berurutan dari IP yang sama.'], 'legitimate_indicators' => ['Jadwal pemeliharaan belum diperiksa.'],
            'recommended_checks' => ['P1: Validasi sesi dan parent process.'], 'limitations' => ['Belum ada konfirmasi pemilik host.'],
        ], JSON_THROW_ON_ERROR)];
        $attempt = 0;
        Http::fake(['https://ai.example.test/v1/responses' => function () use ($payload, $failureFirst, &$attempt) {
            return $failureFirst && $attempt++ === 0
                ? Http::response(['error' => 'provider-private-reasoning'], 500)
                : Http::response($payload);
        }]);
    }

    public function test_detail_contains_only_the_selection_in_chronological_order_and_does_not_call_ai(): void
    {
        $this->analyst();
        Http::fake();
        $later = $this->alert(['rule_description' => 'Later selected event']);
        $earlier = $this->alert(['first_seen_at' => now()->subMinute(), 'rule_description' => 'Earlier selected event']);
        $this->alert(['rule_description' => 'Unselected event must stay out']);
        $this->get(route('alerts.bulk-detail', ['alert_ids' => [$later->id, $earlier->id]]))->assertOk()
            ->assertSee('Gabungan log alert')->assertSee('Analisis AI gabungan')->assertSee('Kronologi alert terpilih')
            ->assertSeeInOrder(['Earlier selected event', 'Later selected event'])->assertDontSee('Unselected event must stay out')
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();
    }

    public function test_one_provider_request_correlates_all_selected_evidence_without_changing_triage_or_individual_analysis(): void
    {
        $this->analyst();
        $later = $this->alert();
        $earlier = $this->alert(['first_seen_at' => now()->subMinute(), 'agent_name' => 'other-private-host']);
        $this->alert(['rule_description' => 'Unselected marker']);
        $ids = [$earlier->id, $later->id];
        $this->provider($ids);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => array_reverse($ids)])
            ->assertOk()->assertJsonPath('cached', false)->assertJsonPath('analysis.verdict', 'suspicious')
            ->assertJsonPath('analysis.correlations.0.alert_ids', $ids)->assertJsonCount(2, 'analysis.agent_refs');
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($ids): bool {
            $prompt = data_get($request->data(), 'input.1.content.0.text');
            $evidence = json_decode(explode("Bukti tersanitasi:\n", $prompt)[1], true);
            $events = $evidence['events'];

            return array_column(array_column($events, 'metadata'), 'alert_id') === $ids
                && $events[0]['wazuh_event']['data']['username'] === $events[1]['wazuh_event']['data']['username']
                && $events[0]['metadata']['agent_ref'] !== $events[1]['metadata']['agent_ref']
                && ! str_contains($prompt, 'super-secret') && ! str_contains($prompt, 'private-account')
                && ! str_contains($prompt, 'analyst@example.test') && ! str_contains($prompt, 'private-host')
                && ! str_contains($prompt, 'Unselected marker') && str_contains($prompt, 'P1/P2/P3');
        });
        $this->assertSame('new', $later->fresh()->status);
        $this->assertSame('new', $earlier->fresh()->status);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('alert_ai_analyses', 0);
    }

    public function test_cached_result_survives_bulk_ack_but_new_evidence_and_force_request_a_new_analysis(): void
    {
        $this->analyst();
        $first = $this->alert();
        $second = $this->alert();
        $ids = [$first->id, $second->id];
        $this->provider($ids);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertOk();
        $detail = route('alerts.bulk-detail', ['alert_ids' => $ids]);
        $this->from($detail)->post(route('alerts.bulk-triage'), ['alert_ids' => $ids, 'action' => 'acknowledge'])
            ->assertRedirect($detail)->assertSessionHas('success', '2 alert berhasil di-ACK.');
        $this->assertDatabaseCount('alert_triages', 2);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => array_reverse($ids)])->assertOk()->assertJsonPath('cached', true);
        Http::assertSentCount(1);
        $this->get($detail)->assertOk()->assertSee('Login dan aktivitas lanjutan perlu divalidasi.');
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids, 'force' => true])->assertOk()->assertJsonPath('cached', false);
        $first->update(['raw_data' => ['new_evidence' => 'Process hash available']]);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertOk()->assertJsonPath('cached', false);
        Http::assertSentCount(3);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids, 'force' => true])->assertStatus(429);
    }

    public function test_cache_is_separate_for_each_user_and_expires_after_a_day(): void
    {
        $this->analyst();
        $ids = [$this->alert()->id, $this->alert()->id];
        $this->provider($ids);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertOk()->assertJsonPath('cached', false);
        $this->travel(25)->hours();
        $this->actingAs(auth()->user()->fresh());
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertOk()->assertJsonPath('cached', false);
        Http::assertSentCount(3);
    }

    public function test_selection_validation_and_roles_reject_the_entire_request(): void
    {
        $this->analyst();
        Http::fake();
        $id = $this->alert()->id;
        foreach ([[], [$id, $id], [$id, 999999], range(1, 101), ['bad-id']] as $ids) {
            $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertUnprocessable();
            $this->getJson(route('alerts.bulk-detail', ['alert_ids' => $ids]))->assertUnprocessable();
        }
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => [$id], 'force' => 'invalid'])->assertUnprocessable();
        $viewer = User::factory()->create(['role' => 'analyst']);
        $viewer->forceFill(['is_active' => false])->save();
        $this->actingAs($viewer);
        $this->getJson(route('alerts.bulk-detail', ['alert_ids' => [$id]]))->assertUnauthorized();
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => [$id]])->assertUnauthorized();
        $this->assertDatabaseCount('alert_triages', 0);
        Http::assertNothingSent();
    }

    public function test_provider_failure_is_safe_and_releases_the_lock_for_retry(): void
    {
        $this->analyst();
        $ids = [$this->alert()->id, $this->alert()->id];
        $this->provider($ids, true);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertStatus(503)->assertDontSee('provider-private-reasoning');
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertOk();
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_large_selection_keeps_every_alert_with_explicit_excerpt_limits(): void
    {
        $this->analyst();
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $this->alert(['raw_data' => ['data' => ['message' => str_repeat('log ', 2000)]]])->id;
        }
        $this->provider([$ids[0], $ids[1]]);
        $this->postJson(route('alerts.bulk-ai-analysis'), ['alert_ids' => $ids])->assertOk()
            ->assertJsonCount(100, 'analysis.alert_ids')->assertJsonFragment(['Sebagian bukti log berupa cuplikan terbatas. Periksa log lengkap sebelum mengambil tindakan.']);
        Http::assertSent(function (Request $request) use ($ids): bool {
            $prompt = data_get($request->data(), 'input.1.content.0.text');
            $evidence = json_decode(explode("Bukti tersanitasi:\n", $prompt)[1], true);

            return count($evidence['events']) === 100 && strlen($prompt) < 180000
                && array_column(array_column($evidence['events'], 'metadata'), 'alert_id') === $ids;
        });
    }
}
