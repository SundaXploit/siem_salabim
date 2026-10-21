<?php

namespace App\Services\Installation;

use Closure;
use RuntimeException;
use Throwable;

final class InstallationState
{
    private readonly string $markerPath;

    private readonly string $tokenPath;

    private readonly int $tokenTtlSeconds;

    private readonly bool $detectLegacyAdmin;

    private readonly ?Closure $legacyAdminDetector;

    public function __construct(
        private readonly ?EnvironmentFileWriter $environment = null,
        ?string $markerPath = null,
        ?string $tokenPath = null,
        ?int $tokenTtlSeconds = null,
        ?bool $detectLegacyAdmin = null,
        ?Closure $legacyAdminDetector = null,
    ) {
        $basePath = $environment !== null
            ? dirname($environment->path())
            : (function_exists('base_path') ? base_path() : dirname(__DIR__, 3));

        $this->markerPath = $markerPath ?? $this->configuration(
            'installer.marker_path',
            $basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'installation'.DIRECTORY_SEPARATOR.'installed.json',
        );
        $this->tokenPath = $tokenPath ?? $this->configuration(
            'installer.token_path',
            $basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'installation'.DIRECTORY_SEPARATOR.'token.json',
        );
        $this->tokenTtlSeconds = max(300, $tokenTtlSeconds ?? (int) $this->configuration('installer.token_ttl_seconds', 3600));
        $this->detectLegacyAdmin = $detectLegacyAdmin ?? (bool) $this->configuration('installer.detect_legacy_admin', true);
        $this->legacyAdminDetector = $legacyAdminDetector;
    }

    public function isInstalled(): bool
    {
        if ($this->environmentFlagIsTrue()) {
            return true;
        }

        if (is_file($this->markerPath)) {
            return true;
        }

        return $this->detectLegacyAdmin && $this->legacyDatabaseHasAdmin();
    }

    public function installationRequired(): bool
    {
        return ! $this->isInstalled();
    }

