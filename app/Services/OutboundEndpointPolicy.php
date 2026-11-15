<?php

namespace App\Services;

use App\Exceptions\UnsafeOutboundEndpoint;
use App\Models\Configuration;
use Closure;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;

class OutboundEndpointPolicy
{
    /** Pin the checked DNS answer to this connection, preserving TLS hostname verification. */
    public function options(string $baseUrl): array
    {
        $endpoint = $this->endpoint($baseUrl);
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new UnsafeOutboundEndpoint('Koneksi integrasi memerlukan ekstensi PHP cURL dengan dukungan DNS pinning.');
        }

        $addresses = $this->resolve($endpoint['host']);
        if ($addresses === []) {
            throw new UnsafeOutboundEndpoint('DNS tujuan integrasi tidak dapat ditemukan. Periksa DNS pada server aplikasi.');
        }
        foreach ($addresses as $address) {
            $this->checkAddress($address);
        }

        // All fallback addresses are checked; cURL must not resolve again or use a proxy.
        $pinned = implode(',', array_map(static fn ($address) => str_contains($address, ':') ? '['.$address.']' : $address, $addresses));
        $host = str_contains($endpoint['host'], ':') ? '['.$endpoint['host'].']' : $endpoint['host'];

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => [
                CURLOPT_RESOLVE => filter_var($endpoint['host'], FILTER_VALIDATE_IP) ? [] : [$host.':'.$endpoint['port'].':'.$pinned],
                CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ],
        ];
    }

    public function validateUrl(string $baseUrl): void
    {
        $endpoint = $this->endpoint($baseUrl);
        if (filter_var($endpoint['host'], FILTER_VALIDATE_IP)) {
            $this->checkAddress($endpoint['host']);
        }
    }

    public static function urlRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }
            try {
                app(self::class)->validateUrl($value);
            } catch (UnsafeOutboundEndpoint $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    public function assertConfigured(string $integration, string $baseUrl): void
    {
        [$key, $fallback] = match ($integration) {
            'opensearch' => ['opensearch_host', 'services.opensearch.host'],
            'ai' => ['ai_base_url', 'services.ai.base_url'],
        };
        // Read the persisted destination, never values from the submitted form.
        $saved = (string) Configuration::get($key, config($fallback, ''));
        if ($saved === '' || $this->endpoint($baseUrl) !== $this->endpoint($saved)) {
            throw new UnsafeOutboundEndpoint('Tujuan koneksi berbeda dari konfigurasi tersimpan. Simpan konfigurasi '.$integration.' terlebih dahulu sebelum menguji koneksi atau memuat model.');
        }
    }

    /** Apply the same policy on every actual OpenSearch request, including a cached client. */
    public function handler(string $baseUrl, ?string $integration = null): HandlerStack
    {
        $stack = HandlerStack::create(new CurlHandler);
        $stack->unshift($this->middleware($baseUrl, $integration), 'outbound_endpoint_policy');

        return $stack;
    }

    public function middleware(string $baseUrl, ?string $integration = null): callable
    {
        return function (callable $next) use ($baseUrl, $integration): callable {
            return function (RequestInterface $request, array $options) use ($next, $baseUrl, $integration) {
                if ($integration !== null) {
                    $this->assertConfigured($integration, $baseUrl);
                }
                $base = $this->endpoint($baseUrl);
                $uri = $request->getUri();
                $origin = $this->endpoint((string) $uri->withPath('')->withQuery('')->withFragment(''));
                if (array_diff_assoc($origin, [...$base, 'path' => '']) !== []
                    || $uri->getUserInfo() !== ''
                    || ($base['path'] !== '' && ! str_starts_with($uri->getPath(), $base['path'].'/'))) {
                    throw new UnsafeOutboundEndpoint('Request integrasi di luar tujuan yang diizinkan diblokir.');
                }

                $safe = $this->options($baseUrl);
                $options['curl'] = array_replace($options['curl'] ?? [], $safe['curl']);
                $options['proxy'] = '';
                $options['allow_redirects'] = false;

                return $next($request, $options);
            };
        };
    }

    private function endpoint(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || (isset($parts['port']) && $parts['port'] < 1)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new UnsafeOutboundEndpoint('Base URL integrasi tidak valid. Gunakan HTTP/HTTPS tanpa kredensial, query, atau fragment.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $path = rtrim($parts['path'] ?? '', '/');
        if ((! filter_var($host, FILTER_VALIDATE_IP) && (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                    || preg_match('/(?:\A|\.)(?:[0-9]+|0x[0-9a-f]+)\z/i', $host)))
            || ! preg_match('#\A(?:/[a-zA-Z0-9_.~-]+)*\z#', $path)
            || array_intersect(explode('/', $path), ['.', '..']) !== []) {
            throw new UnsafeOutboundEndpoint('Host atau path base URL integrasi tidak valid.');
        }

        return ['scheme' => strtolower($parts['scheme']), 'host' => $host,
            'port' => $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80), 'path' => $path];
    }

    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if ($host === 'localhost') {
            return ['127.0.0.1', '::1'];
        }

        return array_values(array_unique(array_merge($this->dnsAddresses($host, []), @gethostbynamel($host) ?: [])));
    }

    private function dnsAddresses(string $host, array $seen): array
    {
        if (count($seen) >= 8 || in_array($host, $seen, true)) {
            return [];
        }
        $seen[] = $host;
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME) ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            } elseif (isset($record['target']) && ($record['type'] ?? '') === 'CNAME') {
                $addresses = array_merge($addresses, $this->dnsAddresses($record['target'], $seen));
            }
        }

        return array_values(array_unique($addresses));
    }

    private function checkAddress(string $address): void
    {
        $valid = filter_var($address, FILTER_VALIDATE_IP);
        $forbidden = ['0.0.0.0/8', '169.254.0.0/16', '100.100.100.200/32', '168.63.129.16/32', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::ffff:0:0/96', 'fe80::/10', 'ff00::/8', '2002::/16', '2001::/32', 'fd00:ec2::254/128'];
        $ipv6Allowed = ! str_contains($address, ':') || IpUtils::checkIp($address, ['2000::/3', 'fc00::/7', '::1/128']);
        if (! $valid || ! $ipv6Allowed || IpUtils::checkIp($address, $forbidden)) {
            throw new UnsafeOutboundEndpoint('Alamat tujuan integrasi diblokir oleh kebijakan SSRF. Alamat metadata, link-local, dan alamat khusus tidak boleh digunakan.');
        }
    }
}
