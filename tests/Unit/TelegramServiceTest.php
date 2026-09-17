<?php

namespace Tests\Unit;

use App\Services\TelegramService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class TelegramServiceTest extends TestCase
{
    public function test_it_retries_a_dns_failure_that_never_reached_telegram(): void
    {
        $service = $this->serviceWithQueue([
            $this->connectionFailure(6),
            new Response(200, [], '{"ok":true,"result":{"message_id":1}}'),
        ]);

        $result = $service->sendMessage(' -100123 ', 'Test notification', ' temporary-token ');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['attempts']);
    }

    public function test_it_returns_a_specific_dns_error_after_all_attempts_fail(): void
    {
        $service = $this->serviceWithQueue([
            $this->connectionFailure(6),
            $this->connectionFailure(6),
            $this->connectionFailure(6),
        ]);

        $result = $service->sendMessage('-100123', 'Test notification', 'token');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['status']);
        $this->assertSame('dns_resolution_failed', $result['error_type']);
        $this->assertSame(3, $result['attempts']);
        $this->assertStringContainsString('DNS gagal', $result['error']);
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
    private function serviceWithQueue(array $queue): TelegramService
    {
        $handler = new MockHandler($queue);
        $client = new Client([
            'base_uri' => 'https://api.telegram.org',
            'handler' => HandlerStack::create($handler),
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
