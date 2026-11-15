<?php

namespace Tests\Feature;

use App\Exceptions\InstallationException;
use App\Models\Configuration;
use App\Models\User;
use App\Services\Installation\InstallationConnectionTester;
use App\Services\OpenSearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenSearchTlsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $queue): OpenSearchService
    {
        config(['services.opensearch.host' => 'https://wazuh.example.test:9200', 'services.opensearch.username' => 'fixture',
            'services.opensearch.password' => 'fixture-secret', 'services.opensearch.index' => 'wazuh-alerts-*', 'services.opensearch.verify_ssl' => true]);

        return new class($queue) extends OpenSearchService
        {
            public array $verifications = [];

            public function __construct(private array $queue)
            {
                parent::__construct();
            }

            protected function makeClient(string $host, string $username, string $password, ?bool $verifySsl = null): Client
            {
                $this->verifications[] = $verifySsl ?? $this->verifySsl;
                foreach ($this->queue as $item) {
                    if ($item instanceof Response) {
                        $item->getBody()->rewind();
                    }
                }

                return new Client(['base_uri' => $host, 'handler' => HandlerStack::create(new MockHandler($this->queue)), 'verify' => $verifySsl ?? $this->verifySsl]);
            }
        };
    }

    public function test_ssl_setting_is_persisted_and_the_test_button_uses_the_unsaved_selection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['opensearch_host' => 'https://wazuh.example.test:9200', 'opensearch_username' => 'fixture',
            'opensearch_password' => 'fixture-secret', 'opensearch_index' => 'wazuh-alerts-*', 'opensearch_verify_ssl' => '0'];
        $this->actingAs($admin)->post(route('settings.opensearch'), $data)->assertSessionHasNoErrors();
        $this->assertSame('false', Configuration::get('opensearch_verify_ssl'));
        $service = $this->service([new Response(200, [], '{"count":2}')]);
        $this->app->instance(OpenSearchService::class, $service);
        $this->postJson(route('settings.test.opensearch'), [...$data, 'opensearch_verify_ssl' => '1'])->assertOk()->assertJsonPath('success', true);
        $this->assertSame([true], $service->verifications);
        $this->assertSame('false', Configuration::get('opensearch_verify_ssl'));
        $this->get(route('settings.index', ['section' => 'opensearch']))->assertOk()->assertSee('Verifikasi SSL Wazuh/OpenSearch');
        $this->postJson(route('settings.opensearch'), [...$data, 'opensearch_verify_ssl' => 'invalid'])->assertUnprocessable();
    }

    public function test_long_running_service_applies_saved_ssl_changes_on_the_next_fetch(): void
    {
        $service = $this->service([new Response(200, [], '{"hits":{"hits":[]}}')]);
        $service->fetchAlerts();
        Configuration::set('opensearch_verify_ssl', 'false');
        $service->fetchAlerts();
        $this->assertSame([true, false], $service->verifications);
    }

    public function test_certificate_failure_has_actionable_safe_advice_without_disabling_verification(): void
    {
        $exception = new ConnectException('SSL certificate problem; https://private-user:secret@upstream.test', new Request('GET', 'https://wazuh.example.test'), null, ['errno' => 60]);
        $service = $this->service([$exception]);
        $result = $service->testConnection();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Verifikasi SSL = false', $result['error']);
        $this->assertStringContainsString('CA', $result['error']);
        $this->assertStringNotContainsString('private-user', $result['error']);
        $this->assertSame([true], $service->verifications);
        $this->assertNull(Configuration::get('opensearch_verify_ssl'));
        $this->expectExceptionMessage('Verifikasi SSL = false');
        $service->fetchAlerts();
    }

    public function test_non_certificate_network_error_does_not_suggest_disabling_ssl(): void
    {
        $service = $this->service([new ConnectException('private-host timeout', new Request('GET', 'https://wazuh.example.test'), null, ['errno' => 28])]);
        $result = $service->testConnection();
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('false', $result['error']);
        $this->assertStringNotContainsString('private-host', $result['error']);
    }

    public function test_installer_tests_the_selected_ssl_mode_and_explains_a_certificate_failure(): void
    {
        $tester = new class extends InstallationConnectionTester
        {
            public array $verifications = [];

            protected function openSearchClient(array $options): Client
            {
                $this->verifications[] = $options['verify'];
                $result = $options['verify']
                    ? new ConnectException('private TLS failure', new Request('GET', 'https://wazuh.example.test'), null, ['errno' => 60])
                    : new Response(200, [], '{"count":7}');

                return new Client(['base_uri' => $options['base_uri'], 'handler' => HandlerStack::create(new MockHandler([$result]))]);
            }
        };
        $input = ['opensearch_host' => 'https://wazuh.example.test', 'opensearch_username' => 'fixture',
            'opensearch_password' => 'fixture-secret', 'opensearch_index' => 'wazuh-alerts-*', 'opensearch_verify_ssl' => false];
        $this->assertSame(7, $tester->testOpenSearch($input)['count']);
        try {
            $tester->testOpenSearch([...$input, 'opensearch_verify_ssl' => true]);
            $this->fail('An untrusted certificate was accepted by the installer.');
        } catch (InstallationException $exception) {
            $this->assertStringContainsString('Verifikasi SSL = false', $exception->getMessage());
            $this->assertStringNotContainsString('private TLS', $exception->getMessage());
        }
        $this->assertSame([false, true], $tester->verifications);
    }
}
