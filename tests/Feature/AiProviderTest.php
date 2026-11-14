<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\User;
use App\Services\AiInsightService;
use App\Services\AiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ai.api_key' => '', 'services.ai.base_url' => '', 'services.ai.model' => '']);
    }

    #[DataProvider('providers')]
    public function test_connection_uses_the_selected_provider_format_and_authentication(string $provider, ?string $apiFormat = null): void
    {
        $preset = config('ai_providers.'.$provider);
        $format = $apiFormat ?? $preset['api_format'];
        $local = AiProviderService::isLocal($provider);
        $path = match ($format) {
            'responses' => '/responses', 'anthropic_messages' => '/messages', default => '/chat/completions',
        };
        $url = $preset['base_url'] ?: ($local ? 'http://llm.internal:8000/v1' : 'https://gateway.example.test/tenant/soc/api/v1');
        $answer = match ($format) {
            'responses' => ['output_text' => '', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'pong']]]]],
            'anthropic_messages' => ['content' => [['type' => 'thinking', 'thinking' => 'private'], ['type' => 'text', 'text' => 'pong']]],
            default => ['choices' => [['message' => ['content' => 'pong']]]],
        };
        Http::fake([$url.$path => Http::response($answer)]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.ai'), [
                'ai_provider' => $provider, 'ai_api_format' => $format,
                'ai_base_url' => $url, 'ai_model' => 'test-model', 'ai_api_key' => $local ? '' : 'temporary-secret',
            ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('reply', 'pong');

        Http::assertSent(function (Request $request) use ($url, $path, $format, $local): bool {
            $this->assertSame($url.$path, $request->url());
            $this->assertSame('test-model', $request['model']);
            $this->assertFalse($request['stream']);
            if ($format === 'anthropic_messages') {
                $this->assertTrue($request->hasHeader('x-api-key', 'temporary-secret'));
                $this->assertTrue($request->hasHeader('anthropic-version'));
                $this->assertSame('user', $request['messages'][0]['role']);
                $this->assertArrayHasKey('system', $request->data());
            } else {
                $this->assertSame(! $local, $request->hasHeader('Authorization'));
            }
            if ($format === 'chat_completions') {
                $this->assertArrayNotHasKey('reasoning', $request->data());
                $this->assertArrayNotHasKey('input', $request->data());
                $this->assertSame('system', $request['messages'][0]['role']);
            }

            return true;
        });
        Http::assertSentCount(1);
        $this->assertDatabaseCount('configurations', 0);
    }

    public static function providers(): array
    {
        return [
            ...array_map(fn ($provider) => [$provider], [
                'openai', 'anthropic', 'gemini', 'openrouter', 'deepseek', 'groq', 'ollama', 'lmstudio', 'local',
            ]),
            'custom chat completions' => ['custom', 'chat_completions'],
            'custom responses' => ['custom', 'responses'],
            'custom anthropic messages' => ['custom', 'anthropic_messages'],
        ];
    }

    public function test_local_configuration_saves_without_a_key_and_removes_previous_cloud_credentials(): void
    {
        Configuration::set('ai_api_key', 'previous-cloud-secret');
        Configuration::set('ai_base_url', 'https://old-provider.example.test/v1');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('settings.ai'), $this->settings([
                'ai_provider' => 'ollama', 'ai_base_url' => 'http://127.0.0.1:11434/v1', 'ai_api_key' => '',
            ]))->assertSessionHasNoErrors();
        $this->assertSame('ollama', Configuration::get('ai_provider'));
        $this->assertSame('', Configuration::get('ai_api_key'));
        $this->assertTrue(app(AiInsightService::class)->isConfigured());
        $this->get(route('settings.index', ['section' => 'ai']))->assertOk()->assertSee('Ollama')->assertSee('LM Studio')
            ->assertSee('Custom endpoint / API kompatibel')->assertDontSee('AmanAI');
        Http::assertNothingSent();
    }

    public function test_blank_key_is_reused_only_for_the_same_provider_and_endpoint(): void
    {
        foreach (['provider' => 'custom', 'api_format' => 'chat_completions', 'base_url' => 'https://saved.example.test/v1', 'api_key' => 'saved-secret', 'model' => 'saved-model'] as $key => $value) {
            Configuration::set('ai_'.$key, $value);
        }
        Http::fake(['https://saved.example.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'pong']]]])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = $this->settings(['ai_base_url' => 'https://saved.example.test/v1', 'ai_api_key' => '']);
        $this->postJson(route('settings.test.ai'), $data)->assertJsonPath('success', true);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer saved-secret'));
        $data['ai_base_url'] = 'https://different.example.test/v1';
        $this->postJson(route('settings.test.ai'), $data)->assertStatus(422)->assertJsonPath('success', false);
        $this->post(route('settings.ai'), $data)->assertSessionHasErrors('ai_api_key');
        $this->assertSame('saved-secret', Configuration::get('ai_api_key'));
        Http::assertSentCount(1);
    }

    public function test_cloud_requires_https_and_a_key_and_unknown_providers_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.ai'), $this->settings(['ai_provider' => 'openai', 'ai_base_url' => 'http://api.example.test/v1']))
            ->assertUnprocessable()->assertJsonValidationErrors('ai_base_url');
        $this->post(route('settings.ai'), $this->settings(['ai_api_key' => '']))->assertSessionHasErrors('ai_api_key');
        $this->postJson(route('settings.test.ai'), $this->settings(['ai_provider' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('ai_provider');
        Http::assertNothingSent();
    }

    public function test_local_provider_runs_dashboard_and_alert_analysis_through_the_same_connection(): void
    {
        foreach (['provider' => 'ollama', 'api_format' => 'chat_completions', 'base_url' => 'http://127.0.0.1:11434/v1', 'api_key' => '', 'model' => 'local-model'] as $key => $value) {
            Configuration::set('ai_'.$key, $value);
        }
        $summary = str_repeat('Aktivitas autentikasi perlu diverifikasi dengan bukti yang tersedia. ', 4);
        Http::fake(['http://127.0.0.1:11434/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => $summary]]]])
            ->push(['choices' => [['message' => ['content' => json_encode([
                'verdict' => 'suspicious', 'confidence' => 64, 'summary' => 'Perlu investigasi lanjutan.',
            ])]]]])]);
        $alert = Alert::create([
            'wazuh_alert_id' => 'local-provider-test', 'rule_id' => '990001', 'rule_level' => 12,
            'rule_description' => 'Suspicious login', 'agent_name' => 'host', 'status' => 'new',
            'first_seen_at' => now(), 'raw_data' => ['agent' => ['id' => '001'], 'password' => 'private-password'],
        ]);
        $this->actingAs(User::factory()->forAgent('001', ['alerts.analyze'])->create())
            ->postJson(route('dashboard.insight.refresh'))->assertOk()->assertJsonPath('insight.summary', trim($summary));
        $this->assertDatabaseHas('dashboard_insights', ['provider' => 'ollama', 'model' => 'local-model']);
        $this->postJson(route('alerts.ai-analysis', $alert))->assertOk()
            ->assertJsonPath('analysis.verdict', 'suspicious')->assertJsonPath('analysis.model', 'local-model');
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertStringNotContainsString('private-password', json_encode($request->data()));

            return true;
        });
    }

    public function test_legacy_saved_endpoint_keeps_responses_format_until_explicitly_changed(): void
    {
        Configuration::set('ai_base_url', 'https://saved.example.test/v1');
        Configuration::set('ai_model', 'existing-model');
        Configuration::set('ai_api_key', 'existing-key');
        $settings = app(AiProviderService::class)->settings();
        $this->assertSame('custom', $settings['provider']);
        $this->assertSame('responses', $settings['api_format']);
        $this->assertTrue(app(AiProviderService::class)->isConfigured());
    }

    private function settings(array $overrides = []): array
    {
        return array_replace([
            'ai_provider' => 'custom', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'https://ai.example.test/v1', 'ai_model' => 'test-model', 'ai_api_key' => 'test-key',
            'ai_snapshot_limit' => 10000, 'min_alert_level' => 12, 'ai_refresh_days' => 10,
        ], $overrides);
    }
}
