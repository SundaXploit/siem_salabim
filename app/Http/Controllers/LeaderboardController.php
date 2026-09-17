<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    /* ═══════════════════════════════════════════════════════════════════
     ║  Page — pre-load default data (week) to avoid AJAX on first visit
     ═══════════════════════════════════════════════════════════════════*/

    public function index()
    {
        [$start, $end] = $this->resolvePeriod('week', null, null);

        return view('leaderboard.index', [
            'initialData' => $this->computeRankings('week', $start, $end),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════
     ║  API — only called when user changes period/date filter
     ═══════════════════════════════════════════════════════════════════*/

    public function data(Request $request): JsonResponse
    {
        $period   = $request->input('period', 'week');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        [$start, $end] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        return response()->json($this->computeRankings($period, $start, $end));
    }

    /* ═══════════════════════════════════════════════════════════════════
     ║  Core computation — shared by index() and data()
     ║
     ║  DB queries: only 2 total
     ║    Q1) notification_logs  GROUP BY user_id, DATE(sent_at)
     ║    Q2) alert_triages       GROUP BY user_id, action, DATE(created_at)
     ║
     ║  Sparklines reuse the same daily data — zero extra queries.
     ║  Badge maxes computed once (O(N)) instead of per-user (O(N²)).
     ═══════════════════════════════════════════════════════════════════*/

    private function computeRankings(string $period, Carbon $start, Carbon $end): array
    {
        $startDay = $start->copy()->startOfDay();
        $endDay   = $end->copy()->endOfDay();

        $users   = User::orderBy('name')->get()->keyBy('id');
        $userIds = $users->keys()->all();

        /* ── Q1: notification_logs — daily per user ─────────────────────────
         * Result shape after transform:
         *   Collection{ userId => Collection{'2026-04-11' => 5, ...} }
         */
        $notifByUserDay = DB::table('notification_logs')
            ->selectRaw('user_id, DATE(sent_at) as day, COUNT(*) as cnt')
            ->whereBetween('sent_at', [$startDay, $endDay])
            ->groupBy('user_id', DB::raw('DATE(sent_at)'))
            ->get()
            ->groupBy('user_id')
            ->map(fn($rows) => $rows->pluck('cnt', 'day'));

        /* ── Q2: alert_triages — daily per user per action ──────────────────
         * Result shape after transform:
         *   Collection{ userId => Collection[{action, day, cnt}, ...] }
         */
        $triageByUserDay = DB::table('alert_triages')
            ->selectRaw('user_id, action, DATE(created_at) as day, COUNT(*) as cnt')
            ->whereIn('action', ['ignore', 'acknowledge'])
            ->whereBetween('created_at', [$startDay, $endDay])
            ->groupBy('user_id', 'action', DB::raw('DATE(created_at)'))
            ->get()
            ->groupBy('user_id');

        /* ── Build per-analyst stats from daily data (no extra queries) ─────*/
        $analysts = [];
        foreach ($users as $uid => $user) {
            $notifDays   = $notifByUserDay->get($uid, collect());
            $triageRows  = $triageByUserDay->get($uid, collect());

            $notify = (int) $notifDays->sum();
            $ignore = (int) $triageRows->where('action', 'ignore')->sum('cnt');
            $ack    = (int) $triageRows->where('action', 'acknowledge')->sum('cnt');

            $analysts[$uid] = [
                'user_id'     => $uid,
                'name'        => $user->name,
                'role'        => $user->role,
                'initials'    => mb_strtoupper(mb_substr($user->name, 0, 1)),
                'notify'      => $notify,
                'ignore'      => $ignore,
                'acknowledge' => $ack,
                'total'       => $notify + $ignore + $ack,
                'badges'      => [],
            ];
        }

        /* ── Badge computation — maxes resolved once, O(N) not O(N²) ───────*/
        $maxTotal  = max(array_column($analysts, 'total')       ?: [0]);
        $maxNotify = max(array_column($analysts, 'notify')      ?: [0]);
        $maxIgnore = max(array_column($analysts, 'ignore')      ?: [0]);
        $maxAck    = max(array_column($analysts, 'acknowledge') ?: [0]);

        foreach ($analysts as $uid => &$row) {
            $row['badges'] = $this->computeBadges(
                $row, $maxTotal, $maxNotify, $maxIgnore, $maxAck
            );
        }
        unset($row);

        /* ── Sorted rankings ─────────────────────────────────────────────────*/
        $byTotal  = collect($analysts)->sortByDesc('total')->values();
        $byNotify = collect($analysts)->sortByDesc('notify')->values();
        $byIgnore = collect($analysts)->sortByDesc('ignore')->values();
        $byAck    = collect($analysts)->sortByDesc('acknowledge')->values();

        /* ── Sparklines — reuse existing daily data, zero extra DB hits ──────*/
        $sparklines = $this->buildSparklinesFromDaily(
            $userIds, $notifByUserDay, $triageByUserDay, $start, $end
        );

        foreach ($analysts as $uid => &$row) {
            $row['sparkline']        = $sparklines[$uid]       ?? array_fill(0, 7, 0);
            $row['sparkline_labels'] = $sparklines['__labels'] ?? [];
        }
        unset($row);

        $enrich = fn($sorted) => $sorted->map(fn($r) => $analysts[$r['user_id']])->values();

        return [
            'period_label' => $this->getPeriodLabel($period, $start, $end),
            'start'        => $start->toDateString(),
            'end'          => $end->toDateString(),
            'by_total'     => $enrich($byTotal),
            'by_notify'    => $enrich($byNotify),
            'by_ignore'    => $enrich($byIgnore),
            'by_ack'       => $enrich($byAck),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
     ║  Private helpers
     ═══════════════════════════════════════════════════════════════════*/

    private function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        $tz  = 'Asia/Jakarta';
        $now = Carbon::now($tz);

        return match ($period) {
            'today'  => [Carbon::today($tz),                $now],
            'week'   => [Carbon::now($tz)->startOfWeek(),   $now],
            'month'  => [Carbon::now($tz)->startOfMonth(),  $now],
            'year'   => [Carbon::now($tz)->startOfYear(),   $now],
            'custom' => [
                Carbon::parse($from ?? 'today', $tz)->startOfDay(),
                Carbon::parse($to ?? $from ?? 'today', $tz)->endOfDay(),
            ],
            default  => [Carbon::now($tz)->startOfWeek(), $now],
        };
    }

    private function getPeriodLabel(string $period, Carbon $start, Carbon $end): string
    {
        return match ($period) {
            'today'  => 'Hari Ini, ' . $start->locale('id')->isoFormat('D MMMM Y'),
            'week'   => 'Minggu Ini (' . $start->format('d M') . ' – ' . $end->format('d M Y') . ')',
            'month'  => 'Bulan ' . $start->locale('id')->isoFormat('MMMM Y'),
            'year'   => 'Tahun ' . $start->format('Y'),
            'custom' => $start->format('d M Y') . ' – ' . $end->format('d M Y'),
            default  => 'Minggu Ini',
        };
    }

    /**
     * All maxes are pre-computed outside the loop → O(N) instead of O(N²)
     */
    private function computeBadges(
        array $row,
        int   $maxTotal,
        int   $maxNotify,
        int   $maxIgnore,
        int   $maxAck
    ): array {
        $badges = [];

        if ($maxTotal  > 0 && $row['total']       === $maxTotal)  $badges[] = ['key' => 'most_active',   'icon' => '🔥', 'label' => 'Most Active',   'desc' => 'Analyst dengan total aksi terbanyak di periode ini!'];
        if ($maxNotify > 0 && $row['notify']       === $maxNotify) $badges[] = ['key' => 'top_notifier',  'icon' => '📤', 'label' => 'Top Notifier',  'desc' => 'Paling banyak mengirim notifikasi ke Telegram!'];
        if ($maxIgnore > 0 && $row['ignore']       === $maxIgnore) $badges[] = ['key' => 'filter_king',   'icon' => '🛡️', 'label' => 'Filter King',   'desc' => 'Paling cekatan memfilter alert yang tidak relevan!'];
        if ($maxAck    > 0 && $row['acknowledge']  === $maxAck)    $badges[] = ['key' => 'triage_master', 'icon' => '🎯', 'label' => 'Triage Master', 'desc' => 'Paling banyak melakukan acknowledge terhadap alert!'];

        if ($row['notify'] > 0 && $row['ignore'] > 0 && $row['acknowledge'] > 0) {
            $badges[] = ['key' => 'all_rounder', 'icon' => '💎', 'label' => 'All-Rounder', 'desc' => 'Aktif dan berkontribusi di semua kategori tindakan SOC!'];
        }

        return $badges;
    }

    /**
     * Build sparklines directly from already-fetched daily data.
     * Zero extra DB queries — reuses $notifByUserDay and $triageByUserDay.
     */
    private function buildSparklinesFromDaily(
        array      $userIds,
        $notifByUserDay,   // Collection{userId => Collection{day => cnt}}
        $triageByUserDay,  // Collection{userId => Collection[{action,day,cnt}]}
        Carbon     $start,
        Carbon     $end
    ): array {
        // Build 7 evenly-spaced date buckets
        $diffDays  = max(0, (int) $start->diffInDays($end));
        $numPoints = $diffDays === 0 ? 1 : min(7, $diffDays + 1);
        $labels    = [];
        $buckets   = [];

        if ($numPoints === 1) {
            $labels[]  = $start->format('d/m');
            $buckets[] = [$start->toDateString()];
        } else {
            $bucketSize = (int) ceil(($diffDays + 1) / $numPoints);
            $cursor     = $start->copy()->startOfDay();

            for ($i = 0; $i < $numPoints && $cursor->lte($end); $i++) {
                $bucketEnd = $cursor->copy()->addDays($bucketSize - 1);
                if ($bucketEnd->gt($end)) $bucketEnd = $end->copy();

                $dates = [];
                for ($d = $cursor->copy(); $d->lte($bucketEnd); $d->addDay()) {
                    $dates[] = $d->toDateString();
                }

                $labels[]  = $cursor->format('d/m');
                $buckets[] = $dates;
                $cursor->addDays($bucketSize);
            }
        }

        while (count($labels)  < 7) $labels[]  = '';
        while (count($buckets) < 7) $buckets[] = [];

        $result = ['__labels' => $labels];

        foreach ($userIds as $uid) {
            $uNotif = $notifByUserDay->get($uid, collect()); // {day => cnt}

            // Collapse triage rows to {day => totalCnt} (ignore + ack combined)
            $uTriage = $triageByUserDay->get($uid, collect())
                ->groupBy('day')
                ->map(fn($rows) => $rows->sum('cnt'));

            $points = [];
            foreach ($buckets as $dates) {
                $sum = 0;
                foreach ($dates as $d) {
                    $sum += (int) ($uNotif[$d] ?? 0) + (int) ($uTriage[$d] ?? 0);
                }
                $points[] = $sum;
            }

            $result[$uid] = $points;
        }

        return $result;
    }
}
