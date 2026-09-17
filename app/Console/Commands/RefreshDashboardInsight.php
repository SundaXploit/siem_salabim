<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDashboardInsight;
use App\Models\DashboardInsight;
use App\Services\AmanaiInsightService;
use App\Services\SecurityInsightSnapshotService;
use Illuminate\Console\Command;

class RefreshDashboardInsight extends Command
{
    protected $signature = 'siem:refresh-dashboard-insight
        {--force : Buat insight baru meskipun insight terakhir masih segar}
        {--period= : Periode target dalam format YYYY-MM}';

    protected $description = 'Queue analisis AI dashboard bila insight bulanan belum ada atau sudah melewati masa refresh';

    public function handle(
        SecurityInsightSnapshotService $snapshots,
        AmanaiInsightService $amanai,
    ): int {
        if (!$amanai->isConfigured()) {
            $this->warn('AmanAI belum dikonfigurasi. Tidak ada job yang dibuat.');
            return self::SUCCESS;
        }

        try {
            $period = $this->option('period')
                ? $snapshots->periodFromKey((string) $this->option('period'))
                : $snapshots->currentPeriod();
        } catch (\Throwable $exception) {
            $this->error('Periode tidak valid. Gunakan format YYYY-MM.');
            return self::FAILURE;
        }

        $latest = DashboardInsight::query()
            ->completed()
            ->where('period_key', $period['key'])
            ->latest('generated_at')
            ->first();

        $refreshDays = $amanai->refreshDays();
        if (!$this->option('force') && $latest?->generated_at?->greaterThan(now()->subDays($refreshDays))) {
            $this->line("Insight {$period['key']} masih segar; tidak dijadwalkan ulang.");
            return self::SUCCESS;
        }

        GenerateDashboardInsight::dispatch($period['key'], 'scheduled');
        $this->info("Insight {$period['key']} telah dimasukkan ke antrean AI.");

        return self::SUCCESS;
    }
}
