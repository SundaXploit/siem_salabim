<?php

namespace App\Jobs;

use App\Models\DashboardInsight;
use App\Services\AmanaiInsightService;
use App\Services\SecurityInsightSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateDashboardInsight implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 2;
    public int $backoff = 60;
    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $periodKey,
        public readonly string $trigger = 'scheduled',
        public readonly ?int $requestedByUserId = null,
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'dashboard-insight:' . $this->periodKey;
    }

    public function handle(
        SecurityInsightSnapshotService $snapshots,
        AmanaiInsightService $amanai,
    ): void {
        if (!$amanai->isConfigured()) {
            Log::notice('[Dashboard Insight] Skipped because AmanAI is not configured.');
            return;
        }

        $lock = Cache::lock('dashboard-insight:running:' . $this->periodKey, 240);
        if (!$lock->get()) {
            return;
        }

        $snapshot = null;

        try {
            $period = $snapshots->periodFromKey($this->periodKey);
            $snapshot = $snapshots->build($period);
            $input = $snapshot['analysis_input'];

            $summary = $input['metrics']['total'] === 0
                ? 'Belum ada alert yang diimpor untuk periode ini. Kesimpulan tren akan tersedia setelah data alert masuk ke SIEM Salabim.'
                : $amanai->generate($input);

            DashboardInsight::create([
                'period_key'            => $period['key'],
                'period_start'          => $period['start_local']->toDateString(),
                'period_end'            => $period['end_local']->copy()->subDay()->toDateString(),
                'status'                => 'completed',
                'summary'               => $summary,
                'source_snapshot'       => $input,
                'source_fingerprint'    => $this->fingerprint($input),
                'provider'              => 'amanai',
                'model'                 => $amanai->modelName(),
                'trigger'               => $this->trigger,
                'requested_by_user_id'  => $this->requestedByUserId,
                'generated_at'          => now(),
                'next_refresh_at'       => now()->addDays($amanai->refreshDays()),
            ]);
        } catch (\Throwable $exception) {
            Log::error('[Dashboard Insight] Generation failed', [
                'period' => $this->periodKey,
                'exception_class' => $exception::class,
            ]);

            // Persist a generic, user-safe state only once retries are exhausted.
            // The exception is still re-thrown below so the queue can retry and
            // Laravel records a final operational failure in failed_jobs.
            if ($snapshot !== null && $this->attempts() >= $this->tries) {
                DashboardInsight::create([
                    'period_key'            => $this->periodKey,
                    'period_start'          => $snapshot['period']['start_local']->toDateString(),
                    'period_end'            => $snapshot['period']['end_local']->copy()->subDay()->toDateString(),
                    'status'                => 'failed',
                    'source_snapshot'       => $snapshot['analysis_input'],
                    'source_fingerprint'    => $this->fingerprint($snapshot['analysis_input']),
                    'provider'              => 'amanai',
                    'model'                 => $amanai->modelName(),
                    'trigger'               => $this->trigger,
                    'requested_by_user_id'  => $this->requestedByUserId,
                    'generated_at'          => now(),
                    'error_message'         => 'Permintaan analisis AI gagal. Coba ulangi beberapa saat lagi.',
                ]);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function fingerprint(array $input): string
    {
        return hash('sha256', json_encode(
            $input,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
