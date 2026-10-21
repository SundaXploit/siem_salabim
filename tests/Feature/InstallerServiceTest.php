<?php

namespace Tests\Feature;

use App\Exceptions\InstallationException;
use App\Models\Configuration;
use App\Models\User;
use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationConnectionTester;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InstallerServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private EnvironmentFileWriter $environment;

    private InstallationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        config(['installer.installed' => false]);
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siem-installer-service-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/.env.example', "APP_KEY=\nAPP_INSTALLED=false\nUNRELATED=preserved\n");
        $this->environment = new EnvironmentFileWriter($this->directory);
        $key = $this->environment->ensureEnvironmentAndKey()['app_key'];
        config(['app.key' => $key]);
        $this->state = new InstallationState(
            environment: $this->environment,
            markerPath: $this->directory.'/private/installed.json',
            tokenPath: $this->directory.'/private/token.json',
            detectLegacyAdmin: false,
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_installation_migrates_without_seed_and_persists_encrypted_integrations(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldReceive('testOpenSearch')->once()->andReturn(['count' => 0, 'index' => 'wazuh-alerts-*']);
        $connections->shouldReceive('testTelegram')->once()->andReturnNull();
        $connections->shouldReceive('activateDatabase')->once()->andReturn(DB::connection());

        $this->manager($connections)->install($this->validInput());

        $admin = User::sole();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertTrue(password_verify('Long-password-123!', $admin->password));
        $this->assertSame('123456789:telegram-secret-token-value', Configuration::get('telegram_bot_token'));
        $this->assertSame('open-search-secret', Configuration::get('opensearch_password'));
        $this->assertNotSame(
            '123456789:telegram-secret-token-value',
            DB::table('configurations')->where('key', 'telegram_bot_token')->value('value'),
        );
        $this->assertSame('preserved', $this->environment->value('UNRELATED'));
        $this->assertSame('production', $this->environment->value('APP_ENV'));
        $this->assertSame('false', $this->environment->value('APP_DEBUG'));
        $this->assertSame('true', $this->environment->value('SESSION_ENCRYPT'));
        $this->assertSame('true', $this->environment->value('SESSION_SECURE_COOKIE'));
        $this->assertSame('true', $this->environment->value('APP_INSTALLED'));
        $this->assertTrue($this->state->isInstalled());
        $this->assertFileExists($this->directory.'/private/installed.json');
    }

    public static function optionalIntegrations(): array
    {
        return [
            'both skipped' => [false, false],
            'only OpenSearch' => [true, false],
            'only Telegram' => [false, true],
        ];
    }

    #[DataProvider('optionalIntegrations')]
    public function test_installation_can_skip_integrations(bool $openSearchEnabled, bool $telegramEnabled): void
    {
        $input = $this->validInput([
            'opensearch_enabled' => $openSearchEnabled,
            'telegram_enabled' => $telegramEnabled,
        ]);
        foreach (['opensearch' => $openSearchEnabled, 'telegram' => $telegramEnabled] as $prefix => $enabled) {
            if (! $enabled) {
                foreach (array_keys($input) as $key) {
                    if (str_starts_with($key, $prefix.'_')) {
                        unset($input[$key]);
                    }
                }
            }
        }

        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldReceive('activateDatabase')->once()->andReturn(DB::connection());
        if ($openSearchEnabled) {
            $connections->shouldReceive('testOpenSearch')->once()->andReturn(['count' => 0]);
        } else {
            $connections->shouldNotReceive('testOpenSearch');
        }
        if ($telegramEnabled) {
            $connections->shouldReceive('testTelegram')->once()->andReturnNull();
        } else {
            $connections->shouldNotReceive('testTelegram');
        }

        $this->manager($connections)->install($input);

        $this->assertSame('admin', User::sole()->role);
        $this->assertTrue($this->state->isInstalled());
        $this->assertSame($openSearchEnabled ? 'open-search-secret' : null, Configuration::get('opensearch_password'));
        $this->assertSame($telegramEnabled ? '123456789:telegram-secret-token-value' : null, Configuration::get('telegram_bot_token'));
        if (! $openSearchEnabled) {
            $this->assertSame('wazuh-primary', $this->environment->value('OPENSEARCH_SOURCE_CLUSTER'));
            $this->assertSame('true', $this->environment->value('OPENSEARCH_VERIFY_SSL'));
            $this->assertTrue(config('services.opensearch.verify_ssl'));
            $this->assertDatabaseMissing('configurations', ['key' => 'opensearch_host']);
        }
        if (! $telegramEnabled) {
            $this->assertSame([], Configuration::getTelegramChatIds());
        }
    }

    public function test_disabled_integrations_ignore_leftover_invalid_values(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldReceive('activateDatabase')->once()->andReturn(DB::connection());
        $connections->shouldNotReceive('testOpenSearch', 'testTelegram');

        $this->manager($connections)->install($this->validInput([
            'opensearch_enabled' => false,
            'telegram_enabled' => false,
            'opensearch_host' => 'invalid-url',
            'telegram_bot_token' => 'incomplete-token',
            'telegram_chat_ids' => [''],
        ]));

        $this->assertTrue($this->state->isInstalled());
        $this->assertDatabaseCount('configurations', 0);
    }

    public function test_enabled_integrations_require_configuration_before_testing_connections(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldNotReceive('testDatabase', 'testOpenSearch', 'testTelegram');

        try {
            $this->manager($connections)->install($this->validInput([
                'opensearch_host' => '',
                'telegram_bot_token' => '',
                'telegram_chat_ids' => [],
            ]));
            $this->fail('Enabled integrations must require configuration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('opensearch_host', $exception->errors());
            $this->assertArrayHasKey('telegram_bot_token', $exception->errors());
            $this->assertArrayHasKey('telegram_chat_ids', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertFileDoesNotExist($this->directory.'/private/installed.json');
    }

    public function test_failed_required_connection_writes_no_environment_admin_or_marker(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldReceive('testOpenSearch')->once()->andThrow(new InstallationException('OpenSearch tidak dapat diakses.'));
        $connections->shouldNotReceive('testTelegram', 'activateDatabase');

        try {
            $this->manager($connections)->install($this->validInput());
            $this->fail('Installer semestinya gagal.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'OpenSearch tidak dapat diakses.',
                $exception->errors()['installation'][0],
            );
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertNull($this->environment->value('DB_HOST'));
        $this->assertSame('false', $this->environment->value('APP_INSTALLED'));
        $this->assertFileDoesNotExist($this->directory.'/private/installed.json');
    }

    public function test_local_http_install_keeps_session_encrypted_without_forcing_a_secure_cookie(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldReceive('testOpenSearch')->once()->andReturn(['count' => 0, 'index' => 'wazuh-alerts-*']);
        $connections->shouldReceive('testTelegram')->once()->andReturnNull();
        $connections->shouldReceive('activateDatabase')->once()->andReturn(DB::connection());

        $this->manager($connections)->install($this->validInput([
            'app_url' => 'http://siem.test',
        ]));

        $this->assertSame('true', $this->environment->value('SESSION_ENCRYPT'));
        $this->assertSame('false', $this->environment->value('SESSION_SECURE_COOKIE'));
    }

    public function test_unexpected_runtime_errors_are_not_exposed_to_the_browser(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andThrow(
            new RuntimeException('postgres://siem:database-secret@db.internal/siem'),
        );

        try {
            $this->manager($connections)->install($this->validInput());
            $this->fail('Installer semestinya gagal.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['installation'][0];

            $this->assertSame(
                'Instalasi gagal tanpa menyimpan marker. Periksa log server lalu jalankan ulang installer.',
                $message,
            );
            $this->assertStringNotContainsString('database-secret', $message);
            $this->assertStringNotContainsString('db.internal', $message);
        }

        $this->assertNull($this->environment->value('DB_HOST'));
        $this->assertFileDoesNotExist($this->directory.'/private/installed.json');
    }

    /** @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return array_replace([
            'app_name' => 'SIEM Test',
            'app_url' => 'https://siem.example.test',
            'app_timezone' => 'Asia/Jakarta',
            'db_connection' => 'pgsql',
            'db_host' => 'db.example.test',
            'db_port' => 5432,
            'db_database' => 'siem',
            'db_username' => 'siem_app',
            'db_password' => 'db # secret $ value',
            'db_sslmode' => 'require',
            'opensearch_enabled' => true,
            'telegram_enabled' => true,
            'opensearch_host' => 'https://indexer.example.test:9200',
            'opensearch_username' => 'wazuh',
            'opensearch_password' => 'open-search-secret',
            'opensearch_index' => 'wazuh-alerts-*',
            'opensearch_source_cluster' => 'wazuh-primary',
            'opensearch_verify_ssl' => true,
            'telegram_bot_token' => '123456789:telegram-secret-token-value',
            'telegram_chat_ids' => ['-1001234567890'],
            'ai_enabled' => false,
            'smtp_enabled' => false,
            'admin_name' => 'SOC Administrator',
            'admin_email' => 'ADMIN@EXAMPLE.TEST',
            'admin_password' => 'Long-password-123!',
            'admin_password_confirmation' => 'Long-password-123!',
        ], $overrides);
    }

    private function manager(InstallationConnectionTester $connections): Installer
    {
        return new Installer($this->state, $this->environment, $connections);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
