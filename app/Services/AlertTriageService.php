<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AlertTriageService
{
    public const MAX_BATCH_SIZE = 100;

    public function apply(array $ids, string $action, int $userId, ?string $reason = null): array
    {
        $actor = User::findOrFail($userId);
        Gate::forUser($actor)->authorize('alerts.'.$action);
        $ownedIds = [];
        $changed = DB::transaction(function () use ($ids, $action, $userId, $reason, &$actor, &$ownedIds) {
            $actor = User::lockForAction($userId);
            Gate::forUser($actor)->authorize('alerts.'.$action);
            // Lock in a consistent order so concurrent triage cannot duplicate the history.
            $alerts = Alert::visibleTo($actor)->whereIn('id', $ids)->orderBy('id')
                ->lockForUpdate()->get(['id', 'status', 'first_seen_at', 'acknowledged_by_user_id']);

            if ($alerts->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'alert_ids' => 'Sebagian alert sudah tidak tersedia. Refresh daftar dan pilih kembali.',
                ]);
            }

            if ($action === 'ignore' && $alerts->contains(fn (Alert $alert) => ! $alert->isAcknowledgedBy($actor)
                && ! ($alert->status === 'ignored' && (int) $alert->acknowledged_by_user_id === $userId))) {
                throw ValidationException::withMessages(['alert_ids' => 'Ignore hanya dapat dilakukan pemilik ACK. ACK alert terlebih dahulu; alert milik pengguna lain tidak dapat diabaikan.']);
            }
            $eligible = $alerts->filter(fn (Alert $alert) => $action === 'acknowledge'
                ? ($alert->status === 'new' || ($alert->status === 'acknowledged' && $alert->acknowledged_by_user_id === null))
                : $alert->isAcknowledgedBy($actor));

            $changed = $eligible->filter(function (Alert $alert) use ($action, $userId): bool {
                $query = Alert::whereKey($alert->id)->where('status', $alert->status);
                $query = $action === 'acknowledge'
                    ? $query->whereNull('acknowledged_by_user_id')
                    : $query->where('acknowledged_by_user_id', $userId);
                $values = ['status' => $action === 'acknowledge' ? 'acknowledged' : 'ignored'];
                if ($action === 'acknowledge') {
                    $values += ['acknowledged_by_user_id' => $userId, 'acknowledged_at' => now()];
                }

                return $query->update($values) === 1;
            });
            $now = now();
            if ($changed->isNotEmpty()) {
                $rows = $changed->map(fn (Alert $alert) => [
                    'alert_id' => $alert->id,
                    'user_id' => $userId,
                    'action' => $action,
                    'reason' => $action === 'ignore' ? $reason : null,
                    'created_at' => $now,
                ])->values()->all();
                if ($action === 'acknowledge') {
                    // ACK has no sensitive note and keeps the existing bulk INSERT.
                    AlertTriage::insert($rows);
                } else {
                    foreach ($rows as $row) {
                        AlertTriage::create($row);
                    }
                }
            }

            $ownedIds = Alert::whereIn('id', $ids)->where('status', 'acknowledged')
                ->where('acknowledged_by_user_id', $userId)->orderBy('id')->pluck('id')->all();

            return $changed;
        }, 3);

        if ($changed->isNotEmpty()) {
            $periods = $changed->map(fn (Alert $alert) => $alert->first_seen_at?->copy()->setTimezone(config('app.timezone'))->format('Y-m')
            )->push(now(config('app.timezone'))->format('Y-m'))->filter()->unique();
            foreach ($periods as $period) {
                Cache::forget('security-insight-snapshot:v3:'.$period);
                Cache::forget('security-insight-snapshot:v3:'.$period.':'.$actor->alertAccessFingerprint());
            }
        }

        return ['processed' => $changed->count(), 'skipped' => count($ids) - $changed->count(),
            'processed_ids' => $changed->modelKeys(), 'owned_ids' => $ownedIds];
    }
}
