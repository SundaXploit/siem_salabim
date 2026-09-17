<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertTriage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AlertTriageService
{
    public const MAX_BATCH_SIZE = 100;

    public function apply(array $ids, string $action, int $userId, ?string $reason = null): array
    {
        $changed = DB::transaction(function () use ($ids, $action, $userId, $reason) {
            // Lock in a consistent order so concurrent triage cannot duplicate the history.
            $alerts = Alert::query()->whereIn('id', $ids)->orderBy('id')
                ->lockForUpdate()->get(['id', 'status', 'first_seen_at']);

            if ($alerts->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'alert_ids' => 'Sebagian alert sudah tidak tersedia. Refresh daftar dan pilih kembali.',
                ]);
            }

            $eligible = $alerts->filter(fn (Alert $alert) => $action === 'acknowledge'
                ? $alert->status === 'new'
                : in_array($alert->status, ['new', 'acknowledged'], true));

            if ($eligible->isEmpty()) {
                return $eligible;
            }

            Alert::whereIn('id', $eligible->modelKeys())->update([
                'status' => $action === 'acknowledge' ? 'acknowledged' : 'ignored',
            ]);
            $now = now();
            AlertTriage::insert($eligible->map(fn (Alert $alert) => [
                'alert_id' => $alert->id,
                'user_id' => $userId,
                'action' => $action,
                'reason' => $action === 'ignore' ? $reason : null,
                'created_at' => $now,
            ])->values()->all());

            return $eligible;
        }, 3);

        if ($changed->isNotEmpty()) {
            $periods = $changed->map(fn (Alert $alert) =>
                $alert->first_seen_at?->copy()->setTimezone('Asia/Jakarta')->format('Y-m')
            )->push(now('Asia/Jakarta')->format('Y-m'))->filter()->unique();
            foreach ($periods as $period) {
                Cache::forget('security-insight-snapshot:v2:' . $period);
            }
        }

        return ['processed' => $changed->count(), 'skipped' => count($ids) - $changed->count()];
    }
}
