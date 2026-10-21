<?php

namespace App\Services\Installation;

use RuntimeException;

final class EnvironmentFileWriter
{
    private readonly string $environmentPath;

    private readonly string $examplePath;

    private readonly string $lockPath;

    public function __construct(?string $basePath = null)
    {
        $basePath ??= function_exists('base_path')
            ? base_path()
            : dirname(__DIR__, 3);

        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR.'/\\');

        $this->environmentPath = $basePath.DIRECTORY_SEPARATOR.'.env';
        $this->examplePath = $basePath.DIRECTORY_SEPARATOR.'.env.example';
        $this->lockPath = $basePath.DIRECTORY_SEPARATOR.'storage'
            .DIRECTORY_SEPARATOR.'framework'
            .DIRECTORY_SEPARATOR.'siem-environment.lock';
    }

    /**
     * Prepare a bootable environment and apply installer values in one lock.
     *
     * APP_KEY is generated exactly once. General installer input is never
     * allowed to replace a non-empty key because doing so would make existing
     * encrypted configuration unreadable.
     *
     * @param  array<string, bool|float|int|string|null>  $values
     * @return array{environment_created: bool, key_created: bool, changed: bool, app_key: string}
     */
    public function prepare(array $values = []): array
    {
        return $this->withExclusiveLock(function () use ($values): array {
            $environmentCreated = ! is_file($this->environmentPath);
            $contents = $this->environmentContents();
            $existingKey = $this->valueFromContents($contents, 'APP_KEY');
            $keyCreated = $existingKey === null || $existingKey === '';
            $requestedKey = null;

            if (array_key_exists('APP_KEY', $values)) {
                $requestedKey = (string) $values['APP_KEY'];

                if (! $keyCreated && ! hash_equals($existingKey, $requestedKey)) {
                    throw new RuntimeException('APP_KEY yang sudah ada tidak boleh diganti oleh installer.');
                }

                unset($values['APP_KEY']);
            }

            if ($keyCreated) {
                $existingKey = $requestedKey !== null && $requestedKey !== ''
                    ? $requestedKey
                    : 'base64:'.base64_encode(random_bytes(32));
                $values = ['APP_KEY' => $existingKey, ...$values];
            }

            $updated = $this->replaceValues($contents, $values);
            $changed = $environmentCreated || ! hash_equals($contents, $updated);

            if ($changed) {
                $this->atomicWrite($this->environmentPath, $updated);
            }

            return [
                'environment_created' => $environmentCreated,
                'key_created' => $keyCreated,
                'changed' => $changed,
                'app_key' => $existingKey,
            ];
        });
    }

    /**
     * @return array{environment_created: bool, key_created: bool, changed: bool, app_key: string}
     */
    public function ensureEnvironmentAndKey(): array
    {
        return $this->prepare();
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $values
     */
    public function update(array $values): void
    {
        $this->prepare($values);
    }

    public function value(string $name): ?string
    {
        $this->assertValidName($name);

        if (! is_file($this->environmentPath)) {
            return null;
        }

        $contents = @file_get_contents($this->environmentPath);

        if (! is_string($contents)) {
            return null;
        }

        return $this->valueFromContents($contents, $name);
    }

    public function path(): string
    {
        return $this->environmentPath;
    }

    private function environmentContents(): string
    {
        if (is_file($this->environmentPath)) {
            $contents = @file_get_contents($this->environmentPath);

            if (! is_string($contents)) {
                throw new RuntimeException('Berkas .env tidak dapat dibaca.');
            }

            return $contents;
        }

        $contents = @file_get_contents($this->examplePath);

        if (! is_string($contents)) {
            throw new RuntimeException('Berkas .env.example diperlukan untuk menyiapkan instalasi.');
        }

        return $contents;
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $values
     */
    private function replaceValues(string $contents, array $values): string
    {
        if ($values === []) {
            return $this->withTrailingNewline($contents);
        }

        $normalized = [];

        foreach ($values as $name => $value) {
            $this->assertValidName($name);
            $normalized[$name] = $this->encodeValue($value);
        }

        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $written = [];
        $result = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $matches) !== 1) {
                $result[] = $line;

                continue;
            }

            $name = $matches[1];

            if (! array_key_exists($name, $normalized)) {
                $result[] = $line;

                continue;
            }

            // Collapse duplicate assignments so a later line cannot silently
            // override the value selected in the installer.
            if (! isset($written[$name])) {
                $result[] = $name.'='.$normalized[$name];
                $written[$name] = true;
            }
        }

        while ($result !== [] && end($result) === '') {
            array_pop($result);
        }

        foreach ($normalized as $name => $value) {
            if (! isset($written[$name])) {
                $result[] = $name.'='.$value;
            }
        }

        return implode($newline, $result).$newline;
    }

    private function valueFromContents(string $contents, string $name): ?string
    {
        $quotedName = preg_quote($name, '/');

        if (preg_match('/^[ \t]*(?:export[ \t]+)?'.$quotedName.'[ \t]*=[ \t]*(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $raw = rtrim($matches[1], "\r");

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^"((?:\\\\.|[^"\\\\])*)"[ \t]*(?:#.*)?$/s', $raw, $matches) === 1) {
            return stripcslashes($matches[1]);
        }

        if (preg_match("/^'([^']*)'[ \\t]*(?:#.*)?$/s", $raw, $matches) === 1) {
            return $matches[1];
        }

        $unquoted = strtok($raw, '#');

        return trim($unquoted === false ? '' : $unquoted);
    }

    private function encodeValue(bool|float|int|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (str_contains($value, "\0")) {
            throw new RuntimeException('Nilai environment tidak boleh mengandung null byte.');
        }

        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\f" => '\\f',
            "\v" => '\\v',
        ]).'"';
    }

    private function assertValidName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
            throw new RuntimeException('Nama environment tidak valid: '.$name);
        }
    }

    private function withTrailingNewline(string $contents): string
    {
        if ($contents === '') {
            return PHP_EOL;
        }

        return rtrim($contents, "\r\n").(str_contains($contents, "\r\n") ? "\r\n" : "\n");
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withExclusiveLock(callable $callback): mixed
    {
        $lockDirectory = dirname($this->lockPath);

        if (! is_dir($lockDirectory)
            && ! @mkdir($lockDirectory, 0700, true)
            && ! is_dir($lockDirectory)
        ) {
            throw new RuntimeException('Direktori kunci untuk memperbarui .env tidak dapat dibuat.');
        }

        $handle = @fopen($this->lockPath, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Kunci eksklusif untuk memperbarui .env tidak dapat dibuat.');
        }

        @chmod($this->lockPath, 0600);

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Kunci eksklusif untuk memperbarui .env tidak dapat diperoleh.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        $temporaryPath = tempnam($directory, '.siem-env-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Berkas sementara untuk .env tidak dapat dibuat.');
        }

        try {
            $handle = @fopen($temporaryPath, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Berkas sementara untuk .env tidak dapat dibuka.');
            }

            try {
                $remaining = $contents;

                while ($remaining !== '') {
                    $written = fwrite($handle, $remaining);

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Penulisan .env tidak dapat diselesaikan.');
                    }

                    $remaining = substr($remaining, $written);
                }

                if (! fflush($handle)) {
                    throw new RuntimeException('Perubahan .env tidak dapat disimpan ke disk.');
                }

                if (function_exists('fsync')) {
                    fsync($handle);
                }
            } finally {
                fclose($handle);
            }

            @chmod($temporaryPath, 0600);

            if (! @rename($temporaryPath, $path)) {
                throw new RuntimeException('Penggantian .env secara atomik gagal.');
            }

            @chmod($path, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
