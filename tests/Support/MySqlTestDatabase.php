<?php

namespace Tests\Support;

use RuntimeException;

final class MySqlTestDatabase
{
    public static function assertSafe(array $configuration): void
    {
        if (getenv('SIEM_TEST_MYSQL') !== '1'
            || ! in_array($configuration['driver'] ?? null, ['mysql', 'mariadb'], true)
            || ! in_array($configuration['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! preg_match('/\Asiem_(?:mysql|mariadb)_test(?:_[a-z0-9_]+)?\z/', (string) ($configuration['database'] ?? ''))
            || filled($configuration['url'] ?? null)) {
            throw new RuntimeException('MySQL/MariaDB tests require SIEM_TEST_MYSQL=1, a loopback host, an empty DB_URL, and a disposable siem_mysql_test[_suffix] or siem_mariadb_test[_suffix] database.');
        }
    }
}
