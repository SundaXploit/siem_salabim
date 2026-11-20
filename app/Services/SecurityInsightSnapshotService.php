<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SecurityInsightSnapshotService
{
    public function currentPeriod(): array
    {
        return $this->periodFromKey(now(config('app.timezone'))->format('Y-m'));
    }

    public function periodFromKey(string $periodKey): array
    {
        if (! preg_match('/^\\d{4}-\\d{2}$/', $periodKey)) {
            throw new InvalidArgumentException('Format periode harus YYYY-MM.');
        }

        $startLocal = Carbon::createFromFormat('!Y-m', $periodKey, config('app.timezone'))->startOfMonth();
        if ($startLocal->format('Y-m') !== $periodKey) {
            throw new InvalidArgumentException('Periode tidak valid.');
        }
        $endLocal = $startLocal->copy()->addMonth()->startOfMonth();

        return [
            'key' => $startLocal->format('Y-m'),
            'label' => ucfirst($startLocal->copy()->locale('id')->translatedFormat('F Y')),
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'start_utc' => $startLocal->copy()->utc(),
            'end_utc' => $endLocal->copy()->utc(),
        ];
    }

    public function build(array $period, bool $fresh = false, ?User $viewer = null): array
    {
        $cacheKey = 'security-insight-snapshot:v3:'.$period['key'];
        if ($viewer && ! $viewer->isAdmin()) {
            $cacheKey .= ':'.$viewer->alertAccessFingerprint();
        }

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($period, $viewer): array {
            return $this->buildUncached($period, $viewer);
        });
    }

    private function buildUncached(array $period, ?User $viewer): array
    {
        $base = $this->alertsForPeriod($period['start_utc'], $period['end_utc'], $viewer);

        $totals = (clone $base)
            ->selectRaw("COUNT(*) AS total_count,
                SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) AS acknowledged_count,
                SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) AS ignored_count,
                SUM(CASE WHEN rule_level >= 15 THEN 1 ELSE 0 END) AS critical_count,
                SUM(CASE WHEN rule_level BETWEEN 12 AND 14 THEN 1 ELSE 0 END) AS high_count,
                SUM(CASE WHEN rule_level BETWEEN 7 AND 11 THEN 1 ELSE 0 END) AS medium_count,
                SUM(CASE WHEN rule_level < 7 THEN 1 ELSE 0 END) AS low_count")
            ->first();

        $actionedAlertCount = (clone $base)
            ->where(function (Builder $query): void {
                $query->whereExists(function ($exists): void {
                    $exists->selectRaw('1')
                        ->from('alert_triages')
                        ->whereColumn('alert_triages.alert_id', 'alerts.id');
                });
            })
            ->count();

        $totalAlerts = (int) ($totals->total_count ?? 0);
        $uniqueAgents = (clone $base)
            ->whereNotNull('agent_name')
            ->where('agent_name', '!=', '')
            ->distinct()
            ->count('agent_name');
        $uniqueSourceIps = (int) (clone $base)
            ->whereNotNull('src_ip')
            ->where('src_ip', '!=', '')
            ->selectRaw("COUNT(DISTINCT COALESCE(src_ip_hash, NULLIF(src_ip, ''))) AS unique_ips")
            ->value('unique_ips');

        $responseOperations = $this->responseOperations($period, [
            'new' => (int) ($totals->total_count ?? 0)
                - (int) ($totals->acknowledged_count ?? 0)
                - (int) ($totals->ignored_count ?? 0),
            'acknowledged' => (int) ($totals->acknowledged_count ?? 0),
            'ignored' => (int) ($totals->ignored_count ?? 0),
        ], $viewer);
        $notificationDeliveries = $responseOperations['notification_delivery']['succeeded_http_200'];

        $topRules = (clone $base)
            ->whereNotNull('rule_description')
            ->where('rule_description', '!=', '')
            ->select('rule_description')
            ->selectRaw('COUNT(*) AS count, MAX(rule_level) AS max_level')
            ->groupBy('rule_description')
            ->orderByDesc('count')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->rule_description,
                'count' => (int) $row->count,
                'max_level' => (int) $row->max_level,
            ])
            ->values()
            ->all();

        $topAgents = (clone $base)
            ->whereNotNull('agent_name')
            ->where('agent_name', '!=', '')
            ->select('agent_name')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('agent_name')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->agent_name,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        // Aggregate queries below cover all records in the period. This limit
        // applies to the small MITRE projection; the encrypted log payload is
        // fetched only for rows waiting for the initial backfill.
        $sampleLimit = min(10_000, max(1_000, (int) Configuration::get('ai_snapshot_limit', config('services.ai.snapshot_limit', 10_000))));

        $sampledAlerts = (clone $base)
            ->orderByDesc('first_seen_at')
            ->limit($sampleLimit)
            ->get(['rule_description', 'rule_level', 'agent_name', 'mitre_metadata', 'first_seen_at',
                DB::raw('CASE WHEN mitre_metadata IS NULL THEN raw_data ELSE NULL END AS legacy_raw_data')]);

        $severity = [
            ['label' => 'Critical', 'count' => (int) ($totals->critical_count ?? 0)],
            ['label' => 'High',     'count' => (int) ($totals->high_count ?? 0)],
            ['label' => 'Medium',   'count' => (int) ($totals->medium_count ?? 0)],
            ['label' => 'Low',      'count' => (int) ($totals->low_count ?? 0)],
        ];

        $metrics = [
            'total' => $totalAlerts,
            'actioned' => $actionedAlertCount,
            'pending' => max(0, $totalAlerts - $actionedAlertCount),
            'acknowledged' => (int) ($totals->acknowledged_count ?? 0),
            'ignored' => (int) ($totals->ignored_count ?? 0),
            'notification_deliveries' => $notificationDeliveries,
            'notification_attempts' => $responseOperations['notification_delivery']['attempts'],
            'notification_failures' => $responseOperations['notification_delivery']['failed_or_unknown'],
            'triage_actions' => $responseOperations['triage_events']['total_actions'],
            'unique_agents' => $uniqueAgents,
            'unique_source_ips' => $uniqueSourceIps,
            'high_critical' => (int) ($totals->critical_count ?? 0) + (int) ($totals->high_count ?? 0),
        ];

        $scope = [
            'minimum_rule_level' => (int) Configuration::get('min_alert_level', 12),
            'sampled_alerts' => $sampledAlerts->count(),
            'maximum_alert_sample' => $sampleLimit,
            'triage_records' => $responseOperations['triage_events']['total_actions'],
            'notification_records' => $responseOperations['notification_delivery']['attempts'],
            'refresh_days' => min(30, max(1, (int) Configuration::get('ai_refresh_days', config('services.ai.refresh_days', 10)))),
            'source' => 'Alert yang telah diimpor dari Wazuh/OpenSearch ke SIEM Salabim.',
        ];

        $analysisInput = [
            'period' => [
                'label' => $period['label'],
                'start' => $period['start_local']->toDateString(),
                'end' => $period['end_local']->copy()->subDay()->toDateString(),
            ],
            'scope' => $scope,
            'metrics' => $metrics,
            'severity' => $severity,
            'daily_alert_trend' => $this->dailyTrend($period, $base),
            // Only a scrubbed aggregate leaves the application for AI analysis.
            // The local dashboard can still show authorized users the source labels.
            'top_rule_patterns' => $this->sanitiseTopRulesForAi($topRules),
            'top_affected_agents' => $this->anonymiseTopAgentsForAi($topAgents),
            'mitre_techniques' => $this->extractMitreTechniques($sampledAlerts),
            'soc_response_activity' => $responseOperations,
        ];

        return [
            'period' => $period,
            'metrics' => $metrics,
            'severity' => $severity,
            'daily_trend' => $analysisInput['daily_alert_trend'],
            'top_rules' => $topRules,
            'top_agents' => $topAgents,
            'techniques' => $analysisInput['mitre_techniques'],
            'soc_activity' => $responseOperations,
            'scope' => $scope,
            'analysis_input' => $analysisInput,
        ];
    }

    private function alertsForPeriod(Carbon $startUtc, Carbon $endUtc, ?User $viewer): Builder
    {
        return Alert::query()
            ->when($viewer, fn (Builder $query) => $query->visibleTo($viewer))
            ->where('first_seen_at', '>=', $startUtc)
            ->where('first_seen_at', '<', $endUtc);
    }

    private function dailyTrend(array $period, Builder $base): array
    {
        // At most 31 calendar buckets. UTC boundaries handle DST and fractional
        // offsets without depending on MySQL timezone tables or the server zone.
        $cases = [];
        for ($day = $period['start_local']->copy(); $day->lt($period['end_local']); $day->addDay()->startOfDay()) {
            $from = $day->copy()->utc()->format('Y-m-d H:i:s');
            $to = $day->copy()->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');
            $date = $day->format('Y-m-d');
            $cases[] = "WHEN first_seen_at >= '{$from}' AND first_seen_at < '{$to}' THEN '{$date}'";
        }
        $dayExpression = 'CASE '.implode(' ', $cases).' END';

        $rows = (clone $base)
            ->selectRaw("{$dayExpression} AS day, COUNT(*) AS total,
                SUM(CASE WHEN rule_level >= 12 THEN 1 ELSE 0 END) AS high")
            // MariaDB ONLY_FULL_GROUP_BY requires the selected CASE alias here.
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $today = now(config('app.timezone'))->startOfDay();
        $lastDay = $period['end_local']->copy()->subDay();
        if ($lastDay->greaterThan($today)) {
            $lastDay = $today;
        }

        $trend = [];
        foreach (CarbonPeriod::create($period['start_local']->copy()->startOfDay(), $lastDay) as $day) {
            $row = $rows->get($day->format('Y-m-d'));
            $trend[] = [
                'date' => $day->format('Y-m-d'),
                'label' => $day->format('d'),
                'total' => (int) ($row->total ?? 0),
                'high' => (int) ($row->high ?? 0),
            ];
        }

        return $trend;
    }

    private function extractMitreTechniques(Collection $alerts): array
    {
        $counts = [];

        foreach ($alerts as $alert) {
            $mitre = $alert->mitre_metadata;
            if ($mitre === null) {
                $mitre = Arr::get(SensitiveDataCipher::decryptJson($alert->legacy_raw_data) ?? [], 'rule.mitre', []);
            }
            if (! is_array($mitre)) {
                continue;
            }

            $ids = $this->normaliseStringList($mitre['id'] ?? []);
            $techniques = $this->normaliseStringList($mitre['technique'] ?? []);

            if ($techniques === [] && $ids === []) {
                continue;
            }

            $length = max(count($ids), count($techniques));
            for ($index = 0; $index < $length; $index++) {
                $id = $ids[$index] ?? $ids[0] ?? null;
                $technique = $techniques[$index] ?? $techniques[0] ?? null;
                $label = $this->sanitiseInsightLabel(
                    trim(implode(' - ', array_filter([$id, $technique])))
                );

                if ($label !== '') {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return collect($counts)
            ->take(8)
            ->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])
            ->values()
            ->all();
    }

    private function normaliseStringList(mixed $value): array
    {
        return collect(Arr::wrap($value))
            ->filter(fn ($item) => is_string($item) || is_numeric($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function sanitiseTopRulesForAi(array $rules): array
    {
        return collect($rules)
            ->map(fn (array $rule) => [
                'label' => $this->sanitiseInsightLabel((string) ($rule['label'] ?? 'Pola rule tidak tersedia')),
                'count' => (int) ($rule['count'] ?? 0),
                'max_level' => (int) ($rule['max_level'] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function anonymiseTopAgentsForAi(array $agents): array
    {
        return collect($agents)
            ->values()
            ->map(fn (array $agent, int $index) => [
                'label' => 'Agent '.($index + 1),
                'count' => (int) ($agent['count'] ?? 0),
            ])
            ->all();
    }

    private function sanitiseInsightLabel(string $label): string
    {
        $label = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label) ?? '';
        $label = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/u', '[IP]', $label) ?? $label;
        $label = preg_replace('/\b(?:[0-9a-f]{1,4}:){2,}[0-9a-f:]*\b/iu', '[IP]', $label) ?? $label;
        $label = preg_replace('/(?:[A-Za-z]:\\\\|\\/)(?:[^\s<>"\']{1,120})/u', '[PATH]', $label) ?? $label;
        $label = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[EMAIL]', $label) ?? $label;
        $label = preg_replace('/\b[a-f0-9]{32,}\b/iu', '[HASH]', $label) ?? $label;
        $label = preg_replace('/\s{2,}/u', ' ', $label) ?? $label;

        return mb_strimwidth(trim($label), 0, 180, '…');
    }

    private function responseOperations(array $period, array $currentAlertStatus, ?User $viewer): array
    {
        // Native application activity timestamps use the application timezone;
        // imported first_seen_at values use UTC and have their own query path.
        $startUtc = $period['start_utc']->copy()->setTimezone(config('app.timezone'));
        $endUtc = $period['end_utc']->copy()->setTimezone(config('app.timezone'));

        $triageQuery = AlertTriage::query()
            ->when($viewer && ! $viewer->isAdmin(), fn ($query) => $query->whereIn('alert_id', Alert::visibleTo($viewer)->select('id')))
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc);

        $triage = (clone $triageQuery)
            ->selectRaw("COUNT(*) AS total_actions,
                SUM(CASE WHEN action = 'acknowledge' THEN 1 ELSE 0 END) AS acknowledge_actions,
                SUM(CASE WHEN action = 'ignore' THEN 1 ELSE 0 END) AS ignore_actions,
                SUM(CASE WHEN action = 'notify' THEN 1 ELSE 0 END) AS notify_actions,
                COUNT(DISTINCT alert_id) AS distinct_alerts_touched,
                COUNT(DISTINCT CASE WHEN action = 'acknowledge' THEN alert_id END) AS distinct_acknowledged,
                COUNT(DISTINCT CASE WHEN action = 'ignore' THEN alert_id END) AS distinct_ignored,
                COUNT(DISTINCT CASE WHEN action = 'notify' THEN alert_id END) AS distinct_notify_attempted")
            ->first();

        $notifQuery = NotificationLog::query()
            ->when($viewer && ! $viewer->isAdmin(), fn ($query) => $query->whereIn('alert_id', Alert::visibleTo($viewer)->select('id')))
            ->where('sent_at', '>=', $startUtc)
            ->where('sent_at', '<', $endUtc);

        $delivery = (clone $notifQuery)->selectRaw($this->notificationAggregateSql())->first();

        $attempts = (int) ($delivery->attempts ?? 0);
        $succeeded = (int) ($delivery->succeeded ?? 0);
        $failed = max(0, $attempts - $succeeded);

        $severityExpression = "CASE
            WHEN alerts.rule_level >= 15 THEN 'critical'
            WHEN alerts.rule_level >= 12 THEN 'high'
            WHEN alerts.rule_level >= 7 THEN 'medium'
            ELSE 'low' END";

        $triageSeverity = DB::table('alert_triages')
            ->when($viewer && ! $viewer->isAdmin(), fn ($query) => $query->whereIn('alert_triages.alert_id', Alert::visibleTo($viewer)->select('id')))
            ->join('alerts', 'alerts.id', '=', 'alert_triages.alert_id')
            ->where('alert_triages.created_at', '>=', $startUtc)
            ->where('alert_triages.created_at', '<', $endUtc)
            ->selectRaw("{$severityExpression} AS severity,
                SUM(CASE WHEN alert_triages.action = 'acknowledge' THEN 1 ELSE 0 END) AS acknowledge_actions,
                SUM(CASE WHEN alert_triages.action = 'ignore' THEN 1 ELSE 0 END) AS ignore_actions,
                SUM(CASE WHEN alert_triages.action = 'notify' THEN 1 ELSE 0 END) AS notify_actions")
            ->groupBy(DB::raw($severityExpression))
            ->get()
            ->keyBy('severity');

        $notificationSeverity = DB::table('notification_logs')
            ->when($viewer && ! $viewer->isAdmin(), fn ($query) => $query->whereIn('notification_logs.alert_id', Alert::visibleTo($viewer)->select('id')))
            ->join('alerts', 'alerts.id', '=', 'notification_logs.alert_id')
            ->where('notification_logs.sent_at', '>=', $startUtc)
            ->where('notification_logs.sent_at', '<', $endUtc)
            ->selectRaw("{$severityExpression} AS severity, ".$this->notificationAggregateSql())
            ->groupBy(DB::raw($severityExpression))
            ->get()
            ->keyBy('severity');

        $responseBySeverity = collect(['critical', 'high', 'medium', 'low'])
            ->map(function (string $severity) use ($triageSeverity, $notificationSeverity): array {
                $triageRow = $triageSeverity->get($severity);
                $deliveryRow = $notificationSeverity->get($severity);

                return [
                    'severity' => $severity,
                    'acknowledge_actions' => (int) ($triageRow->acknowledge_actions ?? 0),
                    'ignore_actions' => (int) ($triageRow->ignore_actions ?? 0),
                    'notify_actions' => (int) ($triageRow->notify_actions ?? 0),
                    'notification_attempts' => (int) ($deliveryRow->attempts ?? 0),
                    'notification_delivered' => (int) ($deliveryRow->succeeded ?? 0),
                    'notification_intents' => (int) ($deliveryRow->intents ?? 0),
                    'notification_pending' => (int) ($deliveryRow->pending ?? 0),
                    'notification_daily_limited' => (int) ($deliveryRow->daily_limited ?? 0),
                ];
            })
            ->all();

        $topRuleOutcomes = DB::table('alert_triages')
            ->when($viewer && ! $viewer->isAdmin(), fn ($query) => $query->whereIn('alert_triages.alert_id', Alert::visibleTo($viewer)->select('id')))
            ->join('alerts', 'alerts.id', '=', 'alert_triages.alert_id')
            ->where('alert_triages.created_at', '>=', $startUtc)
            ->where('alert_triages.created_at', '<', $endUtc)
            ->whereNotNull('alerts.rule_description')
            ->where('alerts.rule_description', '!=', '')
            ->select('alerts.rule_description')
            ->selectRaw("COUNT(*) AS total_actions,
                MAX(alerts.rule_level) AS max_level,
                SUM(CASE WHEN alert_triages.action = 'acknowledge' THEN 1 ELSE 0 END) AS acknowledge_actions,
                SUM(CASE WHEN alert_triages.action = 'ignore' THEN 1 ELSE 0 END) AS ignore_actions,
                SUM(CASE WHEN alert_triages.action = 'notify' THEN 1 ELSE 0 END) AS notify_actions")
            ->groupBy('alerts.rule_description')
            ->orderByDesc('total_actions')
            ->limit(25)
            ->get()
            ->map(fn ($row) => [
                'label' => $this->sanitiseInsightLabel((string) $row->rule_description),
                'max_level' => (int) $row->max_level,
                'total_actions' => (int) $row->total_actions,
                'acknowledge_actions' => (int) $row->acknowledge_actions,
                'ignore_actions' => (int) $row->ignore_actions,
                'notify_actions' => (int) $row->notify_actions,
            ])
            ->values()
            ->all();

        return [
            'alert_status_snapshot' => $currentAlertStatus,
            'triage_events' => [
                'total_actions' => (int) ($triage->total_actions ?? 0),
                'acknowledge_actions' => (int) ($triage->acknowledge_actions ?? 0),
                'ignore_actions' => (int) ($triage->ignore_actions ?? 0),
                'notify_actions' => (int) ($triage->notify_actions ?? 0),
                'distinct_alerts_touched' => (int) ($triage->distinct_alerts_touched ?? 0),
                'distinct_acknowledged' => (int) ($triage->distinct_acknowledged ?? 0),
                'distinct_ignored' => (int) ($triage->distinct_ignored ?? 0),
                'distinct_notify_attempted' => (int) ($triage->distinct_notify_attempted ?? 0),
            ],
            'notification_delivery' => [
                'intents' => (int) ($delivery->intents ?? 0),
                'attempts' => $attempts,
                'sent' => $succeeded,
                'pending' => (int) ($delivery->pending ?? 0),
                'sending' => (int) ($delivery->sending ?? 0),
                'failed' => (int) ($delivery->failed ?? 0),
                'unconfirmed' => (int) ($delivery->unconfirmed ?? 0),
                'cancelled' => (int) ($delivery->cancelled ?? 0),
                'daily_limited' => (int) ($delivery->daily_limited ?? 0),
                'succeeded_http_200' => $succeeded,
                'failed_or_unknown' => $failed,
                'success_rate_percent' => $attempts > 0 ? round(($succeeded / $attempts) * 100, 1) : 0.0,
                'distinct_alerts_attempted' => (int) ($delivery->distinct_alerts_attempted ?? 0),
                'distinct_alerts_delivered' => (int) ($delivery->distinct_alerts_delivered ?? 0),
            ],
            'daily_response_trend' => $this->dailyResponseTrend($period, $viewer),
            'response_by_severity' => $responseBySeverity,
            'top_rule_response_outcomes' => $topRuleOutcomes,
            'privacy_note' => 'Hanya agregat aktivitas yang dikirim. Alasan triage, isi notifikasi, chat ID, identitas operator, IP, path, payload, dan raw log tidak disertakan.',
        ];
    }

    private function dailyResponseTrend(array $period, ?User $viewer): array
    {
        // Application activity is already stored in the reporting timezone.
        $triageDay = 'DATE(alert_triages.created_at)';
        $notificationDay = 'DATE(notification_logs.sent_at)';

        $triageRows = DB::table('alert_triages')
            ->when($viewer && ! $viewer->isAdmin(), fn ($query) => $query->whereIn('alert_id', Alert::visibleTo($viewer)->select('id')))
            ->where('created_at', '>=', $period['start_utc']->copy()->setTimezone(config('app.timezone')))
            ->where('created_at', '<', $period['end_utc']->copy()->setTimezone(config('app.timezone')))
            ->selectRaw("{$triageDay} AS day,
                SUM(CASE WHEN action = 'acknowledge' THEN 1 ELSE 0 END) AS acknowledge_actions,
                SUM(CASE WHEN action = 'ignore' THEN 1 ELSE 0 END) AS ignore_actions,
                SUM(CASE WHEN action = 'notify' THEN 1 ELSE 0 END) AS notify_actions")
            ->groupBy(DB::raw($triageDay))
            ->get()
            ->keyBy('day');

        $notificationRows = DB::table('notification_logs')
            ->when($viewer && ! $viewer->isAdmin(), fn ($query) => $query->whereIn('alert_id', Alert::visibleTo($viewer)->select('id')))
            ->where('sent_at', '>=', $period['start_utc']->copy()->setTimezone(config('app.timezone')))
            ->where('sent_at', '<', $period['end_utc']->copy()->setTimezone(config('app.timezone')))
            ->selectRaw("{$notificationDay} AS day, ".$this->notificationAggregateSql())
            ->groupBy(DB::raw($notificationDay))
            ->get()
            ->keyBy('day');

        $today = now(config('app.timezone'))->startOfDay();
        $lastDay = $period['end_local']->copy()->subDay();
        if ($lastDay->greaterThan($today)) {
            $lastDay = $today;
        }
        $trend = [];

        foreach (CarbonPeriod::create($period['start_local']->copy()->startOfDay(), $lastDay) as $day) {
            $key = $day->format('Y-m-d');
            $triage = $triageRows->get($key);
            $delivery = $notificationRows->get($key);
            $attempts = (int) ($delivery->attempts ?? 0);
            $delivered = (int) ($delivery->succeeded ?? 0);

            $trend[] = [
                'date' => $key,
                'acknowledge' => (int) ($triage->acknowledge_actions ?? 0),
                'ignore' => (int) ($triage->ignore_actions ?? 0),
                'notify_attempt' => (int) ($triage->notify_actions ?? 0),
                'notification_delivered' => $delivered,
                'notification_failed_or_unknown' => max(0, $attempts - $delivered),
                'notification_pending' => (int) ($delivery->pending ?? 0),
                'notification_daily_limited' => (int) ($delivery->daily_limited ?? 0),
                'notification_cancelled' => (int) ($delivery->cancelled ?? 0),
            ];
        }

        return $trend;
    }

    private function notificationAggregateSql(): string
    {
        $attempted = NotificationLog::ATTEMPTED_SQL;
        $succeeded = "{$attempted} AND notification_logs.response_status = 200";
        $columns = [
            'COUNT(*) AS intents',
            NotificationLog::deliveryCountSql($attempted).' AS attempts',
            NotificationLog::deliveryCountSql($succeeded).' AS succeeded',
            "COUNT(DISTINCT CASE WHEN {$attempted} THEN notification_logs.alert_id END) AS distinct_alerts_attempted",
            "COUNT(DISTINCT CASE WHEN {$succeeded} THEN notification_logs.alert_id END) AS distinct_alerts_delivered",
        ];
        foreach (['pending', 'sending', 'failed', 'unconfirmed', 'cancelled', 'daily_limited'] as $state) {
            $columns[] = NotificationLog::deliveryCountSql("notification_logs.delivery_state = '{$state}'").' AS '.$state;
        }

        return implode(', ', $columns);
    }
}
