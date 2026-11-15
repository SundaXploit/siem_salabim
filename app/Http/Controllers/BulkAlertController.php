<?php

namespace App\Http\Controllers;

use App\Exceptions\AiAnalysisException;
use App\Models\Alert;
use App\Services\AlertAiAnalysisService;
use App\Services\AlertTriageService;
use App\Services\SensitiveDataCipher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class BulkAlertController extends Controller
{
    public function show(Request $request)
    {
        $alerts = $this->selection($request);
        $storedAnalysis = $this->cachedAnalysis($this->analysisKey($request, $alerts));

        return response()->view('alerts.bulk', compact('alerts', 'storedAnalysis'))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function analyse(Request $request, AlertAiAnalysisService $analysis)
    {
        $alerts = $this->selection($request);
        $data = $request->validate(['force' => ['sometimes', 'boolean']]);
        $key = $this->analysisKey($request, $alerts);
        $force = (bool) ($data['force'] ?? false);
        if (! $force && ($stored = $this->cachedAnalysis($key))) {
            return response()->json(['success' => true, 'cached' => true, 'analysis' => $stored]);
        }
        if (! $analysis->isConfigured()) {
            return response()->json(['success' => false, 'error' => 'Analisis AI belum dikonfigurasi. Lengkapi konfigurasi AI pada Settings.'], 422);
        }
        $rateKey = 'bulk-alert-ai:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return response()->json(['success' => false, 'error' => 'Batas analisis massal tercapai. Coba kembali dalam '.RateLimiter::availableIn($rateKey).' detik.'], 429);
        }
        $lock = Cache::lock($key.':running', 195);
        if (! $lock->get()) {
            return response()->json(['success' => false, 'error' => 'Analisis untuk pilihan alert ini sedang berjalan.'], 409);
        }
        try {
            if (! $force && ($stored = $this->cachedAnalysis($key))) {
                return response()->json(['success' => true, 'cached' => true, 'analysis' => $stored]);
            }
            RateLimiter::hit($rateKey, 600);
            $startedAt = hrtime(true);
            $result = $analysis->analyseBatch($alerts);
            $result['duration_ms'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            Cache::put($key, SensitiveDataCipher::encryptJson($result), now()->addDay());
            Log::info('[Bulk alert AI] Analysis completed', [
                'user_id' => $request->user()->id, 'alert_ids' => $alerts->modelKeys(),
                'verdict' => $result['verdict'], 'duration_ms' => $result['duration_ms'],
            ]);

            return response()->json(['success' => true, 'cached' => false, 'analysis' => $result]);
        } catch (\Throwable $exception) {
            Log::warning('[Bulk alert AI] Analysis failed', ['user_id' => $request->user()->id, 'exception_class' => $exception::class]);

            return response()->json([
                'success' => false,
                'error' => $exception instanceof AiAnalysisException ? $exception->getMessage() : 'Analisis massal belum dapat diselesaikan. Coba kembali beberapa saat lagi.',
            ], 503);
        } finally {
            $lock->release();
        }
    }

    private function selection(Request $request): Collection
    {
        abort_unless($request->user() && ($request->user()->isAdmin() || $request->user()->isAnalyst()), 403);
        $data = $request->validate([
            'alert_ids' => ['required', 'array', 'min:1', 'max:'.AlertTriageService::MAX_BATCH_SIZE],
            'alert_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $alerts = Alert::visibleTo($request->user())->with('acknowledgedBy')->whereIn('id', $data['alert_ids'])->orderBy('first_seen_at')->orderBy('id')->get();
        if ($alerts->count() !== count($data['alert_ids'])) {
            throw ValidationException::withMessages(['alert_ids' => 'Sebagian alert tidak tersedia atau tidak termasuk akses Anda. Kembali ke Live Alerts dan pilih ulang.']);
        }

        return $alerts;
    }

    private function analysisKey(Request $request, Collection $alerts): string
    {
        // Status changes from triage do not change the evidence being analysed.
        $evidence = $alerts->sortBy('id')->map(fn (Alert $alert) => $alert->only([
            'id', 'source_identity_hash', 'rule_id', 'rule_level', 'rule_description',
            'agent_name', 'src_ip', 'dst_ip', 'first_seen_at', 'raw_data',
        ]))->values()->all();

        return 'bulk-alert-ai:v1:'.$request->user()->id.':'.hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    private function cachedAnalysis(string $key): ?array
    {
        $value = Cache::get($key);
        if ($value === null) {
            return null;
        }
        if (is_array($value) && config('data_security.allow_legacy_plaintext', true)) {
            return $value;
        }

        return SensitiveDataCipher::decryptJson($value);
    }
}
