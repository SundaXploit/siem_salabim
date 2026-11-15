<?php

namespace Tests\Feature;

use App\Exceptions\AiAnalysisException;
use App\Exceptions\InstallationException;
use App\Models\Configuration;
use App\Models\User;
use App\Services\AiProviderService;
use App\Services\Installation\InstallationConnectionTester;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationEndpointSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_is_blocked_in_both_ai_settings_endpoints_before_http_is_sent(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $input = ['ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'http://169.254.169.254', 'ai_model' => 'model', 'ai_api_key' => ''];
        foreach (['settings.test.ai', 'settings.ai.models'] as $route) {
            $this->postJson(route($route), $input)->assertUnprocessable()->assertJsonValidationErrors('ai_base_url')
                ->assertJsonMissingPath('reply')->assertJsonMissingPath('models')->assertSee('SSRF');
        }
        Http::assertNothingSent();
    }

    public function test_opensearch_test_and_normal_fetch_block_metadata(): void
    {
        $input = ['opensearch_host' => 'http://169.254.169.254', 'opensearch_username' => 'fixture',
            'opensearch_password' => 'fixture-secret', 'opensearch_index' => 'test'];
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.opensearch'), $input)->assertUnprocessable()->assertJsonValidationErrors('opensearch_host')
            ->assertJsonMissingPath('count')->assertSee('SSRF')->assertDontSee('fixture-secret');
        config(['services.opensearch.host' => $input['opensearch_host'], 'services.opensearch.index' => 'test']);
        $this->expectExceptionMessage('SSRF');
        (new OpenSearchService)->fetchAlerts();
    }

    public function test_installer_opensearch_has_the_same_metadata_guard(): void
    {
        $this->expectException(InstallationException::class);
        $this->expectExceptionMessage('SSRF');
        (new InstallationConnectionTester)->testOpenSearch([
            'opensearch_host' => 'http://169.254.169.254', 'opensearch_username' => 'fixture',
            'opensearch_password' => 'fixture-secret', 'opensearch_index' => 'test',
        ]);
    }

    public function test_regular_ai_generation_cannot_bypass_metadata_protection(): void
    {
        Configuration::set('ai_base_url', 'http://169.254.169.254');
        Http::fake();
        try {
            app(AiProviderService::class)->generate('system', 'prompt', 256, 5, settings: [
                'provider' => 'ollama', 'api_format' => 'chat_completions', 'base_url' => 'http://169.254.169.254',
                'api_key' => '', 'model' => 'model',
            ]);
            $this->fail('Metadata access was not blocked.');
        } catch (AiAnalysisException $exception) {
            $this->assertStringContainsString('SSRF', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_ai_test_does_not_reflect_the_upstream_response(): void
    {
        Configuration::set('ai_base_url', 'http://127.0.0.1:7779');
        Http::fake(['http://127.0.0.1:7779/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'private-internal-response']]],
        ])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.ai'), [
                'ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions',
                'ai_base_url' => 'http://127.0.0.1:7779', 'ai_model' => 'model',
            ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('reply', 'Respons AI diterima.')
            ->assertDontSee('private-internal-response');
        Http::assertSentCount(1);
    }

    public function test_changing_the_opensearch_destination_cannot_reuse_a_saved_password(): void
    {
        Configuration::set('opensearch_host', 'https://saved.example.test:9200');
        Configuration::set('opensearch_username', 'fixture');
        Configuration::set('opensearch_password', 'saved-private-secret');
        $this->mock(OpenSearchService::class)->shouldNotReceive('testConfiguration');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $input = ['opensearch_host' => 'http://127.0.0.1:7788', 'opensearch_username' => 'fixture',
            'opensearch_password' => '', 'opensearch_index' => 'test'];
        foreach (['settings.test.opensearch', 'settings.opensearch'] as $route) {
            $this->postJson(route($route), $input)->assertUnprocessable()->assertJsonValidationErrors('opensearch_password');
        }
        $this->assertSame('https://saved.example.test:9200', Configuration::get('opensearch_host'));
        $this->assertSame('saved-private-secret', Configuration::get('opensearch_password'));
    }
}
