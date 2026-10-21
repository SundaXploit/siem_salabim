<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\IngestionCheckpoint;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\OpenSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchWazuhAlerts extends Command
{
    public const EXIT_LOCK_BUSY = 75;

    public const EXIT_MAINTENANCE = 69;

    protected $signature = 'siem:fetch-alerts
        {--all : Scan all matching alerts, including older pages}
        {--today : Fetch all matching alerts from today in Asia/Jakarta}
        {--lookback-days= : Reconcile 2-30 local calendar days ending today}
        {--dry-run : Show what would be imported, without saving}';

    protected $description = 'Fetch Wazuh alerts from OpenSearch and store them in the database';

    public function handle(
        OpenSearchService $openSearch,
        ApplicationUpdateRuntimeGuard $updateGuard,
    ): int {
        // All entry points share this lock: scheduler, live alerts and manual
        // fetch. The scheduler's withoutOverlapping only covers its own jobs.
        $lock = Cache::lock(
            'siem_fetch_running',
            max(600, (int) config('services.opensearch.fetch_lock_seconds', 3600)),
        );
        if (! $lock->get()) {
            $this->warn('[SIEM] Fetch lain masih berjalan. Tunggu proses tersebut selesai sebelum menjalankan fetch kembali.');

            return self::EXIT_LOCK_BUSY;
        }

        try {
            // The command is also a public CLI entry point. Checking only in
            // the queued wrapper would allow an operator-started import to
            // race a self-update. This check is intentionally made while the
            // shared ingestion lock is held; the updater checks the same lock
            // after publishing its runtime marker.
            if ($updateGuard->isActive()) {
                $this->warn('[SIEM] Fetch ditunda karena update aplikasi sedang berjalan atau menunggu pemulihan.');

                return self::EXIT_MAINTENANCE;
            }

            $remaining = (int) Cache::get('siem_fetch_cooldown_until', 0) - now()->timestamp;
            if ($remaining > 0) {
                $this->warn("[SIEM] Fetch dijeda karena batas memori OpenSearch. Tunggu {$remaining} detik sebelum mencoba kembali.");

                return self::FAILURE;
            }

            return $this->importAlerts($openSearch);
        } finally {
            $lock->release();
        }
    }

    private function importAlerts(OpenSearchService $openSearch): int
    {
        $this->info('[SIEM] Starting Wazuh alert fetch...');

        $minLevel = (int) Configuration::get('min_alert_level', config('services.opensearch.min_level', 12));
        $size = max(1, min((int) config('services.opensearch.size', 100), 10000));
        $isDryRun = (bool) $this->option('dry-run');
        $fetchToday = (bool) $this->option('today');
        $fetchArchive = (bool) $this->option('all');
        $fetchReconciliation = $this->input->hasParameterOption('--lookback-days');
        $lookbackRaw = $this->option('lookback-days');

        if ((int) $fetchToday + (int) $fetchArchive + (int) $fetchReconciliation > 1) {
            $this->error('[SIEM] Pilih hanya satu mode: --today, --lookback-days, atau --all.');

            return self::INVALID;
        }

        $lookbackDays = null;
        if ($fetchReconciliation) {
            $lookbackValue = is_scalar($lookbackRaw) ? (string) $lookbackRaw : '';
            if (preg_match('/^\d+$/', $lookbackValue) !== 1
                || (int) $lookbackValue < 2
                || (int) $lookbackValue > 30) {
                $this->error('[SIEM] --lookback-days harus berupa angka 2-30.');

                return self::INVALID;
            }

            $lookbackDays = (int) $lookbackValue;
        }

        $isIncremental = ! $fetchToday && ! $fetchArchive && ! $fetchReconciliation;
        $checkpoint = null;
        $checkpointTarget = null;

        if ($isIncremental) {
            $checkpointDefinition = $this->checkpointDefinition($minLevel);
            $checkpoint = IngestionCheckpoint::query()
                ->where('stream_key', $checkpointDefinition['stream_key'])
                ->first();
            $checkpointTarget = CarbonImmutable::now('UTC');
            $overlapSeconds = max(1, min(
                86400,
                (int) config('services.opensearch.fetch_overlap_seconds', 900),
            ));
            $cursor = $checkpoint?->cursor_at === null
                ? CarbonImmutable::now('Asia/Jakarta')->startOfDay()->utc()
                : CarbonImmutable::instance($checkpoint->cursor_at)->utc();
            if ($cursor->greaterThan($checkpointTarget)) {
                $cursor = $checkpointTarget;
            }
            $from = $checkpoint === null ? $cursor : $cursor->subSeconds($overlapSeconds);
            if ($from->greaterThanOrEqualTo($checkpointTarget)) {
                $from = $checkpointTarget->subSeconds($overlapSeconds);
            }
            $timeRange = [
                'gte' => $from->toIso8601String(),
                'lt' => $checkpointTarget->toIso8601String(),
            ];
        } elseif ($fetchToday || $fetchReconciliation) {
            $day = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
            $timeRange = [
                'gte' => ($fetchReconciliation
                    ? $day->subDays($lookbackDays - 1)
                    : $day)->utc()->toIso8601String(),
                'lt' => $day->addDay()->utc()->toIso8601String(),
            ];
        } else {
            $timeRange = null;
        }

        $newCount = 0;
        $skippedCount = 0;
        $retrievedCount = 0;
        $pageCount = 0;
        $dryRunIdentities = [];

        if ($isIncremental) {
            $this->info('[SIEM] Periode incremental: '.$timeRange['gte'].' sampai sebelum '.$timeRange['lt']
                ."; overlap {$overlapSeconds} detik.");
        } elseif ($fetchToday) {
            $this->info('[SIEM] Periode: hari ini '.$day->format('Y-m-d').' (00:00 sampai sebelum 00:00 hari berikutnya, Asia/Jakarta).');
        } elseif ($fetchReconciliation) {
            $this->info("[SIEM] Rekonsiliasi {$lookbackDays} hari kalender Asia/Jakarta: {$timeRange['gte']} sampai sebelum {$timeRange['lt']}.");
        }
        $this->info("[SIEM] Filter: rule.level >= {$minLevel}. Memeriksa seluruh halaman "
            .($isIncremental ? 'pada snapshot incremental' : 'per indeks')
            ." ({$size} alert per batch).");

        try {
            if ($isIncremental) {
                $batches = $openSearch->fetchIncrementalAlertBatches($minLevel, $size, $timeRange);
            } elseif ($fetchToday || $fetchReconciliation) {
                $batches = $openSearch->fetchAlertBatches($minLevel, $size, $timeRange);
            } else {
                $batches = $openSearch->fetchAlertBatches($minLevel, $size);
            }

            foreach ($batches as $hits) {
                if ($hits === []) {
                    continue;
                }

                $pageCount++;
                $retrievedCount += count($hits);

                $parsedRows = array_map(
                    static fn (array $hit): array => OpenSearchService::parseHit($hit),
                    $hits,
                );

                if ($isDryRun) {
                    [$wouldInsert, $wouldSkip] = $this->countDryRunRows($parsedRows, $dryRunIdentities);
                    $newCount += $wouldInsert;
                    $skippedCount += $wouldSkip;

                    continue;
                }

                $this->adoptLegacyIdentities($parsedRows);
                $inserted = $this->insertAlertBatch($parsedRows);
                $newCount += $inserted;
                $skippedCount += count($parsedRows) - $inserted;
            }

            if ($isIncremental && ! $isDryRun && $checkpointTarget !== null) {
                $this->advanceCheckpoint($checkpointDefinition, $checkpointTarget);
            }
        } catch (\Throwable $e) {
            $message = $e instanceof QueryException
                ? 'Gagal menyimpan alert ke database. Periksa log aplikasi.'
                : $e->getMessage();
            if (str_contains(strtolower($message), 'circuit breaker')
                || str_contains(strtolower($message), 'circuit_breaking_exception')) {
                // A shared pause also stops automatic polling from immediately
                // adding load after the manual import reaches a memory limit.
                Cache::put('siem_fetch_cooldown_until', now()->timestamp + 120, 120);
                $this->warn('[SIEM] Semua fetch dijeda selama 120 detik agar OpenSearch dapat pulih.');
            }
            $this->error('[SIEM] Fetch gagal: '.$message);
            $this->warn("[SIEM] Pemeriksaan belum selesai. Diproses: {$retrievedCount}, "
                .($isDryRun ? 'Would import' : 'New').": {$newCount}, Skipped: {$skippedCount}. Jalankan fetch kembali untuk memeriksa ulang; alert yang sudah tersimpan akan dilewati.");
            Log::error('[SIEM] FetchWazuhAlerts failed during fetch/import', [
                'exception_class' => $e::class,
                'retrieved' => $retrievedCount,
                'new' => $newCount,
                'skipped' => $skippedCount,
            ]);

            return self::FAILURE;
        } finally {
            // Release the generator so its scroll context closes even when
            // processing a batch fails before the next network request.
            unset($batches);
        }

        $this->info("[SIEM] Retrieved {$retrievedCount} hits from OpenSearch across {$pageCount} batches.");

        if ($retrievedCount === 0) {
            $period = match (true) {
                $fetchToday => ' hari ini (Asia/Jakarta)',
                $fetchReconciliation => " pada rekonsiliasi {$lookbackDays} hari",
                $isIncremental => ' pada periode incremental',
                default => '',
            };
            $this->warn("[SIEM] Tidak ada alert{$period} yang cocok dengan filter level >= {$minLevel} pada indeks yang dikonfigurasi.");
        } elseif ($newCount === 0) {
            $this->info("[SIEM] Semua {$retrievedCount} alert yang diperiksa sudah tersimpan. Tidak ada alert baru untuk diimpor.");
        }

        $summary = $isDryRun ? 'Dry run. Would import' : 'Done. New';
        $this->info("[SIEM] {$summary}: {$newCount}, Skipped (already exists): {$skippedCount}");
        Log::info('[SIEM] FetchWazuhAlerts completed', [
            'mode' => match (true) {
                $isIncremental => 'incremental',
                $fetchToday => 'today',
                $fetchReconciliation => 'reconcile',
                default => 'archive',
            },
            'time_range' => $timeRange,
            'dry_run' => $isDryRun,
            'min_level' => $minLevel,
            'retrieved' => $retrievedCount,
            'new' => $newCount,
            'skipped' => $skippedCount,
        ]);

        return self::SUCCESS;
    }

    /** @return array{stream_key: string, source_cluster: string, source_index: string, min_level: int} */
    private function checkpointDefinition(int $minLevel): array
    {
        $sourceCluster = trim((string) config('services.opensearch.source_cluster', 'wazuh-primary'));
        $sourceIndex = trim((string) Configuration::get(
            'opensearch_index',
            config('services.opensearch.index', 'wazuh-alerts-*'),
        ));
        if ($sourceCluster === '' || strlen($sourceCluster) > 64) {
            throw new \RuntimeException('OPENSEARCH_SOURCE_CLUSTER harus berisi 1-64 karakter.');
        }
        if ($sourceIndex === '' || strlen($sourceIndex) > 255) {
            throw new \RuntimeException('Index pattern OpenSearch harus berisi 1-255 karakter.');
        }

        return [
            'stream_key' => hash('sha256', $sourceCluster."\0".$sourceIndex."\0".$minLevel),
            'source_cluster' => $sourceCluster,
            'source_index' => $sourceIndex,
            'min_level' => $minLevel,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, true>  $seen
     * @return array{int, int}
     */
    private function countDryRunRows(array $rows, array &$seen): array
    {
        $stored = array_fill_keys(
            Alert::query()
                ->whereIn('source_identity_hash', array_column($rows, 'source_identity_hash'))
                ->pluck('source_identity_hash')
                ->all(),
            true,
        );
        $legacyHashes = $this->legacyCandidateHashes($rows);
        $storedLegacyHashes = array_fill_keys(
            Alert::query()
                ->where('source_index', 'legacy')
                ->whereIn('source_identity_hash', array_keys($legacyHashes))
                ->pluck('source_identity_hash')
                ->all(),
            true,
        );

        $inserted = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $identity = $this->identityKey($row);
            if (isset($stored[$identity]) || isset($seen[$identity])) {
                $skipped++;

                continue;
            }
            $legacyHash = $this->firstStoredLegacyHash($row, $storedLegacyHashes);
            if ($legacyHash !== null) {
                // A real import would adopt this legacy row as the first
                // concrete source identity and preserve its triage state.
                unset($storedLegacyHashes[$legacyHash]);
                $stored[$identity] = true;
                $seen[$identity] = true;
                $skipped++;

                continue;
            }

            $this->line("  [DRY] Would import: {$row['wazuh_alert_id']} - {$row['rule_description']}");
            $seen[$identity] = true;
            $inserted++;
        }

        return [$inserted, $skipped];
    }

    /** @param list<array<string, mixed>> $rows */
    private function adoptLegacyIdentities(array $rows): void
    {
        $candidates = $this->legacyCandidateHashes($rows);

        $legacyAlerts = Alert::query()
            ->where('source_index', 'legacy')
            ->whereIn('source_identity_hash', array_keys($candidates))
            ->get(['id', 'source_identity_hash']);

        foreach ($legacyAlerts as $alert) {
            $candidate = $candidates[$alert->source_identity_hash];
            try {
                Alert::query()
                    ->whereKey($alert->id)
                    ->where('source_index', 'legacy')
                    ->where('source_identity_hash', $alert->source_identity_hash)
                    ->update([
                        'source_cluster' => $candidate['source_cluster'],
                        'source_index' => $candidate['source_index'],
                        'source_id' => $candidate['source_id'],
                        'source_identity_hash' => $candidate['source_identity_hash'],
                    ]);
            } catch (UniqueConstraintViolationException) {
                // An exact source row already exists. Leave the legacy row
                // untouched; the no-op upsert below remains idempotent.
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertAlertBatch(array $rows): int
    {
        $existing = array_fill_keys(
            Alert::query()
                ->whereIn('source_identity_hash', array_column($rows, 'source_identity_hash'))
                ->pluck('source_identity_hash')
                ->all(),
            true,
        );
        $novel = [];
        foreach ($rows as $row) {
            $identity = $this->identityKey($row);
            if (isset($existing[$identity]) || isset($novel[$identity])) {
                continue;
            }
            $novel[$identity] = $row;
        }

        $timestamp = now()->toDateTimeString();
        $prepared = array_map(static function (array $row) use ($timestamp): array {
            $row['raw_data'] = $row['raw_data'] === null
                ? null
                : json_encode(
                    $row['raw_data'],
                    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
                );
            $row['status'] ??= 'new';
            $row['created_at'] = $timestamp;
            $row['updated_at'] = $timestamp;

            return $row;
        }, array_values($novel));

        $batchSize = max(1, min(
            1000,
            (int) config('services.opensearch.fetch_insert_batch_size', 100),
        ));
        foreach (array_chunk($prepared, $batchSize) as $chunk) {
            Alert::query()->upsert(
                $chunk,
                ['source_identity_hash'],
                // A no-op update makes a concurrent replay idempotent while
                // preserving status, triage and the originally stored event.
                ['source_identity_hash'],
            );
        }

        return count($prepared);
    }

    /** @param array<string, mixed> $identity */
    private function identityKey(array $identity): string
    {
        return (string) ($identity['source_identity_hash'] ?? Alert::sourceIdentityHash(
            (string) $identity['source_cluster'],
            (string) $identity['source_index'],
            (string) $identity['source_id'],
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function legacyCandidateHashes(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            foreach ($this->legacyIdentityHashes($row) as $hash) {
                $candidates[$hash] ??= $row;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function legacyIdentityHashes(array $row): array
    {
        return array_values(array_unique([
            Alert::sourceIdentityHash('legacy', 'legacy', (string) $row['source_id']),
            Alert::sourceIdentityHash((string) $row['source_cluster'], 'legacy', (string) $row['source_id']),
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $storedLegacyHashes
     */
    private function firstStoredLegacyHash(array $row, array $storedLegacyHashes): ?string
    {
        foreach ($this->legacyIdentityHashes($row) as $hash) {
            if (isset($storedLegacyHashes[$hash])) {
                return $hash;
            }
        }

        return null;
    }

    /**
     * @param  array{stream_key: string, source_cluster: string, source_index: string, min_level: int}  $definition
     */
    private function advanceCheckpoint(array $definition, CarbonImmutable $target): void
    {
        DB::transaction(function () use ($definition, $target): void {
            IngestionCheckpoint::query()->firstOrCreate(
                ['stream_key' => $definition['stream_key']],
                [
                    ...$definition,
                    'cursor_at' => $target,
                    'last_completed_at' => $target,
                ],
            );

            $checkpoint = IngestionCheckpoint::query()
                ->where('stream_key', $definition['stream_key'])
                ->lockForUpdate()
                ->firstOrFail();
            $current = CarbonImmutable::instance($checkpoint->cursor_at)->utc();
            $checkpoint->forceFill([
                // Never let an older overlapping worker move the watermark
                // backwards if its cache lock expired during a long catch-up.
                'cursor_at' => $current->greaterThan($target) ? $current : $target,
                'last_completed_at' => CarbonImmutable::now('UTC'),
            ])->save();
        });
    }
}
