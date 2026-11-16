<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\MySqlTestDatabase;

class MySqlTestDatabaseGuardTest extends TestCase
{
    #[DataProvider('databaseConfigurations')]
    public function test_only_explicit_local_disposable_databases_are_allowed(array $override, bool $optIn, bool $allowed): void
    {
        $previous = getenv('SIEM_TEST_MYSQL');
        putenv('SIEM_TEST_MYSQL='.($optIn ? '1' : '0'));
        try {
            if (! $allowed) {
                $this->expectException(RuntimeException::class);
            }
            MySqlTestDatabase::assertSafe([
                'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'siem_mysql_test_audit', 'url' => null, ...$override,
            ]);
            $this->assertTrue($allowed);
        } finally {
            $previous === false ? putenv('SIEM_TEST_MYSQL') : putenv('SIEM_TEST_MYSQL='.$previous);
        }
    }

    public static function databaseConfigurations(): array
    {
        return [
            'local MySQL' => [[], true, true],
            'local MariaDB' => [['driver' => 'mariadb', 'database' => 'siem_mariadb_test_ci'], true, true],
            'missing explicit opt-in' => [[], false, false],
            'production database name' => [['database' => 'siem_salabim'], true, false],
            'remote server' => [['host' => '192.0.2.15'], true, false],
            'URL overrides host and database' => [['url' => 'mysql://root@example.test/production'], true, false],
            'different driver' => [['driver' => 'sqlite'], true, false],
            'database name suffix injection' => [['database' => 'siem_mysql_test; DROP DATABASE production'], true, false],
        ];
    }
}
