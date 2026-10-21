<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

class ReleaseManifestScriptTest extends TestCase
{
    private string $releaseRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siem-release-'.bin2hex(random_bytes(8));
        mkdir($this->releaseRoot, 0700, true);
        $this->createMinimalRelease();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->releaseRoot);

        parent::tearDown();
    }

    public function test_it_builds_a_complete_portable_file_manifest(): void
    {
        $this->put('app/Example.php', "<?php\n");

        $process = $this->runBuilder();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $manifest = json_decode(
            (string) file_get_contents($this->releaseRoot.'/release-manifest.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('siem-salabim-release/v1', $manifest['schema']);
        $this->assertSame('SundaXploit/siem_salabim', $manifest['repository']);
        $this->assertSame('1.2.3', $manifest['version']);
        $this->assertSame('v1.2.3', $manifest['tag']);
        $this->assertSame(str_repeat('a', 40), $manifest['commit']);
        $this->assertContains('iconv', $manifest['required_extensions']);
        $this->assertContains('libxml', $manifest['required_extensions']);
        $this->assertContains('zip', $manifest['required_extensions']);
        $this->assertSame(hash('sha256', "<?php\n"), $manifest['files']['app/Example.php']);
        $this->assertArrayNotHasKey('release-manifest.json', $manifest['files']);
        $this->assertSame(array_keys($manifest['files']), $this->sorted(array_keys($manifest['files'])));
    }

    public function test_it_rejects_a_missing_runtime_dependency(): void
    {
        unlink($this->releaseRoot.'/vendor/autoload.php');

        $process = $this->runBuilder();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('vendor/autoload.php is missing', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->releaseRoot.'/release-manifest.json');
    }

    public function test_it_rejects_runtime_secrets(): void
    {
        $this->put('.env', "APP_KEY=secret\n");

        $process = $this->runBuilder();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('forbidden runtime or development path: .env', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->releaseRoot.'/release-manifest.json');
    }

    public function test_it_rejects_even_empty_runtime_directories(): void
    {
        mkdir($this->releaseRoot.'/storage/framework', 0700, true);

        $process = $this->runBuilder();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('forbidden runtime or development path: storage', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->releaseRoot.'/release-manifest.json');
    }

    public function test_it_rejects_nested_local_database_files(): void
    {
        $this->put('database/private/runtime.sqlite-wal', 'database payload');

        $process = $this->runBuilder();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString(
            'forbidden runtime or development path: database/private/runtime.sqlite-wal',
            $process->getErrorOutput(),
        );
        $this->assertFileDoesNotExist($this->releaseRoot.'/release-manifest.json');
    }

    public function test_it_rejects_paths_that_collide_on_case_insensitive_filesystems(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The Windows filesystem cannot create this collision fixture.');
        }

        $this->put('config/Service.php', "<?php\n");
        $this->put('config/service.php', "<?php\n");

        $process = $this->runBuilder();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('case-ambiguous paths', $process->getErrorOutput());
    }

    private function createMinimalRelease(): void
    {
        $this->put('.env.example', "APP_KEY=\n");
        $this->put('VERSION', "1.2.3\n");
        $this->put('artisan', "<?php\n");
        $this->put('composer.json', "{}\n");
        $this->put('composer.lock', "{}\n");
        $this->put('vendor/autoload.php', "<?php\n");
        $this->put('public/build/manifest.json', "{}\n");
    }

    private function put(string $relativePath, string $contents): void
    {
        $path = $this->releaseRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $contents);
    }

    private function runBuilder(): Process
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/scripts/build-release-manifest.php',
            $this->releaseRoot,
            str_repeat('a', 40),
            'v1.2.3',
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
