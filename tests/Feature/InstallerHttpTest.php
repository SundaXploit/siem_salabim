<?php

namespace Tests\Feature;

use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use Illuminate\Support\Facades\Http;
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

    public function test_skipped_integrations_do_not_require_or_validate_hidden_fields(): void
    {
        $input = array_replace($this->validInput(), [
            'opensearch_enabled' => '0', 'opensearch_host' => 'not a URL',
            'telegram_enabled' => '0', 'telegram_bot_token' => 'invalid',
            'ai_enabled' => '0', 'ai_base_url' => 'not a URL',
        ]);
        $this->mock(Installer::class)->shouldReceive('install')->once()->withArgs(
            fn (array $data): bool => ! $data['opensearch_enabled'] && ! $data['telegram_enabled']
                && ! isset($data['opensearch_host']) && ! isset($data['telegram_bot_token']) && ! isset($data['ai_base_url']),
        );

        $this->withSession([
            'installer.unlocked' => true, 'installer.unlocked_at' => now()->timestamp,
        ])->post(route('install.store'), $input)
            ->assertSessionHasNoErrors()->assertRedirect(route('install.complete'));
    }

    public function test_enabled_integrations_still_require_valid_credentials(): void
    {
        $input = array_replace($this->validInput(), [
            'opensearch_enabled' => '1', 'opensearch_password' => '',
            'telegram_enabled' => '1', 'telegram_bot_token' => '',
            'ai_enabled' => '1', 'ai_api_key' => '',
        ]);
        $this->mock(Installer::class)->shouldNotReceive('install');

        $this->withSession([
            'installer.unlocked' => true, 'installer.unlocked_at' => now()->timestamp,
        ])->post(route('install.store'), $input)
            ->assertSessionHasErrors(['opensearch_password', 'telegram_bot_token', 'ai_api_key']);
    }

    public function test_installer_accepts_a_local_llm_without_an_api_key(): void
    {
        $input = array_replace($this->validInput(), [
            'ai_enabled' => '1', 'ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'http://127.0.0.1:11434/v1', 'ai_model' => 'local-model', 'ai_api_key' => '',
        ]);
        $this->mock(Installer::class)->shouldReceive('install')->once()->withArgs(
            fn (array $data): bool => $data['ai_provider'] === 'ollama'
                && $data['ai_base_url'] === 'http://127.0.0.1:11434/v1' && blank($data['ai_api_key'] ?? null),
        );
        $this->withSession([
            'installer.unlocked' => true, 'installer.unlocked_at' => now()->timestamp,
        ])->post(route('install.store'), $input)->assertSessionHasNoErrors()->assertRedirect(route('install.complete'));
    }

    public function test_unlocked_installer_discovers_local_models_before_database_setup(): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:11434/v1/models' => Http::response(['data' => [['id' => 'local-model']]])]);
        $this->withSession([
            'installer.unlocked' => true, 'installer.unlocked_at' => now()->timestamp,
        ])->postJson(route('install.ai.models'), [
            'ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'http://127.0.0.1:11434/v1',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('models.0.id', 'local-model');
        Http::assertSentCount(1);
        $this->assertNull($this->environment->value('DB_HOST'));
        $this->assertFalse($this->state->isInstalled());
    }

    public function test_locked_or_expired_installer_cannot_query_model_endpoints(): void
    {
        Http::preventStrayRequests();
        $this->postJson(route('install.ai.models'))->assertForbidden()->assertJsonPath('success', false);
        $this->withSession([
            'installer.unlocked' => true, 'installer.unlocked_at' => now()->subMinutes(31)->timestamp,
        ])->postJson(route('install.ai.models'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_model_discovery_never_flashes_installer_credentials(): void
    {
        Http::preventStrayRequests();
        $this->withSession([
            'installer.unlocked' => true, 'installer.unlocked_at' => now()->timestamp,
        ])->post(route('install.ai.models'), [
            'ai_provider' => 'custom', 'ai_api_format' => 'chat_completions',
            'ai_base_url' => 'not-a-url', 'ai_api_key' => 'private-installer-model-secret',
        ])->assertUnprocessable()->assertDontSee('private-installer-model-secret');
        $this->assertStringNotContainsString('private-installer-model-secret', serialize(session()->all()));
        Http::assertNothingSent();
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
