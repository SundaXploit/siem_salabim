<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoryLogTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $analyst = User::factory()->forAgent('001')->create(['name' => 'Analis SOC']);
        $alert = Alert::create(['wazuh_alert_id' => 'log-test', 'rule_description' => 'Selected alert', 'agent_name' => 'Staging', 'raw_data' => ['agent' => ['id' => '001']], 'rule_level' => 12, 'status' => 'new', 'first_seen_at' => now()]);
        $this->actingAs($analyst);

        return [$analyst, $alert];
    }

    public function test_analyst_log_merges_notification_ack_and_ignore_history_without_duplicate_notify_entries(): void
    {
        [$user, $alert] = $this->fixtures();
        foreach (['acknowledge', 'ignore', 'notify'] as $index => $action) {
            AlertTriage::create(['user_id' => $user->id, 'alert_id' => $alert->id, 'action' => $action, 'reason' => 'Catatan '.$action, 'created_at' => now()->subMinutes(5 - $index)]);
        }
        foreach ([200, 500] as $status) {
            NotificationLog::create(['user_id' => $user->id, 'alert_id' => $alert->id, 'chat_id' => '-100111', 'message' => 'Pesan <script>alert(1)</script>', 'response_status' => $status, 'sent_at' => now()->subMinute()]);
        }
        $response = $this->get(route('analyst-log.index'))->assertOk()->assertSee('Log analis')->assertSee('SENT')->assertSee('FAILED (500)')
            ->assertDontSee('<script>alert(1)</script>', false)->assertHeader('Cache-Control', 'no-store, private');
        $logs = $response->viewData('logs');
        $this->assertSame(4, $logs->total());
        $this->assertSame(['notify', 'notify', 'ignore', 'acknowledge'], $logs->pluck('action')->all());
        $this->assertDatabaseCount('alert_triages', 3);
        $this->assertDatabaseCount('notification_logs', 2);
        $this->get(route('history.notifications', ['action' => 'ignore', 'search' => 'Staging']))
            ->assertStatus(301)->assertRedirect(route('analyst-log.index', ['search' => 'Staging', 'action' => 'ignore']));
    }

    public function test_analyst_filters_apply_across_both_sources_and_use_local_day_boundaries(): void
    {
        config(['app.timezone' => 'UTC']);
        [$user, $alert] = $this->fixtures();
        foreach ([
            ['2026-11-11 16:59:59', 'before'], ['2026-11-11 17:00:00', 'first'],
            ['2026-11-12 16:59:59', 'last'], ['2026-11-12 17:00:00', 'after'],
        ] as [$at, $reason]) {
            AlertTriage::create(['user_id' => $user->id, 'alert_id' => $alert->id, 'action' => 'ignore', 'reason' => $reason, 'created_at' => $at]);
        }
        NotificationLog::create(['user_id' => $user->id, 'alert_id' => $alert->id, 'chat_id' => '123456', 'message' => 'Notification only', 'response_status' => 200, 'sent_at' => '2026-11-12 00:00:00']);
        $response = $this->get(route('analyst-log.index', ['date_from' => '2026-11-12', 'date_to' => '2026-11-12']));
        $this->assertSame(3, $response->assertOk()->viewData('logs')->total());
        $response = $this->get(route('analyst-log.index', ['action' => 'notify', 'search' => '123456']));
        $this->assertSame(1, $response->assertOk()->viewData('logs')->total());
        $response = $this->get(route('analyst-log.index', ['action' => 'ignore', 'search' => 'Analis SOC', 'date_from' => '2026-11-12', 'date_to' => '2026-11-12']));
        $this->assertSame(['last', 'first'], $response->assertOk()->viewData('logs')->pluck('note')->all());
        $this->getJson(route('analyst-log.index', ['date_from' => '2026-11-13', 'date_to' => '2026-11-12']))->assertUnprocessable();
    }

    public function test_combined_pagination_has_one_global_order_and_keeps_filters(): void
    {
        [$user, $alert] = $this->fixtures();
        for ($index = 0; $index < 30; $index++) {
            if ($index % 2) {
                AlertTriage::create(['user_id' => $user->id, 'alert_id' => $alert->id, 'action' => 'acknowledge', 'created_at' => now()->subMinutes($index)]);
            } else {
                NotificationLog::create(['user_id' => $user->id, 'alert_id' => $alert->id, 'chat_id' => '-100111', 'message' => 'Test', 'response_status' => 200, 'sent_at' => now()->subMinutes($index)]);
            }
        }
        $first = $this->get(route('analyst-log.index', ['search' => 'Staging']))->assertOk()->viewData('logs');
        $second = $this->get(route('analyst-log.index', ['search' => 'Staging', 'page' => 2]))->assertOk()->viewData('logs');
        $this->assertSame(30, $first->total());
        $this->assertCount(25, $first);
        $this->assertCount(5, $second);
        $keys = fn ($logs) => $logs->map(fn ($log) => $log->entry_type.':'.$log->id);
        $this->assertCount(30, $keys($first)->merge($keys($second))->unique());
        $this->assertStringContainsString('search=Staging', $first->nextPageUrl());
    }

    public function test_activity_is_admin_only_and_navigation_is_split_by_role(): void
    {
        [$analyst, $alert] = $this->fixtures();
        AlertTriage::create(['user_id' => $analyst->id, 'alert_id' => $alert->id, 'action' => 'ignore', 'reason' => 'Triage marker', 'created_at' => now()]);
        $this->get(route('analyst-log.index'))->assertOk()->assertSee(route('analyst-log.index'), false)->assertDontSee(route('history.activity'), false)->assertDontSee('Log Notifikasi');
        $this->get(route('history.activity'))->assertForbidden();
        $admin = User::factory()->create(['name' => 'Admin SOC', 'role' => 'admin']);
        $this->actingAs($admin);
        ActivityLog::record('login', $analyst);
        ActivityLog::record('opensearch_updated', $admin);
        $response = $this->get(route('history.activity'))->assertOk()->assertSee('Simpan konfigurasi OpenSearch')->assertDontSee('Triage marker')
            ->assertSee(route('history.activity'), false)->assertSee(route('analyst-log.index'), false);
        $this->assertSame(2, $response->viewData('logs')->total());
        $response = $this->get(route('history.activity', ['action' => 'login', 'search' => 'Analis SOC']));
        $this->assertSame(1, $response->assertOk()->viewData('logs')->total());
    }
}
