<?php

namespace App\Services;

use App\Exceptions\ApplicationUpdateException;
use App\Models\ApplicationUpdate;
use FilesystemIterator;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class ApplicationUpdateFilesystem
{
    private const MAX_MANAGED_FILES = 100_000;

    public function hasPristineInstalledRelease(string $currentVersion): bool
    {
        $applicationRoot = $this->applicationRoot();
        $managedFiles = $this->installedManagedFiles($applicationRoot, $currentVersion, true);

        return $managedFiles !== null;
    }

    /**
     * @param  array<string, mixed>  $releaseManifest
     * @return array<string, mixed>
     */
    public function backup(
        ApplicationUpdate $update,
        string $releaseRoot,
        array $releaseManifest,
    ): array {
        $applicationRoot = $this->applicationRoot();
        $releaseRoot = $this->existingDirectory($releaseRoot, 'Root release update tidak ditemukan.');
        $this->assertInstalledVersion($applicationRoot, $update->from_version);
        $files = $this->releaseFiles($releaseManifest);
        $previousManagedFiles = $this->installedManagedFiles(
            $applicationRoot,
            $update->from_version,
            true,
        );
        $usesGitBaseline = $previousManagedFiles === null;
        if ($usesGitBaseline) {
            $previousManagedFiles = $this->gitManagedFiles($applicationRoot);
        }
        $previousManagedLookup = array_fill_keys($previousManagedFiles, true);
        $staleFiles = array_values(array_diff($previousManagedFiles, $files));
        $workRoot = $this->updateWorkRoot($update);
        $backupRoot = $workRoot.DIRECTORY_SEPARATOR.'code-backup';
        $backupFilesRoot = $backupRoot.DIRECTORY_SEPARATOR.'files';

        if (file_exists($backupRoot) || is_link($backupRoot)) {
            throw new ApplicationUpdateException('Backup kode untuk update ini sudah ada.');
        }

        $this->makePrivateDirectory($backupFilesRoot);

        $releaseFiles = $this->releaseFileMetadata($releaseRoot, $files, $releaseManifest);
        $restoreFiles = [];
        $newFiles = [];
        $targetState = [];
        $requiredBytes = 10_485_760;
        foreach (array_values(array_unique([...$files, ...$staleFiles])) as $relativePath) {
            $target = $this->targetPath($applicationRoot, $relativePath);
            $this->assertSafeTarget($applicationRoot, $relativePath);

            if (! file_exists($target)) {
                $targetState[$relativePath] = ['exists' => false];
                if (in_array($relativePath, $files, true)) {
                    $newFiles[] = $relativePath;
                }

                continue;
            }

            if (! isset($previousManagedLookup[$relativePath])) {
                throw new ApplicationUpdateException(
                    "File release bertabrakan dengan file lokal yang tidak dikelola: {$relativePath}.",
                );
            }

            if (! is_file($target) || is_link($target)) {
                throw new ApplicationUpdateException("Target update bukan file reguler: {$relativePath}.");
            }

            $size = filesize($target);
            if ($size === false) {
                throw new ApplicationUpdateException("Ukuran file lama tidak dapat dibaca: {$relativePath}.");
            }
            $digest = hash_file('sha256', $target);
            if (! is_string($digest)) {
                throw new ApplicationUpdateException("Digest file lama tidak dapat dibaca: {$relativePath}.");
            }
            $targetState[$relativePath] = [
                'exists' => true,
                'sha256' => $digest,
                'size' => $size,
            ];
            $requiredBytes += $size;
            $restoreFiles[] = $relativePath;
        }

        foreach ($releaseFiles as $metadata) {
            $requiredBytes += $metadata['size'];
        }

        $freeBytes = @disk_free_space($backupRoot);
        if (is_float($freeBytes) && $freeBytes < $requiredBytes) {
            throw new ApplicationUpdateException('Ruang disk tidak cukup untuk staging dan backup kode update.');
        }

        $restoreMetadata = [];
        foreach ($restoreFiles as $relativePath) {
            $source = $this->targetPath($applicationRoot, $relativePath);
            $destination = $this->targetPath($backupFilesRoot, $relativePath);
            $this->makePrivateDirectory(dirname($destination));
            if (! @copy($source, $destination)) {
                throw new ApplicationUpdateException("Backup file aplikasi gagal: {$relativePath}.");
            }

            $sourcePermissions = fileperms($source);
            if (is_int($sourcePermissions)) {
                @chmod($destination, $sourcePermissions & 0777);
            }

            $sourceDigest = hash_file('sha256', $source);
            $backupDigest = hash_file('sha256', $destination);
            $backupSize = filesize($destination);
            if (! is_string($sourceDigest)
                || ! is_string($backupDigest)
                || ! is_int($backupSize)
                || $backupSize !== $targetState[$relativePath]['size']
                || ! hash_equals($targetState[$relativePath]['sha256'], $sourceDigest)
                || ! hash_equals($sourceDigest, $backupDigest)) {
                throw new ApplicationUpdateException("Verifikasi backup kode gagal: {$relativePath}.");
            }

            $restoreMetadata[$relativePath] = [
                'sha256' => $backupDigest,
                'size' => $backupSize,
                'mode' => is_int($sourcePermissions) ? $sourcePermissions & 0777 : null,
            ];
        }

        // A tracked file may have changed while the backup was being copied.
        // Recheck the worktree after the snapshot so that the opt-out flag is
        // the only way a modified Git checkout can be updated.
        if ($usesGitBaseline && config('application_updates.refuse_dirty_worktree', true)) {
            $this->assertCleanGitWorktree($applicationRoot);
        }
        $this->assertInstalledVersion($applicationRoot, $update->from_version);

        sort($files);
        sort($staleFiles);
        sort($newFiles);
        ksort($restoreMetadata);
        ksort($releaseFiles);
        ksort($targetState);

        $backupManifest = [
            'schema' => 'siem-salabim-code-backup/v1',
            'update_uuid' => $update->uuid,
            'from_version' => $update->from_version,
            'target_version' => $update->target_version,
            'created_at' => now()->toIso8601String(),
            'release_root' => $releaseRoot,
            'deploy_files' => $files,
            'stale_files' => $staleFiles,
            'new_files' => $newFiles,
            'restore_files' => $restoreMetadata,
            'release_files' => $releaseFiles,
            'target_state' => $targetState,
        ];
        $manifestPath = $backupRoot.DIRECTORY_SEPARATOR.'backup-manifest.json';
        $encoded = json_encode(
            $backupManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        if (file_put_contents($manifestPath, $encoded, LOCK_EX) !== strlen($encoded)) {
            throw new ApplicationUpdateException('Manifest backup kode tidak dapat ditulis.');
        }
        @chmod($manifestPath, 0600);

        return [
            'application_root' => $applicationRoot,
            'release_root' => $releaseRoot,
            'backup_root' => $backupRoot,
            'manifest_path' => $manifestPath,
            'deploy_files' => $files,
            'stale_files' => $staleFiles,
            'new_files' => $newFiles,
            'restore_files' => $restoreMetadata,
            'release_files' => $releaseFiles,
            'target_state' => $targetState,
            'update_uuid' => $update->uuid,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  (callable(): void)|null  $onMutationStarting
     */
    public function deploy(array $plan, ?callable $onMutationStarting = null): void
    {
        $applicationRoot = $this->existingDirectory(
            (string) ($plan['application_root'] ?? ''),
            'Root aplikasi pada rencana update tidak valid.',
        );
        $releaseRoot = $this->existingDirectory(
            (string) ($plan['release_root'] ?? ''),
            'Root release pada rencana update tidak valid.',
        );
        $updateUuid = (string) ($plan['update_uuid'] ?? '');
        $files = $this->stringList($plan['deploy_files'] ?? null);
        $staleFiles = $this->stringList($plan['stale_files'] ?? []);
        $releaseFiles = $this->fileStateMap($plan['release_files'] ?? null, true);
        $targetState = $this->fileStateMap($plan['target_state'] ?? null);
        $this->assertPlanCoverage($files, $staleFiles, $releaseFiles, $targetState);

        foreach ($files as $relativePath) {
            $source = $this->targetPath($releaseRoot, $relativePath);
            $this->assertRegularFileDigest($source, $releaseFiles[$relativePath], 'staging update', $relativePath);
            $this->assertSafeTarget($applicationRoot, $relativePath);
            $this->assertTargetMatchesState($applicationRoot, $relativePath, $targetState[$relativePath]);
        }
        foreach ($staleFiles as $relativePath) {
            $this->assertSafeTarget($applicationRoot, $relativePath);
            $this->assertTargetMatchesState($applicationRoot, $relativePath, $targetState[$relativePath]);
        }

        usort($files, static function (string $left, string $right): int {
            $priority = static fn (string $path): int => match (true) {
                $path === 'VERSION' => 30,
                $path === 'release-manifest.json' => 20,
                str_starts_with($path, 'vendor/') => 10,
                default => 0,
            };

            return [$priority($left), $left] <=> [$priority($right), $right];
        });

        if ($onMutationStarting !== null) {
            $onMutationStarting();
        }

        foreach ($files as $relativePath) {
            $relativePath = $this->validatedRelativePath($relativePath);
            $source = $this->targetPath($releaseRoot, $relativePath);
            if (! is_file($source) || is_link($source)) {
                throw new ApplicationUpdateException("File staging update tidak tersedia: {$relativePath}.");
            }

            $this->assertSafeTarget($applicationRoot, $relativePath);
            $this->atomicReplace(
                $source,
                $this->targetPath($applicationRoot, $relativePath),
                $updateUuid,
                $releaseFiles[$relativePath]['sha256'],
                $targetState[$relativePath],
            );
        }

        foreach ($staleFiles as $relativePath) {
            $relativePath = $this->validatedRelativePath($relativePath);
            $this->assertSafeTarget($applicationRoot, $relativePath);
            $this->assertTargetMatchesState($applicationRoot, $relativePath, $targetState[$relativePath]);
            $target = $this->targetPath($applicationRoot, $relativePath);
            if (is_file($target) && ! is_link($target) && ! @unlink($target)) {
                throw new ApplicationUpdateException("File lama tidak dapat dihapus: {$relativePath}.");
            }
        }

        foreach ($files as $relativePath) {
            $this->assertTargetMatchesState($applicationRoot, $relativePath, $releaseFiles[$relativePath]);
        }
        foreach ($staleFiles as $relativePath) {
            $this->assertTargetMatchesState($applicationRoot, $relativePath, ['exists' => false]);
        }
    }

    /** @param array<string, mixed> $plan */
    public function rollback(array $plan): void
    {
        $applicationRoot = $this->existingDirectory(
            (string) ($plan['application_root'] ?? ''),
            'Root aplikasi pada backup update tidak valid.',
        );
        $backupRoot = $this->existingDirectory(
            (string) ($plan['backup_root'] ?? ''),
            'Backup kode update tidak ditemukan.',
        );
        $backupFilesRoot = $backupRoot.DIRECTORY_SEPARATOR.'files';
        $updateUuid = (string) ($plan['update_uuid'] ?? '');
        $newFiles = $this->stringList($plan['new_files'] ?? []);
        $restoreFiles = is_array($plan['restore_files'] ?? null)
            ? $plan['restore_files']
            : [];
        $releaseFiles = $this->fileStateMap($plan['release_files'] ?? null, true);
        $targetState = $this->fileStateMap($plan['target_state'] ?? null);

        $rollbackState = [];
        foreach ($newFiles as $relativePath) {
            $relativePath = $this->validatedRelativePath($relativePath);
            if (! isset($releaseFiles[$relativePath]) || ! isset($targetState[$relativePath])) {
                throw new ApplicationUpdateException('Rencana rollback file baru tidak lengkap.');
            }
            $this->assertSafeTarget($applicationRoot, $relativePath);
            $rollbackState[$relativePath] = $this->assertRollbackTargetAllowed(
                $applicationRoot,
                $relativePath,
                $targetState[$relativePath],
                $releaseFiles[$relativePath],
                false,
            );
        }

        foreach ($restoreFiles as $relativePath => $metadata) {
            if (! is_string($relativePath)
                || ! is_array($metadata)
                || ! is_string($metadata['sha256'] ?? null)) {
                throw new ApplicationUpdateException('Manifest restore kode tidak valid.');
            }

            $relativePath = $this->validatedRelativePath($relativePath);
            $source = $this->targetPath($backupFilesRoot, $relativePath);
            $backupState = $this->validatedExistingFileState($metadata, 'Manifest restore kode tidak valid.');
            if (! isset($targetState[$relativePath])
                || ($targetState[$relativePath]['exists'] ?? null) !== true
                || $targetState[$relativePath]['size'] !== $backupState['size']
                || ! hash_equals($targetState[$relativePath]['sha256'], $backupState['sha256'])) {
                throw new ApplicationUpdateException('Snapshot asli dan backup rollback tidak cocok.');
            }
            $this->assertRegularFileDigest($source, $backupState, 'backup rollback', $relativePath);
            $this->assertSafeTarget($applicationRoot, $relativePath);
            $rollbackState[$relativePath] = $this->assertRollbackTargetAllowed(
                $applicationRoot,
                $relativePath,
                $targetState[$relativePath],
                $releaseFiles[$relativePath] ?? null,
                ! isset($releaseFiles[$relativePath]),
            );
        }

        foreach ($newFiles as $relativePath) {
            $relativePath = $this->validatedRelativePath($relativePath);
            $this->assertSafeTarget($applicationRoot, $relativePath);
            $target = $this->targetPath($applicationRoot, $relativePath);
            $this->assertTargetMatchesState($applicationRoot, $relativePath, $rollbackState[$relativePath]);
            if (($rollbackState[$relativePath]['exists'] ?? false)
                && ! @unlink($target)) {
                throw new ApplicationUpdateException("Rollback file baru gagal: {$relativePath}.");
            }
        }

        foreach ($restoreFiles as $relativePath => $metadata) {
            $relativePath = $this->validatedRelativePath($relativePath);
            $source = $this->targetPath($backupFilesRoot, $relativePath);
            $backupState = $this->validatedExistingFileState($metadata, 'Manifest restore kode tidak valid.');
            $currentState = $rollbackState[$relativePath];
            $this->assertTargetMatchesState($applicationRoot, $relativePath, $currentState);
            if (($currentState['exists'] ?? false)
                && hash_equals($backupState['sha256'], $currentState['sha256'])) {
                continue;
            }

            $this->assertSafeTarget($applicationRoot, $relativePath);
            $this->atomicReplace(
                $source,
                $this->targetPath($applicationRoot, $relativePath),
                $updateUuid.'-rollback',
                $backupState['sha256'],
                $currentState,
            );
        }

        foreach ($targetState as $relativePath => $state) {
            $this->assertTargetMatchesState($applicationRoot, $relativePath, $state);
        }

        foreach ($newFiles as $relativePath) {
            $this->removeEmptyParents(dirname($this->targetPath($applicationRoot, $relativePath)), $applicationRoot);
        }
    }

    /** @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function releaseFiles(array $manifest): array
    {
        $declared = $manifest['files'] ?? null;
        if (! is_array($declared) || $declared === [] || array_is_list($declared)) {
            throw new ApplicationUpdateException('Daftar file pada manifest release tidak valid.');
        }

        $files = [];
        foreach (array_keys($declared) as $relativePath) {
            if (! is_string($relativePath)) {
                throw new ApplicationUpdateException('Path pada manifest release tidak valid.');
            }
            $files[] = $this->validatedRelativePath($relativePath);
        }
        $files[] = 'release-manifest.json';

        return array_values(array_unique($files));
    }

    /**
     * Build the ownership baseline for the first self-update. Git identifies
     * source files that may have been removed by the new release, while the
     * generated dependency and asset trees cover ignored files that Git does
     * not know about.
     *
     * @return list<string>
     */
    private function gitManagedFiles(string $applicationRoot): array
    {
        $gitMetadata = $applicationRoot.DIRECTORY_SEPARATOR.'.git';
        if ((! file_exists($gitMetadata) && ! is_link($gitMetadata))
            || is_link($gitMetadata)
            || (! is_file($gitMetadata) && ! is_dir($gitMetadata))) {
            throw new ApplicationUpdateException(
                'Baseline update pertama memerlukan metadata Git reguler yang dapat diverifikasi.',
            );
        }

        $requireClean = (bool) config('application_updates.refuse_dirty_worktree', true);
        if ($requireClean) {
            $this->assertCleanGitWorktree($applicationRoot);
        }

        try {
            $result = Process::path($applicationRoot)
                ->timeout(30)
                ->run(['git', 'ls-files', '-z', '--cached']);
        } catch (Throwable $exception) {
            throw new ApplicationUpdateException(
                'Daftar file Git untuk baseline update tidak dapat dibaca.',
                0,
                $exception,
            );
        }
        if ($result->failed()) {
            throw new ApplicationUpdateException('Daftar file Git untuk baseline update tidak dapat diverifikasi.');
        }

        $output = $result->output();
        $lastSeparator = strrpos($output, "\0");
        if ($lastSeparator !== false) {
            $trailing = substr($output, $lastSeparator + 1);
            if (trim($trailing) !== '') {
                throw new ApplicationUpdateException('Output daftar file Git tidak valid.');
            }
            $output = substr($output, 0, $lastSeparator + 1);
        } elseif (trim($output) === '') {
            $output = '';
        }

        $files = [];
        $canonicalPaths = [];
        foreach (explode("\0", $output) as $path) {
            if ($path === '') {
                continue;
            }
            $path = $this->normalisedRelativePath($path);
            if ($this->isProtectedRuntimePath($path)) {
                continue;
            }
            $this->addManagedPath($files, $canonicalPaths, $path);
        }

        foreach (['vendor', 'public/build'] as $generatedRoot) {
            $absoluteRoot = $this->targetPath($applicationRoot, $generatedRoot);
            if (! file_exists($absoluteRoot) && ! is_link($absoluteRoot)) {
                continue;
            }
            if (! is_dir($absoluteRoot) || is_link($absoluteRoot)) {
                throw new ApplicationUpdateException(
                    "Direktori hasil build untuk baseline update tidak aman: {$generatedRoot}.",
                );
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                $relativePath = str_replace(
                    '\\',
                    '/',
                    substr($entry->getPathname(), strlen($applicationRoot) + 1),
                );
                if ($entry->isLink()) {
                    throw new ApplicationUpdateException(
                        "Baseline update tidak menerima symbolic link: {$relativePath}.",
                    );
                }
                if ($entry->isDir()) {
                    continue;
                }
                if (! $entry->isFile()) {
                    throw new ApplicationUpdateException(
                        "Baseline update memuat file non-reguler: {$relativePath}.",
                    );
                }

                $relativePath = $this->validatedRelativePath($relativePath);
                $this->addManagedPath($files, $canonicalPaths, $relativePath);
            }
        }

        if ($files === []) {
            throw new ApplicationUpdateException('Baseline update pertama dari Git tidak berisi file yang dapat dikelola.');
        }

        sort($files);

        return $files;
    }

    private function assertCleanGitWorktree(string $applicationRoot): void
    {
        try {
            $result = Process::path($applicationRoot)
                ->timeout(30)
                ->run(['git', 'status', '--porcelain=v1', '--untracked-files=normal']);
        } catch (Throwable $exception) {
            throw new ApplicationUpdateException(
                'Status Git worktree tidak dapat diverifikasi; update dibatalkan.',
                0,
                $exception,
            );
        }

        if ($result->failed() || trim($result->output()) !== '') {
            throw new ApplicationUpdateException(
                'Ada perubahan file terlacak atau tidak terlacak pada Git worktree. Gunakan deployment bersih sebelum self-update.',
            );
        }
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, string>  $canonicalPaths
     */
    private function addManagedPath(array &$files, array &$canonicalPaths, string $path): void
    {
        $canonical = strtolower($path);
        if (isset($canonicalPaths[$canonical])) {
            if ($canonicalPaths[$canonical] === $path) {
                return;
            }
            throw new ApplicationUpdateException('Baseline update memiliki path duplikat atau ambigu.');
        }
        if (count($files) >= self::MAX_MANAGED_FILES) {
            throw new ApplicationUpdateException('Baseline update melampaui batas jumlah file yang aman.');
        }

        $canonicalPaths[$canonical] = $path;
        $files[] = $path;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, mixed>  $releaseManifest
     * @return array<string, array{exists: true, sha256: string, size: int}>
     */
    private function releaseFileMetadata(string $releaseRoot, array $files, array $releaseManifest): array
    {
        $declared = $releaseManifest['files'] ?? null;
        if (! is_array($declared)) {
            throw new ApplicationUpdateException('Manifest release untuk backup tidak valid.');
        }

        $metadata = [];
        foreach ($files as $relativePath) {
            $source = $this->targetPath($releaseRoot, $relativePath);
            if (! is_file($source) || is_link($source)) {
                throw new ApplicationUpdateException("File release tidak tersedia untuk backup: {$relativePath}.");
            }
            $size = filesize($source);
            $digest = hash_file('sha256', $source);
            if (! is_int($size) || ! is_string($digest)) {
                throw new ApplicationUpdateException("Metadata file release tidak dapat dibaca: {$relativePath}.");
            }

            if ($relativePath === 'release-manifest.json') {
                try {
                    $onDiskManifest = json_decode(
                        (string) file_get_contents($source),
                        true,
                        64,
                        JSON_THROW_ON_ERROR,
                    );
                } catch (Throwable $exception) {
                    throw new ApplicationUpdateException(
                        'Manifest release berubah setelah verifikasi awal.',
                        0,
                        $exception,
                    );
                }
                if ($onDiskManifest !== $releaseManifest) {
                    throw new ApplicationUpdateException('Manifest release berubah setelah verifikasi awal.');
                }
            } else {
                $expected = $declared[$relativePath] ?? null;
                if (! is_string($expected)
                    || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
                    || ! hash_equals($expected, $digest)) {
                    throw new ApplicationUpdateException(
                        "File release berubah setelah verifikasi awal: {$relativePath}.",
                    );
                }
            }

            $metadata[$relativePath] = [
                'exists' => true,
                'sha256' => $digest,
                'size' => $size,
            ];
        }

        return $metadata;
    }

    /** @return list<string>|null */
    private function installedManagedFiles(
        string $applicationRoot,
        string $currentVersion,
        bool $verifyDigests,
    ): ?array {
        $path = $applicationRoot.DIRECTORY_SEPARATOR.'release-manifest.json';
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        if (! is_file($path) || is_link($path)) {
            throw new ApplicationUpdateException('Manifest release terpasang bukan file reguler.');
        }

        try {
            $previous = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($previous)
                || ($previous['schema'] ?? null) !== 'siem-salabim-release/v1'
                || ($previous['repository'] ?? null) !== config('application_updates.repository')
                || ($previous['version'] ?? null) !== $currentVersion
                || ($previous['tag'] ?? null) !== 'v'.$currentVersion
                || ! is_string($previous['commit'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $previous['commit']) !== 1
                || ! is_array($previous['files'] ?? null)
                || $previous['files'] === []
                || array_is_list($previous['files'])
                || count($previous['files']) > 100_000) {
                throw new ApplicationUpdateException('Manifest release terpasang tidak valid.');
            }

            $managed = [];
            $canonicalPaths = [];
            foreach ($previous['files'] as $relativePath => $expectedDigest) {
                if (! is_string($relativePath)
                    || ! is_string($expectedDigest)
                    || preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1) {
                    throw new ApplicationUpdateException('Digest manifest release terpasang tidak valid.');
                }
                $relativePath = $this->validatedRelativePath($relativePath);
                if ($relativePath === 'release-manifest.json') {
                    throw new ApplicationUpdateException(
                        'Manifest release terpasang tidak boleh mendaftarkan dirinya sendiri.',
                    );
                }
                $canonical = strtolower($relativePath);
                if (isset($canonicalPaths[$canonical])) {
                    throw new ApplicationUpdateException(
                        'Manifest release terpasang memiliki path duplikat atau ambigu.',
                    );
                }
                $canonicalPaths[$canonical] = true;

                if ($verifyDigests) {
                    $absolutePath = $this->targetPath($applicationRoot, $relativePath);
                    $actualDigest = is_file($absolutePath) && ! is_link($absolutePath)
                        ? hash_file('sha256', $absolutePath)
                        : false;
                    if (! is_string($actualDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
                        throw new ApplicationUpdateException(
                            "File release terpasang berubah atau hilang: {$relativePath}.",
                        );
                    }
                }

                $managed[] = $relativePath;
            }

            foreach (['VERSION', 'artisan', 'composer.json', 'composer.lock', 'vendor/autoload.php', 'public/build/manifest.json'] as $required) {
                if (! in_array($required, $managed, true)) {
                    throw new ApplicationUpdateException(
                        "Manifest release terpasang tidak lengkap: {$required} tidak terdaftar.",
                    );
                }
            }

            $managed[] = 'release-manifest.json';

            return array_values(array_unique($managed));
        } catch (ApplicationUpdateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ApplicationUpdateException(
                'Manifest release terpasang tidak dapat diverifikasi.',
                0,
                $exception,
            );
        }
    }

    /** @param array{exists: bool, sha256?: string, size?: int} $expectedTargetState */
    private function atomicReplace(
        string $source,
        string $target,
        string $operationId,
        string $expectedSourceDigest,
        array $expectedTargetState,
    ): void {
        $directory = dirname($target);
        $this->makeTargetDirectory($directory);

        if (is_dir($target) || is_link($target)) {
            throw new ApplicationUpdateException('Target update bertabrakan dengan direktori atau symbolic link.');
        }

        $suffix = substr(hash('sha256', $operationId."\0".$target), 0, 20);
        $temporary = $directory.DIRECTORY_SEPARATOR.'.siem-update-'.$suffix.'.tmp';
        $old = $directory.DIRECTORY_SEPARATOR.'.siem-update-'.$suffix.'.old';
        if (file_exists($temporary) || file_exists($old) || is_link($temporary) || is_link($old)) {
            throw new ApplicationUpdateException('File sementara update sudah ada; pemeriksaan manual diperlukan.');
        }

        if (! @copy($source, $temporary)) {
            throw new ApplicationUpdateException('File staging tidak dapat disalin ke target update.');
        }
        $sourcePermissions = fileperms($source);
        if (is_int($sourcePermissions)) {
            @chmod($temporary, $sourcePermissions & 0777);
        }

        $sourceDigest = hash_file('sha256', $source);
        $temporaryDigest = hash_file('sha256', $temporary);
        if (! is_string($sourceDigest)
            || ! is_string($temporaryDigest)
            || ! hash_equals($expectedSourceDigest, $sourceDigest)
            || ! hash_equals($sourceDigest, $temporaryDigest)) {
            @unlink($temporary);
            throw new ApplicationUpdateException('Verifikasi file sementara update gagal.');
        }

        $applicationRoot = $this->applicationRoot();
        $relativePath = str_replace('\\', '/', substr($target, strlen($applicationRoot) + 1));
        $this->assertTargetMatchesState($applicationRoot, $relativePath, $expectedTargetState);

        $hadTarget = is_file($target);
        if ($hadTarget && ! @rename($target, $old)) {
            @unlink($temporary);
            throw new ApplicationUpdateException('File aplikasi lama tidak dapat dipindahkan untuk update.');
        }

        if (! @rename($temporary, $target)) {
            if ($hadTarget) {
                @rename($old, $target);
            }
            @unlink($temporary);
            throw new ApplicationUpdateException('File update tidak dapat dipasang secara atomik.');
        }

        if ($hadTarget && ! @unlink($old) && is_file($old)) {
            throw new ApplicationUpdateException('File sementara lama tidak dapat dibersihkan.');
        }
    }

    private function assertSafeTarget(string $root, string $relativePath): void
    {
        $segments = explode('/', $this->validatedRelativePath($relativePath));
        $cursor = $root;
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new ApplicationUpdateException("Target update melewati symbolic link: {$relativePath}.");
            }
            if ($index < $lastIndex && file_exists($cursor) && ! is_dir($cursor)) {
                throw new ApplicationUpdateException(
                    "Parent target update bukan direktori: {$relativePath}.",
                );
            }
        }
    }

    private function validatedRelativePath(string $path): string
    {
        $normalised = $this->normalisedRelativePath($path);
        if ($this->isProtectedRuntimePath($normalised)) {
            throw new ApplicationUpdateException("Release mencoba mengubah path runtime terlarang: {$normalised}.");
        }

        return $normalised;
    }

    private function normalisedRelativePath(string $path): string
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, ':')
            || str_starts_with($path, '/')
            || str_ends_with($path, '/')) {
            throw new ApplicationUpdateException('Path update relatif tidak valid.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (! $this->isPortableSegment($segment)) {
                throw new ApplicationUpdateException('Path update mengandung traversal.');
            }
        }

        return implode('/', $segments);
    }

    private function isProtectedRuntimePath(string $path): bool
    {
        $lower = strtolower($path);
        if (str_starts_with($lower, '.env') && $lower !== '.env.example') {
            return true;
        }
        if (in_array($lower, ['auth.json', '.npmrc'], true)) {
            return true;
        }
        foreach (['.git', 'storage', 'bootstrap/cache', 'public/storage', 'public/hot'] as $protected) {
            if ($lower === $protected || str_starts_with($lower, $protected.'/')) {
                return true;
            }
        }

        return preg_match(
            '#^database/(?:[^/]+/)*[^/]*(?:\.sqlite[^/]*|\.db(?:-[^/]*)?)$#iD',
            $path,
        ) === 1;
    }

    private function assertInstalledVersion(string $applicationRoot, string $expectedVersion): void
    {
        if (preg_match(
            '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D',
            $expectedVersion,
        ) !== 1) {
            throw new ApplicationUpdateException('Versi awal pada rencana update tidak valid.');
        }

        $versionPath = $applicationRoot.DIRECTORY_SEPARATOR.'VERSION';
        $currentVersion = is_file($versionPath) && ! is_link($versionPath)
            ? trim((string) file_get_contents($versionPath))
            : '';
        if (! hash_equals($expectedVersion, $currentVersion)) {
            throw new ApplicationUpdateException(
                'VERSION aplikasi berubah setelah pemeriksaan awal; update dibatalkan.',
            );
        }
    }

    /**
     * @param  list<string>  $deployFiles
     * @param  list<string>  $staleFiles
     * @param  array<string, array{exists: bool, sha256?: string, size?: int}>  $releaseFiles
     * @param  array<string, array{exists: bool, sha256?: string, size?: int}>  $targetState
     */
    private function assertPlanCoverage(
        array $deployFiles,
        array $staleFiles,
        array $releaseFiles,
        array $targetState,
    ): void {
        $deployFiles = array_map($this->validatedRelativePath(...), $deployFiles);
        $staleFiles = array_map($this->validatedRelativePath(...), $staleFiles);
        if (count($deployFiles) !== count(array_unique($deployFiles))
            || count($staleFiles) !== count(array_unique($staleFiles))
            || array_intersect($deployFiles, $staleFiles) !== []) {
            throw new ApplicationUpdateException('Rencana update berisi path duplikat atau bertentangan.');
        }

        $expectedRelease = $deployFiles;
        $expectedTargets = array_values(array_unique([...$deployFiles, ...$staleFiles]));
        $actualRelease = array_keys($releaseFiles);
        $actualTargets = array_keys($targetState);
        sort($expectedRelease);
        sort($expectedTargets);
        sort($actualRelease);
        sort($actualTargets);
        if ($expectedRelease !== $actualRelease || $expectedTargets !== $actualTargets) {
            throw new ApplicationUpdateException('Cakupan snapshot file pada rencana update tidak lengkap.');
        }
    }

    /**
     * @return array<string, array{exists: bool, sha256?: string, size?: int}>
     */
    private function fileStateMap(mixed $value, bool $mustExist = false): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new ApplicationUpdateException('Snapshot file pada rencana update tidak valid.');
        }

        $states = [];
        $canonicalPaths = [];
        foreach ($value as $relativePath => $state) {
            if (! is_string($relativePath) || ! is_array($state)) {
                throw new ApplicationUpdateException('Snapshot path pada rencana update tidak valid.');
            }
            $relativePath = $this->validatedRelativePath($relativePath);
            $canonical = strtolower($relativePath);
            if (isset($canonicalPaths[$canonical])) {
                throw new ApplicationUpdateException('Snapshot rencana update memiliki path duplikat atau ambigu.');
            }
            $canonicalPaths[$canonical] = true;

            if (($state['exists'] ?? null) === false && ! $mustExist) {
                $states[$relativePath] = ['exists' => false];

                continue;
            }
            $states[$relativePath] = $this->validatedExistingFileState(
                $state,
                'Metadata snapshot file pada rencana update tidak valid.',
            );
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{exists: true, sha256: string, size: int}
     */
    private function validatedExistingFileState(array $state, string $message): array
    {
        if (($state['exists'] ?? true) !== true
            || ! is_string($state['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $state['sha256']) !== 1
            || ! is_int($state['size'] ?? null)
            || $state['size'] < 0) {
            throw new ApplicationUpdateException($message);
        }

        return [
            'exists' => true,
            'sha256' => $state['sha256'],
            'size' => $state['size'],
        ];
    }

    /** @param array{exists: bool, sha256?: string, size?: int} $state */
    private function assertTargetMatchesState(string $root, string $relativePath, array $state): void
    {
        $this->assertSafeTarget($root, $relativePath);
        $target = $this->targetPath($root, $relativePath);
        if (($state['exists'] ?? null) === false) {
            if (file_exists($target) || is_link($target)) {
                throw new ApplicationUpdateException(
                    "Target berubah setelah backup dibuat: {$relativePath}.",
                );
            }

            return;
        }

        $state = $this->validatedExistingFileState(
            $state,
            'Metadata target pada rencana update tidak valid.',
        );
        $this->assertRegularFileDigest($target, $state, 'target aplikasi', $relativePath);
    }

    /**
     * @param  array{exists: bool, sha256?: string, size?: int}  $state
     */
    private function assertRegularFileDigest(
        string $path,
        array $state,
        string $description,
        string $relativePath,
    ): void {
        $state = $this->validatedExistingFileState(
            $state,
            "Metadata {$description} tidak valid.",
        );
        $size = is_file($path) && ! is_link($path) ? filesize($path) : false;
        $digest = is_int($size) ? hash_file('sha256', $path) : false;
        if (! is_int($size)
            || ! is_string($digest)
            || $size !== $state['size']
            || ! hash_equals($state['sha256'], $digest)) {
            throw new ApplicationUpdateException(
                ucfirst($description)." berubah atau tidak valid: {$relativePath}.",
            );
        }
    }

    /**
     * @param  array{exists: bool, sha256?: string, size?: int}  $originalState
     * @param  array{exists: bool, sha256?: string, size?: int}|null  $releaseState
     * @return array{exists: bool, sha256?: string, size?: int}
     */
    private function assertRollbackTargetAllowed(
        string $root,
        string $relativePath,
        array $originalState,
        ?array $releaseState,
        bool $mayBeDeletedAsStale,
    ): array {
        $target = $this->targetPath($root, $relativePath);
        if (! file_exists($target) && ! is_link($target)) {
            if ($mayBeDeletedAsStale || ($originalState['exists'] ?? null) === false) {
                return ['exists' => false];
            }
            throw new ApplicationUpdateException(
                "Target rollback hilang di luar perubahan updater: {$relativePath}.",
            );
        }
        if (! is_file($target) || is_link($target)) {
            throw new ApplicationUpdateException("Target rollback bukan file reguler: {$relativePath}.");
        }

        $size = filesize($target);
        $digest = is_int($size) ? hash_file('sha256', $target) : false;
        if (! is_int($size) || ! is_string($digest)) {
            throw new ApplicationUpdateException("Target rollback tidak dapat diverifikasi: {$relativePath}.");
        }
        $current = ['exists' => true, 'sha256' => $digest, 'size' => $size];
        foreach ([$originalState, $releaseState] as $allowed) {
            if (is_array($allowed)
                && ($allowed['exists'] ?? null) === true
                && isset($allowed['sha256'], $allowed['size'])
                && $allowed['size'] === $size
                && hash_equals($allowed['sha256'], $digest)) {
                return $current;
            }
        }

        throw new ApplicationUpdateException(
            "Target rollback memiliki perubahan asing dan tidak akan ditimpa: {$relativePath}.",
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ApplicationUpdateException('Rencana file update tidak valid.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new ApplicationUpdateException('Rencana path update tidak valid.');
            }
        }

        return $value;
    }

    private function applicationRoot(): string
    {
        return $this->existingDirectory(
            (string) config('application_updates.application_root', base_path()),
            'Root aplikasi updater tidak valid.',
        );
    }

    private function updateWorkRoot(ApplicationUpdate $update): string
    {
        $root = rtrim((string) config('application_updates.work_directory'), '/\\');
        $uuid = $update->uuid;
        if (! is_string($uuid)
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                $uuid,
            ) !== 1) {
            throw new ApplicationUpdateException('UUID update untuk direktori kerja tidak valid.');
        }
        $path = $root.DIRECTORY_SEPARATOR.$uuid;

        return $this->existingDirectory($path, 'Direktori kerja update tidak ditemukan.');
    }

    private function existingDirectory(string $path, string $message): string
    {
        $real = $path !== '' ? realpath($path) : false;
        if (! is_string($real) || ! is_dir($real)) {
            throw new ApplicationUpdateException($message);
        }

        return rtrim($real, '/\\');
    }

    private function targetPath(string $root, string $relativePath): string
    {
        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function isPortableSegment(string $segment): bool
    {
        if ($segment === ''
            || $segment === '.'
            || $segment === '..'
            || strlen($segment) > 255
            || preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $segment) === 1
            || str_ends_with($segment, ' ')
            || str_ends_with($segment, '.')) {
            return false;
        }

        return preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/iD', $segment) !== 1;
    }

    private function makePrivateDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ApplicationUpdateException('Direktori untuk update tidak dapat dibuat.');
        }

        if (is_link($directory)) {
            throw new ApplicationUpdateException('Direktori update tidak boleh berupa symbolic link.');
        }
    }

    private function makeTargetDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new ApplicationUpdateException('Direktori target update tidak boleh berupa symbolic link.');
        }
        if (is_dir($directory)) {
            return;
        }

        $missing = [];
        $cursor = $directory;
        while (! is_dir($cursor)) {
            if (file_exists($cursor) || is_link($cursor)) {
                throw new ApplicationUpdateException('Path direktori target update tidak valid.');
            }
            $missing[] = $cursor;
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new ApplicationUpdateException('Parent direktori target update tidak ditemukan.');
            }
            $cursor = $parent;
        }
        if (is_link($cursor)) {
            throw new ApplicationUpdateException('Parent direktori target update tidak boleh berupa symbolic link.');
        }

        foreach (array_reverse($missing) as $path) {
            if (! mkdir($path, 0755) && ! is_dir($path)) {
                throw new ApplicationUpdateException('Direktori target aplikasi tidak dapat dibuat.');
            }
            // Target code/public directories must remain traversable by the
            // web server. Private staging and backup paths use 0700 above.
            @chmod($path, 0755);
        }
    }

    private function removeEmptyParents(string $directory, string $root): void
    {
        $root = rtrim($root, '/\\');
        while ($directory !== $root && str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
            if (is_link($directory) || ! is_dir($directory) || ! @rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }
}
