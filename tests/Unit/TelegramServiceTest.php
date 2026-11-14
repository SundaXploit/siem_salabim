<?php

namespace Tests\Unit;

use App\Models\Alert;
use App\Models\NotificationTemplate;
use App\Services\TelegramService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class TelegramServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.dns_fallback' => true,
            'services.telegram.doh_resolvers' => ['https://1.1.1.1/dns-query', 'https://1.0.0.1/dns-query'],
        ]);
    }

    public function test_it_retries_a_dns_failure_that_never_reached_telegram(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->connectionFailure(6),
            new Response(200, [], '{"ok":true,"result":{"message_id":1}}'),
        ], $history);

        $result = $service->sendMessage(' -100123 ', 'Test notification', ' temporary-token ');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['attempts']);
        $this->assertArrayNotHasKey('curl', $history[0]['options']);
        $this->assertSame('https://1.1.1.1/dns-query', $history[1]['options']['curl'][CURLOPT_DOH_URL]);
        $this->assertTrue($history[1]['options']['curl'][CURLOPT_DOH_SSL_VERIFYPEER]);
        $this->assertSame(2, $history[1]['options']['curl'][CURLOPT_DOH_SSL_VERIFYHOST]);
        $this->assertSame((string) $history[0]['request']->getUri(), (string) $history[1]['request']->getUri());
    }

    public function test_it_returns_a_specific_dns_error_after_all_attempts_fail(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->connectionFailure(6),
            $this->connectionFailure(6),
            $this->connectionFailure(6),
        ], $history);

        $result = $service->sendMessage('-100123', 'Test notification', 'token');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['status']);
        $this->assertSame('dns_resolution_failed', $result['error_type']);
        $this->assertSame(3, $result['attempts']);
        $this->assertStringContainsString('DNS gagal', $result['error']);
        $this->assertSame('https://1.0.0.1/dns-query', $history[2]['options']['curl'][CURLOPT_DOH_URL]);
    }

    public function test_lookup_timeout_switches_to_encrypted_dns_before_connecting(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([$this->connectionFailure(28), new Response(200, [], '{"ok":true}')], $history);
        $result = $service->testConfiguration('token', []);

        $this->assertTrue($result['success']);
        $this->assertSame('https://1.1.1.1/dns-query', $history[1]['options']['curl'][CURLOPT_DOH_URL]);
    }

    public function test_dns_fallback_can_be_disabled(): void
    {
        config(['services.telegram.dns_fallback' => false]);
        $history = [];
        $service = $this->serviceWithQueue([$this->connectionFailure(6), new Response(200, [], '{"ok":true}')], $history);

        $this->assertTrue($service->testConfiguration('token', [])['success']);
        $this->assertArrayNotHasKey('curl', $history[1]['options']);
    }

    public function test_dns_fallback_rejects_an_insecure_resolver_url(): void
    {
        config(['services.telegram.doh_resolvers' => ['http://1.1.1.1/dns-query']]);
        $history = [];
        $service = $this->serviceWithQueue([$this->connectionFailure(6), new Response(200, [], '{"ok":true}')], $history);

        $this->assertTrue($service->testConfiguration('token', [])['success']);
        $this->assertArrayNotHasKey('curl', $history[1]['options']);
    }

    public function test_it_does_not_retry_an_ambiguous_timeout_after_upload_started(): void
    {
        $service = $this->serviceWithQueue([
            $this->connectionFailure(28, [
                'primary_ip' => '149.154.166.110',
                'connect_time' => 0.15,
                'size_upload' => 200,
            ]),
        ]);

        $result = $service->sendMessage('-100123', 'Test notification', 'token');

        $this->assertFalse($result['success']);
        $this->assertSame('connection_timeout', $result['error_type']);
        $this->assertSame(1, $result['attempts']);
        $this->assertTrue($result['delivery_unknown']);
        $this->assertStringContainsString('Periksa chat tujuan', $result['error']);
    }

    public function test_it_maps_api_authentication_errors_without_retrying(): void
    {
        $service = $this->serviceWithQueue([
            new Response(401, [], '{"ok":false,"error_code":401,"description":"Unauthorized"}'),
        ]);

        $result = $service->sendMessage('-100123', 'Test notification', 'bad-token');

        $this->assertFalse($result['success']);
        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['error_type']);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame('Bot token Telegram tidak valid.', $result['error']);
    }

    /** @param array<int, mixed> $queue */
    private function serviceWithQueue(array $queue, ?array &$history = null): TelegramService
    {
        $handler = new MockHandler($queue);
        $stack = HandlerStack::create($handler);
        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }
        $client = new Client([
            'base_uri' => 'https://api.telegram.org',
            'handler' => $stack,
            'http_errors' => false,
        ]);

        return new class($client) extends TelegramService
        {
            public function __construct(Client $client)
            {
                $this->client = $client;
                $this->botToken = 'stored-token';
                $this->maxAttempts = 3;
                $this->retryDelaysMs = [1, 1, 1];
            }
        };
    }

    public function test_api_failure_with_http_200_is_never_reported_as_sent(): void
    {
        $service = $this->serviceWithQueue([
            new Response(200, [], '{"ok":false,"error_code":403,"description":"bot was blocked by the user"}'),
        ]);
        $result = $service->sendMessage('123', 'Notification', 'token');

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('chat_access_denied', $result['error_type']);
        $this->assertSame(1, $result['attempts']);
    }

    public function test_rejected_html_is_sent_once_as_plain_text_without_losing_content(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            new Response(400, [], '{"ok":false,"error_code":400,"description":"Bad Request: can\'t parse entities"}'),
            new Response(200, [], '{"ok":true}'),
        ], $history);
        $text = 'Alert <root> & malformed <b>markup';
        $result = $service->sendMessage('123', $text, 'token');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['format_fallback']);
        $this->assertCount(2, $history);
        $retry = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame($text, $retry['text']);
        $this->assertArrayNotHasKey('parse_mode', $retry);
    }

    public function test_short_rate_limit_is_retried_only_after_explicit_rejection(): void
    {
        $service = $this->serviceWithQueue([
            new Response(429, [], '{"ok":false,"error_code":429,"parameters":{"retry_after":1}}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $result = $service->sendMessage('123', 'Notification', 'token');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['attempts']);
    }

    public function test_long_rate_limit_is_returned_without_blocking_or_resending(): void
    {
        $service = $this->serviceWithQueue([
            new Response(429, [], '{"ok":false,"error_code":429,"parameters":{"retry_after":60}}'),
        ]);
        $result = $service->sendMessage('123', 'Notification', 'token');

        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['status']);
        $this->assertSame(60, $result['retry_after']);
        $this->assertSame(1, $result['attempts']);
        $this->assertStringContainsString('60 detik', $result['error']);
    }

    public function test_invalid_response_is_not_confirmation_and_is_not_retried(): void
    {
        $service = $this->serviceWithQueue([new Response(200, [], '<html>proxy error</html>')]);
        $result = $service->sendMessage('123', 'Notification', 'token');

        $this->assertFalse($result['success']);
        $this->assertSame(502, $result['status']);
        $this->assertTrue($result['delivery_unknown']);
        $this->assertSame(1, $result['attempts']);
    }

    public function test_configuration_checks_bot_and_each_chat_without_sending_a_message(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
            new Response(403, [], '{"ok":false,"error_code":403}'),
        ], $history);
        $result = $service->testConfiguration('token', ['123', '-456']);

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame(['/bottoken/getMe', '/bottoken/getChat', '/bottoken/getChat'], array_map(
            fn ($entry) => $entry['request']->getUri()->getPath(), $history,
        ));
    }

    public function test_bot_connection_timeout_reports_a_connection_failure_without_a_delivery_warning(): void
    {
        $service = $this->serviceWithQueue([
            $this->connectionFailure(28, ['primary_ip' => '149.154.166.110', 'connect_time' => 0.15]),
        ]);
        $result = $service->testConfiguration('token', []);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['delivery_unknown']);
        $this->assertSame('connection_timeout', $result['error_type']);
        $this->assertStringNotContainsString('pesan tidak ganda', $result['error']);
    }

    public function test_default_template_escapes_external_html_and_handles_invalid_timestamps(): void
    {
        $text = TelegramService::buildTemplate([
            'rule_description' => 'A < B & C', 'agent_name' => '<root>',
            'raw_data' => ['@timestamp' => 'invalid-timestamp', 'syscheck' => ['path' => '/a&b/<secret>']],
        ], '<extra>');

        $this->assertStringContainsString('A &lt; B &amp; C', $text);
        $this->assertStringContainsString('&lt;root&gt;', $text);
        $this->assertStringContainsString('/a&amp;b/&lt;secret&gt;', $text);
        $this->assertStringContainsString('&lt;extra&gt;', $text);
        $this->assertStringContainsString('<b>Rule ID</b>', $text);
    }

    public function test_saved_template_escapes_variables_without_recursive_substitution(): void
    {
        $template = new NotificationTemplate(['body' => '<b>{rule_description}</b> {agent_name}']);
        $alert = new Alert(['rule_description' => '<root> & {agent_name}', 'agent_name' => 'host']);

        $this->assertSame('<b>&lt;root&gt; &amp; {agent_name}</b> host', $template->render($alert));
    }

    /** @param array<string, mixed> $overrides */
    private function connectionFailure(int $errno, array $overrides = []): ConnectException
    {
        $context = array_merge([
            'errno' => $errno,
            'primary_ip' => '',
            'connect_time' => 0.0,
            'size_upload' => 0.0,
            'namelookup_time' => 0.0,
        ], $overrides);

        return new ConnectException(
            'Sanitized network failure',
            new Request('POST', 'https://api.telegram.org/bot[REDACTED]/sendMessage'),
            null,
            $context,
        );
    }
}
