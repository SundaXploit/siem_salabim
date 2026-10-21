<?php

namespace App\Services;

use App\Exceptions\ApplicationUpdateException;

class ApplicationUpdateRuntimeGuard
{
    public function isActive(): bool
    {
        return is_file($this->markerPath());
    }

    public function activate(string $updateUuid): void
    {
        $this->assertValidUuid($updateUuid);
        $directory = dirname($this->markerPath());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ApplicationUpdateException('Direktori marker update tidak dapat dibuat.');
        }

        $handle = @fopen($this->markerPath(), 'x+b');
        if ($handle === false) {
            throw new ApplicationUpdateException(
                'Update lain sedang berjalan atau marker recovery masih aktif.',
            );
        }

        try {
            if (! flock($handle, LOCK_EX)
                || fwrite($handle, $updateUuid.PHP_EOL) !== strlen($updateUuid.PHP_EOL)
                || ! fflush($handle)) {
                throw new ApplicationUpdateException('Marker update tidak dapat ditulis dengan aman.');
            }

            @chmod($this->markerPath(), 0600);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($this->markerPath());

            throw $exception;
        }

        fclose($handle);
    }

    public function deactivate(string $updateUuid): void
    {
        $this->assertValidUuid($updateUuid);
        $path = $this->markerPath();
        if (! is_file($path)) {
            return;
        }

        $owner = trim((string) @file_get_contents($path));
        if ($owner === '' || ! hash_equals($owner, $updateUuid)) {
            throw new ApplicationUpdateException('Marker update dimiliki proses lain dan tidak dihapus.');
        }

        if (! @unlink($path) && is_file($path)) {
            throw new ApplicationUpdateException('Marker update tidak dapat dihapus.');
        }
    }

    public function markerPath(): string
    {
        return (string) config(
            'application_updates.runtime_marker',
            storage_path('framework/application-update.lock'),
        );
    }

    private function assertValidUuid(string $updateUuid): void
    {
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
            $updateUuid,
        ) !== 1) {
            throw new ApplicationUpdateException('UUID marker update tidak valid.');
        }
    }
}
