<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // A controller may set a request time limit. PHPUnit reuses the PHP
        // process, so reset it before the next independent request/test.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        parent::setUp();
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $driver = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$driver}.database");

        // Feature tests migrate/drop tables. Never let an external DB override
        // or a cached production configuration target the operational database.
        if ($app->configurationIsCached()
            || $app['config']->get("database.connections.{$driver}.url")
            || ($driver !== 'sqlite' && ! str_ends_with($database, '_test'))
            || ($driver === 'sqlite' && $database !== ':memory:')) {
            throw new \RuntimeException('Tests require uncached configuration, no DB_URL, and a dedicated *_test database (or SQLite :memory:).');
        }

        // Tests must also work on a fresh checkout without a local .env/key.
        if (! $app['config']->get('app.key')) {
            $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        }

        return $app;
    }
}
