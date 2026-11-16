<?php

namespace Tests\Support;

use RuntimeException;

final class PostgresTestDatabase
{
    public static function assertSafe(array $configuration): void
    {
        if (getenv('SIEM_TEST_PGSQL') !== '1'
            || ($configuration['driver'] ?? null) !== 'pgsql'
            || ! in_array($configuration['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! preg_match('/\Asiem_pgsql_test(?:_[a-z0-9_]+)?\z/', (string) ($configuration['database'] ?? ''))
            || ! empty($configuration['url'])
            || ($configuration['search_path'] ?? 'public') !== 'public') {
            throw new RuntimeException('PostgreSQL tests require SIEM_TEST_PGSQL=1, a loopback host, an empty DB_URL, public search_path, and a disposable siem_pgsql_test[_suffix] database. Never use a production database.');
        }
    }
}
