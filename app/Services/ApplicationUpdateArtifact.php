<?php

namespace App\Services;

use App\Exceptions\ApplicationUpdateException;
use App\Models\ApplicationUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class ApplicationUpdateArtifact
{
    private const REQUIRED_FILES = [
        'VERSION',
        'artisan',
        'composer.json',
        'composer.lock',
        'release-manifest.json',
        'vendor/autoload.php',
        'public/build/manifest.json',
    ];

    /** @return array{package_path: string, staging_path: string, release_root: string, manifest: array<string, mixed>} */
    public function prepare(ApplicationUpdate $update): array
    {
        $this->assertUpdateMetadata($update);

        if (! extension_loaded('zip')) {
            throw new ApplicationUpdateException('Ekstensi PHP zip diperlukan untuk menerapkan update.');
        }

        $workingDirectory = $this->workingDirectory($update);
        $packagePath = $workingDirectory.DIRECTORY_SEPARATOR.$update->asset_name;
        $stagingPath = $workingDirectory.DIRECTORY_SEPARATOR.'staging';
        $this->ensureEmptyDirectory($workingDirectory);
        if (! mkdir($stagingPath, 0700, true) && ! is_dir($stagingPath)) {
            throw new ApplicationUpdateException('Direktori staging update tidak dapat dibuat.');
        }

        $this->download($update, $packagePath);
        $this->verifyPackage($update, $packagePath);
        $releaseRoot = $this->extractSafely($packagePath, $stagingPath);
        $manifest = $this->validateRelease($update, $releaseRoot);

        return [
            'package_path' => $packagePath,
            'staging_path' => $stagingPath,
            'release_root' => $releaseRoot,
            'manifest' => $manifest,
        ];
    }

    private function download(ApplicationUpdate $update, string $packagePath): void
    {
        $this->assertAssetUrl($update);
        $limit = min(
            $update->asset_size,
            (int) config('application_updates.max_asset_bytes', 314_572_800),
        );

        try {
            $response = Http::accept('application/octet-stream')
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'SIEM-Salabim-Updater/'.$update->from_version,
                ])
                ->connectTimeout(10)
                ->timeout(300)
                ->withOptions([
                    'on_headers' => static function (ResponseInterface $response) use ($limit): void {
                        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                            return;
                        }

                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== ''
                            && ctype_digit($length)
                            && (strlen($length) > strlen((string) $limit)
                                || (strlen($length) === strlen((string) $limit)
                                    && strcmp($length, (string) $limit) > 0))) {
                            throw new ApplicationUpdateException(
                                'Server mencoba mengirim paket update melebihi ukuran yang diizinkan.',
                            );
                        }
                    },
                    'progress' => static function (
                        mixed $downloadTotal,
                        mixed $downloadedBytes,
                    ) use ($limit): void {
                        if ((is_numeric($downloadTotal) && (float) $downloadTotal > $limit)
                            || (is_numeric($downloadedBytes) && (float) $downloadedBytes > $limit)) {
                            throw new ApplicationUpdateException(
                                'Download paket update dihentikan karena melewati batas ukuran.',
                            );
                        }
                    },
                ])
                ->sink($packagePath)
                ->get($update->asset_api_url);
        } catch (ConnectionException) {
            @unlink($packagePath);
            throw new ApplicationUpdateException('Paket update tidak dapat diunduh dari GitHub.');
        } catch (ApplicationUpdateException $exception) {
            @unlink($packagePath);

            throw $exception;
        } catch (Throwable $exception) {
            @unlink($packagePath);
            throw new ApplicationUpdateException('Download paket update gagal.', 0, $exception);
        }

        if (! $response->successful()) {
            @unlink($packagePath);
            throw new ApplicationUpdateException(
                "GitHub mengembalikan HTTP {$response->status()} saat mengunduh paket update.",
            );
        }
    }

    private function verifyPackage(ApplicationUpdate $update, string $packagePath): void
    {
        clearstatcache(true, $packagePath);
        $size = is_file($packagePath) ? filesize($packagePath) : false;
        if ($size === false || $size !== $update->asset_size) {
            @unlink($packagePath);
            throw new ApplicationUpdateException('Ukuran paket update tidak sesuai metadata GitHub Release.');
        }

        $maximum = (int) config('application_updates.max_asset_bytes', 314_572_800);
        if ($size < 1 || $size > $maximum) {
            @unlink($packagePath);
            throw new ApplicationUpdateException('Ukuran paket update berada di luar batas yang diizinkan.');
        }

        $digest = hash_file('sha256', $packagePath);
        if (! is_string($digest) || ! hash_equals($update->asset_sha256, $digest)) {
            @unlink($packagePath);
            throw new ApplicationUpdateException('Digest SHA-256 paket update tidak cocok.');
        }
    }

    private function extractSafely(string $packagePath, string $stagingPath): string
    {
        $archive = new ZipArchive;
        if ($archive->open($packagePath) !== true) {
            throw new ApplicationUpdateException('Paket update bukan arsip ZIP yang valid.');
        }

        $root = null;
        $totalBytes = 0;
        $seenPaths = [];
        $entries = [];
        $maximumExpanded = (int) config('application_updates.max_asset_bytes', 314_572_800) * 3;

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > 100_000) {
                throw new ApplicationUpdateException('Jumlah file dalam paket update tidak valid.');
            }

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
                $name = is_array($stat) && is_string($stat['name'] ?? null)
                    ? str_replace('\\', '/', $stat['name'])
                    : '';
                $segments = $this->safeSegments($name);
                if ($segments === []) {
                    continue;
                }

                $root ??= $segments[0];
                if ($segments[0] !== $root || $root !== 'siem-salabim') {
                    throw new ApplicationUpdateException('Struktur root paket update tidak valid.');
                }

                // Reject names which collide on the case-insensitive filesystems
                // commonly used by Windows deployments. A release must behave
                // identically when it is verified on Linux and installed there.
                $canonicalPath = strtolower(implode('/', $segments));
                if (isset($seenPaths[$canonicalPath])) {
                    throw new ApplicationUpdateException('Paket update memuat path duplikat atau ambigu.');
                }
                $seenPaths[$canonicalPath] = true;

                $isDirectory = str_ends_with($name, '/');
                $unixMode = $this->validatedUnixMode($archive, $index, $isDirectory);
                if (! is_int($stat['size'] ?? null) || $stat['size'] < 0) {
                    throw new ApplicationUpdateException('Metadata ukuran file paket update tidak valid.');
                }
                $size = $stat['size'];
                $totalBytes += $size;
                if ($totalBytes > $maximumExpanded) {
                    throw new ApplicationUpdateException('Ukuran hasil ekstraksi paket update melebihi batas aman.');
                }

                $entries[] = [
                    'archive_name' => $stat['name'],
                    'segments' => $segments,
                    'directory' => $isDirectory,
                    'size' => $size,
                    'mode' => $unixMode,
                ];
            }

            $freeBytes = @disk_free_space($stagingPath);
            if (! is_float($freeBytes) && ! is_int($freeBytes)) {
                throw new ApplicationUpdateException(
                    'Ruang disk untuk ekstraksi paket update tidak dapat diverifikasi.',
                );
            }
            if ($freeBytes < $totalBytes + 10_485_760) {
                throw new ApplicationUpdateException(
                    'Ruang disk tidak cukup untuk mengekstrak paket update dengan aman.',
                );
            }

            foreach ($entries as $entry) {
                $segments = $entry['segments'];
                $isDirectory = $entry['directory'];
                $size = $entry['size'];
                $unixMode = $entry['mode'];
                $destination = $stagingPath.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
                if ($isDirectory) {
                    $this->makeDirectory($destination);
                    if ($unixMode !== null) {
                        @chmod($destination, $unixMode);
                    }

                    continue;
                }

                $this->makeDirectory(dirname($destination));
                $input = $archive->getStream($entry['archive_name']);
                $output = fopen($destination, 'xb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new ApplicationUpdateException('File paket update tidak dapat diekstrak dengan aman.');
                }

                try {
                    // The central directory size is an integrity boundary for
                    // zip-bomb protection. Never allow the stream to write more
                    // than that declared size plus one detection byte.
                    $copied = stream_copy_to_stream($input, $output, $size + 1);
                    if ($copied === false || $copied !== $size) {
                        throw new ApplicationUpdateException('Ekstraksi paket update tidak lengkap.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($unixMode !== null) {
                    @chmod($destination, $unixMode);
                }
            }
        } finally {
            $archive->close();
        }

        if ($root === null) {
            throw new ApplicationUpdateException('Paket update tidak berisi release aplikasi.');
        }

        return $stagingPath.DIRECTORY_SEPARATOR.$root;
    }

    /** @return array<string, mixed> */
    private function validateRelease(ApplicationUpdate $update, string $releaseRoot): array
    {
        foreach (self::REQUIRED_FILES as $required) {
            if (! is_file($releaseRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $required))) {
                throw new ApplicationUpdateException("Paket update tidak lengkap: {$required} tidak ditemukan.");
            }
        }

        foreach (['.env', '.git', 'storage', 'database/database.sqlite'] as $protected) {
            $path = $releaseRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $protected);
            if (file_exists($path) || is_link($path)) {
                throw new ApplicationUpdateException("Paket update memuat path runtime terlarang: {$protected}.");
            }
        }

        $version = trim((string) file_get_contents($releaseRoot.DIRECTORY_SEPARATOR.'VERSION'));
        if (! hash_equals($update->target_version, $version)) {
            throw new ApplicationUpdateException('VERSION di dalam paket tidak sesuai target update.');
        }

        try {
            $manifest = json_decode(
                (string) file_get_contents($releaseRoot.DIRECTORY_SEPARATOR.'release-manifest.json'),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $exception) {
            throw new ApplicationUpdateException('Manifest internal paket update tidak valid.', 0, $exception);
        }

        if (! is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'siem-salabim-release/v1'
            || ($manifest['repository'] ?? null) !== config('application_updates.repository')
            || ($manifest['version'] ?? null) !== $update->target_version
            || ($manifest['tag'] ?? null) !== $update->release_tag
            || ! is_string($manifest['commit'] ?? null)
            || preg_match('/^[a-f0-9]{40}$/D', $manifest['commit']) !== 1) {
            throw new ApplicationUpdateException('Identitas manifest paket update tidak cocok.');
        }

        $minimumPhp = $this->validatedMinimumPhp($manifest['minimum_php'] ?? null);
        if (version_compare(PHP_VERSION, $minimumPhp, '<')) {
            throw new ApplicationUpdateException("Update memerlukan PHP {$minimumPhp} atau lebih baru.");
        }

        $extensions = $manifest['required_extensions'] ?? [];
        if (! is_array($extensions) || ! array_is_list($extensions)) {
            throw new ApplicationUpdateException('Daftar ekstensi pada manifest update tidak valid.');
        }
        foreach ($extensions as $extension) {
            if (! is_string($extension)
                || preg_match('/^[A-Za-z0-9_-]+$/D', $extension) !== 1
                || ! extension_loaded($extension)) {
                throw new ApplicationUpdateException(
                    is_string($extension)
                        ? "Ekstensi PHP {$extension} diperlukan oleh update."
                        : 'Manifest ekstensi PHP tidak valid.',
                );
            }
        }

        $this->validateFileManifest($manifest, $releaseRoot);

        return $manifest;
    }

    private function validatedMinimumPhp(mixed $minimumPhp): string
    {
        if (! is_string($minimumPhp)
            || strlen($minimumPhp) > 32
            || preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D', $minimumPhp) !== 1) {
            throw new ApplicationUpdateException('Versi minimum PHP pada manifest update tidak valid.');
        }

        return $minimumPhp;
    }

    /** @param array<string, mixed> $manifest */
    private function validateFileManifest(array $manifest, string $releaseRoot): void
    {
        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === [] || array_is_list($files) || count($files) > 100_000) {
            throw new ApplicationUpdateException('Manifest daftar file release tidak valid.');
        }

        $declared = [];
        foreach ($files as $relativePath => $expectedDigest) {
            if (! is_string($relativePath)
                || ! is_string($expectedDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1) {
                throw new ApplicationUpdateException('Manifest digest file release tidak valid.');
            }

            $relativePath = $this->validatedRelativePath($relativePath);
            if ($relativePath === 'release-manifest.json' || isset($declared[$relativePath])) {
                throw new ApplicationUpdateException('Manifest file release berisi path duplikat atau terlarang.');
            }

            $absolutePath = $releaseRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (! is_file($absolutePath) || is_link($absolutePath)) {
                throw new ApplicationUpdateException("File release tidak ditemukan: {$relativePath}.");
            }

            $actualDigest = hash_file('sha256', $absolutePath);
            if (! is_string($actualDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
                throw new ApplicationUpdateException("Digest file release tidak cocok: {$relativePath}.");
            }

            $declared[$relativePath] = true;
        }

        $actual = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($releaseRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($releaseRoot) + 1));
            if ($relativePath !== 'release-manifest.json') {
                $actual[$relativePath] = true;
            }
        }

        ksort($declared);
        ksort($actual);
        if (array_keys($declared) !== array_keys($actual)) {
            throw new ApplicationUpdateException('Daftar file release tidak sama dengan isi paket update.');
        }
    }

    private function validatedRelativePath(string $path): string
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, ':')
            || str_starts_with($path, '/')
            || str_ends_with($path, '/')) {
            throw new ApplicationUpdateException('Manifest release mengandung path yang tidak valid.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (! $this->isPortableSegment($segment)) {
                throw new ApplicationUpdateException('Manifest release mengandung traversal path.');
            }
        }

        $normalised = implode('/', $segments);
        if ($this->isProtectedRuntimePath($normalised)) {
            throw new ApplicationUpdateException(
                "Manifest release mencoba mengubah path runtime terlarang: {$normalised}.",
            );
        }

        return $normalised;
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

    /** @return list<string> */
    private function safeSegments(string $name): array
    {
        if ($name === ''
            || str_contains($name, "\0")
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:\//', $name) === 1) {
            throw new ApplicationUpdateException('Paket update mengandung path absolut atau tidak valid.');
        }

        // Remove exactly one directory marker. Using trim() would silently
        // canonicalize malformed names such as "dir//" into a valid path.
        $path = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (! $this->isPortableSegment($segment)) {
                throw new ApplicationUpdateException(
                    'Paket update mengandung traversal atau nama path yang tidak portabel.',
                );
            }
        }

        return $segments;
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

        // Windows treats these names as devices even when an extension is
        // present (for example NUL.txt), which can redirect extraction away
        // from the intended regular file.
        return preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/iD', $segment) !== 1;
    }

    private function validatedUnixMode(ZipArchive $archive, int $index, bool $directory): ?int
    {
        $operationsSystem = 0;
        $attributes = 0;
        if (! $archive->getExternalAttributesIndex($index, $operationsSystem, $attributes)
            || $operationsSystem !== ZipArchive::OPSYS_UNIX) {
            return null;
        }

        $mode = ($attributes >> 16) & 0xFFFF;
        $type = $mode & 0xF000;
        $expectedType = $directory ? 0x4000 : 0x8000;
        if ($type !== 0 && $type !== $expectedType) {
            throw new ApplicationUpdateException(
                'Paket update hanya boleh memuat file reguler dan direktori.',
            );
        }

        // Normalize instead of propagating writable or privileged bits from
        // the archive. Application files must remain readable by the web
        // runtime; executable files keep only the ordinary execute intent.
        return $directory
            ? 0755
            : (($mode & 0111) !== 0 ? 0755 : 0644);
    }

    private function assertAssetUrl(ApplicationUpdate $update): void
    {
        $repository = (string) config('application_updates.repository');
        $expected = "https://api.github.com/repos/{$repository}/releases/assets/{$update->asset_id}";
        if (! hash_equals($expected, $update->asset_api_url)) {
            throw new ApplicationUpdateException('URL asset update tidak sesuai repository resmi.');
        }
    }

    private function assertUpdateMetadata(ApplicationUpdate $update): void
    {
        $uuid = $update->uuid;
        $fromVersion = $update->from_version;
        $targetVersion = $update->target_version;
        $assetId = $update->asset_id;
        $releaseId = $update->release_id;
        $assetSize = $update->asset_size;
        $assetDigest = $update->asset_sha256;

        if (! is_string($uuid)
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                $uuid,
            ) !== 1) {
            throw new ApplicationUpdateException('UUID update tidak valid.');
        }

        foreach ([$fromVersion, $targetVersion] as $version) {
            if (! is_string($version)
                || strlen($version) > 63
                || preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D', $version) !== 1) {
                throw new ApplicationUpdateException('Metadata versi update tidak valid.');
            }
        }

        if (! is_int($releaseId) || $releaseId < 1 || ! is_int($assetId) || $assetId < 1) {
            throw new ApplicationUpdateException('ID release atau asset update tidak valid.');
        }

        $expectedTag = 'v'.$targetVersion;
        $expectedName = 'siem-salabim-'.$expectedTag.'.zip';
        if (! is_string($update->release_tag)
            || ! hash_equals($expectedTag, $update->release_tag)
            || ! is_string($update->asset_name)
            || ! hash_equals($expectedName, $update->asset_name)) {
            throw new ApplicationUpdateException('Nama asset atau tag update tidak sesuai versi target.');
        }

        $maximum = (int) config('application_updates.max_asset_bytes', 314_572_800);
        if (! is_int($assetSize) || $assetSize < 1 || $assetSize > $maximum) {
            throw new ApplicationUpdateException('Ukuran asset update tidak valid.');
        }
        if (! is_string($assetDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $assetDigest) !== 1) {
            throw new ApplicationUpdateException('Digest asset update tidak valid.');
        }

        if (! is_string($update->asset_api_url)) {
            throw new ApplicationUpdateException('URL asset update tidak valid.');
        }
        $this->assertAssetUrl($update);
    }

    private function workingDirectory(ApplicationUpdate $update): string
    {
        $root = rtrim((string) config('application_updates.work_directory'), '/\\');
        if ($root === '' || str_contains($root, "\0")) {
            throw new ApplicationUpdateException('Direktori kerja updater tidak valid.');
        }

        return $root.DIRECTORY_SEPARATOR.$update->uuid;
    }

    private function ensureEmptyDirectory(string $directory): void
    {
        if (file_exists($directory) || is_link($directory)) {
            throw new ApplicationUpdateException(
                'Direktori kerja update sudah ada. Tinjau status percobaan sebelumnya sebelum mengulang.',
            );
        }

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ApplicationUpdateException('Direktori kerja update tidak dapat dibuat.');
        }
    }

    private function makeDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Direktori ekstraksi update tidak dapat dibuat.');
        }
    }
}
