<?php

namespace App\Http\Controllers;

use App\Exceptions\AiAnalysisException;
use App\Models\DashboardInsight;
use App\Services\AiInsightService;
use App\Services\SecurityInsightSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function index(SecurityInsightSnapshotService $snapshots, AiInsightService $ai): View
    {
        $period = $snapshots->currentPeriod();
        $viewer = auth()->user();
        $dashboard = $snapshots->build($period, viewer: $viewer);

        $insight = DashboardInsight::query()
            ->where('access_scope', $viewer->alertAccessFingerprint())
            ->completed()
            ->where('period_key', $period['key'])
            ->latest('generated_at')
            ->limit(20)
            ->get()
            ->first(fn (DashboardInsight $candidate): bool => $ai->isMeaningfulSummary($candidate->summary)
                && ($dashboard['metrics']['total'] === 0
                    || (int) data_get($candidate->source_snapshot, 'metrics.total', 0) > 0)
            );

        $insightSummary = $insight
            ? $ai->normaliseSummary($insight->summary)
            : null;

        $latestAttempt = DashboardInsight::query()
            ->where('access_scope', $viewer->alertAccessFingerprint())
            ->where('period_key', $period['key'])
            ->latest('generated_at')
            ->first();

        $canRefreshInsight = $viewer->hasPrivilege('alerts.analyze');

        return view('dashboard.overview', [
            'dashboard' => $dashboard,
            'insight' => $insight,
            'insightSummary' => $insightSummary,
            'latestAttempt' => $latestAttempt,
            'aiConfigured' => $ai->isConfigured(),
            'canRefreshInsight' => $canRefreshInsight,
        ]);
    }

    public function refreshInsight(
        Request $request,
        SecurityInsightSnapshotService $snapshots,
        AiInsightService $ai,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $user->isAnalyst()), 403, 'Akses analisis AI hanya untuk analyst dan admin.');

        if (! $ai->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'Analisis AI belum dikonfigurasi. Administrator dapat melengkapinya melalui menu Settings.',
            ], 422);
        }

        $period = $snapshots->currentPeriod();
        $accessScope = $user->alertAccessFingerprint();
        $rateKey = 'dashboard-insight-manual:'.$user->id.':'.$period['key'];

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'success' => false,
                'error' => "Batas analisis ulang tercapai. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        RateLimiter::hit($rateKey, 600);
        $lock = Cache::lock('dashboard-insight:running:'.$period['key'].($accessScope === 'all' ? '' : ':'.$accessScope), 240);

        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'error' => 'Analisis untuk periode ini sedang berjalan. Tunggu hingga proses sebelumnya selesai.',
            ], 409);
        }

        $executionLimit = ini_get('max_execution_time');
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }

            $snapshot = $snapshots->build($period, fresh: true, viewer: $user);
            $input = $snapshot['analysis_input'];
            $summary = $input['metrics']['total'] === 0
                ? 'Belum ada alert yang diimpor untuk periode ini. Kesimpulan tren akan tersedia setelah data alert masuk ke SIEM Salabim.'
                : $ai->generate($input);

            $insight = DashboardInsight::create([
                'access_scope' => $accessScope,
                'period_key' => $period['key'],
                'period_start' => $period['start_local']->toDateString(),
                'period_end' => $period['end_local']->copy()->subDay()->toDateString(),
                'status' => 'completed',
                'summary' => $summary,
                'source_snapshot' => $input,
                'source_fingerprint' => hash('sha256', json_encode(
                    $input,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )),
                'provider' => $ai->providerName(),
                'model' => $ai->modelName(),
                'trigger' => 'manual',
                'requested_by_user_id' => $user->id,
                'generated_at' => now(),
                'next_refresh_at' => now()->addDays($ai->refreshDays()),
            ]);

            $generatedAt = $insight->generated_at
                ->copy()
                ->setTimezone('Asia/Jakarta')
                ->format('d M Y, H:i').' WIB';

            return response()->json([
                'success' => true,
                'message' => 'Kesimpulan AI berhasil diperbarui.',
                'insight' => [
                    'summary' => $ai->normaliseSummary($insight->summary),
                    'generated_at' => $generatedAt,
                    'model' => $insight->model,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('[Dashboard Insight] Direct generation failed', [
                'period' => $period['key'],
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception instanceof AiAnalysisException
                    ? $exception->getMessage()
                    : 'Analisis AI tidak dapat diselesaikan. Coba kembali beberapa saat lagi.',
            ], 502);
        } finally {
            if ($executionLimit !== false && function_exists('set_time_limit')) {
                @set_time_limit((int) $executionLimit);
            }
            $lock->release();
        }
    }
}
