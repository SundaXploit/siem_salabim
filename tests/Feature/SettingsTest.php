<?php

namespace Tests\Feature;

use App\Jobs\FetchWazuhAlerts;
use App\Models\Alert;
use App\Models\AlertAiAnalysis;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\OpenSearchService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_only_admin_can_open_settings(): void
    {
        $admin = $this->makeAdmin();
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk();

        $this->actingAs($analyst)
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_opensearch_save_preserves_a_blank_password_and_index_retrieves_non_secret_configuration(): void
    {
        $admin = $this->makeAdmin();
        Configuration::set('opensearch_password', 'saved-secret');
        Configuration::set('min_alert_level', 12);

        $response = $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.opensearch'), [
                'opensearch_host' => 'https://indexer.example.test:9200',
                'opensearch_username' => 'siem_alarm_service',
                'opensearch_password' => '',
                'opensearch_index' => 'siem-alarm-*',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.index'));

        $this->assertSame('https://indexer.example.test:9200', Configuration::get('opensearch_host'));
        $this->assertSame('siem_alarm_service', Configuration::get('opensearch_username'));
        $this->assertSame('siem-alarm-*', Configuration::get('opensearch_index'));
        $this->assertSame('12', Configuration::get('min_alert_level'));
        $this->assertSame('saved-secret', Configuration::get('opensearch_password'));

        $page = $this->actingAs($admin)->get(route('settings.index'));

        $page
            ->assertOk()
            ->assertViewHas('configs', fn ($configs) => $configs->get('opensearch_host') === 'https://indexer.example.test:9200'
                && $configs->get('opensearch_username') === 'siem_alarm_service'
                && $configs->get('opensearch_index') === 'siem-alarm-*'
                && ! $configs->has('opensearch_password')
            )
            ->assertDontSee('saved-secret');
    }

    public function test_only_admin_can_save_ai_configuration_and_the_key_is_encrypted(): void
    {
        $admin = $this->makeAdmin();
        $analyst = User::factory()->create(['role' => 'analyst']);
        $payload = [
            'ai_api_key' => 'test-secret-key',
            'ai_base_url' => 'https://api.amanai.dev/v1',
            'ai_model' => 'amanai/glm-5.3',
            'ai_snapshot_limit' => 10000,
            'min_alert_level' => 12,
            'ai_refresh_days' => 10,
        ];

        $this->actingAs($analyst)
            ->post(route('settings.ai'), $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.ai'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.index'));

        $stored = Configuration::query()->where('key', 'ai_api_key')->value('value');
        $this->assertNotSame('test-secret-key', $stored);
        $this->assertSame('test-secret-key', Configuration::get('ai_api_key'));
        $this->assertSame('10000', Configuration::get('ai_snapshot_limit'));
        $this->assertSame('12', Configuration::get('min_alert_level'));
        $this->assertSame('10', Configuration::get('ai_refresh_days'));

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Konfigurasi Analisis AI')
            ->assertDontSee('test-secret-key');
    }

    public function test_opensearch_test_uses_unsaved_form_configuration_without_persisting_it(): void
    {
        $admin = $this->makeAdmin();
        Configuration::set('opensearch_host', 'https://saved-indexer.example.test:9200');
        Configuration::set('opensearch_password', 'saved-password');

        $payload = [
            'opensearch_host' => 'https://temporary-indexer.example.test:9200',
            'opensearch_username' => 'temporary-service',
            'opensearch_password' => 'temporary-password',
            'opensearch_index' => 'wazuh-alerts-*',
        ];

        $this->mock(OpenSearchService::class, function (MockInterface $service) use ($payload): void {
            $service->shouldReceive('testConfiguration')
                ->once()
                ->withArgs(fn (array $configuration) => $configuration === $payload)
                ->andReturn([
                    'success' => true,
                    'status' => 200,
                    'index' => 'wazuh-alerts-*',
                    'count' => 42,
                    'message' => 'Koneksi dan akses indeks berhasil.',
                ]);
        });

        $this->actingAs($admin)
            ->postJson(route('settings.test.opensearch'), $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 42);

        $this->assertSame('https://saved-indexer.example.test:9200', Configuration::get('opensearch_host'));
        $this->assertSame('saved-password', Configuration::get('opensearch_password'));
    }

    public function test_telegram_test_uses_a_temporary_token_without_saving_it(): void
    {
        $admin = $this->makeAdmin();
        Configuration::set('telegram_bot_token', 'saved-bot-token');

        $this->mock(TelegramService::class, function (MockInterface $service): void {
            $service->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (string $chatId, string $message, ?string $token) => $chatId === '-1001234567890'
                    && $message !== ''
                    && $token === 'temporary-bot-token'
                )
                ->andReturn([
                    'success' => true,
                    'status' => 200,
                    'error' => null,
                ]);
        });

        $this->actingAs($admin)
            ->postJson(route('settings.test.telegram'), [
                'chat_id' => '-1001234567890',
                'telegram_bot_token' => 'temporary-bot-token',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 200);

        $this->assertSame('saved-bot-token', Configuration::get('telegram_bot_token'));
    }

    public function test_telegram_save_preserves_a_blank_token_and_stores_clean_chat_ids(): void
    {
        $admin = $this->makeAdmin();
        Configuration::set('telegram_bot_token', 'saved-bot-token');

        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.telegram'), [
                'telegram_bot_token' => '',
                'telegram_chat_ids' => ['  -100123  ', '', '  -100456'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.index'));

        $this->assertSame('saved-bot-token', Configuration::get('telegram_bot_token'));
        $this->assertSame(
            ['-100123', '-100456'],
            json_decode(Configuration::get('telegram_chat_ids'), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_admin_can_create_a_user_and_cannot_delete_own_account(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.users.create'), [
                'name' => 'New Analyst',
                'email' => 'new.analyst@example.test',
                'password' => 'safe-password-123',
                'password_confirmation' => 'safe-password-123',
                'role' => 'analyst',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.index'));

        $newUser = User::where('email', 'new.analyst@example.test')->firstOrFail();
        $this->assertSame('New Analyst', $newUser->name);
        $this->assertSame('analyst', $newUser->role);
        $this->assertTrue(Hash::check('safe-password-123', $newUser->password));

        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->delete(route('settings.users.delete', $admin))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('error', 'Tidak bisa menghapus akun sendiri.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_update_and_delete_another_user(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create([
            'name' => 'Existing Analyst',
            'role' => 'analyst',
        ]);

        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->patch(route('settings.users.update', $target), [
                'name' => 'Updated Administrator',
                'role' => 'admin',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.index'));

        $target->refresh();
        $this->assertSame('Updated Administrator', $target->name);
        $this->assertSame('admin', $target->role);

        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->delete(route('settings.users.delete', $target))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_preview_range_honors_both_start_and_end_dates(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAlert('before-range', '2026-08-09 16:59:59');
        $this->makeAlert('inside-range-one', '2026-08-09 17:00:00');
        $this->makeAlert('inside-range-two', '2026-08-15 16:59:59');
        $this->makeAlert('after-range', '2026-08-15 17:00:00');

        $this->actingAs($admin)
            ->getJson(route('settings.data.preview', [
                'scope' => 'alerts',
                'period_mode' => 'range',
                'date_from' => '2026-08-10',
                'date_to' => '2026-08-15',
            ]))
            ->assertOk()
            ->assertJsonPath('counts.alerts', 2)
            ->assertJsonPath('period.from', '2026-08-09T17:00:00+00:00')
            ->assertJsonPath('period.to_exclusive', '2026-08-15T17:00:00+00:00')
            ->assertJsonPath('period.label', '2026-08-10 s/d 2026-08-15');
    }

    public function test_preview_counts_every_entity_included_by_each_export_scope(): void
    {
        $admin = $this->makeAdmin();
        $inside = $this->makeAlert('inside-preview-scope', '2026-08-10 12:00:00');
        $outside = $this->makeAlert('outside-preview-scope', '2026-08-20 12:00:00');

        $this->makeAiAnalysis($inside, $admin);
        $this->makeAiAnalysis($inside, $admin);
        $this->makeAiAnalysis($outside, $admin);

        foreach ([$inside, $inside, $outside] as $index => $alert) {
            AlertTriage::create([
                'alert_id' => $alert->id,
                'user_id' => $admin->id,
                'action' => 'acknowledge',
                'reason' => "Preview triage {$index}",
                'created_at' => now(),
            ]);

            NotificationLog::create([
                'alert_id' => $alert->id,
                'user_id' => $admin->id,
                'chat_id' => '-100123',
                'message' => "Preview notification {$index}",
                'response_status' => 200,
                'sent_at' => now(),
            ]);
        }

        $expectedCounts = [
            'alerts' => [
                'alerts' => 1,
                'alert_ai_analyses' => 2,
                'triages' => 2,
                'notif_logs' => 2,
            ],
            'all' => [
                'alerts' => 1,
                'alert_ai_analyses' => 2,
                'triages' => 2,
                'notif_logs' => 2,
            ],
            'triages' => [
                'alerts' => 1,
                'triages' => 2,
            ],
            'notif_logs' => [
                'alerts' => 1,
                'notif_logs' => 2,
            ],
        ];

        foreach ($expectedCounts as $scope => $counts) {
            $response = $this->actingAs($admin)
                ->getJson(route('settings.data.preview', [
                    'scope' => $scope,
                    'period_mode' => 'range',
                    'date_from' => '2026-08-10',
                    'date_to' => '2026-08-10',
                ]))
                ->assertOk();

            $this->assertSame($counts, $response->json('counts'));
        }
    }

    public function test_data_selection_rejects_missing_or_invalid_periods(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('settings.data.preview', [
                'scope' => 'alerts',
                'period_mode' => 'range',
                'date_to' => '2026-08-15',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from']);

        $this->actingAs($admin)
            ->getJson(route('settings.data.preview', [
                'scope' => 'alerts',
                'period_mode' => 'range',
                'date_from' => '2026-08-16',
                'date_to' => '2026-08-15',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_direct_range_deletion_is_disabled_until_a_verified_export_exists(): void
    {
        $admin = $this->makeAdmin();
        $before = $this->makeAlert('before-delete', '2026-08-01 12:00:00');
        $inside = $this->makeAlert('inside-delete', '2026-08-10 12:00:00');
        $after = $this->makeAlert('after-delete', '2026-08-20 12:00:00');
        $beforeAnalysis = $this->makeAiAnalysis($before, $admin);
        $insideAnalysis = $this->makeAiAnalysis($inside, $admin);
        $afterAnalysis = $this->makeAiAnalysis($after, $admin);

        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.data.delete'), [
                'scope' => 'alerts',
                'period_mode' => 'range',
                'date_from' => '2026-08-10',
                'date_to' => '2026-08-10',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('error')
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('alerts', ['id' => $before->id]);
        $this->assertDatabaseHas('alerts', ['id' => $inside->id]);
        $this->assertDatabaseHas('alerts', ['id' => $after->id]);
        $this->assertDatabaseHas('alert_ai_analyses', ['id' => $beforeAnalysis->id]);
        $this->assertDatabaseHas('alert_ai_analyses', ['id' => $insideAnalysis->id]);
        $this->assertDatabaseHas('alert_ai_analyses', ['id' => $afterAnalysis->id]);
    }

    public function test_json_backup_for_a_range_contains_only_selected_alerts_and_keeps_all_data(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();
        $before = $this->makeAlert('before-backup', '2026-08-01 12:00:00');
        $inside = $this->makeAlert('inside-backup', '2026-08-10 12:00:00');
        $after = $this->makeAlert('after-backup', '2026-08-20 12:00:00');

        $response = $this->actingAs($admin)
            ->post(route('settings.data.backup'), [
                'format' => 'json',
                'scope' => 'alerts',
                'period_mode' => 'range',
                'date_from' => '2026-08-10',
                'date_to' => '2026-08-10',
            ]);

        $response->assertRedirect(route('settings.index'));

        $export = DataExport::query()->sole();
        $this->assertSame('verified', $export->status);
        Storage::disk('local')->assertExists($export->storage_path);
        Storage::disk('local')->assertExists($export->manifest_path);

        $response = $this->actingAs($admin)->get(route('settings.data.exports.download', $export));
        $response->assertOk()->assertDownload($export->filename)->assertStreamed();

        $backup = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $backup['backup_info']['total_alerts']);
        $this->assertSame([$inside->id], array_column($backup['alerts'], 'id'));
        $this->assertDatabaseHas('alerts', ['id' => $before->id]);
        $this->assertDatabaseHas('alerts', ['id' => $inside->id]);
        $this->assertDatabaseHas('alerts', ['id' => $after->id]);
    }

    public function test_legacy_backup_and_delete_only_exports_then_explicit_purge_deletes_selected_alerts(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();
        $before = $this->makeAlert('before-backup-delete', '2026-08-01 12:00:00');
        $inside = $this->makeAlert('inside-backup-delete', '2026-08-10 12:00:00');
        $after = $this->makeAlert('after-backup-delete', '2026-08-20 12:00:00');

        $response = $this->actingAs($admin)
            ->post(route('settings.data.backup-delete'), [
                'format' => 'json',
                'scope' => 'alerts',
                'period_mode' => 'range',
                'date_from' => '2026-08-10',
                'date_to' => '2026-08-10',
            ]);

        $response->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('alerts', ['id' => $before->id]);
        $this->assertDatabaseHas('alerts', ['id' => $inside->id]);
        $this->assertDatabaseHas('alerts', ['id' => $after->id]);

        $export = DataExport::query()->sole();
        $this->assertSame('verified', $export->status);

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertRedirect();

        $this->assertDatabaseHas('alerts', ['id' => $before->id]);
        $this->assertDatabaseMissing('alerts', ['id' => $inside->id]);
        $this->assertDatabaseHas('alerts', ['id' => $after->id]);
        $this->assertSame('completed', $export->fresh()->purge_status);
    }

    public function test_manual_fetch_queues_a_today_scan_without_contacting_opensearch(): void
    {
        $admin = $this->makeAdmin();
        config(['queue.default' => 'database']);
        Queue::fake();

        $this->actingAs($admin)
            ->post(route('settings.fetch-now'))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'masuk antrean')
            );

        Queue::assertPushed(FetchWazuhAlerts::class, fn (FetchWazuhAlerts $job): bool => $job->mode === 'today' && $job->trigger === 'settings-manual'
        );
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeAlert(string $wazuhAlertId, string $firstSeenAt): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => $wazuhAlertId,
            'rule_description' => 'Settings test alert',
            'agent_name' => 'test-agent',
            'rule_id' => '100001',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => $firstSeenAt,
        ]);
    }

    private function makeAiAnalysis(Alert $alert, User $user): AlertAiAnalysis
    {
        return $alert->aiAnalyses()->create([
            'requested_by_user_id' => $user->id,
            'verdict' => 'suspicious',
            'confidence' => 60,
            'summary' => 'Stored analysis for deletion test.',
            'supporting_indicators' => [],
            'legitimate_indicators' => [],
            'recommended_checks' => [],
            'limitations' => [],
            'model' => 'amanai/glm-5.3',
            'duration_ms' => 500,
            'generated_at' => now(),
        ]);
    }
}
