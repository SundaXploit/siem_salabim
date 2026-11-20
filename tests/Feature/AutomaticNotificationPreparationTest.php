<?php

namespace Tests\Feature;

use App\Jobs\PrepareAutomaticAlertNotifications;
use App\Jobs\SendAutomaticAlertNotification;
use App\Models\Alert;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\AutomaticAlertNotifier;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomaticNotificationPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Configuration::set('telegram_auto_enabled', '1');
        Configuration::set('telegram_bot_token', 'test-token');
        Configuration::set('telegram_auto_above_level', 6);
        Configuration::set('telegram_auto_daily_limit', 1);
        Configuration::set('telegram_destinations', json_encode([
            ['label' => 'Developer', 'chat_id' => '-100111', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001')]],
            ['label' => 'Developer personal', 'chat_id' => '123456', 'agent_keys' => [Alert::agentScopeKey('wazuh-primary', '001')]],
        ]));
    }

    private function hit(string $id): array
    {
        return ['_id' => $id, '_index' => 'wazuh-alerts-4.x-2026.11.12', '_source' => [
            'id' => $id, 'rule' => ['id' => '533', 'level' => 7, 'description' => 'Wazuh alert'],
            'agent' => ['id' => '001', 'name' => 'Staging'], '@timestamp' => now('UTC')->toIso8601String(),
            'full_log' => 'Original source evidence',
        ]];
    }

    private function alert(string $id): Alert
    {
        return Alert::create([...OpenSearchService::parseHit($this->hit($id)), 'status' => 'new'])->refresh();
    }

    public function test_enabling_and_resaving_never_queue_saved_new_alerts(): void
    {
        Configuration::set('telegram_auto_enabled', '0');
        $old = $this->alert('stored-before-activation');
        $original = $old->getRawOriginal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        for ($save = 0; $save < 2; $save++) {
            $this->post(route('settings.telegram.automatic'), ['telegram_auto_enabled' => 1, 'telegram_auto_above_level' => 6])->assertSessionHasNoErrors();
            (new PrepareAutomaticAlertNotifications)->handle(app(AutomaticAlertNotifier::class));
        }
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNotPushed(SendAutomaticAlertNotification::class);
        $this->assertSame($original, $old->fresh()->getRawOriginal());
    }

    public function test_only_new_imports_after_activation_create_intents_while_replayed_old_alerts_are_skipped(): void
    {
        Configuration::set('telegram_auto_enabled', '0');
        $old = $this->alert('old');
        $original = $old->getRawOriginal();
        Configuration::set('telegram_auto_enabled', '1');
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->once()->andReturn([[$this->hit('old'), $this->hit('new-after-activation')]]);
        $this->artisan('siem:fetch-alerts', ['--today' => true])->assertSuccessful();
        $new = Alert::where('source_id', 'new-after-activation')->sole();
        $this->assertSame(2, $new->notificationLogs()->count());
        $this->assertSame(0, $old->notificationLogs()->count());
        Queue::assertPushed(SendAutomaticAlertNotification::class, 2);
        (new PrepareAutomaticAlertNotifications)->handle(app(AutomaticAlertNotifier::class));
        $this->assertDatabaseCount('notification_logs', 2);
        $this->assertSame($original, $old->fresh()->getRawOriginal());
        $this->assertSame('new', $new->status);
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_alerts_imported_while_disabled_are_not_sent_when_the_feature_is_reenabled(): void
    {
        Configuration::set('telegram_auto_enabled', '0');
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->twice()
            ->andReturn([[$this->hit('while-disabled')]], [[$this->hit('while-disabled'), $this->hit('while-enabled')]]);
        $this->artisan('siem:fetch-alerts', ['--today' => true])->assertSuccessful();
        Configuration::set('telegram_auto_enabled', '1');
        (new PrepareAutomaticAlertNotifications)->handle(app(AutomaticAlertNotifier::class));
        $this->assertDatabaseCount('notification_logs', 0);
        $this->artisan('siem:fetch-alerts', ['--today' => true])->assertSuccessful();
        $this->assertSame(0, Alert::where('source_id', 'while-disabled')->sole()->notificationLogs()->count());
        $this->assertSame(2, Alert::where('source_id', 'while-enabled')->sole()->notificationLogs()->count());
    }

    public function test_scheduler_only_recovers_preexisting_delivery_intents_and_does_not_scan_other_saved_alerts(): void
    {
        $old = $this->alert('unhandled-history');
        $new = $this->alert('new-with-committed-intent');
        app(AutomaticAlertNotifier::class)->prepare([$new]);
        for ($cycle = 0; $cycle < 2; $cycle++) {
            (new PrepareAutomaticAlertNotifications)->handle(app(AutomaticAlertNotifier::class));
        }
        Queue::assertPushed(SendAutomaticAlertNotification::class, 2);
        $this->assertDatabaseCount('notification_logs', 2);
        $this->assertSame(0, $old->notificationLogs()->count());
        $this->assertSame(2, $new->notificationLogs()->count());
    }

    public function test_recovery_reports_a_large_pending_backlog_and_keeps_the_batch_limit(): void
    {
        $notifier = app(AutomaticAlertNotifier::class);
        $notifier->prepare([$this->alert('recovery-backlog')]);
        $template = NotificationLog::firstOrFail()->getRawOriginal();
        unset($template['id']);
        $rows = [];
        for ($index = 0; $index < 499; $index++) {
            $rows[] = [...$template, 'automatic_key' => hash('sha256', 'recovery-backlog-'.$index)];
        }
        DB::table('notification_logs')->insert($rows);
        Log::spy();

        $notifier->dispatchPending();

        Queue::assertPushed(SendAutomaticAlertNotification::class, 500);
        $this->assertSame(501, NotificationLog::where('delivery_state', 'pending')->count());
        Log::shouldHaveReceived('info')->once()->with('[Automatic Telegram] Pending recovery backlog.', [
            'pending_total' => 501, 'batch_limit' => 500,
        ]);
    }
}
