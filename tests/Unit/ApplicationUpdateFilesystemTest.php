<?php

namespace Tests\Unit;

use App\Exceptions\ApplicationUpdateException;
use App\Models\ApplicationUpdate;
use App\Services\ApplicationUpdateArtifact;
use App\Services\ApplicationUpdateFilesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ApplicationUpdateFilesystemTest extends TestCase
{
    public function test_first_git_update_removes_stale_source_vendor_and_built_assets_and_can_rollback(): void
    {
        $fixture = $this->fixture();

        try {
            $plan = app(ApplicationUpdateFilesystem::class)->backup(
                $fixture['update'],
                $fixture['release_root'],
                $fixture['manifest'],
            );

            $this->assertEqualsCanonicalizing([
                'app/Removed.php',
                'public/build/assets/old.js',
                'vendor/obsolete.php',
            ], array_values(array_intersect($plan['stale_files'], [
                'app/Removed.php',
                'public/build/assets/old.js',
                'vendor/obsolete.php',
            ])));

            $mutationStarted = false;
            app(ApplicationUpdateFilesystem::class)->deploy(
                $plan,
                static function () use (&$mutationStarted): void {
                    $mutationStarted = true;
                },
            );

            $this->assertTrue($mutationStarted);
            $this->assertSame("<?php // new\n", file_get_contents($fixture['app_root'].'/app/Keep.php'));
            $this->assertFileDoesNotExist($fixture['app_root'].'/app/Removed.php');
            $this->assertFileDoesNotExist($fixture['app_root'].'/vendor/obsolete.php');
            $this->assertFileDoesNotExist($fixture['app_root'].'/public/build/assets/old.js');
            $this->assertFileExists($fixture['app_root'].'/public/build/assets/new.js');
            $this->assertFileExists($fixture['app_root'].'/release-manifest.json');

            app(ApplicationUpdateFilesystem::class)->rollback($plan);

            $this->assertSame("<?php // old\n", file_get_contents($fixture['app_root'].'/app/Keep.php'));
            $this->assertFileExists($fixture['app_root'].'/app/Removed.php');
            $this->assertFileExists($fixture['app_root'].'/vendor/obsolete.php');
            $this->assertFileExists($fixture['app_root'].'/public/build/assets/old.js');
            $this->assertFileDoesNotExist($fixture['app_root'].'/public/build/assets/new.js');
            $this->assertFileDoesNotExist($fixture['app_root'].'/release-manifest.json');
        } finally {
            app('files')->deleteDirectory($fixture['root']);
        }
    }

    public function test_deploy_aborts_before_mutation_when_a_target_changes_after_backup(): void
    {
        $fixture = $this->fixture();

        try {
            $filesystem = app(ApplicationUpdateFilesystem::class);
            $plan = $filesystem->backup(
                $fixture['update'],
                $fixture['release_root'],
                $fixture['manifest'],
            );
            file_put_contents($fixture['app_root'].'/app/Keep.php', "<?php // foreign change\n");

            $mutationStarted = false;
            try {
                $filesystem->deploy(
                    $plan,
                    static function () use (&$mutationStarted): void {
                        $mutationStarted = true;
                    },
                );
                $this->fail('Deploy accepted a target changed after backup.');
            } catch (ApplicationUpdateException $exception) {
                $this->assertStringContainsString('berubah', $exception->getMessage());
            }

            $this->assertFalse($mutationStarted);
            $this->assertSame("<?php // foreign change\n", file_get_contents($fixture['app_root'].'/app/Keep.php'));
            $this->assertSame("0.1.0\n", file_get_contents($fixture['app_root'].'/VERSION'));
            $this->assertFileExists($fixture['app_root'].'/app/Removed.php');
        } finally {
            app('files')->deleteDirectory($fixture['root']);
        }
    }

    public function test_backup_rejects_a_version_changed_after_the_job_preflight(): void
    {
        $fixture = $this->fixture();
        file_put_contents($fixture['app_root'].'/VERSION', "0.0.9\n");

        try {
            try {
                app(ApplicationUpdateFilesystem::class)->backup(
                    $fixture['update'],
                    $fixture['release_root'],
                    $fixture['manifest'],
                );
                $this->fail('Backup accepted an application version different from the queued update.');
            } catch (ApplicationUpdateException $exception) {
                $this->assertStringContainsString('VERSION aplikasi berubah', $exception->getMessage());
            }

            $this->assertDirectoryDoesNotExist(
                $fixture['root'].'/work/'.$fixture['update']->uuid.'/code-backup',
            );
        } finally {
            app('files')->deleteDirectory($fixture['root']);
        }
    }

    public function test_backup_rechecks_version_after_copying_the_snapshot(): void
    {
        $fixture = $this->fixture();
        $statusCalls = 0;
        $tracked = ['VERSION', 'app/Keep.php', 'app/Removed.php'];
        Process::fake(function (PendingProcess $process) use ($fixture, &$statusCalls, $tracked) {
            if ($process->command === ['git', 'status', '--porcelain=v1', '--untracked-files=normal']) {
                $statusCalls++;
                if ($statusCalls === 2) {
                    file_put_contents($fixture['app_root'].'/VERSION', "0.0.9\n");
                }

                return Process::result('');
            }

            return match ($process->command) {
                ['git', 'ls-files', '-z', '--cached'] => Process::result(implode("\0", $tracked)."\0"),
                default => Process::result('', 'Unexpected updater test process.', 1),
            };
        });

        try {
            app(ApplicationUpdateFilesystem::class)->backup(
                $fixture['update'],
                $fixture['release_root'],
                $fixture['manifest'],
            );
            $this->fail('Backup accepted a version changed while its snapshot was being created.');
        } catch (ApplicationUpdateException $exception) {
            $this->assertStringContainsString('VERSION aplikasi berubah', $exception->getMessage());
        } finally {
            app('files')->deleteDirectory($fixture['root']);
        }

        $this->assertSame(2, $statusCalls);
    }

    public function test_rollback_refuses_to_overwrite_a_foreign_change_made_after_deploy(): void
    {
        $fixture = $this->fixture();

        try {
            $filesystem = app(ApplicationUpdateFilesystem::class);
            $plan = $filesystem->backup(
                $fixture['update'],
                $fixture['release_root'],
                $fixture['manifest'],
            );
            $filesystem->deploy($plan);
            file_put_contents($fixture['app_root'].'/app/Keep.php', "<?php // foreign after deploy\n");

            try {
                $filesystem->rollback($plan);
                $this->fail('Rollback overwrote a foreign post-deploy change.');
            } catch (ApplicationUpdateException $exception) {
                $this->assertStringContainsString('perubahan asing', $exception->getMessage());
            }

            $this->assertSame(
                "<?php // foreign after deploy\n",
                file_get_contents($fixture['app_root'].'/app/Keep.php'),
            );
        } finally {
            app('files')->deleteDirectory($fixture['root']);
        }
    }

    public function test_first_git_update_rejects_a_release_collision_with_an_unmanaged_local_file(): void
    {
        $fixture = $this->fixture(['app/Unmanaged.php' => "<?php // release\n"]);
        $this->putFile($fixture['app_root'], 'app/Unmanaged.php', "<?php // local ignored file\n");

        try {
            $this->expectException(ApplicationUpdateException::class);
            $this->expectExceptionMessage('tidak dikelola');

            app(ApplicationUpdateFilesystem::class)->backup(
                $fixture['update'],
                $fixture['release_root'],
                $fixture['manifest'],
            );
        } finally {
            app('files')->deleteDirectory($fixture['root']);
        }
    }

    #[DataProvider('protectedRuntimePaths')]
    public function test_runtime_and_secret_paths_are_rejected_by_artifact_and_filesystem(string $path): void
    {
        foreach ([
            app(ApplicationUpdateArtifact::class),
            app(ApplicationUpdateFilesystem::class),
        ] as $service) {
            try {
                (new ReflectionMethod($service, 'validatedRelativePath'))->invoke($service, $path);
                $this->fail("Protected updater path was accepted: {$path}");
            } catch (ApplicationUpdateException $exception) {
                $this->assertStringContainsString('terlarang', $exception->getMessage());
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function protectedRuntimePaths(): array
    {
        return [
            'environment variant' => ['.env.production'],
            'installer environment lock' => ['.env.install.lock'],
            'Composer credentials' => ['auth.json'],
            'npm credentials' => ['.npmrc'],
            'SQLite sidecar' => ['database/custom.sqlite-wal'],
            'nested SQLite sidecar' => ['database/private/runtime.sqlite-shm'],
            'nested DB sidecar' => ['database/private/runtime.db-wal'],
            'public storage' => ['public/storage/evidence.txt'],
            'runtime cache' => ['bootstrap/cache/config.php'],
        ];
    }

    /**
     * @param  array<string, string>  $extraReleaseFiles
     * @return array{
     *     root: string,
     *     app_root: string,
     *     release_root: string,
     *     manifest: array<string, mixed>,
     *     update: ApplicationUpdate
     * }
     */
    private function fixture(array $extraReleaseFiles = []): array
    {
        $root = storage_path('framework/testing/update-filesystem-'.Str::uuid());
        $appRoot = $root.'/application';
        $releaseRoot = $root.'/release';
        $workRoot = $root.'/work';
        $uuid = (string) Str::uuid();
        mkdir($appRoot.'/.git', 0755, true);
        mkdir($releaseRoot, 0755, true);
        mkdir($workRoot.'/'.$uuid, 0700, true);

        $oldFiles = [
            'VERSION' => "0.1.0\n",
            'app/Keep.php' => "<?php // old\n",
            'app/Removed.php' => "<?php // removed\n",
            'vendor/autoload.php' => "<?php // old autoload\n",
            'vendor/obsolete.php' => "<?php // obsolete\n",
            'public/build/manifest.json' => "{\"old\":true}\n",
            'public/build/assets/old.js' => "old();\n",
        ];
        foreach ($oldFiles as $path => $contents) {
            $this->putFile($appRoot, $path, $contents);
        }

        $releaseFiles = array_replace([
            'VERSION' => "0.2.0\n",
            'app/Keep.php' => "<?php // new\n",
            'vendor/autoload.php' => "<?php // new autoload\n",
            'public/build/manifest.json' => "{\"new\":true}\n",
            'public/build/assets/new.js' => "newAsset();\n",
        ], $extraReleaseFiles);
        $manifestFiles = [];
        foreach ($releaseFiles as $path => $contents) {
            $absolute = $this->putFile($releaseRoot, $path, $contents);
            $manifestFiles[$path] = hash_file('sha256', $absolute);
        }
        $manifest = ['files' => $manifestFiles];
        file_put_contents(
            $releaseRoot.'/release-manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR),
        );

        config([
            'application_updates.application_root' => $appRoot,
            'application_updates.work_directory' => $workRoot,
            'application_updates.refuse_dirty_worktree' => true,
        ]);

        $tracked = [
            'VERSION',
            'app/Keep.php',
            'app/Removed.php',
        ];
        Process::fake(static function (PendingProcess $process) use ($tracked) {
            return match ($process->command) {
                ['git', 'status', '--porcelain=v1', '--untracked-files=normal'] => Process::result(''),
                ['git', 'ls-files', '-z', '--cached'] => Process::result(implode("\0", $tracked)."\0"),
                default => Process::result('', 'Unexpected updater test process.', 1),
            };
        });
        Process::preventStrayProcesses();

        return [
            'root' => $root,
            'app_root' => $appRoot,
            'release_root' => $releaseRoot,
            'manifest' => $manifest,
            'update' => new ApplicationUpdate([
                'uuid' => $uuid,
                'from_version' => '0.1.0',
                'target_version' => '0.2.0',
            ]),
        ];
    }

    private function putFile(string $root, string $relativePath, string $contents): string
    {
        $absolute = $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, $contents);

        return $absolute;
    }
}
