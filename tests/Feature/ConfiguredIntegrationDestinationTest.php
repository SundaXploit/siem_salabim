<?php

namespace Tests\Feature;

use App\Exceptions\AiAnalysisException;
use App\Exceptions\UnsafeOutboundEndpoint;
use App\Models\Configuration;
use App\Models\User;
use App\Services\AiProviderService;
use App\Services\OutboundEndpointPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConfiguredIntegrationDestinationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('unapprovedDestinations')]
    public function test_tests_and_catalogues_cannot_authorize_a_submitted_destination(string $destination): void
    {
        Configuration::set('opensearch_host', 'https://saved.example.test:9200');
        Configuration::set('ai_base_url', 'https://saved.example.test:9200');
        $this->mock(OutboundEndpointPolicy::class)->makePartial()->shouldAllowMockingProtectedMethods()
            ->shouldNotReceive('resolve');
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson(route('settings.test.opensearch'), [
            'opensearch_host' => $destination, 'opensearch_username' => 'fixture',
            'opensearch_password' => 'temporary-secret', 'opensearch_index' => 'test',
        ])->assertOk()->assertJsonPath('success', false)->assertJsonMissingPath('count')
            ->assertSee('konfigurasi tersimpan')->assertDontSee('temporary-secret');
        foreach (['settings.test.ai', 'settings.ai.models'] as $route) {
            $this->postJson(route($route), [
                'ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions',
                'ai_base_url' => $destination, 'ai_model' => 'model',
            ])->assertOk()->assertJsonPath('success', false)->assertJsonMissingPath('reply')
                ->assertJsonMissingPath('models')->assertSee('konfigurasi tersimpan');
        }
        Http::assertNothingSent();
        $this->assertSame('https://saved.example.test:9200', Configuration::get('opensearch_host'));
        $this->assertSame('https://saved.example.test:9200', Configuration::get('ai_base_url'));
    }

    public static function unapprovedDestinations(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://127.0.0.1:7788', 'http://127.0.0.1:7779', 'http://localhost:9200',
            'https://10.0.0.5:9200', 'http://192.168.1.1', 'http://[::1]:9200',
            'https://saved.example.test:9201', 'http://saved.example.test:9200',
            'https://saved.example.test:9200/v1', 'https://other.example.test:9200',
        ]);
    }

    public function test_saving_ai_without_a_model_authorizes_only_that_destination_and_catalogue(): void
    {
        $this->fakeIntegrationDns();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $input = ['ai_provider' => 'local', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'http://192.168.1.15:11434/v1', 'ai_model' => '',
            'ai_snapshot_limit' => 1000, 'ai_refresh_days' => 7];
        Http::fake([$input['ai_base_url'].'/models' => Http::response(['data' => [['id' => 'local-model']]])]);
        $this->post(route('settings.ai'), $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($input['ai_base_url'], Configuration::get('ai_base_url'));
        $this->assertSame('', Configuration::get('ai_model'));
        $this->assertFalse(app(AiProviderService::class)->isConfigured());
        $this->postJson(route('settings.ai.models'), $input)->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('models.0.id', 'local-model');
        Http::assertSentCount(1);
        $this->post(route('settings.ai'), [...$input, 'ai_model' => 'local-model'])->assertSessionHasNoErrors();
        $this->assertTrue(app(AiProviderService::class)->isConfigured());
        $this->assertDatabaseHas('activity_logs', ['action' => 'ai_updated', 'result' => 'success']);
    }

    public function test_an_admin_can_replace_the_destination_and_the_old_one_is_no_longer_authorized(): void
    {
        Configuration::set('opensearch_host', 'https://old.example.test:9200');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('settings.opensearch'), [
                'opensearch_host' => 'https://10.0.0.5:9443', 'opensearch_username' => 'fixture',
                'opensearch_password' => 'new-secret', 'opensearch_index' => 'test',
            ])->assertSessionHasNoErrors();
        $policy = app(OutboundEndpointPolicy::class);
        $policy->assertConfigured('opensearch', 'https://10.0.0.5:9443/');
        $this->assertDatabaseHas('activity_logs', ['action' => 'opensearch_updated', 'result' => 'success']);
        $this->expectException(UnsafeOutboundEndpoint::class);
        $policy->assertConfigured('opensearch', 'https://old.example.test:9200');
    }

    public function test_configured_endpoints_match_only_normalized_scheme_host_port_and_base_path(): void
    {
        Configuration::set('ai_base_url', 'https://AI.EXAMPLE.TEST:443/v1/');
        app(OutboundEndpointPolicy::class)->assertConfigured('ai', 'https://ai.example.test/v1');
        $this->expectException(UnsafeOutboundEndpoint::class);
        app(OutboundEndpointPolicy::class)->assertConfigured('ai', 'https://ai.example.test/v10');
    }

    public function test_saved_empty_configuration_disables_the_environment_fallback(): void
    {
        config(['services.ai.base_url' => 'https://ai.example.test/v1']);
        Configuration::set('ai_base_url', '');
        $this->expectException(UnsafeOutboundEndpoint::class);
        app(OutboundEndpointPolicy::class)->assertConfigured('ai', 'https://ai.example.test/v1');
    }

    public function test_a_cached_transport_rejects_an_old_destination_after_configuration_changes(): void
    {
        $this->fakeIntegrationDns();
        Configuration::set('opensearch_host', 'https://old.example.test:9200');
        $policy = app(OutboundEndpointPolicy::class);
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->unshift($policy->middleware('https://old.example.test:9200', 'opensearch'));
        $client = new Client(['handler' => $stack]);
        $this->assertSame(200, $client->get('https://old.example.test:9200/test/_count')->getStatusCode());
        Configuration::set('opensearch_host', 'https://new.example.test:9200');
        $this->expectException(UnsafeOutboundEndpoint::class);
        $client->get('https://old.example.test:9200/test/_count');
    }

    public function test_operational_ai_cannot_use_a_different_endpoint_or_the_installer_mode(): void
    {
        Http::fake();
        Configuration::set('ai_base_url', 'https://saved.example.test/v1');
        $settings = ['provider' => 'ollama', 'api_format' => 'chat_completions',
            'base_url' => 'http://127.0.0.1:7779', 'api_key' => '', 'model' => 'model'];
        foreach ([false, true] as $installation) {
            try {
                if ($installation) {
                    app(AiProviderService::class)->models($settings, installation: true);
                } else {
                    app(AiProviderService::class)->generate('system', 'prompt', 256, 5, settings: $settings);
                }
                $this->fail('An unapproved destination or installer bypass was accepted.');
            } catch (AiAnalysisException) {
                Http::assertNothingSent();
            }
        }
    }

    public function test_metadata_cannot_be_approved_by_saving_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.opensearch'), [
                'opensearch_host' => 'http://169.254.169.254', 'opensearch_username' => 'fixture',
                'opensearch_password' => 'secret', 'opensearch_index' => 'test',
            ])->assertUnprocessable()->assertJsonValidationErrors('opensearch_host');
        $this->assertNull(Configuration::get('opensearch_host'));
    }
}
