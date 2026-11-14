<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ai.api_key' => '', 'services.ai.base_url' => '']);
    }

    #[DataProvider('providers')]
    public function test_models_are_read_without_a_model_name_or_generation_request(string $provider, string $format): void
    {
        $local = in_array($provider, ['ollama', 'lmstudio'], true);
        $baseUrl = $local ? 'http://127.0.0.1:11434/v1' : 'https://gateway.example.test/tenant/soc/v1';
        Http::fake([$baseUrl.'/models*' => Http::response(['data' => [
            ['id' => 'model-b', 'owner_secret' => 'private-metadata'],
            ['id' => 'model-a', 'display_name' => 'Model A'], ['id' => 'model-b'], ['id' => null],
        ]])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.ai.models'), [
                'ai_provider' => $provider, 'ai_api_format' => $format,
                'ai_base_url' => $baseUrl, 'ai_api_key' => $local ? '' : 'temporary-secret',
            ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('success', true)->assertJsonCount(2, 'models')
            ->assertJsonPath('models.0.id', 'model-a')->assertJsonPath('models.0.name', 'Model A')
            ->assertJsonPath('models.1.id', 'model-b')->assertJsonPath('truncated', false)
            ->assertDontSee('private-metadata')->assertDontSee('temporary-secret');

        Http::assertSent(function (Request $request) use ($format, $local, $baseUrl): bool {
            $this->assertSame('GET', $request->method());
            $this->assertSame(parse_url($baseUrl, PHP_URL_PATH).'/models', parse_url($request->url(), PHP_URL_PATH));
            if ($format === 'anthropic_messages') {
                $this->assertTrue($request->hasHeader('x-api-key', 'temporary-secret'));
                $this->assertTrue($request->hasHeader('anthropic-version'));
            } else {
                $this->assertSame(! $local, $request->hasHeader('Authorization'));
            }

            return true;
        });
        Http::assertSentCount(1);
        $this->assertDatabaseCount('configurations', 0);
    }

    public static function providers(): array
    {
        return [
            ['openai', 'responses'], ['anthropic', 'anthropic_messages'], ['gemini', 'chat_completions'],
            ['ollama', 'chat_completions'], ['lmstudio', 'chat_completions'],
            ['custom', 'chat_completions'], ['custom', 'responses'], ['custom', 'anthropic_messages'],
        ];
    }

    public function test_analysts_and_guests_cannot_discover_models(): void
    {
        $this->postJson(route('settings.ai.models'), $this->input())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson(route('settings.ai.models'), $this->input())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_stored_token_is_used_only_for_the_same_destination(): void
    {
        Configuration::set('ai_provider', 'custom');
        Configuration::set('ai_base_url', 'https://gateway.example.test/v1');
        Configuration::set('ai_api_key', 'saved-private-key');
        Http::fake(['https://gateway.example.test/v1/models' => Http::response(['data' => [['id' => 'model-a']]])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson(route('settings.ai.models'), $this->input(['ai_api_key' => '']))
            ->assertJsonPath('success', true)->assertDontSee('saved-private-key');
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer saved-private-key'));
        $this->postJson(route('settings.ai.models'), $this->input(['ai_api_key' => '', 'ai_base_url' => 'https://different.example.test/v1']))
            ->assertJsonPath('success', false);
        Http::assertSentCount(1);
        $this->assertSame('saved-private-key', Configuration::get('ai_api_key'));
    }

    #[DataProvider('failures')]
    public function test_failed_or_unsupported_catalogues_return_safe_feedback(int $status, string $body): void
    {
        Http::fake(['*/models' => Http::response($body, $status)]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.ai.models'), $this->input())
            ->assertOk()->assertJsonPath('success', false)->assertJsonStructure(['error'])
            ->assertDontSee('private-provider-detail');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('configurations', 0);
    }

    public static function failures(): array
    {
        return [
            [401, '{"error":"private-provider-detail"}'], [404, '{"error":"private-provider-detail"}'],
            [429, '{"error":"private-provider-detail"}'], [500, 'private-provider-detail'],
            [200, '<html>private-provider-detail</html>'], [200, '{"error":"private-provider-detail"}'],
        ];
    }

    public function test_an_empty_catalogue_does_not_invent_models(): void
    {
        Http::fake(['*/models' => Http::response(['data' => []])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.ai.models'), $this->input())
            ->assertJsonPath('success', true)->assertJsonPath('models', []);
    }

    public function test_anthropic_pagination_keeps_the_same_endpoint_and_authentication(): void
    {
        Http::fake(['https://gateway.example.test/v1/models*' => Http::sequence()
            ->push(['data' => [['id' => 'model-a']], 'has_more' => true, 'last_id' => 'model-a'])
            ->push(['data' => [['id' => 'model-b']], 'has_more' => false])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.ai.models'), $this->input(['ai_api_format' => 'anthropic_messages']))
            ->assertJsonCount(2, 'models')->assertJsonPath('truncated', false);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after_id=model-a')
            && $request->hasHeader('x-api-key', 'temporary-secret'));
        Http::assertSentCount(2);
    }

    public function test_large_catalogues_report_truncation(): void
    {
        Http::fake(['*/models' => Http::response(['data' => array_map(fn ($index) => ['id' => 'model-'.$index], range(1, 1005))])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.ai.models'), $this->input())
            ->assertJsonCount(1000, 'models')->assertJsonPath('truncated', true);
        Http::assertSentCount(1);
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'ai_provider' => 'custom', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'https://gateway.example.test/v1', 'ai_api_key' => 'temporary-secret',
        ], $overrides);
    }
}
