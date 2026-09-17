<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OpenSearchService;
use App\Models\Alert;
use App\Models\Configuration;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchWazuhAlerts extends Command
{
    protected $signature   = 'siem:fetch-alerts
        {--all : Scan all matching alerts, including older pages}
        {--today : Fetch all matching alerts from today in Asia/Jakarta}
        {--dry-run : Show what would be imported, without saving}';
    protected $description = 'Fetch Wazuh alerts from OpenSearch and store them in the database';

    public function handle(OpenSearchService $openSearch): int
    {
        // All entry points share this lock: scheduler, live alerts and manual
        // fetch. The scheduler's withoutOverlapping only covers its own jobs.
        $lock = Cache::lock('siem_fetch_running', 600);
        if (!$lock->get()) {
            $this->warn('[SIEM] Fetch lain masih berjalan. Tunggu proses tersebut selesai sebelum menjalankan fetch kembali.');

            return self::SUCCESS;
        }

        try {
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
        $size     = max(1, min((int) config('services.opensearch.size', 100), 10000));
        $isDryRun = (bool) $this->option('dry-run');
        $todayOnly = !(bool) $this->option('all') || (bool) $this->option('today');
        $fetchAll = (bool) $this->option('today') || (bool) $this->option('all');
        $day = $todayOnly ? CarbonImmutable::now('Asia/Jakarta')->startOfDay() : null;
        $timeRange = $day ? [
            'gte' => $day->utc()->toIso8601String(),
            'lt' => $day->addDay()->utc()->toIso8601String(),
        ] : null;
        $newCount = 0;
        $skippedCount = 0;
        $retrievedCount = 0;
        $pageCount = 0;
        $dryRunIds = [];

        if ($day !== null) {
            $this->info('[SIEM] Periode: hari ini '.$day->format('Y-m-d').' (00:00 sampai sebelum 00:00 hari berikutnya, Asia/Jakarta).');
        }
        $this->info("[SIEM] Filter: rule.level >= {$minLevel}. " . ($fetchAll
            ? "Memeriksa seluruh halaman per indeks ({$size} alert per batch)."
            : "Memeriksa {$size} alert terbaru hari ini. Gunakan --today untuk seluruh halaman hari ini."));

        try {
            if ($fetchAll && $todayOnly) {
                $batches = $openSearch->fetchAlertBatches($minLevel, $size, $timeRange);
            } elseif ($fetchAll) {
                $batches = $openSearch->fetchAlertBatches($minLevel, $size);
            } else {
                $batches = [$openSearch->fetchAlerts($minLevel, $size, $timeRange)];
            }

            foreach ($batches as $hits) {
                if ($hits === []) {
                    continue;
                }

                $pageCount++;
                $retrievedCount += count($hits);
                $existingIds = array_fill_keys(
                    Alert::whereIn('wazuh_alert_id', array_column($hits, '_id'))
                        ->pluck('wazuh_alert_id')->all(),
                    true,
                );

                foreach ($hits as $hit) {
                    $parsed = OpenSearchService::parseHit($hit);
                    $id = $parsed['wazuh_alert_id'];

                    if (isset($existingIds[$id]) || isset($dryRunIds[$id])) {
                        $skippedCount++;
                        continue;
                    }

                    if ($isDryRun) {
                        $this->line("  [DRY] Would import: {$id} - {$parsed['rule_description']}");
                        $dryRunIds[$id] = true;
                        $newCount++;
                        continue;
                    }

                    try {
                        // Preserve existing triage. The unique index also
                        // protects against a concurrent automatic import.
                        Alert::create($parsed);
                        $newCount++;
                    } catch (UniqueConstraintViolationException) {
                        $skippedCount++;
                    }

                    $existingIds[$id] = true;
                }
            }
        } catch (\Throwable $e) {
            $message = $e instanceof \Illuminate\Database\QueryException
                ? 'Gagal menyimpan alert ke database. Periksa log aplikasi.'
                : $e->getMessage();
            if (str_contains(strtolower($message), 'circuit breaker')
                || str_contains(strtolower($message), 'circuit_breaking_exception')) {
                // A shared pause also stops automatic polling from immediately
                // adding load after the manual import reaches a memory limit.
                Cache::put('siem_fetch_cooldown_until', now()->timestamp + 120, 120);
                $this->warn('[SIEM] Semua fetch dijeda selama 120 detik agar OpenSearch dapat pulih.');
            }
            $this->error('[SIEM] Fetch gagal: ' . $message);
            $this->warn("[SIEM] Pemeriksaan belum selesai. Diproses: {$retrievedCount}, "
                . ($isDryRun ? 'Would import' : 'New') . ": {$newCount}, Skipped: {$skippedCount}. Jalankan fetch kembali untuk memeriksa ulang; alert yang sudah tersimpan akan dilewati.");
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
            $period = $todayOnly ? ' hari ini (Asia/Jakarta)' : '';
            $this->warn("[SIEM] Tidak ada alert{$period} yang cocok dengan filter level >= {$minLevel} pada indeks yang dikonfigurasi.");
        } elseif ($newCount === 0) {
            $this->info("[SIEM] Semua {$retrievedCount} alert yang diperiksa sudah tersimpan. Tidak ada alert baru untuk diimpor.");
        }

        $summary = $isDryRun ? 'Dry run. Would import' : 'Done. New';
        $this->info("[SIEM] {$summary}: {$newCount}, Skipped (already exists): {$skippedCount}");
        Log::info('[SIEM] FetchWazuhAlerts completed', [
            'all_pages' => $fetchAll,
            'today_only' => $todayOnly,
            'time_range' => $timeRange,
            'dry_run' => $isDryRun,
            'min_level' => $minLevel,
            'retrieved' => $retrievedCount,
            'new' => $newCount,
            'skipped' => $skippedCount,
        ]);

        return self::SUCCESS;
    }
}
