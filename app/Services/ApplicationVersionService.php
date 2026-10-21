<?php

namespace App\Services;

use App\Exceptions\ApplicationUpdateException;

class ApplicationVersionService
{
    public function current(): string
    {
        $root = rtrim((string) config('application_updates.application_root', base_path()), '/\\');
        $path = $root.DIRECTORY_SEPARATOR.'VERSION';
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if (! $this->isStableSemver($version)) {
            throw new ApplicationUpdateException('File VERSION aplikasi tidak valid.');
        }

        return $version;
    }

    public function normalizeTag(string $tag): string
    {
        $version = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;

        if (! $this->isStableSemver($version)) {
            throw new ApplicationUpdateException('Tag rilis tidak menggunakan format SemVer stabil.');
        }

        return $version;
    }

    public function isStableSemver(string $version): bool
    {
        return strlen($version) <= 63
            && preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D', $version) === 1;
    }
}
