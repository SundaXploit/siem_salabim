<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use App\Services\OpenSearchService;
use App\Services\TelegramService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class IntegrationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_installation_does_not_copy_integration_credentials_to_the_database(): void
    {
        // Environment configuration remains a fallback until an admin saves settings.
        $this->assertDatabaseCount('configurations', 0);
    }

    #[DataProvider('sslVerificationValues')]
    public function test_opensearch_uses_configured_connection_and_tls_without_saved_settings(
        bool|string $setting,
        bool $expected,
    ): void {
        config([
            'services.opensearch.host' => 'https://configured-indexer.example.test:9200',
            'services.opensearch.username' => 'configured-user',
            'services.opensearch.password' => 'configured-test-password',
            'services.opensearch.index' => 'configured-alerts-*',
            'services.opensearch.verify_ssl' => $setting,
        ]);
        $history = [];
        $service = $this->mockOpenSearchTransport($history);

        $this->assertSame([], $service->fetchAlerts(5, 17));

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('configured-indexer.example.test', $request->getUri()->getHost());
        $this->assertSame('/configured-alerts-*/_search', $request->getUri()->getPath());
        $this->assertSame(
            'Basic '.base64_encode('configured-user:configured-test-password'),
            $request->getHeaderLine('Authorization'),
        );
        $this->assertSame($expected, $history[0]['options']['verify']);
        $this->assertDatabaseCount('configurations', 0);
    }

    public static function sslVerificationValues(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'boolean false' => [false, false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
    }

    public function test_saved_opensearch_settings_override_configured_connection_defaults(): void
    {
        config([
            'services.opensearch.host' => 'https://configured-indexer.example.test:9200',
            'services.opensearch.username' => 'configured-user',
            'services.opensearch.password' => 'configured-test-password',
            'services.opensearch.index' => 'configured-alerts-*',
            'services.opensearch.verify_ssl' => true,
        ]);
        Configuration::set('opensearch_host', 'https://saved-indexer.example.test:9200');
        Configuration::set('opensearch_username', 'saved-user');
        Configuration::set('opensearch_password', 'saved-test-password');
        Configuration::set('opensearch_index', 'saved-alerts-*');
        $history = [];
        $service = $this->mockOpenSearchTransport($history);

        $service->fetchAlerts();

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('saved-indexer.example.test', $request->getUri()->getHost());
        $this->assertSame('/saved-alerts-*/_search', $request->getUri()->getPath());
        $this->assertSame(
            'Basic '.base64_encode('saved-user:saved-test-password'),
            $request->getHeaderLine('Authorization'),
        );
        $this->assertTrue($history[0]['options']['verify']);
    }

    public function test_telegram_uses_configured_token_without_saved_settings(): void
    {
        config([
            'services.telegram.bot_token' => ' configured-test-token ',
            'services.telegram.base_url' => 'https://telegram.example.test',
        ]);
        $history = [];
        $service = $this->mockTelegramTransport($history);

        $result = $service->sendMessage('123', 'Synthetic test message');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $history);
        $this->assertSame('telegram.example.test', $history[0]['request']->getUri()->getHost());
        $this->assertSame('/botconfigured-test-token/sendMessage', $history[0]['request']->getUri()->getPath());
        $this->assertDatabaseCount('configurations', 0);
    }

    public function test_telegram_uses_saved_token_and_observes_updates_after_service_creation(): void
    {
        config(['services.telegram.bot_token' => 'configured-test-token']);
        Configuration::set('telegram_bot_token', 'initial-saved-token');
        $history = [];
        $service = $this->mockTelegramTransport($history);
        Configuration::set('telegram_bot_token', 'updated-saved-token');

        $result = $service->sendMessage('123', 'Synthetic test message');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $history);
        $this->assertSame('/botupdated-saved-token/sendMessage', $history[0]['request']->getUri()->getPath());
    }

    #[DataProvider('savedThresholds')]
    public function test_fetch_command_uses_configured_batch_size_and_resolves_threshold(?int $savedLevel): void
    {
        config([
            'services.opensearch.min_level' => 5,
            'services.opensearch.size' => 17,
        ]);
        $this->travelTo(Carbon::parse('2026-09-17T03:00:00Z'));
        if ($savedLevel !== null) {
            Configuration::set('min_alert_level', $savedLevel);
        }
        $this->mock(OpenSearchService::class, function (MockInterface $service) use ($savedLevel): void {
            $service->shouldReceive('fetchAlerts')->once()->with($savedLevel ?? 5, 17, [
                'gte' => '2026-09-16T17:00:00+00:00',
                'lt' => '2026-09-17T17:00:00+00:00',
            ])->andReturn([]);
        });

        $this->artisan('siem:fetch-alerts')->assertSuccessful();

        $this->assertDatabaseCount('alerts', 0);
    }

    public static function savedThresholds(): iterable
    {
        yield 'configured threshold' => [null];
        yield 'saved threshold' => [13];
    }

    public function test_settings_shows_configured_defaults_without_exposing_integration_secrets(): void
    {
        config([
            'services.opensearch.host' => 'https://configured-indexer.example.test:9200',
            'services.opensearch.username' => 'configured-user',
            'services.opensearch.password' => 'private-configured-opensearch-password',
            'services.opensearch.index' => 'configured-alerts-*',
            'services.opensearch.min_level' => 5,
            'services.telegram.bot_token' => 'private-configured-telegram-token',
            'services.amanai.api_key' => 'private-configured-ai-key',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertViewHas('configs', fn ($configs) => $configs->get('opensearch_host') === 'https://configured-indexer.example.test:9200'
                && $configs->get('opensearch_username') === 'configured-user'
                && $configs->get('opensearch_index') === 'configured-alerts-*'
                && $configs->get('min_alert_level') === 5
                && ! $configs->has('opensearch_password')
                && ! $configs->has('telegram_bot_token')
                && ! $configs->has('ai_api_key')
            )
            ->assertDontSee('private-configured-opensearch-password')
            ->assertDontSee('private-configured-telegram-token')
            ->assertDontSee('private-configured-ai-key');
    }

    public function test_settings_connection_check_uses_configured_password_when_form_is_blank(): void
    {
        config(['services.opensearch.password' => 'configured-test-password']);
        $this->mock(OpenSearchService::class, function (MockInterface $service): void {
            $service->shouldReceive('testConfiguration')->once()
                ->withArgs(fn (array $configuration) => $configuration['opensearch_password'] === 'configured-test-password'
                    && $configuration['opensearch_host'] === 'https://form-indexer.example.test:9200'
                )
                ->andReturn(['success' => true]);
        });

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.opensearch'), [
                'opensearch_host' => 'https://form-indexer.example.test:9200',
                'opensearch_username' => 'form-user',
                'opensearch_password' => '',
                'opensearch_index' => 'wazuh-alerts-*',
            ])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertDontSee('configured-test-password');
        $this->assertDatabaseCount('configurations', 0);
    }

    public function test_settings_telegram_check_uses_configured_token_when_form_is_blank(): void
    {
        config(['services.telegram.bot_token' => 'configured-test-token']);
        $this->mock(TelegramService::class, function (MockInterface $service): void {
            $service->shouldReceive('sendMessage')->once()
                ->withArgs(fn (string $chatId, string $message, string $token) => $chatId === '123' && $token === 'configured-test-token'
                )
                ->andReturn(['success' => true, 'status' => 200, 'error' => null]);
        });

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.telegram'), [
                'chat_id' => '123',
                'telegram_bot_token' => '',
            ])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertDontSee('configured-test-token');
        $this->assertDatabaseCount('configurations', 0);
    }

    private function mockOpenSearchTransport(array &$history): OpenSearchService
    {
        $service = new OpenSearchService;
        // Initialise the real configured client, then replace only its transport.
        $client = (new ReflectionMethod($service, 'client'))->invoke($service);
        $this->mockTransport($client, new Response(200, [], '{"hits":{"hits":[]}}'), $history);

        return $service;
    }

    private function mockTelegramTransport(array &$history): TelegramService
    {
        $service = new TelegramService;
        $client = (new ReflectionProperty($service, 'client'))->getValue($service);
        $this->mockTransport($client, new Response(200, [], '{"ok":true}'), $history);

        return $service;
    }

    private function mockTransport(Client $client, Response $response, array &$history): void
    {
        $handler = $client->getConfig('handler');
        $handler->setHandler(new MockHandler([$response]));
        $handler->push(Middleware::history($history));
    }
}
