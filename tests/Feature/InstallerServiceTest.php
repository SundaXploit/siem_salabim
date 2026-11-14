<?php

namespace Tests\Feature;

use App\Exceptions\InstallationException;
use App\Models\Configuration;
use App\Models\User;
use App\Services\AiInsightService;
use App\Services\AlertIngestionCoordinator;
use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationConnectionTester;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
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

    public function test_installation_without_integrations_creates_an_admin_and_opens_the_dashboard(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldNotReceive('testOpenSearch', 'testTelegram');
        $connections->shouldReceive('activateDatabase')->once()->andReturn(DB::connection());
        $input = $this->validInput();
        foreach (array_keys($input) as $key) {
            if (str_starts_with($key, 'opensearch_') || str_starts_with($key, 'telegram_') || str_starts_with($key, 'ai_')) {
                unset($input[$key]);
            }
        }

        $this->manager($connections)->install($input);

        $this->assertTrue($this->state->isInstalled());
        $this->assertSame('', Configuration::get('opensearch_host'));
        $this->assertSame('', Configuration::get('telegram_bot_token'));
        $this->assertSame([], Configuration::getTelegramChatIds());
        $this->assertSame('', Configuration::get('ai_api_key'));
        $this->assertSame('', $this->environment->value('OPENSEARCH_HOST'));
        $this->app->instance(InstallationState::class, $this->state);
        $this->actingAs(User::sole())->get(route('dashboard.index'))->assertOk();
        Queue::fake();
        $status = app(AlertIngestionCoordinator::class)->requestLatestFetch('test');
        $this->assertSame('not_configured', $status['state']);
        Queue::assertNothingPushed();
    }

    public function test_skipped_integrations_ignore_stale_invalid_fields(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldNotReceive('testOpenSearch', 'testTelegram');
        $connections->shouldReceive('activateDatabase')->once()->andReturn(DB::connection());

        $this->manager($connections)->install($this->validInput([
            'opensearch_enabled' => false, 'opensearch_host' => 'not a URL',
            'telegram_enabled' => false, 'telegram_bot_token' => 'invalid',
            'ai_enabled' => false, 'ai_base_url' => 'not a URL',
        ]));

        $this->assertTrue($this->state->isInstalled());
        $this->assertSame('', Configuration::get('opensearch_host'));
        $this->assertSame('', Configuration::get('telegram_bot_token'));
    }

    public function test_installer_persists_local_ai_without_requiring_a_remote_api_key(): void
    {
        $connections = Mockery::mock(InstallationConnectionTester::class);
        $connections->shouldReceive('testDatabase')->once()->andReturn(config('database.connections.sqlite'));
        $connections->shouldNotReceive('testOpenSearch', 'testTelegram');
        $connections->shouldReceive('activateDatabase')->once()->andReturn(DB::connection());
        $this->manager($connections)->install($this->validInput([
            'opensearch_enabled' => false, 'telegram_enabled' => false,
            'ai_enabled' => true, 'ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'http://127.0.0.1:11434/v1', 'ai_model' => 'local-model',
        ]));

        $this->assertTrue($this->state->isInstalled());
        $this->assertSame('ollama', Configuration::get('ai_provider'));
        $this->assertSame('chat_completions', Configuration::get('ai_api_format'));
        $this->assertSame('', Configuration::get('ai_api_key'));
        $this->assertTrue(app(AiInsightService::class)->isConfigured());
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
