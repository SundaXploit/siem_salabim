<?php

namespace Tests\Unit;

use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationState;
use RuntimeException;
use Tests\TestCase;

class InstallationStateTest extends TestCase
{
    private string $directory;

    private EnvironmentFileWriter $environment;

    private string $markerPath;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['installer.installed' => false]);

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siem-install-state-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/.env.example', "APP_KEY=\nAPP_INSTALLED=false\n");

        $this->environment = new EnvironmentFileWriter($this->directory);
        $this->environment->ensureEnvironmentAndKey();
        $this->markerPath = $this->directory.'/private/installed.json';
        $this->tokenPath = $this->directory.'/private/token.json';
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_environment_flag_or_marker_closes_the_installer(): void
    {
        $state = $this->state(detectLegacyAdmin: false);

        $this->assertTrue($state->installationRequired());

        mkdir(dirname($this->markerPath), 0700, true);
        file_put_contents($this->markerPath, '{}');
        $this->assertTrue($state->isInstalled());

        unlink($this->markerPath);
        $this->environment->update(['APP_INSTALLED' => true]);
        $this->assertTrue($state->isInstalled());
    }

    public function test_runtime_installed_configuration_takes_precedence_over_a_false_file_flag(): void
    {
        config(['installer.installed' => true]);

        $this->assertTrue($this->state(detectLegacyAdmin: false)->isInstalled());
    }

    public function test_legacy_admin_detection_is_safe_when_database_check_fails(): void
    {
        $installed = $this->state(legacyDetector: static fn (): bool => true);
        $unavailable = $this->state(legacyDetector: static function (): bool {
            throw new RuntimeException('database unavailable');
        });

        $this->assertTrue($installed->isInstalled());
        $this->assertFalse($unavailable->isInstalled());
    }

    public function test_token_is_hashed_rotated_and_removable(): void
    {
        $state = $this->state(detectLegacyAdmin: false);

        $first = $state->issueInstallerToken();
        $stored = file_get_contents($this->tokenPath);

        $this->assertTrue($state->verifyToken($first));
        $this->assertTrue($state->verifyInstallerToken($first));
        $this->assertFalse($state->verifyToken('incorrect-token'));
        $this->assertStringNotContainsString($first, $stored);
        $this->assertSame(43, strlen($first));

        $second = $state->issueInstallerToken();

        $this->assertFalse($state->verifyToken($first));
        $this->assertTrue($state->verifyToken($second));

        $this->assertFalse($state->consumeInstallerToken('incorrect-token'));
        $this->assertTrue($state->verifyToken($second));
        $this->assertTrue($state->consumeInstallerToken($second));
        $this->assertFalse($state->consumeInstallerToken($second));

        $this->assertFalse($state->verifyToken($second));
        $this->assertFileDoesNotExist($this->tokenPath);
    }

    public function test_mark_installed_updates_environment_then_writes_sanitized_marker(): void
    {
        $state = $this->state(detectLegacyAdmin: false);
        $token = $state->issueInstallerToken();

        $state->markInstalled([
            'version' => '1.2.3',
            'database_password' => 'must-not-be-stored',
            'api_key' => 'must-not-be-stored-either',
        ]);

        $marker = json_decode(file_get_contents($this->markerPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('true', $this->environment->value('APP_INSTALLED'));
        $this->assertTrue($state->isInstalled());
        $this->assertSame('1.2.3', $marker['metadata']['version']);
        $this->assertArrayNotHasKey('database_password', $marker['metadata']);
        $this->assertArrayNotHasKey('api_key', $marker['metadata']);
        $this->assertTrue($state->verifyToken($token), 'Manager removes the token only after every install step succeeds.');
    }

    public function test_mark_installed_rolls_back_environment_flag_when_marker_cannot_be_written(): void
    {
        $blockedParent = $this->directory.'/not-a-directory';
        file_put_contents($blockedParent, 'blocked');
        $state = new InstallationState(
            environment: $this->environment,
            markerPath: $blockedParent.'/installed.json',
            tokenPath: $this->tokenPath,
            detectLegacyAdmin: false,
        );

        try {
            $state->markInstalled();
            $this->fail('Marker write should fail when its parent is a regular file.');
        } catch (RuntimeException) {
            $this->assertSame('false', $this->environment->value('APP_INSTALLED'));
            $this->assertFalse($state->isInstalled());
        }
    }

    private function state(
        bool $detectLegacyAdmin = true,
        ?\Closure $legacyDetector = null,
    ): InstallationState {
        return new InstallationState(
            environment: $this->environment,
            markerPath: $this->markerPath,
            tokenPath: $this->tokenPath,
            tokenTtlSeconds: 3600,
            detectLegacyAdmin: $detectLegacyAdmin,
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