    public function issueInstallerToken(?int $ttlSeconds = null): string
    {
        $ttlSeconds = max(300, $ttlSeconds ?? $this->tokenTtlSeconds);
        $token = $this->base64UrlEncode(random_bytes(32));
        $now = time();

        $payload = json_encode([
            'version' => 1,
            'digest' => hash('sha256', $token),
            'created_at' => gmdate(DATE_ATOM, $now),
            'expires_at' => gmdate(DATE_ATOM, $now + $ttlSeconds),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        $this->withExclusiveLock($this->tokenPath.'.lock', function () use ($payload): void {
            $this->atomicPrivateWrite($this->tokenPath, $payload);
        });

        return $token;
    }

    public function verifyToken(string $token): bool
    {
        if ($token === '' || strlen($token) > 512 || ! is_file($this->tokenPath)) {
            return false;
        }

        try {
            return $this->tokenMatchesFile($token);
        } catch (Throwable) {
            return false;
        }
    }

    public function verifyInstallerToken(string $token): bool
    {
        return $this->verifyToken($token);
    }

    public function removeToken(): void
    {
        $this->withExclusiveLock($this->tokenPath.'.lock', function (): void {
            if (is_file($this->tokenPath) && ! @unlink($this->tokenPath)) {
                throw new RuntimeException('Token installer tidak dapat dihapus.');
            }
        });
    }

    /**
     * Verify and remove a token while holding the same exclusive lock.
     *
     * Keeping these operations atomic prevents two concurrent unlock requests
     * from turning one bootstrap token into two valid installer sessions.
     */
    public function consumeInstallerToken(string $token): bool
    {
        if ($token === '' || strlen($token) > 512) {
            return false;
        }

        try {
            return $this->withExclusiveLock($this->tokenPath.'.lock', function () use ($token): bool {
                if (! $this->tokenMatchesFile($token)) {
                    return false;
                }

                if (! @unlink($this->tokenPath)) {
                    throw new RuntimeException('Token installer tidak dapat dihapus.');
                }

                return true;
            });
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Mark an installation only after migrations, configuration, and the
     * initial administrator have all succeeded. The marker is written last.
     *
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public function markInstalled(array $metadata = []): void
    {
        $environment = $this->environment ?? new EnvironmentFileWriter;
        $safeMetadata = [];

        foreach ($metadata as $name => $value) {
            if (preg_match('/password|passwd|token|secret|credential|api.?key/i', $name) === 1) {
                continue;
            }

            $safeMetadata[$name] = $value;
        }

        $payload = json_encode([
            'version' => 1,
            'installation_id' => bin2hex(random_bytes(16)),
            'installed_at' => gmdate(DATE_ATOM),
            'metadata' => $safeMetadata,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        $environment->update(['APP_INSTALLED' => true]);

        try {
            $this->withExclusiveLock($this->markerPath.'.lock', function () use ($payload): void {
                $this->atomicPrivateWrite($this->markerPath, $payload);
            });
        } catch (Throwable $exception) {
            try {
                $environment->update(['APP_INSTALLED' => false]);
            } catch (Throwable) {
                // Preserve the marker failure. A subsequent request still has
                // legacy-admin detection as a fail-closed installation guard.
            }

            throw $exception;
        }
    }

    private function environmentFlagIsTrue(): bool
    {
        if (filter_var($this->configuration('installer.installed', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $environment = $this->environment ?? new EnvironmentFileWriter;
        $value = $environment->value('APP_INSTALLED');

        return $value !== null
            && filter_var(trim($value, " \t\n\r\0\x0B()"), FILTER_VALIDATE_BOOL);
    }

    private function legacyDatabaseHasAdmin(): bool
    {
        try {
            if ($this->legacyAdminDetector !== null) {
                return (bool) ($this->legacyAdminDetector)();
            }

            if (! function_exists('app') || ! app()->bound('db')) {
                return false;
            }

            $connection = app('db')->connection();
            $schema = $connection->getSchemaBuilder();

            if (! $schema->hasTable('users') || ! $schema->hasColumn('users', 'role')) {
                return false;
            }

            return $connection->table('users')->where('role', 'admin')->exists();
        } catch (Throwable) {
            // A missing/unreachable database means installation is still
            // undecided; it must never turn the installer route into a 500.
            return false;
        }
    }

    private function configuration(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config($key, $default) : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function tokenMatchesFile(string $token): bool
    {
        if (! is_file($this->tokenPath)) {
            return false;
        }

        $contents = @file_get_contents($this->tokenPath);

        if (! is_string($contents)) {
            return false;
        }

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)
            || ! is_string($payload['digest'] ?? null)
            || ! is_string($payload['expires_at'] ?? null)
        ) {
            return false;
        }

        $expiresAt = strtotime($payload['expires_at']);

        return $expiresAt !== false
            && $expiresAt >= time()
            && hash_equals($payload['digest'], hash('sha256', $token));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withExclusiveLock(string $lockPath, callable $callback): mixed
    {
        $this->ensurePrivateDirectory(dirname($lockPath));
        $handle = @fopen($lockPath, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Kunci status installer tidak dapat dibuat.');
        }

        @chmod($lockPath, 0600);

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Kunci status installer tidak dapat diperoleh.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function atomicPrivateWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        $this->ensurePrivateDirectory($directory);
        $temporaryPath = tempnam($directory, '.siem-install-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Berkas sementara status installer tidak dapat dibuat.');
        }

        try {
            $handle = @fopen($temporaryPath, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Berkas sementara status installer tidak dapat dibuka.');
            }

            try {
                $remaining = $contents;

                while ($remaining !== '') {
                    $written = fwrite($handle, $remaining);

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Status installer tidak dapat ditulis secara lengkap.');
                    }

                    $remaining = substr($remaining, $written);
                }

                if (! fflush($handle)) {
                    throw new RuntimeException('Status installer tidak dapat disimpan ke disk.');
                }

                if (function_exists('fsync')) {
                    fsync($handle);
                }
            } finally {
                fclose($handle);
            }

            @chmod($temporaryPath, 0600);

            if (! @rename($temporaryPath, $path)) {
                throw new RuntimeException('Penggantian status installer secara atomik gagal.');
            }

            @chmod($path, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Direktori privat installer tidak dapat dibuat.');
        }

        @chmod($directory, 0700);
    }
}
