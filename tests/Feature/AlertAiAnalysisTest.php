<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertAiAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amanai.alert_model' => null,
            'services.amanai.alert_reasoning_effort' => 'none',
        ]);
    }

    public function test_opening_alert_detail_does_not_call_ai(): void
    {
        Http::fake();
        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $this->actingAs($analyst)
            ->get(route('alerts.show', $alert))
            ->assertOk()
            ->assertSee('Analisis AI alert');

        Http::assertNothingSent();
    }

    public function test_analyst_can_request_a_sanitised_on_demand_ai_hint(): void
    {
        Configuration::set('ai_api_key', 'test-api-key');
        Configuration::set('ai_base_url', 'https://api.amanai.dev/v1');
        Configuration::set('ai_model', 'amanai/glm-5.3');

        Http::fake([
            'https://api.amanai.dev/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'verdict' => 'likely_malicious',
                            'confidence' => 82,
                            'summary' => 'Aktivitas memiliki indikator yang perlu diinvestigasi.',
                            'supporting_indicators' => ['Executable dibuat pada direktori web.'],
                            'legitimate_indicators' => [],
                            'recommended_checks' => ['Validasi hash dan parent process.'],
                            'limitations' => ['Tidak ada reputasi hash eksternal.'],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ], 200),
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('analysis.verdict', 'likely_malicious')
            ->assertJsonPath('analysis.confidence', 82)
            ->assertJsonPath('analysis.model', 'amanai/glm-5.3')
            ->assertJsonPath('cached', false);

        $this->assertDatabaseHas('alert_ai_analyses', [
            'alert_id' => $alert->id,
            'requested_by_user_id' => $analyst->id,
            'verdict' => 'likely_malicious',
            'confidence' => 82,
            'model' => 'amanai/glm-5.3',
        ]);

        Http::assertSent(function (Request $request): bool {
            $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return $request->url() === 'https://api.amanai.dev/v1/responses'
                && data_get($request->data(), 'reasoning.effort') === 'none'
                && data_get($request->data(), 'max_output_tokens') <= 3072
                && !str_contains($payload, 'super-secret')
                && !str_contains($payload, 'analyst@example.test')
                && str_contains($payload, '[REDACTED]')
                && str_contains($payload, '[EMAIL_REDACTED]');
        });
    }

    public function test_alert_model_override_is_used_and_saved_without_changing_the_shared_model(): void
    {
        $this->configureAi();
        config(['services.amanai.alert_model' => 'amanai/qwen3.7-plus']);
        Http::fake([
            'https://api.amanai.dev/v1/responses' => Http::response($this->successfulProviderPayload()),
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertOk()
            ->assertJsonPath('analysis.model', 'amanai/qwen3.7-plus');

        Http::assertSent(fn (Request $request): bool =>
            $request['model'] === 'amanai/qwen3.7-plus'
            && data_get($request->data(), 'reasoning.effort') === 'none'
        );
        $this->assertDatabaseHas('alert_ai_analyses', [
            'alert_id' => $alert->id,
            'model' => 'amanai/qwen3.7-plus',
        ]);
        $this->assertSame('amanai/glm-5.3', Configuration::get('ai_model'));
    }

    #[DataProvider('reasoningEffortSettings')]
    public function test_reasoning_setting_defaults_to_none_and_normalises_explicit_choices(
        ?string $configured,
        string $expected,
    ): void {
        $this->configureAi();
        config(['services.amanai.alert_reasoning_effort' => $configured]);
        Http::fake([
            'https://api.amanai.dev/v1/responses' => Http::response($this->successfulProviderPayload()),
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertOk();

        Http::assertSent(fn (Request $request): bool =>
            data_get($request->data(), 'reasoning.effort') === $expected
        );
    }

    public static function reasoningEffortSettings(): array
    {
        return [
            'null falls back to none' => [null, 'none'],
            'empty falls back to none' => ['', 'none'],
            'blank falls back to none' => ['   ', 'none'],
            'invalid falls back to none' => ['invalid-effort', 'none'],
            'none is trimmed and case insensitive' => [' NONE ', 'none'],
            'explicit low is still supported' => [' LOW ', 'low'],
        ];
    }

    public function test_missing_reasoning_setting_disables_thinking(): void
    {
        $this->configureAi();
        $settings = config('services.amanai');
        unset($settings['alert_reasoning_effort']);
        config(['services.amanai' => $settings]);
        Http::fake([
            'https://api.amanai.dev/v1/responses' => Http::response($this->successfulProviderPayload()),
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertOk();

        Http::assertSent(fn (Request $request): bool =>
            data_get($request->data(), 'reasoning.effort') === 'none'
        );
    }

    public function test_provider_failure_is_not_retried_and_releases_the_alert_lock(): void
    {
        $this->configureAi();
        Http::fake([
            'https://api.amanai.dev/v1/responses' => Http::sequence()
                ->push(['error' => ['message' => 'provider-internal-detail']], 429)
                ->push($this->successfulProviderPayload()),
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertDontSee('provider-internal-detail');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('alert_ai_analyses', 0);

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cached', false);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('alert_ai_analyses', 1);
    }

    public function test_reasoning_only_response_is_not_saved_or_retried(): void
    {
        $this->configureAi();
        Http::fake([
            'https://api.amanai.dev/v1/responses' => Http::response([
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [[
                    'type' => 'reasoning',
                    'summary' => [[
                        'type' => 'summary_text',
                        'text' => 'provider-private-reasoning',
                    ]],
                ]],
                'usage' => [
                    'output_tokens' => 2800,
                    'output_tokens_details' => ['reasoning_tokens' => 2800],
                ],
            ]),
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertDontSee('provider-private-reasoning');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('alert_ai_analyses', 0);
    }

    public function test_saved_analysis_is_returned_without_calling_provider_again(): void
    {
        Http::fake();
        $analyst = User::factory()->create(['role' => 'analyst']);
        $alert = $this->makeAlert();

        $alert->aiAnalyses()->create([
            'requested_by_user_id' => $analyst->id,
            'verdict' => 'suspicious',
            'confidence' => 64,
            'summary' => 'Hasil tersimpan untuk alert ini.',
            'supporting_indicators' => ['Indikator tersimpan.'],
            'legitimate_indicators' => [],
            'recommended_checks' => ['Periksa sumber aktivitas.'],
            'limitations' => [],
            'model' => 'amanai/glm-5.3',
            'duration_ms' => 1250,
            'generated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->postJson(route('alerts.ai-analysis', $alert))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cached', true)
            ->assertJsonPath('analysis.summary', 'Hasil tersimpan untuk alert ini.')
            ->assertJsonPath('analysis.duration_ms', 1250);

        Http::assertNothingSent();
    }

    private function configureAi(): void
    {
        Configuration::set('ai_api_key', 'test-api-key');
        Configuration::set('ai_base_url', 'https://api.amanai.dev/v1');
        Configuration::set('ai_model', 'amanai/glm-5.3');
    }

    private function successfulProviderPayload(): array
    {
        return [
            'output_text' => json_encode([
                'verdict' => 'inconclusive',
                'confidence' => 30,
                'summary' => 'Bukti perlu divalidasi oleh analyst.',
                'supporting_indicators' => [],
                'legitimate_indicators' => [],
                'recommended_checks' => ['Periksa proses sumber.'],
                'limitations' => ['Konteks proses belum tersedia.'],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function makeAlert(): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => 'alert-ai-test',
            'rule_description' => 'Executable file dropped in web directory',
            'src_ip' => '192.0.2.10',
            'dst_ip' => '192.0.2.20',
            'agent_name' => 'sensitive-hostname',
            'rule_id' => '92213',
            'rule_level' => 15,
            'status' => 'new',
            'first_seen_at' => now(),
            'raw_data' => [
                'data' => [
                    'password' => 'super-secret',
                    'message' => 'Contact analyst@example.test for context',
                    'path' => '/var/www/html/shell.php',
                ],
            ],
        ]);
    }
}
