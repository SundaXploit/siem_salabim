<?php

namespace App\Services;

use App\Exceptions\ApplicationUpdateException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubReleaseService
{
    private const CACHE_KEY_PREFIX = 'siem:application-update:latest-release:v1:';

    public function __construct(private readonly ApplicationVersionService $versions) {}

    /** @return array<string, mixed> */
    public function check(bool $force = false): array
    {
        if (! $force) {
            $cached = Cache::get($this->cacheKey());
            if (is_array($cached)) {
                return $cached;
            }
        }

        $status = $this->fetchLatestRelease();
        Cache::put(
            $this->cacheKey(),
            $status,
            now()->addSeconds((int) config('application_updates.check_cache_seconds', 900)),
        );

        return $status;
    }

    /** @return array<string, mixed>|null */
    public function cached(): ?array
    {
        $cached = Cache::get($this->cacheKey());

        return is_array($cached) ? $cached : null;
    }

    /** @return array<string, mixed> */
    public function releaseForUpdate(string $expectedVersion): array
    {
        $status = $this->check(true);
        if (($status['state'] ?? null) !== 'update_available'
            || ($status['latest_version'] ?? null) !== $expectedVersion) {
            throw new ApplicationUpdateException(
                'Rilis berubah atau tidak lagi lebih baru. Jalankan pemeriksaan versi kembali.',
            );
        }

        return $status;
    }

    /** @return array<string, mixed> */
    private function fetchLatestRelease(): array
    {
        $current = $this->versions->current();
        $repository = (string) config('application_updates.repository', 'SundaXploit/siem_salabim');
        $url = rtrim((string) config('application_updates.api_url', 'https://api.github.com'), '/')
            ."/repos/{$repository}/releases/latest";

        try {
            $response = Http::accept('application/vnd.github+json')
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'SIEM-Salabim-Updater/'.$current,
                ])
                ->connectTimeout(5)
                ->timeout(10)
                ->retry(2, 250, throw: false)
                ->get($url);
        } catch (ConnectionException) {
            throw new ApplicationUpdateException('GitHub tidak dapat dihubungi untuk memeriksa versi terbaru.');
        } catch (Throwable $exception) {
            throw new ApplicationUpdateException('Pemeriksaan versi terbaru tidak dapat diselesaikan.', 0, $exception);
        }

        if ($response->status() === 404) {
            return [
                'state' => 'no_release',
                'current_version' => $current,
                'latest_version' => null,
                'update_available' => false,
                'checked_at' => now()->toIso8601String(),
                'releases_url' => config('application_updates.releases_url'),
                'message' => 'Belum ada GitHub Release resmi yang dipublikasikan.',
            ];
        }

        if ($response->status() === 403 || $response->status() === 429) {
            throw new ApplicationUpdateException(
                'Batas permintaan GitHub tercapai. Coba periksa versi kembali beberapa saat lagi.',
            );
        }

        if (! $response->successful()) {
            throw new ApplicationUpdateException(
                "GitHub mengembalikan HTTP {$response->status()} saat memeriksa rilis.",
            );
        }

        return $this->parseRelease($response, $current, $repository);
    }

    /** @return array<string, mixed> */
    private function parseRelease(Response $response, string $current, string $repository): array
    {
        $release = $response->json();
        if (! is_array($release)
            || ($release['draft'] ?? true) !== false
            || ($release['prerelease'] ?? true) !== false) {
            throw new ApplicationUpdateException('Metadata GitHub Release tidak valid atau bukan rilis stabil.');
        }

        $releaseId = $this->positiveInteger($release['id'] ?? null, 'ID rilis');
        $tag = is_string($release['tag_name'] ?? null) ? $release['tag_name'] : '';
        $latest = $this->versions->normalizeTag($tag);
        if ($tag !== 'v'.$latest) {
            throw new ApplicationUpdateException('Tag rilis resmi harus menggunakan format vX.Y.Z.');
        }

        $releaseUrl = $this->validatedGitHubUrl(
            $release['html_url'] ?? null,
            '/'.$repository.'/releases/tag/'.$tag,
        );
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $assetName = "siem-salabim-v{$latest}.zip";
        $matchingAssets = array_values(array_filter(
            $assets,
            static fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['name'] ?? null) === $assetName,
        ));

        if (count($matchingAssets) !== 1) {
            throw new ApplicationUpdateException("Rilis {$tag} tidak memiliki asset {$assetName}.");
        }
        $asset = $matchingAssets[0];

        $assetId = $this->positiveInteger($asset['id'] ?? null, 'ID asset');
        $assetSize = $this->positiveInteger($asset['size'] ?? null, 'Ukuran asset');
        $maximum = (int) config('application_updates.max_asset_bytes', 314_572_800);
        if ($assetSize > $maximum) {
            throw new ApplicationUpdateException('Ukuran paket update melebihi batas yang diizinkan server.');
        }

        $assetApiUrl = $this->validatedAssetApiUrl($asset['url'] ?? null, $repository, $assetId);
        $digest = is_string($asset['digest'] ?? null) ? strtolower($asset['digest']) : '';
        $sha256 = preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $matches) === 1
            ? $matches[1]
            : null;
        if ($sha256 === null && config('application_updates.require_github_digest', true)) {
            throw new ApplicationUpdateException('GitHub belum menyediakan digest SHA-256 untuk asset rilis.');
        }

        $comparison = version_compare($latest, $current);

        return [
            'state' => $comparison > 0 ? 'update_available' : 'current',
            'current_version' => $current,
            'latest_version' => $latest,
            'update_available' => $comparison > 0,
            'checked_at' => now()->toIso8601String(),
            'release_id' => $releaseId,
            'release_tag' => $tag,
            'release_url' => $releaseUrl,
            'published_at' => is_string($release['published_at'] ?? null)
                ? $release['published_at']
                : null,
            'asset' => [
                'id' => $assetId,
                'name' => $assetName,
                'api_url' => $assetApiUrl,
                'size' => $assetSize,
                'sha256' => $sha256,
            ],
            'releases_url' => config('application_updates.releases_url'),
            'message' => $comparison > 0
                ? "Versi {$latest} tersedia."
                : 'Aplikasi sudah menggunakan versi resmi terbaru.',
        ];
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 1) {
            throw new ApplicationUpdateException("{$label} GitHub Release tidak valid.");
        }

        return $value;
    }

    private function validatedGitHubUrl(mixed $url, string $exactPath): string
    {
        if (! is_string($url)
            || str_contains($url, "\r")
            || str_contains($url, "\n")
            || str_contains($url, "\0")) {
            throw new ApplicationUpdateException('URL GitHub Release tidak valid.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || (string) ($parts['path'] ?? '') !== $exactPath
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            throw new ApplicationUpdateException('URL GitHub Release berada di luar repository resmi.');
        }

        return $url;
    }

    private function validatedAssetApiUrl(mixed $url, string $repository, int $assetId): string
    {
        $expected = "https://api.github.com/repos/{$repository}/releases/assets/{$assetId}";
        if (! is_string($url) || ! hash_equals($expected, $url)) {
            throw new ApplicationUpdateException('URL API asset rilis tidak sesuai repository resmi.');
        }

        return $url;
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX.$this->versions->current();
    }
}
