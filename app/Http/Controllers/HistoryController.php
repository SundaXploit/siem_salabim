<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\NotificationLog;
use App\Services\AlertDateFilter;
use App\Services\EncryptedSearchIndex;
use App\Services\SensitiveDataCipher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HistoryController extends Controller
{
    public function alerts(Request $request)
    {
        $query = Alert::visibleTo($request->user())->select(Alert::LIST_COLUMNS);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('level')) {
            $query->where('rule_level', '>=', (int) $request->level);
        }

        AlertDateFilter::apply($query, $request);

        if ($request->filled('search')) {
            $query->searchTerms((string) $request->search);
        }

        $alerts = $query->orderBy('first_seen_at', 'desc')->paginate(25)->withQueryString();

        return view('history.alerts', compact('alerts'));
    }

    public function analystLog(Request $request)
    {
        $filters = $this->filters($request, ['notify', 'acknowledge', 'ignore']);
        $notifications = DB::table('notification_logs')->select([
            'id', 'alert_id', 'user_id', DB::raw("'notify' as action"), 'message as note',
            'chat_id', 'response_status', 'sent_at as occurred_at', DB::raw("'notification' as entry_type"), 'automatic_key', 'delivery_state',
        ]);
        $triages = DB::table('alert_triages')->whereIn('action', ['acknowledge', 'ignore'])->select([
            'id', 'alert_id', 'user_id', 'action', 'reason as note',
            DB::raw('NULL as chat_id'), DB::raw('NULL as response_status'),
            'created_at as occurred_at', DB::raw("'triage' as entry_type"),
            DB::raw('NULL as automatic_key'), DB::raw('NULL as delivery_state'),
        ]);
        // Notification deliveries already have their own records. Excluding
        // triage's "notify" avoids duplicating each Telegram delivery.
        $query = DB::query()->fromSub($notifications->unionAll($triages), 'entries')
            ->leftJoin('users', 'users.id', '=', 'entries.user_id')
            ->leftJoin('alerts', 'alerts.id', '=', 'entries.alert_id')
            ->select('entries.*', DB::raw("CASE WHEN entries.automatic_key IS NOT NULL THEN 'Sistem (otomatis)' ELSE users.name END as analyst_name"), 'alerts.id as existing_alert_id', 'alerts.rule_description', 'alerts.agent_name');
        if (! $request->user()->isAdmin()) {
            $query->whereIn('entries.alert_id', Alert::visibleTo($request->user())->select('alerts.id'));
        }
        if (filled($filters['search'] ?? null)) {
            foreach (array_slice(preg_split('/\s+/u', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8) as $term) {
                $like = '%'.$term.'%';
                $query->where(function ($query) use ($like, $term) {
                    foreach (['users.name', 'alerts.rule_description', 'alerts.agent_name', 'entries.chat_id'] as $field) {
                        $query->orWhere($field, 'like', $like);
                    }
                    $query->orWhere(fn ($notes) => $notes->where('entries.entry_type', 'notification')->whereExists(
                        fn ($tokens) => EncryptedSearchIndex::match($tokens, 'notification', 'entries.id', $term)))
                        ->orWhere(fn ($notes) => $notes->where('entries.entry_type', 'triage')->whereExists(
                            fn ($tokens) => EncryptedSearchIndex::match($tokens, 'triage', 'entries.id', $term)));
                    if (config('data_security.allow_legacy_plaintext', true)) {
                        $query->orWhere(fn ($legacy) => $legacy->where('entries.note', 'not like', SensitiveDataCipher::PREFIX.'%')->where('entries.note', 'like', $like));
                    }
                    $query->orWhereRaw("CASE WHEN entries.automatic_key IS NOT NULL THEN 'Sistem (otomatis)' ELSE '' END LIKE ?", [$like]);
                });
            }
        }
        $this->applyFilters($query, $filters, 'entries.action', 'entries.occurred_at');
        $logs = $query->orderByDesc('entries.occurred_at')->orderBy('entries.entry_type')->orderByDesc('entries.id')
            ->paginate(25)->withQueryString();
        $deliverySnapshots = NotificationLog::whereIn('id', collect($logs->items())->where('entry_type', 'notification')->pluck('id'))
            ->get(['id', 'message', 'delivery_details'])->keyBy('id');
        $logs->through(function ($row) use ($request, $deliverySnapshots) {
            $row->note = $row->entry_type === 'notification'
                ? $deliverySnapshots[$row->id]->visibleMessage($request->user())
                : SensitiveDataCipher::decryptString($row->note);

            return $row;
        });

        return response()->view('history.analyst-log', compact('logs'))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function activity(Request $request)
    {
        $filters = $this->filters($request, array_keys(ActivityLog::ACTIONS));
        $query = ActivityLog::query();
        if (filled($filters['search'] ?? null)) {
            $like = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query->where('actor_name', 'like', $like)
                ->orWhere('subject', 'like', $like)->orWhere('ip_address', 'like', $like));
        }
        $this->applyFilters($query, $filters, 'action', 'created_at');
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $actions = ActivityLog::ACTIONS;

        return response()->view('history.activity', compact('logs', 'actions'))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    private function filters(Request $request, array $actions): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'action' => ['nullable', Rule::in(['all', ...$actions])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
        ]);
    }

    private function applyFilters($query, array $filters, string $actionField, string $dateField): void
    {
        if (filled($filters['action'] ?? null) && $filters['action'] !== 'all') {
            $query->where($actionField, $filters['action']);
        }
        if (filled($filters['date_from'] ?? null)) {
            $from = CarbonImmutable::parse($filters['date_from'], 'Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone'));
            $query->where($dateField, '>=', $from->format('Y-m-d H:i:s'));
        }
        if (filled($filters['date_to'] ?? null)) {
            $until = CarbonImmutable::parse($filters['date_to'], 'Asia/Jakarta')->addDay()->startOfDay()->setTimezone(config('app.timezone'));
            $query->where($dateField, '<', $until->format('Y-m-d H:i:s'));
        }
    }
}
