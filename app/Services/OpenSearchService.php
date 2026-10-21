<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Configuration;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class OpenSearchService
{
    /**
     * The saved client is created only when a fetch is actually requested.
     *
     * This matters for the Settings test button: an administrator must be able
     * to test a corrected, unsaved form even if the previously saved endpoint
     * is malformed or unreachable.
     */
    protected ?Client $client = null;

    protected string $host;

    protected string $username;

    protected string $password;

    protected string $index;

    protected bool $verifySsl;

    public function __construct()
    {
        $this->host = (string) Configuration::get('opensearch_host', config('services.opensearch.host', 'https://localhost:9200'));
        $this->username = (string) Configuration::get('opensearch_username', config('services.opensearch.username', ''));
        $this->password = (string) Configuration::get('opensearch_password', config('services.opensearch.password', ''));
        $this->index = (string) Configuration::get('opensearch_index', config('services.opensearch.index', 'wazuh-alerts-*'));
        $this->verifySsl = filter_var(config('services.opensearch.verify_ssl', true), FILTER_VALIDATE_BOOL);

    }

    /**
     * Fetch alerts from OpenSearch with rule.level >= $minLevel
     */
    public function fetchAlerts(int $minLevel = 12, int $size = 100, ?array $timeRange = null): array
    {
        $indices = implode(',', $this->scopedIndexPatterns($timeRange));
        $query = $this->alertQuery($minLevel, $size, $timeRange);
        $query['track_total_hits'] = false;
        $body = $this->searchRequest("/{$indices}/_search", [
            'json' => $query,
        ]);
        $this->validateSearchResponse($body);

        return $body['hits']['hits'];
    }

    /**
     * Scan matching indices sequentially. A wildcard scroll opens one context
     * per shard and can exceed the cluster limit before returning its first
     * complete page. Close each index's scroll before starting the next.
     *
     * @param  array{gte: string, lt: string}|null  $timeRange  Fixed UTC bounds,
     *                                                          shared by index discovery and every scroll in this run.
     */
    public function fetchAlertBatches(int $minLevel = 12, int $size = 100, ?array $timeRange = null): iterable
    {
        if ($timeRange !== null) {
            // A Wazuh day spans at most two UTC daily index suffixes. Do not
            // discover it by aggregating over every historical shard twice.
            // A custom index/alias remains intact, preserving alias filters.
            foreach ($this->scopedIndexPatterns($timeRange) as $index) {
                yield from $this->fetchIndexBatches($index, $minLevel, $size, $timeRange);
            }

            return;
        }

        foreach ($this->matchingIndices($minLevel, $timeRange) as $index) {
            yield from $this->fetchIndexBatches($index, $minLevel, $size, $timeRange);
        }
    }

    /**
     * Read a fixed incremental window from one stable OpenSearch snapshot.
     *
     * A PIT plus search_after avoids the "newest N" gap that can happen when
     * more alerts arrive while pages are being read. Older clusters and
     * least-privilege roles may not expose PIT; in that case a scroll remains
     * a stable snapshot and is used as the compatibility fallback.
     *
     * @param  array{gte: string, lt: string}  $timeRange
     */
    public function fetchIncrementalAlertBatches(
        int $minLevel = 12,
        int $size = 100,
        array $timeRange = [],
    ): iterable {
        if (! isset($timeRange['gte'], $timeRange['lt'])) {
            throw new \InvalidArgumentException('Incremental fetch memerlukan batas waktu gte dan lt.');
        }

        if (! filter_var(config('services.opensearch.pit_enabled', false), FILTER_VALIDATE_BOOL)) {
            yield from $this->fetchAlertBatches($minLevel, $size, $timeRange);

            return;
        }

        $tieBreaker = $this->pitTiebreakerField();

        try {
            $pitId = $this->openPointInTime($timeRange);
        } catch (\RuntimeException $e) {
            // PIT is an optional API and commonly needs a separate role
            // permission. Falling back before any hit was consumed is safe.
            if (! in_array($e->getCode(), [400, 403, 404, 405], true)) {
                throw $e;
            }

            Log::notice('[OpenSearch] PIT unavailable; using scroll snapshot for incremental fetch', [
                'status' => $e->getCode(),
            ]);
            yield from $this->fetchAlertBatches($minLevel, $size, $timeRange);

            return;
        }

        $keepAlive = $this->pitKeepAlive();
        $searchAfter = null;

        try {
            while (true) {
                $query = $this->alertQuery($minLevel, $size, $timeRange);
                $query['track_total_hits'] = false;
                $query['pit'] = ['id' => $pitId, 'keep_alive' => $keepAlive];
                $query['sort'] = [
                    ['@timestamp' => ['order' => 'asc', 'unmapped_type' => 'date']],
                    ['_index' => ['order' => 'asc']],
                    // This field is operator-supplied because OpenSearch
                    // forbids a correctness-safe sort on _id. Missing or
                    // unmapped values must fail the snapshot rather than let
                    // search_after silently skip equal tuples.
                    // Do not set unmapped_type here: an index without the
                    // configured field must fail the run before its checkpoint
                    // advances instead of sharing an ambiguous null cursor.
                    [$tieBreaker => ['order' => 'asc']],
                ];
                if ($searchAfter !== null) {
                    $query['search_after'] = $searchAfter;
                }

                $body = $this->searchRequest('/_search', [
                    'delay' => $searchAfter === null
                        ? 0
                        : max(0, min(5000, (int) config('services.opensearch.fetch_page_delay_ms', 250))),
                    'json' => $query,
                ]);
                $this->validateSearchResponse($body);

                if (is_string($body['pit_id'] ?? null) && $body['pit_id'] !== '') {
                    $pitId = $body['pit_id'];
                }

                $hits = $body['hits']['hits'];
                if ($hits === []) {
                    return;
                }

                $lastSort = $hits[array_key_last($hits)]['sort'] ?? null;
                if (! is_array($lastSort) || ! array_is_list($lastSort) || $lastSort === []) {
                    throw new \RuntimeException('OpenSearch tidak mengembalikan cursor sort untuk pagination incremental.');
                }
                if (count($lastSort) !== 3 || in_array(null, $lastSort, true)) {
                    throw new \RuntimeException(
                        'Field tiebreaker PIT tidak tersedia pada seluruh alert. Gunakan field unik dengan doc_values atau nonaktifkan PIT.',
                    );
                }
                if ($lastSort === $searchAfter) {
                    throw new \RuntimeException('Cursor pagination incremental OpenSearch tidak bergerak.');
                }

                yield $hits;

                if (count($hits) < max(1, min($size, 10000))) {
                    return;
                }

                $searchAfter = $lastSort;
            }
        } finally {
            $this->closePointInTime($pitId);
        }
    }

    /** @param array{gte: string, lt: string} $timeRange */
    private function openPointInTime(array $timeRange): string
    {
        $indices = implode(',', array_map('rawurlencode', $this->scopedIndexPatterns($timeRange)));

        try {
            $response = $this->requestWithMemoryRetry(
                'POST',
                "/{$indices}/_search/point_in_time",
                ['query' => [
                    'keep_alive' => $this->pitKeepAlive(),
                    // Never advance a checkpoint over a partial snapshot.
                    'allow_partial_pit_creation' => 'false',
                ]],
            );
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;

            throw new \RuntimeException(self::safeErrorMessage($status), $status ?? 0, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Tidak dapat membuka snapshot OpenSearch untuk fetch incremental.',
                0,
                $e,
            );
        }

        $body = json_decode($response->getBody()->getContents(), true);
        $pitId = is_array($body) ? ($body['pit_id'] ?? null) : null;
        if (is_array($body) && ($message = $this->searchFailureMessage($body))) {
            if (is_string($pitId) && $pitId !== '') {
                $this->closePointInTime($pitId);
            }
            throw new \RuntimeException($message);
        }
        if (! is_string($pitId) || $pitId === '') {
            throw new \RuntimeException('OpenSearch tidak mengembalikan PIT ID yang valid.');
        }

        return $pitId;
    }

    private function closePointInTime(string $pitId): void
    {
        try {
            $this->requestWithMemoryRetry('DELETE', '/_search/point_in_time', [
                'json' => ['pit_id' => [$pitId]],
            ]);
        } catch (\Throwable $e) {
            // PIT expires automatically. Cleanup must not hide the import
            // result or the original pagination failure.
            Log::warning('[OpenSearch] Could not close fetch PIT', [
                'exception_class' => $e::class,
            ]);
        }
    }

    private function pitKeepAlive(): string
    {
        $seconds = max(30, min(600, (int) config('services.opensearch.pit_keep_alive_seconds', 120)));

        return "{$seconds}s";
    }

    private function pitTiebreakerField(): string
    {
        $field = trim((string) config('services.opensearch.pit_tiebreaker_field', ''));
        $metadataFields = ['_id', '_doc', '_score', '_shard_doc'];

        if ($field === ''
            || strlen($field) > 255
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $field)
            || in_array($field, $metadataFields, true)) {
            throw new \RuntimeException(
                'PIT memerlukan OPENSEARCH_PIT_TIEBREAKER_FIELD berupa field keyword/doc_values yang unik. '
                .'Nonaktifkan PIT untuk fallback scroll yang aman.',
            );
        }

        return $field;
    }

    private function scopedIndexPatterns(?array $timeRange): array
    {
        // Only infer date suffixes for the standard Wazuh daily index patterns.
        // Arbitrary custom indices and aliases may use another naming scheme.
        if ($timeRange === null || ! preg_match('/^wazuh-alerts-(?:\d+\.x-)?\*$/', $this->index)) {
            return [$this->index];
        }

        $from = CarbonImmutable::parse($timeRange['gte'])->utc();
        $until = CarbonImmutable::parse($timeRange['lt'])->utc();
        if ($until->lessThanOrEqualTo($from)) {
            throw new \InvalidArgumentException('Rentang waktu fetch tidak valid.');
        }

        $patterns = [];
        $firstDay = $from->startOfDay();
        for ($day = $until->subMicrosecond()->startOfDay(); $day->greaterThanOrEqualTo($firstDay); $day = $day->subDay()) {
            $patterns[] = $this->index.$day->format('Y.m.d');
        }

        return $patterns;
    }

    private function matchingIndices(int $minLevel, ?array $timeRange): iterable
    {
        $after = null;

        do {
            $composite = [
                'size' => 100,
                'sources' => [['index' => ['terms' => ['field' => '_index', 'order' => 'desc']]]],
            ];
            if ($after !== null) {
                $composite['after'] = $after;
            }

            // An ordinary search needs no persistent scroll contexts and only
            // requires the same search permission as the import itself.
            $options = [
                'json' => [
                    'size' => 0,
                    'track_total_hits' => false,
                    'query' => $this->alertQuery($minLevel, 1, $timeRange)['query'],
                    'aggs' => ['alert_indices' => ['composite' => $composite]],
                ],
            ];
            if ($timeRange !== null) {
                // Skip shards outside the requested day before executing the
                // search and limit concurrent work on the indexer.
                $options['query'] = ['pre_filter_shard_size' => 1, 'max_concurrent_shard_requests' => 1];
            }
            $body = $this->searchRequest("/{$this->index}/_search", $options);
            $this->validateSearchResponse($body);
            $aggregation = $body['aggregations']['alert_indices'] ?? [];
            $buckets = $aggregation['buckets'] ?? null;

            if (! is_array($buckets) || ! array_is_list($buckets)) {
                throw new \RuntimeException('OpenSearch tidak mengembalikan daftar indeks alert yang valid.');
            }

            foreach ($buckets as $bucket) {
                $index = $bucket['key']['index'] ?? null;
                if (! is_string($index) || $index === '' || preg_match('/[\s\/*?,#]/', $index)) {
                    throw new \RuntimeException('OpenSearch mengembalikan nama indeks alert yang tidak valid.');
                }

                yield $index;
            }

            $next = $aggregation['after_key'] ?? null;
            if ($next !== null && (! is_array($next) || ! is_string($next['index'] ?? null) || $next === $after)) {
                throw new \RuntimeException('OpenSearch mengembalikan cursor daftar indeks yang tidak valid.');
            }
            $after = $next;
        } while ($buckets !== [] && $after !== null);
    }

    private function fetchIndexBatches(string $index, int $minLevel, int $size, ?array $timeRange): iterable
    {
        $scrollId = null;

        try {
            $query = $this->alertQuery($minLevel, $size, $timeRange);
            // Import order does not affect the dashboard's local time sorting.
            // _doc avoids allocating a timestamp sort for every import page.
            $query['sort'] = ['_doc'];
            $body = $this->searchRequest('/'.rawurlencode($index).'/_search', [
                'query' => ['scroll' => '2m'],
                'json' => $query,
            ]);

            while (true) {
                if (isset($body['_scroll_id']) && is_string($body['_scroll_id']) && $body['_scroll_id'] !== '') {
                    $scrollId = $body['_scroll_id'];
                }

                $this->validateSearchResponse($body);
                $hits = $body['hits']['hits'];

                if ($hits === []) {
                    return;
                }

                if ($scrollId === null) {
                    throw new \RuntimeException('OpenSearch tidak memberikan scroll ID; pengambilan seluruh halaman tidak dapat dilanjutkan.');
                }

                yield $hits;

                $body = $this->searchRequest('/_search/scroll', [
                    'delay' => max(0, min(5000, (int) config('services.opensearch.fetch_page_delay_ms', 250))),
                    'json' => ['scroll' => '2m', 'scroll_id' => $scrollId],
                ]);
            }
        } finally {
            if ($scrollId !== null) {
                try {
                    $this->requestWithMemoryRetry('DELETE', '/_search/scroll', [
                        'json' => ['scroll_id' => [$scrollId]],
                    ]);
                } catch (\Throwable $e) {
                    // The context also expires automatically. Cleanup must not
                    // hide the import result or its original failure.
                    Log::warning('[OpenSearch] Could not clear fetch scroll', [
                        'exception_class' => $e::class,
                    ]);
                }
            }
        }
    }

    private function alertQuery(int $minLevel, int $size, ?array $timeRange = null): array
    {
        $levelFilter = ['range' => ['rule.level' => ['gte' => $minLevel]]];

        return [
            'query' => $timeRange === null ? $levelFilter : [
                'bool' => ['filter' => [$levelFilter, ['range' => ['@timestamp' => $timeRange]]]],
            ],
            'sort' => [
                ['@timestamp' => 'desc'],
            ],
            'size' => max(1, min($size, 10000)),
        ];
    }

    private function searchRequest(string $path, array $options): array
    {
        if ($path !== '/_search/scroll') {
            $options['query'] = array_merge($options['query'] ?? [], [
                'max_concurrent_shard_requests' => 1,
                'pre_filter_shard_size' => 1,
                // Query-string booleans must be literal true/false; Guzzle
                // serializes a PHP boolean as 1, which OpenSearch rejects.
                'allow_no_indices' => 'true',
            ]);
        }

        try {
            $response = $this->requestWithMemoryRetry('POST', $path, $options);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
            $body = $e->hasResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;
            $detail = is_array($body) ? $this->searchFailureMessage($body) : null;
            Log::error('[OpenSearch] fetchAlerts HTTP failure', [
                'status' => $status,
                'exception_class' => $e::class,
            ]);

            throw new \RuntimeException($detail ?? self::safeErrorMessage($status), $status ?? 0, $e);
        } catch (\Throwable $e) {
            Log::error('[OpenSearch] fetchAlerts connection failure', [
                'exception_class' => $e::class,
            ]);

            throw new \RuntimeException(
                'Tidak dapat terhubung ke OpenSearch. Periksa host, port, sertifikat TLS, dan jaringan.',
                0,
                $e
            );
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (! is_array($body)) {
            throw new \RuntimeException('OpenSearch mengembalikan respons yang tidak valid.');
        }

        return $body;
    }

    private function requestWithMemoryRetry(string $method, string $path, array $options): ResponseInterface
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->client()->request($method, $path, $options);
            } catch (RequestException $e) {
                $response = $e->getResponse();
                $body = $response ? json_decode((string) $response->getBody(), true) : null;
                $reasons = is_array($body['error'] ?? null)
                    ? array_merge([$body['error']], $body['error']['root_cause'] ?? [])
                    : [];
                $admissionRejected = false;
                foreach ($reasons as $reason) {
                    if (($reason['type'] ?? '') === 'circuit_breaking_exception'
                        && str_contains((string) ($reason['reason'] ?? ''), '[<http_request>]')) {
                        $admissionRejected = true;
                    }
                }

                // Retry only a request rejected before execution. Retrying an
                // ambiguous scroll timeout could advance past an unseen page.
                if ($attempt >= 3 || $response?->getStatusCode() !== 429 || ! $admissionRejected) {
                    throw $e;
                }

                $options['delay'] = 500 * (2 ** $attempt);
                Log::notice('[OpenSearch] Retrying request rejected by memory admission limit', [
                    'attempt' => $attempt + 1,
                    'delay_ms' => $options['delay'],
                ]);
            }
        }
    }

    private function validateSearchResponse(array $body): void
    {
        if ($message = $this->searchFailureMessage($body)) {
            throw new \RuntimeException($message);
        }

        if (! isset($body['hits']['hits']) || ! is_array($body['hits']['hits']) || ! array_is_list($body['hits']['hits'])) {
            throw new \RuntimeException('OpenSearch mengembalikan respons pencarian yang tidak valid.');
        }
    }

    private function searchFailureMessage(array $body): ?string
    {
        $timedOut = (bool) ($body['timed_out'] ?? false);
        $failed = (int) ($body['_shards']['failed'] ?? 0);
        $total = (int) ($body['_shards']['total'] ?? 0);
        $reasons = array_column($body['_shards']['failures'] ?? [], 'reason');
        if (is_array($body['error'] ?? null)) {
            $reasons[] = $body['error'];
            $reasons = array_merge($reasons, $body['error']['root_cause'] ?? []);
        }

        $types = [];
        $scrollLimit = false;
        foreach ($reasons as $reason) {
            while (is_array($reason)) {
                $type = $reason['type'] ?? '';
                if (is_string($type) && preg_match('/^[a-z_]+$/', $type)) {
                    $types[] = $type;
                }
                $scrollLimit = $scrollLimit || str_contains((string) ($reason['reason'] ?? ''), 'too many scroll contexts');
                $reason = $reason['caused_by'] ?? null;
            }
        }
        $types = array_values(array_unique($types));

        if ($timedOut || $failed > 0 || $types !== []) {
            // Record diagnostics without upstream bodies, alert data or secrets.
            Log::warning('[OpenSearch] Search rejected or incomplete', [
                'timed_out' => $timedOut,
                'shards_total' => $total,
                'shards_failed' => $failed,
                'failure_types' => $types,
                'scroll_context_limit' => $scrollLimit,
            ]);
        }

        if ($scrollLimit) {
            return "Batas scroll context OpenSearch tercapai ({$failed}/{$total} shard gagal). Tunggu konteks lama kedaluwarsa lalu jalankan fetch kembali.";
        }
        if (in_array('circuit_breaking_exception', $types, true)) {
            return 'Memori OpenSearch mencapai batas circuit breaker. Tunggu beban server turun lalu coba kembali; jika berulang, periksa heap memori OpenSearch.';
        }
        if ($timedOut) {
            return 'Pencarian OpenSearch melewati batas waktu (timed_out). Hasil belum lengkap; coba kembali setelah beban server turun.';
        }
        if ($failed > 0) {
            $detail = $types === [] ? '' : ' ('.implode(', ', $types).')';

            return "OpenSearch gagal membaca {$failed} dari {$total} shard{$detail}. Periksa kesehatan indeks dan log OpenSearch.";
        }

        return null;
    }

    /**
     * Test the saved OpenSearch configuration. The probe is deliberately
     * index-scoped, so a least-privilege service account does not need access
     * to the cluster root endpoint.
     */
    public function testConnection(): array
    {
        return $this->testConfiguration([
            'opensearch_host' => $this->host,
            'opensearch_username' => $this->username,
            'opensearch_password' => $this->password,
            'opensearch_index' => $this->index,
        ]);
    }

    /**
     * Test a temporary, unpersisted OpenSearch configuration.
     *
     * The caller may pass opensearch_host, opensearch_username,
     * opensearch_password, and opensearch_index. No value is stored here.
     */
    public function testConfiguration(array $configuration): array
    {
        $host = trim((string) ($configuration['opensearch_host'] ?? $this->host));
        $username = (string) ($configuration['opensearch_username'] ?? $this->username);
        $password = (string) ($configuration['opensearch_password'] ?? $this->password);
        $index = trim((string) ($configuration['opensearch_index'] ?? $this->index));

        if (! $this->isValidEndpoint($host) || $username === '' || $password === '' || ! $this->isValidIndex($index)) {
            return [
                'success' => false,
                'error' => 'Konfigurasi OpenSearch belum lengkap atau tidak valid.',
            ];
        }

        try {
            $response = $this->makeClient($host, $username, $password)
                ->get("/{$index}/_count", ['http_errors' => false]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning('[OpenSearch] connection test rejected', [
                    'status' => $status,
                    'index' => $index,
                ]);

                return [
                    'success' => false,
                    'status' => $status,
                    'error' => self::safeErrorMessage($status),
                ];
            }

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'status' => $status,
                'index' => $index,
                'count' => (int) ($body['count'] ?? 0),
                // Keep legacy consumers working until they use message/index.
                'version' => 'index accessible',
                'name' => $index,
                'message' => 'Koneksi dan akses indeks berhasil.',
            ];
        } catch (\Throwable $e) {
            Log::warning('[OpenSearch] connection test failed', [
                'exception_class' => $e::class,
                'index' => $index,
            ]);

            return [
                'success' => false,
                'error' => 'Tidak dapat terhubung ke OpenSearch. Periksa host, port, sertifikat TLS, dan jaringan.',
            ];
        }
    }

    private function makeClient(string $host, string $username, string $password): Client
    {
        return new Client([
            'base_uri' => rtrim($host, '/'),
            'auth' => [$username, $password],
            'verify' => $this->verifySsl,
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function client(): Client
    {
        return $this->client ??= $this->makeClient($this->host, $this->username, $this->password);
    }

    private function isValidEndpoint(string $host): bool
    {
        $parts = parse_url($host);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    private function isValidIndex(string $index): bool
    {
        return $index !== ''
            && ! str_starts_with($index, '/')
            && ! str_contains($index, '..')
            && ! str_contains($index, "\0");
    }

    private static function safeErrorMessage(?int $status): string
    {
        return match ($status) {
            401 => 'Autentikasi OpenSearch ditolak. Periksa username dan password.',
            403 => 'Akses ke indeks OpenSearch ditolak. Periksa role akun service.',
            404 => 'Endpoint atau index pattern OpenSearch tidak ditemukan. Periksa host, port, dan index pattern.',
            408, 504 => 'OpenSearch tidak merespons tepat waktu. Coba lagi setelah memeriksa jaringan.',
            429 => 'OpenSearch menolak pencarian karena batas sumber daya atau beban server. Tunggu sebentar lalu coba kembali.',
            default => $status
                ? "OpenSearch mengembalikan HTTP status {$status}."
                : 'Tidak dapat terhubung ke OpenSearch. Periksa host, port, sertifikat TLS, dan jaringan.',
        };
    }

    /**
     * Parse a raw OpenSearch hit into our alert structure
     */
    public static function parseHit(array $hit): array
    {
        $src = $hit['_source'] ?? [];
        $data = $src['data'] ?? [];
        $sourceId = (string) ($hit['_id'] ?? '');
        $sourceIndex = (string) ($hit['_index'] ?? '');
        $sourceCluster = trim((string) config('services.opensearch.source_cluster', 'wazuh-primary'));

        if ($sourceId === '' || strlen($sourceId) > 512 || str_contains($sourceId, "\0")) {
            throw new \UnexpectedValueException('Hit OpenSearch harus memiliki _id sepanjang 1-512 byte tanpa null byte.');
        }
        if ($sourceIndex === '' || strlen($sourceIndex) > 255 || str_contains($sourceIndex, "\0")) {
            throw new \UnexpectedValueException('Hit OpenSearch harus memiliki _index sepanjang 1-255 byte tanpa null byte.');
        }
        if ($sourceCluster === '' || strlen($sourceCluster) > 64 || str_contains($sourceCluster, "\0")) {
            throw new \UnexpectedValueException('Identitas source cluster OpenSearch harus sepanjang 1-64 byte tanpa null byte.');
        }

        return [
            'wazuh_alert_id' => $sourceId,
            'source_cluster' => $sourceCluster,
            'source_index' => $sourceIndex,
            'source_id' => $sourceId,
            'source_identity_hash' => Alert::sourceIdentityHash($sourceCluster, $sourceIndex, $sourceId),
            'rule_description' => self::textColumn($src['rule']['description'] ?? null),
            'src_ip' => self::textColumn($data['srcip'] ?? $src['data']['src_ip'] ?? null),
            'dst_ip' => self::textColumn($data['dstip'] ?? $src['data']['dst_ip'] ?? null),
            'agent_name' => self::textColumn($src['agent']['name'] ?? null),
            'rule_id' => self::textColumn((string) ($src['rule']['id'] ?? null)),
            'rule_level' => (int) ($src['rule']['level'] ?? 0),
            'raw_data' => $src,
            'first_seen_at' => isset($src['@timestamp'])
                ? Carbon::parse($src['@timestamp'])->utc()->toDateTimeString()
                : now('UTC')->toDateTimeString(),
        ];
    }

    private static function textColumn(mixed $value): ?string
    {
        // PostgreSQL text cannot contain NUL. Render it visibly in searchable
        // projections while raw_data retains the original escaped JSON value.
        return $value === null ? null : str_replace("\0", '\u0000', (string) $value);
    }
}
