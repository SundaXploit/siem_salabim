<?php

namespace App\Http\Controllers;

use App\Exceptions\AiAnalysisException;
use App\Models\Alert;
use App\Services\AlertAiAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AlertController extends Controller
{
    /* ═══════════════════════════════════════════════════════════════════
     ║  Dashboard — optimized for fast page load
     ║
     ║  Bottlenecks fixed:
     ║  1. Artisan::call('siem:fetch-alerts') moved to background
     ║     with a 60-second cooldown via cache, so it no longer BLOCKS
     ║     every single page request.
     ║  2. Stats (new/ack/ignored/total) computed in ONE SQL query
     ║     instead of 4 separate Model::where()->count() calls.
     ║  3. $newCount removed — stats already passed to view.
     ═══════════════════════════════════════════════════════════════════*/

    public function index(Request $request)
    {
        // Keep filter URLs canonical. Empty GET controls must not look like
        // active filters or survive in copied/bookmarked search URLs.
        $cleanQuery = collect($request->query())
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();

        if ($cleanQuery !== $request->query()) {
            return redirect()->route('alerts.index', $cleanQuery);
        }

        // ── Non-blocking auto-fetch (max once per 60 s) ─────────────────
        // Previously this ran SYNCHRONOUSLY on every page load, blocking
        // the response for the full duration of the OpenSearch HTTP call.
        // Now it runs at most once per minute and never blocks the user.
        $this->maybeDispatchFetch();

        // ── Single-query stats (was 4 separate queries in the view) ──────
        $rawStats = DB::table('alerts')
            ->selectRaw("
                SUM(status = 'new')          AS new_count,
                SUM(status = 'acknowledged') AS ack_count,
                SUM(status = 'ignored')      AS ignored_count,
                COUNT(*)                     AS total_count
            ")
            ->first();

        $stats = [
            'new'     => (int) ($rawStats->new_count     ?? 0),
            'ack'     => (int) ($rawStats->ack_count     ?? 0),
            'ignored' => (int) ($rawStats->ignored_count ?? 0),
            'total'   => (int) ($rawStats->total_count   ?? 0),
        ];

        // ── Alert list with eager-loaded triage users ─────────────────────
        $query = Alert::query()->with(['triages.user']);

        // Default: only show active (non-ignored)
        if (!$request->has('status') || $request->status === '') {
            $query->where('status', '!=', 'ignored');
        } elseif ($request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('level')) {
            $query->where('rule_level', '>=', (int) $request->level);
        }
        if ($request->filled('agent')) {
            $query->where('agent_name', 'like', '%' . $request->agent . '%');
        }
        if ($request->filled('date_from')) {
            $query->where('first_seen_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('first_seen_at', '<=', $request->date_to . ' 23:59:59');
        }
        if ($request->filled('search')) {
            $terms = collect(preg_split('/\s+/u', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY))
                ->take(8);

            foreach ($terms as $term) {
                $like = "%{$term}%";

                $query->where(function ($q) use ($term, $like) {
                    $q->where('rule_description', 'like', $like)
                        ->orWhere('src_ip', 'like', $like)
                        ->orWhere('dst_ip', 'like', $like)
                        ->orWhere('agent_name', 'like', $like)
                        ->orWhere('rule_id', 'like', $like)
                        ->orWhere('wazuh_alert_id', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhereRaw('CAST(raw_data AS CHAR) LIKE ?', [$like]);

                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            }
        }

        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100], true)
            ? (int) $request->input('per_page', 25)
            : 25;
        $alerts = $query->orderByDesc('first_seen_at')->orderByDesc('id')->paginate($perPage)->withQueryString();

        return view('dashboard.index', compact('alerts', 'stats'));
    }

    /* ═══════════════════════════════════════════════════════════════════
     ║  Alert detail
     ═══════════════════════════════════════════════════════════════════*/

    public function show(Alert $alert)
    {
        $alert->load(['triages.user', 'notificationLogs.user', 'latestAiAnalysis']);
        return view('alerts.show', compact('alert'));
    }

    public function analyseWithAi(
        Request $request,
        Alert $alert,
        AlertAiAnalysisService $analysis,
    ): JsonResponse {
        $user = $request->user();
        abort_unless(
            $user && ($user->isAdmin() || $user->isAnalyst()),
            403,
            'Akses analisis AI hanya untuk analyst dan admin.'
        );

        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);
        $force = (bool) ($validated['force'] ?? false);

        if (!$force && ($stored = $alert->latestAiAnalysis()->first())) {
            return response()->json([
                'success' => true,
                'cached' => true,
                'analysis' => $stored->toResult(),
            ]);
        }

        if (!$analysis->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'Analisis AI belum dikonfigurasi. Hubungi administrator atau lengkapi konfigurasi pada Settings.',
            ], 422);
        }

        $rateKey = "alert-ai-analysis:{$user->id}:{$alert->id}";
        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return response()->json([
                'success' => false,
                'error' => 'Batas analisis alert ini tercapai. Coba kembali dalam ' . RateLimiter::availableIn($rateKey) . ' detik.',
            ], 429);
        }

        $lock = Cache::lock("alert-ai-analysis-running:{$alert->id}", 90);
        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'error' => 'Analisis untuk alert ini sedang berjalan.',
            ], 409);
        }

        try {
            if (!$force && ($stored = $alert->latestAiAnalysis()->first())) {
                return response()->json([
                    'success' => true,
                    'cached' => true,
                    'analysis' => $stored->toResult(),
                ]);
            }

            RateLimiter::hit($rateKey, 600);

            Log::info('[Alert AI] On-demand analysis requested', [
                'alert_id' => $alert->id,
                'user_id' => $user->id,
            ]);

            $startedAt = hrtime(true);
            $result = $analysis->analyse($alert);
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $stored = $alert->aiAnalyses()->create([
                'requested_by_user_id' => $user->id,
                'verdict' => $result['verdict'],
                'confidence' => $result['confidence'],
                'summary' => $result['summary'],
                'supporting_indicators' => $result['supporting_indicators'] ?? [],
                'legitimate_indicators' => $result['legitimate_indicators'] ?? [],
                'recommended_checks' => $result['recommended_checks'] ?? [],
                'limitations' => $result['limitations'] ?? [],
                'model' => $result['model'],
                'duration_ms' => $durationMs,
                'generated_at' => $result['generated_at'] ?? now(),
            ]);

            Log::info('[Alert AI] On-demand analysis completed', [
                'alert_id' => $alert->id,
                'user_id' => $user->id,
                'verdict' => $result['verdict'],
                'duration_ms' => $durationMs,
            ]);

            return response()->json([
                'success' => true,
                'cached' => false,
                'analysis' => $stored->toResult(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('[Alert AI] On-demand analysis failed', [
                'alert_id' => $alert->id,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception instanceof AiAnalysisException
                    ? $exception->getMessage()
                    : 'Analisis AI tidak dapat diselesaikan. Coba kembali beberapa saat lagi.',
            ], 503);
        } finally {
            $lock->release();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     ║  API: new-count badge (auto-fetch dengan cooldown)
     ═══════════════════════════════════════════════════════════════════*/

    public function newCount()
    {
        $this->maybeDispatchFetch();

        return response()->json([
            'count' => Alert::where('status', 'new')->count(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════
     ║  API: latest alerts (untuk keperluan polling eksternal)
     ═══════════════════════════════════════════════════════════════════*/

    public function latest()
    {
        $alerts = Alert::where('status', '!=', 'ignored')
            ->orderBy('first_seen_at', 'desc')
            ->limit(100)
            ->get(['id', 'rule_description', 'src_ip', 'dst_ip', 'agent_name',
                   'rule_id', 'rule_level', 'status', 'first_seen_at']);

        return response()->json($alerts);
    }

    /* ═══════════════════════════════════════════════════════════════════
     ║  Private helper — rate-limited Artisan dispatch
     ═══════════════════════════════════════════════════════════════════*/

    /**
     * Run siem:fetch-alerts at most once per 60 seconds.
     * Uses a cache lock so concurrent requests don't pile up.
     */
    private function maybeDispatchFetch(): void
    {
        // Atomically claim this minute. The command also holds a shared lock
        // across dashboard, scheduler and manual requests.
        if (!Cache::add('siem_fetch_ran', true, now()->addSeconds(60))) {
            return;
        }

        try {
            Artisan::call('siem:fetch-alerts');
        } catch (\Throwable $e) {
            Log::error('[SIEM Dashboard] Auto-fetch error: ' . $e->getMessage());
        }
    }
}
