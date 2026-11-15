<?php

namespace Tests\Unit;

use App\Exceptions\UnsafeOutboundEndpoint;
use App\Services\OutboundEndpointPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutboundEndpointPolicyTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function test_unsafe_urls_are_rejected_before_transport(string $url): void
    {
        $this->expectException(UnsafeOutboundEndpoint::class);
        (new OutboundEndpointPolicy)->options($url);
    }

    public static function blockedUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'file:///etc/passwd', 'gopher://localhost', 'http://user:secret@localhost:9200',
            'http://localhost/?secret=value', 'http://localhost/#fragment', 'http://localhost\\@127.0.0.1',
            'http://localhost:0', 'http://localhost/v1/../metadata', 'http://localhost/%2e%2e',
            'http://2130706433', 'http://0177.0.0.1', 'http://0x7f000001', 'http://127.1',
            'http://169.254.169.254', 'http://169.254.170.2:80', 'http://100.100.100.200',
            'http://168.63.129.16', 'http://0.0.0.0:9200', 'http://0.1.2.3', 'http://255.255.255.255',
            'http://224.0.0.1', 'http://[::]', 'http://[::ffff:169.254.169.254]',
            'http://[fe80::1]', 'http://[ff02::1]', 'http://[fd00:ec2::254]',
            'http://[64:ff9b::a9fe:a9fe]', 'http://[2002:a9fe:a9fe::1]',
        ]);
    }

    #[DataProvider('supportedUrls')]
    public function test_local_and_public_destinations_remain_usable_without_an_allowlist(string $url): void
    {
        $options = (new OutboundEndpointPolicy)->options($url);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
    }

    public static function supportedUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://127.0.0.1:7788', 'http://localhost:7779/v1', 'https://10.0.0.5:9200',
            'http://192.168.1.1', 'http://172.16.1.2:8000/v1', 'https://1.1.1.1',
            'http://[::1]:11434/v1', 'http://[fd12::5]:1234/v1', 'https://[2606:4700:4700::1111]',
        ]);
    }

    public function test_all_dns_answers_are_checked_including_a_forbidden_answer_among_valid_ones(): void
    {
        $policy = $this->policy(['203.0.113.10', '169.254.169.254']);
        $this->expectException(UnsafeOutboundEndpoint::class);
        $policy->options('https://provider.example.test/v1');
    }

    public function test_an_empty_dns_result_fails_closed(): void
    {
        $this->expectException(UnsafeOutboundEndpoint::class);
        $this->policy([])->options('https://missing.example.test');
    }

    public function test_checked_dns_is_pinned_and_tls_hostname_is_preserved(): void
    {
        $policy = $this->policy(['203.0.113.10', '203.0.113.11']);
        $options = $policy->options('https://provider.example.test:8443/v1');
        $this->assertSame(['provider.example.test:8443:203.0.113.10,203.0.113.11'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
        $this->assertArrayNotHasKey('verify', $options);
    }

    public function test_redirects_are_disabled_before_guzzle_redirect_middleware_runs(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data']), new Response(200),
        ]));
        $stack->unshift($this->policy(['203.0.113.10'])->middleware('https://provider.example.test'));
        $stack->push(Middleware::history($history));
        $response = (new Client(['handler' => $stack]))->get('https://provider.example.test/models', ['allow_redirects' => true]);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $history);
        $this->assertSame('provider.example.test', $history[0]['request']->getUri()->getHost());
    }

    public function test_a_request_cannot_escape_the_configured_origin_or_base_path(): void
    {
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->unshift($this->policy(['203.0.113.10'])->middleware('https://provider.example.test/v1'));
        $this->expectException(UnsafeOutboundEndpoint::class);
        (new Client(['handler' => $stack]))->get('http://127.0.0.1:7779/private');
    }

    public function test_cached_clients_recheck_dns_on_each_request(): void
    {
        $policy = new class extends OutboundEndpointPolicy
        {
            public int $calls = 0;

            protected function resolve(string $host): array
            {
                return [++$this->calls === 1 ? '203.0.113.10' : '169.254.169.254'];
            }
        };
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->unshift($policy->middleware('https://provider.example.test'));
        $client = new Client(['handler' => $stack]);
        $this->assertSame(200, $client->get('https://provider.example.test/models')->getStatusCode());
        $this->expectException(UnsafeOutboundEndpoint::class);
        $client->get('https://provider.example.test/models');
    }

    private function policy(array $addresses): OutboundEndpointPolicy
    {
        return new class($addresses) extends OutboundEndpointPolicy
        {
            public function __construct(private array $addresses) {}

            protected function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
    }
}
