<?php

namespace Tests\Unit;

use App\Exceptions\ApplicationUpdateException;
use App\Models\ApplicationUpdate;
use App\Services\ApplicationUpdateArtifact;
use App\Services\ApplicationUpdateFilesystem;
use App\Services\GitHubReleaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ApplicationUpdateSecurityTest extends TestCase
{
    #[DataProvider('unsafeZipPaths')]
    public function test_zip_entries_reject_windows_devices_ads_and_ambiguous_names(string $path): void
    {
        $this->expectException(ApplicationUpdateException::class);

        $this->invokeArtifactMethod('safeSegments', $path);
    }

    /** @return array<string, array{string}> */
    public static function unsafeZipPaths(): array
    {
        return [
            'device name' => ['siem-salabim/NUL.txt'],
            'serial device name' => ['siem-salabim/COM1'],
            'alternate data stream' => ['siem-salabim/config.php:payload'],
            'trailing dot' => ['siem-salabim/config.php.'],
            'trailing space' => ['siem-salabim/config.php '],
            'empty segment' => ['siem-salabim//config.php'],
        ];
    }

    #[DataProvider('invalidMinimumPhpVersions')]
    public function test_manifest_requires_an_exact_stable_minimum_php_version(mixed $version): void
    {
        $this->expectException(ApplicationUpdateException::class);
        $this->expectExceptionMessage('Versi minimum PHP');

        $this->invokeArtifactMethod('validatedMinimumPhp', $version);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidMinimumPhpVersions(): array
    {
        return [
            'constraint' => ['>=8.3'],
            'short version' => ['8.3'],
            'prerelease' => ['8.3.0-rc1'],
            'leading zero' => ['08.3.0'],
            'wrong type' => [80300],
        ];
    }

    #[DataProvider('tamperedUpdateMetadata')]
    public function test_tampered_database_metadata_is_rejected_before_a_work_directory_is_created(
        array $overrides,
        string $message,
    ): void {
        $workRoot = storage_path('framework/testing/updater-metadata-'.Str::uuid());
        config(['application_updates.work_directory' => $workRoot]);
        $update = new ApplicationUpdate(array_replace($this->validUpdateMetadata(), $overrides));

        try {
            app(ApplicationUpdateArtifact::class)->prepare($update);
            $this->fail('Tampered updater metadata was accepted.');
        } catch (ApplicationUpdateException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($workRoot);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function tamperedUpdateMetadata(): array
    {
        return [
            'path traversal uuid' => [['uuid' => '../outside'], 'UUID'],
            'path traversal asset name' => [['asset_name' => '../payload.zip'], 'Nama asset'],
            'header injection source version' => [[
                'from_version' => "0.1.0\r\nX-Injected: yes",
            ], 'versi'],
            'uppercase digest' => [['asset_sha256' => str_repeat('A', 64)], 'Digest'],
            'untrusted asset endpoint' => [[
                'asset_api_url' => 'https://example.test/payload.zip',
            ], 'URL asset'],
        ];
    }

    #[DataProvider('misleadingGitHubUrls')]
    public function test_release_link_must_be_an_exact_origin_and_path(string $url): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $release = $this->validRelease();
        $release['html_url'] = $url;
        Http::fake([
            'https://api.github.com/repos/SundaXploit/siem_salabim/releases/latest' => Http::response($release),
        ]);

        $this->expectException(ApplicationUpdateException::class);
        $this->expectExceptionMessage('URL GitHub Release');

        app(GitHubReleaseService::class)->check(true);
    }

    /** @return array<string, array{string}> */
    public static function misleadingGitHubUrls(): array
    {
        $path = '/SundaXploit/siem_salabim/releases/tag/v0.2.0';

        return [
            'userinfo' => ["https://operator@github.com{$path}"],
            'explicit port' => ["https://github.com:443{$path}"],
            'query' => ["https://github.com{$path}?download=1"],
            'fragment' => ["https://github.com{$path}#notes"],
            'path suffix' => ["https://github.com{$path}/unexpected"],
        ];
    }

    public function test_a_matching_installed_manifest_is_accepted_as_pristine_release_proof(): void
    {
        $root = storage_path('framework/testing/pristine-release-'.Str::uuid());
        mkdir($root.'/vendor', 0755, true);
        mkdir($root.'/public/build', 0755, true);
        $files = [
            'VERSION' => '0.1.0'.PHP_EOL,
            'artisan' => '<?php',
            'composer.json' => '{}',
            'composer.lock' => '{}',
            'vendor/autoload.php' => '<?php',
            'public/build/manifest.json' => '{}',
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative), $contents);
        }
        $manifestFiles = [];
        foreach (array_keys($files) as $relative) {
            $manifestFiles[$relative] = hash_file(
                'sha256',
                $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative),
            );
        }
        file_put_contents($root.'/release-manifest.json', json_encode([
            'schema' => 'siem-salabim-release/v1',
            'repository' => 'SundaXploit/siem_salabim',
            'version' => '0.1.0',
            'tag' => 'v0.1.0',
            'commit' => str_repeat('a', 40),
            'files' => $manifestFiles,
        ], JSON_THROW_ON_ERROR));
        config(['application_updates.application_root' => $root]);

        try {
            $this->assertTrue(
                app(ApplicationUpdateFilesystem::class)->hasPristineInstalledRelease('0.1.0'),
            );
            file_put_contents($root.'/artisan', '<?php // locally modified');
            $this->expectException(ApplicationUpdateException::class);
            $this->expectExceptionMessage('berubah atau hilang');
            app(ApplicationUpdateFilesystem::class)->hasPristineInstalledRelease('0.1.0');
        } finally {
            app('files')->deleteDirectory($root);
        }
    }

    /** @return array<string, mixed> */
    private function validUpdateMetadata(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'from_version' => '0.1.0',
            'target_version' => '0.2.0',
            'release_id' => 901,
            'release_tag' => 'v0.2.0',
            'asset_id' => 902,
            'asset_name' => 'siem-salabim-v0.2.0.zip',
            'asset_api_url' => 'https://api.github.com/repos/SundaXploit/siem_salabim/releases/assets/902',
            'asset_size' => 10_485_760,
            'asset_sha256' => str_repeat('a', 64),
        ];
    }

    /** @return array<string, mixed> */
    private function validRelease(): array
    {
        return [
            'id' => 901,
            'tag_name' => 'v0.2.0',
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://github.com/SundaXploit/siem_salabim/releases/tag/v0.2.0',
            'published_at' => '2026-10-16T10:00:00Z',
            'assets' => [[
                'id' => 902,
                'name' => 'siem-salabim-v0.2.0.zip',
                'url' => 'https://api.github.com/repos/SundaXploit/siem_salabim/releases/assets/902',
                'size' => 10_485_760,
                'digest' => 'sha256:'.str_repeat('a', 64),
            ]],
        ];
    }

    private function invokeArtifactMethod(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(ApplicationUpdateArtifact::class, $method))
            ->invoke(app(ApplicationUpdateArtifact::class), ...$arguments);
    }
}
