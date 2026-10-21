<?php

namespace Tests\Feature;

use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use Tests\TestCase;

class InstallerHttpTest extends TestCase
{
    private string $directory;

    private EnvironmentFileWriter $environment;

    private InstallationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'installer.installed' => false,
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siem-installer-http-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/.env.example', "APP_KEY=\nAPP_INSTALLED=false\n");
        $this->environment = new EnvironmentFileWriter($this->directory);
        $this->environment->ensureEnvironmentAndKey();
        $this->state = $this->makeState();
        $this->app->instance(EnvironmentFileWriter::class, $this->environment);
        $this->app->instance(InstallationState::class, $this->state);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_fresh_application_redirects_root_and_normal_json_routes_to_installer(): void
    {
        $this->get('/')->assertRedirect(route('install.index'));

        $this->getJson('/dashboard')
            ->assertStatus(503)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'message' => 'Aplikasi belum diinstal.',
                'setup_url' => route('install.index'),
            ]);
    }

    public function test_installer_page_never_discloses_the_one_time_token(): void
    {
        $token = $this->state->issueInstallerToken();

        $response = $this->get(route('install.index'));

        $response->assertOk()
            ->assertSee('siem:prepare-installation', false)
            ->assertDontSee($token, false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_prepare_command_forces_fail_safe_browser_environment_before_issuing_token(): void
    {
        $this->environment->update([
            'APP_ENV' => 'local',
            'APP_DEBUG' => true,
            'SESSION_ENCRYPT' => false,
        ]);

        $this->artisan('siem:prepare-installation')->assertSuccessful();

        $this->assertSame('production', $this->environment->value('APP_ENV'));
        $this->assertSame('false', $this->environment->value('APP_DEBUG'));
        $this->assertSame('true', $this->environment->value('SESSION_ENCRYPT'));
        $this->assertNotNull($this->environment->value('APP_KEY'));
    }

    public function test_valid_token_unlocks_wizard_without_storing_plain_token_in_session(): void
    {
        $token = $this->state->issueInstallerToken();

        $response = $this->post(route('install.unlock'), ['installation_token' => $token]);

        $response->assertRedirect(route('install.index'))
            ->assertSessionHas('installer.unlocked', true);
        $this->assertStringNotContainsString($token, serialize(session()->all()));
        $this->assertFalse($this->state->verifyToken($token));

        $this->get(route('install.index'))
            ->assertOk()
            ->assertSee('Konfigurasi awal')
            ->assertSee('Wazuh / OpenSearch')
            ->assertDontSee($token, false);
    }

    public function test_invalid_token_and_validation_errors_never_flash_secrets(): void
    {
        $badToken = 'not-a-valid-install-token';
        $this->post(route('install.unlock'), ['installation_token' => $badToken])
            ->assertRedirect(route('install.index'))
            ->assertSessionHasErrors('installation_token');
        $this->assertStringNotContainsString($badToken, serialize(session()->all()));

        $secrets = [
            'db_password' => 'database-secret',
            'opensearch_password' => 'indexer-secret',
            'telegram_bot_token' => '123456789:telegram-secret-token-value',
            'ai_api_key' => 'ai-secret',
            'mail_password' => 'mail-secret',
            'admin_password' => 'Admin-password-123!',
            'admin_password_confirmation' => 'Admin-password-123!',
        ];

        $this->withSession([
            'installer.unlocked' => true,
            'installer.unlocked_at' => now()->timestamp,
        ])->post(route('install.store'), $secrets)
            ->assertSessionHasErrors();

        $serializedSession = serialize(session()->all());
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $serializedSession);
        }
    }

    public function test_valid_form_calls_installer_then_closes_unlock_session(): void
    {
        $installer = $this->mock(Installer::class);
        $installer->shouldReceive('install')->once()->withArgs(function (array $data): bool {
            return $data['db_connection'] === 'pgsql'
                && $data['opensearch_verify_ssl'] === true
                && $data['ai_enabled'] === false
                && $data['smtp_enabled'] === false
                && $data['admin_email'] === 'admin@example.test';
        });

        $this->withSession([
            'installer.unlocked' => true,
            'installer.unlocked_at' => now()->timestamp,
        ])->post(route('install.store'), $this->validInput())
            ->assertRedirect(route('install.complete'))
            ->assertSessionMissing('installer.unlocked')
            ->assertSessionMissing('installer.unlocked_at');
    }

    public function test_installed_and_legacy_applications_cannot_reopen_wizard(): void
    {
        $this->environment->update(['APP_INSTALLED' => true]);

        $this->get(route('install.index'))->assertRedirect(route('install.complete'));
        $this->get(route('install.complete'))->assertOk();

        $this->environment->update(['APP_INSTALLED' => false]);
        $legacyState = $this->makeState(static fn (): bool => true);
        $this->app->instance(InstallationState::class, $legacyState);

        $this->get(route('install.index'))->assertRedirect(route('install.complete'));
    }

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        return [
            'app_name' => 'SIEM Salabim',
            'app_url' => 'https://siem.example.test',
            'app_timezone' => 'Asia/Jakarta',
            'db_connection' => 'pgsql',
            'db_host' => '127.0.0.1',
            'db_port' => '5432',
            'db_database' => 'siem',
            'db_username' => 'siem',
            'db_password' => 'database-secret',
            'db_sslmode' => 'require',
            'opensearch_host' => 'https://indexer.example.test:9200',
            'opensearch_username' => 'wazuh',
            'opensearch_password' => 'indexer-secret',
            'opensearch_index' => 'wazuh-alerts-*',
            'opensearch_source_cluster' => 'wazuh-primary',
            'opensearch_verify_ssl' => '1',
            'telegram_bot_token' => '123456789:telegram-secret-token-value',
            'telegram_chat_ids' => ['-1001234567890'],
            'ai_enabled' => '0',
            'smtp_enabled' => '0',
            'mail_encryption' => 'none',
            'admin_name' => 'SOC Administrator',
            'admin_email' => 'ADMIN@EXAMPLE.TEST',
            'admin_password' => 'Admin-password-123!',
            'admin_password_confirmation' => 'Admin-password-123!',
        ];
    }

    private function makeState(?\Closure $legacyDetector = null): InstallationState
    {
        return new InstallationState(
            environment: $this->environment,
            markerPath: $this->directory.'/private/installed.json',
            tokenPath: $this->directory.'/private/token.json',
            detectLegacyAdmin: $legacyDetector !== null,
            legacyAdminDetector: $legacyDetector,
        );
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
