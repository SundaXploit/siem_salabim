<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class HttpFoundationSecurityTest extends TestCase
{
    #[DataProvider('scriptPrefixPaths')]
    public function test_script_prefix_does_not_turn_a_different_path_into_an_admin_route(string $path): void
    {
        $request = Request::create($path, server: ['SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php']);
        $this->assertNotSame('/admin', $request->getPathInfo());
    }

    public static function scriptPrefixPaths(): array
    {
        return [['/index.phpadmin'], ['/index%2Ephpadmin'], ['/%69ndex.phpadmin']];
    }

    #[DataProvider('hostInjectionPaths')]
    public function test_an_attacker_controlled_path_does_not_become_a_generated_url_host(string $path): void
    {
        $request = Request::create('https://siem.example.test', server: [
            'SCRIPT_NAME' => '/public/index.php', 'PHP_SELF' => '/public/index.php', 'REQUEST_URI' => $path,
        ]);
        $this->assertSame('', $request->getBaseUrl());
        $this->assertStringNotContainsString('evil.example', $request->getSchemeAndHttpHost().$request->getBaseUrl());
    }

    public static function hostInjectionPaths(): array
    {
        return [['//evil.example/public/index.php/login'], ['/\\evil.example/public/index.php/login']];
    }
}
