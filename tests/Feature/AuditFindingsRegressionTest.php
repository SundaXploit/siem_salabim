<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\AlertTriageService;
use App\Services\AutomaticAlertNotifier;
use App\Services\OpenSearchService;
use App\Services\SecurityInsightSnapshotService;
use App\Services\TelegramService;
use App\Services\UserManagementService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditFindingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function hit(string $id, string $agent = '001', string $time = '2026-11-18T08:00:00Z'): array
    {
        return ['_id' => $id, '_index' => 'wazuh-audit-2026.11.18', '_source' => [
            '@timestamp' => $time, 'rule' => ['id' => '12345', 'level' => 14, 'description' => 'Audit fixture'],
            'agent' => ['id' => $agent, 'name' => 'audit-agent'],
        ]];
    }

    #[DataProvider('malformedMetadata')]
    public function test_bad_metadata_cannot_discard_healthy_hits_or_later_pages(string $path, mixed $value): void
    {
        Queue::fake();
        $bad = $this->hit('bad');
        data_set($bad, $path, $value);
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->once()->andReturn([
            [$this->hit('healthy-before'), $bad, $this->hit('healthy-after')], [$this->hit('healthy-next-page')],
        ]);

        $this->artisan('siem:fetch-alerts', ['--all' => true])
            ->expectsOutput('[SIEM] Done. New: 3, Skipped (already exists): 0')->assertSuccessful();

        $this->assertSame(['healthy-after', 'healthy-before', 'healthy-next-page'], Alert::orderBy('source_id')->pluck('source_id')->all());
    }

    public static function malformedMetadata(): iterable
    {
        foreach (['_id', '_index', '_source', '_source.rule', '_source.agent', '_source.data',
            '_source.rule.description', '_source.agent.name', '_source.data.srcip', '_source.data.dstip',
            '_source.rule.id', '_source.rule.level', '_source.agent.id'] as $path) {
            yield $path => [$path, $path === '_source' || in_array($path, ['_source.rule', '_source.agent', '_source.data'], true) ? 'invalid-object' : ['unexpected-array']];
        }
        yield 'rule ID exceeds schema' => ['_source.rule.id', str_repeat('9', 65)];
        yield 'rule ID null byte' => ['_source.rule.id', "12\0x"];
        yield 'level above Wazuh range' => ['_source.rule.level', 17];
    }

    public function test_changing_scope_releases_only_inaccessible_ack_claims_and_keeps_evidence(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->forAgent('001', ['alerts.acknowledge', 'alerts.ignore'])->create();
        $secondScope = User::factory()->forAgent('002')->make()->allowed_agents;
        $owner->update(['allowed_agents' => [...$owner->allowed_agents, ...$secondScope]]);
        $removed = Alert::create(OpenSearchService::parseHit($this->hit('removed')))->refresh();
        $retained = Alert::create(OpenSearchService::parseHit($this->hit('retained', '002')))->refresh();
        $rawEvidence = $removed->getRawOriginal('raw_data');
        $triage = app(AlertTriageService::class);
        $this->assertSame(2, $triage->apply([$removed->id, $retained->id], 'acknowledge', $owner->id)['processed']);

        app(UserManagementService::class)->update($admin, $owner, ['role' => 'analyst', 'allowed_agents' => $secondScope]);

        $this->assertSame('new', $removed->fresh()->status);
        $this->assertNull($removed->fresh()->acknowledged_by_user_id);
        $this->assertSame($rawEvidence, $removed->fresh()->getRawOriginal('raw_data'));
        $this->assertSame($owner->id, (int) $retained->fresh()->acknowledged_by_user_id);
        $this->assertSame('acknowledged', $retained->fresh()->status);
        $log = ActivityLog::where('action', 'ack_released')->sole();
        $this->assertSame('agent_access_changed', $log->context['reason']);
        $this->assertSame($owner->id, $log->context['previous_owner_id']);
        $this->assertSame(1, $triage->apply([$removed->id], 'acknowledge', $admin->id)['processed']);
    }

    #[DataProvider('sourceTimestamps')]
    public function test_every_notification_formats_the_normalized_event_time(string $sourceZone, string $sourceTime, string $expected): void
    {
        config(['app.timezone' => 'Asia/Jakarta', 'services.opensearch.timezone' => $sourceZone]);
        date_default_timezone_set('Asia/Jakarta');
        $alert = Alert::create(OpenSearchService::parseHit($this->hit('time', time: $sourceTime), $sourceZone))->refresh();
        $utc = $alert->first_seen_at->toIso8601String();

        $this->assertStringContainsString($expected, app(AutomaticAlertNotifier::class)->message($alert, 'https://audit.example.test'));
        $this->assertSame($expected, (new NotificationTemplate(['body' => '{timestamp}']))->render($alert));
        $this->assertStringContainsString($expected, TelegramService::buildTemplate($alert->toArray()));
        $this->assertSame($utc, $alert->first_seen_at->toIso8601String());
    }

    public static function sourceTimestamps(): iterable
    {
        yield 'naive UTC' => ['UTC', '2026-11-18 08:15:00', '18/11/2026 15:15:00 WIB'];
        yield 'naive WIB' => ['Asia/Jakarta', '2026-11-18 08:15:00', '18/11/2026 08:15:00 WIB'];
        yield 'naive WITA' => ['Asia/Makassar', '2026-11-18 08:15:00', '18/11/2026 07:15:00 WIB'];
        yield 'explicit offset wins' => ['UTC', '2026-11-18T08:15:00+07:00', '18/11/2026 08:15:00 WIB'];
    }

    private function snapshot(): array
    {
        $service = app(SecurityInsightSnapshotService::class);

        return $service->build($service->currentPeriod(), true);
    }

    public function test_pending_limited_and_cancelled_intents_are_not_failed_transport_attempts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18T09:00:00Z'));
        $alert = Alert::create(OpenSearchService::parseHit($this->hit('metrics')));
        foreach (['sent' => 200, 'pending' => null, 'daily_limited' => null, 'cancelled' => null] as $state => $status) {
            NotificationLog::create(['alert_id' => $alert->id, 'chat_id' => '-1001', 'message' => 'Fixture',
                'delivery_state' => $state, 'response_status' => $status, 'sent_at' => now()]);
        }

        $snapshot = $this->snapshot();
        $delivery = $snapshot['soc_activity']['notification_delivery'];
        $this->assertSame(4, $delivery['intents']);
        $this->assertSame(1, $delivery['attempts']);
        $this->assertSame(1, $delivery['sent']);
        $this->assertSame(1, $delivery['pending']);
        $this->assertSame(1, $delivery['daily_limited']);
        $this->assertSame(1, $delivery['cancelled']);
        $this->assertSame(0, $delivery['failed_or_unknown']);
        $this->assertEquals(100, $delivery['success_rate_percent']);
        $this->assertSame(0, $snapshot['metrics']['notification_failures']);
    }

    public function test_one_bulk_message_counts_once_in_totals_daily_and_severity_aggregates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18T09:00:00Z'));
        foreach (['bulk-1', 'bulk-2'] as $id) {
            $alert = Alert::create(OpenSearchService::parseHit($this->hit($id)));
            NotificationLog::create(['alert_id' => $alert->id, 'chat_id' => '-1001', 'message' => 'Fixture',
                'delivery_state' => 'sent', 'response_status' => 200, 'sent_at' => now(), 'delivery_group_key' => hash('sha256', 'bulk-group')]);
        }
        $snapshot = $this->snapshot();
        $delivery = $snapshot['soc_activity']['notification_delivery'];
        $this->assertSame(2, $delivery['intents']);
        $this->assertSame(1, $delivery['attempts']);
        $this->assertSame(1, $delivery['sent']);
        $this->assertSame(2, $delivery['distinct_alerts_delivered']);
        $this->assertSame(1, array_sum(array_column($snapshot['soc_activity']['daily_response_trend'], 'notification_delivered')));
        $this->assertSame(1, array_sum(array_column($snapshot['soc_activity']['response_by_severity'], 'notification_attempts')));
    }

    public function test_delivery_group_backfill_preserves_the_encrypted_history(): void
    {
        $alert = Alert::create(OpenSearchService::parseHit($this->hit('legacy-bulk')));
        $log = NotificationLog::create(['alert_id' => $alert->id, 'chat_id' => '-1001', 'message' => 'Private fixture',
            'delivery_state' => 'sent', 'sent_at' => now(), 'delivery_details' => ['bulk_group' => ['id' => 'old-bulk-group']]]);
        $encrypted = $log->getRawOriginal('delivery_details');

        $migration = require database_path('migrations/2026_11_19_000026_add_notification_delivery_group_key.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('notification_logs', 'delivery_group_key'));
        $migration->up();

        $this->assertSame(hash('sha256', 'old-bulk-group'), $log->fresh()->delivery_group_key);
        $this->assertSame($encrypted, $log->fresh()->getRawOriginal('delivery_details'));
    }

    #[DataProvider('reportingZones')]
    public function test_quota_and_activity_days_follow_the_installer_timezone(string $zone, string $day, string $label): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
        $this->travelTo(CarbonImmutable::parse('2026-11-18T16:30:00Z'));
        $alert = Alert::create(OpenSearchService::parseHit($this->hit('zone', time: '2026-11-18T16:30:00Z')));
        $user = User::factory()->create(['role' => 'admin']);
        AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $user->id, 'action' => 'acknowledge', 'created_at' => now()]);
        $delivery = NotificationLog::create(['alert_id' => $alert->id, 'chat_id' => '-1001', 'message' => 'Fixture', 'delivery_state' => 'pending', 'sent_at' => now()]);
        $this->assertTrue(app(AutomaticAlertNotifier::class)->claimDelivery($delivery, $alert, 'Fixture', 2));
        $snapshot = $this->snapshot();

        $this->assertSame($day, DB::table('automatic_notification_quotas')->sole()->quota_day);
        $this->assertSame($label, LocalTime::label());
        $this->assertSame(1, collect($snapshot['daily_trend'])->firstWhere('date', $day)['total']);
        $this->assertSame(1, collect($snapshot['soc_activity']['daily_response_trend'])->firstWhere('date', $day)['acknowledge']);
    }

    public static function reportingZones(): iterable
    {
        yield ['UTC', '2026-11-18', 'UTC'];
        yield ['Asia/Jakarta', '2026-11-18', 'WIB'];
        yield ['Asia/Makassar', '2026-11-19', 'WITA'];
        yield ['Asia/Jayapura', '2026-11-19', 'WIT'];
    }

    public function test_utc_event_buckets_handle_a_23_hour_dst_day(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertStringContainsString('ONLY_FULL_GROUP_BY', DB::selectOne('SELECT @@SESSION.sql_mode AS modes')->modes);
        }
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
        $this->travelTo(CarbonImmutable::parse('2026-03-10T12:00:00Z'));
        foreach (['2026-03-08T04:59:59Z', '2026-03-08T05:00:00Z', '2026-03-09T03:59:59Z', '2026-03-09T04:00:00Z'] as $id => $time) {
            Alert::create(OpenSearchService::parseHit($this->hit('dst-'.$id, time: $time)));
        }
        $days = collect($this->snapshot()['daily_trend'])->keyBy('date');

        $this->assertSame(1, $days['2026-03-07']['total']);
        $this->assertSame(2, $days['2026-03-08']['total']);
        $this->assertSame(1, $days['2026-03-09']['total']);
    }

    #[DataProvider('badFilters')]
    public function test_alert_lists_reject_malformed_filter_types(string $route, string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route($route, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function badFilters(): iterable
    {
        foreach (['alerts.index', 'history.alerts'] as $route) {
            foreach ([['status', ['new']], ['status', 'invalid'], ['level', ['12']], ['level', -1], ['level', 17],
                ['per_page', ['25']], ['per_page', 26]] as [$field, $value]) {
                yield [$route, $field, $value];
            }
        }
    }
}
