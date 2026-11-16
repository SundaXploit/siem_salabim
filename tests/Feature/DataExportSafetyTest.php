<?php

namespace Tests\Feature;

use App\Jobs\GenerateDataExport;
use App\Jobs\PurgeDataExport;
use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\DataExport;
use App\Models\User;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\DataExportArchiveService;
use App\Services\DataExportPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DataExportSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_is_persisted_with_a_verified_manifest_before_download(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('manifest-alert');

        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload())
            ->assertRedirect(route('settings.index', ['section' => 'data']));

        $export = DataExport::query()->sole();
        $this->assertSame('verified', $export->status);
        $this->assertNotNull($export->verified_at);
        $this->assertSame(64, strlen((string) $export->checksum));
        $this->assertSame(['alerts' => 1, 'alert_ai_analyses' => 0, 'alert_triages' => 0, 'manual_notification_operations' => 0, 'notification_logs' => 0,
            'encrypted_search_tokens' => DB::table('encrypted_search_tokens')->count()], $export->record_counts);

        $manifest = json_decode(
            Storage::disk('local')->get($export->manifest_path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($export->uuid, $manifest['export_uuid']);
        $this->assertSame($export->checksum, $manifest['archive']['sha256']);
        $this->assertSame($alert->id, $export->snapshot_boundaries['alerts']);

        $download = $this->actingAs($admin)
            ->get($this->authorizedDownloadUrl($export))
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
    }

    public function test_record_count_verification_is_independent_of_json_key_order(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('record-count-order-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();

        $manifest = json_decode(
            Storage::disk('local')->get($export->manifest_path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $manifest['source']['record_counts'] = [
            'encrypted_search_tokens' => $export->record_counts['encrypted_search_tokens'],
            'manual_notification_operations' => 0,
            'alert_triages' => 0,
            'notification_logs' => 0,
            'alerts' => 1,
            'alert_ai_analyses' => 0,
        ];
        $manifestJson = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        Storage::disk('local')->put($export->manifest_path, $manifestJson);

        // Simulate a database engine that returns native JSON object keys in a
        // different order and numeric values as integer strings.
        $export->forceFill([
            'record_counts' => [
                'encrypted_search_tokens' => (string) $export->record_counts['encrypted_search_tokens'],
                'manual_notification_operations' => '0',
                'notification_logs' => '0',
                'alert_ai_analyses' => '0',
                'alert_triages' => '0',
                'alerts' => '1',
            ],
            'manifest_checksum' => hash('sha256', $manifestJson),
        ])->save();

        $this->actingAs($admin)
            ->get($this->authorizedDownloadUrl($export))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('alerts', ['id' => $alert->id]);
        $this->assertSame('completed', $export->fresh()->purge_status);
    }

    public function test_json_and_sql_exports_are_standard_zip_files_readable_without_a_password(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->makeAlert('standard-export');
        foreach (['json', 'sql'] as $format) {
            $this->post(route('settings.data.backup'), [...$this->payload(), 'format' => $format])->assertSessionHasNoErrors();
            $export = DataExport::latest('id')->firstOrFail();
            $this->assertTrue($export->isVerified());
            $this->assertNull($export->archive_password);
            $manifest = json_decode(Storage::disk('local')->get($export->manifest_path), true);
            $this->assertSame('none', $manifest['archive']['encryption']);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open(Storage::disk('local')->path($export->storage_path)));
            $this->assertSame(\ZipArchive::EM_NONE, $zip->statIndex(0)['encryption_method']);
            $this->assertSame(\ZipArchive::CM_DEFLATE, $zip->statIndex(0)['comp_method']);
            $this->assertIsString($zip->getFromName('data.'.$format));
            $zip->close();
            $this->get($this->authorizedDownloadUrl($export))->assertOk()->assertHeader('Content-Type', 'application/zip');
        }
    }

    public function test_download_requires_account_password_and_a_short_lived_user_bound_signature(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->makeAlert('download-auth');
        $this->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::sole();
        $this->getJson(route('settings.data.exports.status', $export))->assertOk()
            ->assertJsonMissingPath('archive_password')->assertJsonMissingPath('download_url')->assertJsonPath('download_available', true);
        $this->get(route('settings.data.exports.download', $export))->assertForbidden();
        $authorizeUrl = route('settings.data.exports.authorize-download', $export);
        $this->postJson($authorizeUrl, [])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson($authorizeUrl, ['current_password' => 'wrong-password'])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password')->assertJsonMissingPath('download_url');
        $authorization = $this->postJson($authorizeUrl, ['current_password' => 'password'])->assertOk()
            ->assertJsonMissingPath('archive_password')->assertHeader('Cache-Control', 'no-store, private');
        $downloadUrl = $authorization->json('download_url');
        $this->get($downloadUrl)->assertOk();
        $otherAdmin = User::factory()->create(['role' => 'admin', 'password' => 'another-account-password']);
        $this->actingAs($otherAdmin)->get($downloadUrl)->assertForbidden();
        $this->postJson($authorizeUrl, ['current_password' => 'password'])->assertUnprocessable();
        $this->actingAs($admin);
        $this->travel(3)->minutes();
        $this->get($downloadUrl)->assertForbidden();
        $export->forceFill(['status' => 'failed'])->save();
        $this->getJson(route('settings.data.exports.status', $export))->assertJsonPath('download_available', false);
        $this->postJson($authorizeUrl, ['current_password' => 'password'])->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson($authorizeUrl, ['current_password' => 'password'])->assertForbidden();
    }

    public function test_download_verification_is_rate_limited(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->makeAlert('download-rate-limit');
        $this->post(route('settings.data.backup'), $this->payload());
        $authorizeUrl = route('settings.data.exports.authorize-download', DataExport::sole());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($authorizeUrl, ['current_password' => 'incorrect'])->assertUnprocessable();
        }
        $this->postJson($authorizeUrl, ['current_password' => 'password'])->assertTooManyRequests()->assertJsonMissingPath('download_url');
    }

    public function test_older_encrypted_exports_download_as_standard_zip_without_changing_the_backup(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->makeAlert('legacy-zip');
        $this->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::sole();
        $payload = $this->readExportPayload($export);
        $disk = Storage::disk('local');
        foreach ([\ZipArchive::EM_AES_256, \ZipArchive::EM_TRAD_PKWARE] as $encryption) {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($disk->path($export->storage_path), \ZipArchive::OVERWRITE));
            $zip->addFromString('data.json', $payload);
            $zip->setEncryptionName('data.json', $encryption, 'legacy-secret');
            $this->assertTrue($zip->close());
            clearstatcache();
            $manifest = json_decode($disk->get($export->manifest_path), true);
            $manifest['archive']['sha256'] = hash_file('sha256', $disk->path($export->storage_path));
            $manifest['archive']['bytes'] = $disk->size($export->storage_path);
            $manifest['archive']['encryption'] = $encryption === \ZipArchive::EM_AES_256 ? 'AES-256' : 'ZipCrypto';
            $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);
            $disk->put($export->manifest_path, $manifestJson);
            $export->forceFill(['archive_password' => 'legacy-secret', 'checksum' => $manifest['archive']['sha256'],
                'file_size' => $manifest['archive']['bytes'], 'manifest_checksum' => hash('sha256', $manifestJson)])->save();
            $response = $this->get($this->authorizedDownloadUrl($export))->assertOk();
            $downloadPath = $response->baseResponse->getFile()->getPathname();
            $this->assertTrue($zip->open($downloadPath));
            $this->assertSame(\ZipArchive::EM_NONE, $zip->statIndex(0)['encryption_method']);
            $this->assertSame($payload, $zip->getFromName('data.json'));
            $zip->close();
            $this->assertSame($export->checksum, hash_file('sha256', $disk->path($export->storage_path)));
            ob_start();
            try {
                $response->baseResponse->sendContent();
            } finally {
                ob_end_clean();
            }
            $this->assertFileDoesNotExist($downloadPath);
        }
    }

    public function test_range_uses_jakarta_day_as_a_half_open_utc_interval(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $before = $this->makeAlert('before-wib-day', '2026-10-15 16:59:59');
        $start = $this->makeAlert('start-wib-day', '2026-10-15 17:00:00');
        $end = $this->makeAlert('end-wib-day', '2026-10-16 16:59:59');
        $after = $this->makeAlert('after-wib-day', '2026-10-16 17:00:00');

        $this->actingAs($admin)->post(route('settings.data.backup'), [
            'format' => 'json',
            'scope' => 'alerts',
            'period_mode' => 'range',
            'date_from' => '2026-10-16',
            'date_to' => '2026-10-16',
        ])->assertRedirect(route('settings.index', ['section' => 'data']));

        $export = DataExport::query()->sole();
        $this->assertSame('2026-10-15 17:00:00', $export->period_start?->toDateTimeString());
        $this->assertSame('2026-10-16 17:00:00', $export->period_end?->toDateTimeString());

        $archive = json_decode(
            $this->readExportPayload($export),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $exportedIds = array_column($archive['alerts'], 'id');

        $this->assertSame([$start->id, $end->id], $exportedIds);
        $this->assertNotContains($before->id, $exportedIds);
        $this->assertNotContains($after->id, $exportedIds);
    }

    public function test_purge_refuses_a_tampered_archive_and_keeps_source_rows(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('tamper-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();

        Storage::disk('local')->put($export->storage_path, 'tampered');

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('alerts', ['id' => $alert->id]);
        $this->assertSame('failed', $export->fresh()->purge_status);
    }

    public function test_download_refuses_a_tampered_archive(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('tampered-download-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();

        Storage::disk('local')->put($export->storage_path, 'tampered');

        $this->actingAs($admin)
            ->get($this->authorizedDownloadUrl($export))
            ->assertConflict();
    }

    public function test_purge_refuses_source_changes_made_after_the_verified_export(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('changed-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();

        $alert->update(['rule_description' => 'Changed after export']);

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('alerts', ['id' => $alert->id]);
        $this->assertSame('failed', $export->fresh()->purge_status);
    }

    public function test_purge_delete_and_completed_status_roll_back_together(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('atomic-purge-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION test_reject_purge() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION 'forced completed status failure'; END;
                $$;
                CREATE TRIGGER reject_completed_purge BEFORE UPDATE OF purge_status ON data_exports
                FOR EACH ROW WHEN (NEW.purge_status = 'completed') EXECUTE FUNCTION test_reject_purge()
                SQL);
        } else {
            DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_completed_purge
            BEFORE UPDATE OF purge_status ON data_exports
            WHEN NEW.purge_status = 'completed'
            BEGIN
                SELECT RAISE(ABORT, 'forced completed status failure');
            END
            SQL);
        }

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('alerts', ['id' => $alert->id]);
        $export->refresh();
        $this->assertSame('failed', $export->purge_status);
        $this->assertNull($export->purged_at);
        $this->assertNull($export->purged_counts);
    }

    public function test_failed_callback_does_not_overwrite_completed_purge(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('completed-state-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();
        $export->forceFill([
            'purge_status' => 'completed',
            'purged_at' => now(),
        ])->save();

        (new PurgeDataExport($export->id))->failed(new RuntimeException('late callback'));

        $this->assertSame('completed', $export->fresh()->purge_status);
    }

    public function test_late_failed_callback_does_not_overwrite_a_verified_export(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('verified-export-state-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();

        (new GenerateDataExport($export->id))->failed(new RuntimeException('late callback'));

        $this->assertSame('verified', $export->fresh()->status);
    }

    public function test_stale_running_purge_is_reclaimed_after_timeout(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('stale-purge-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();
        $export->forceFill([
            'purge_status' => 'running',
            'purge_started_at' => now()->subSeconds(1801),
        ])->save();

        (new PurgeDataExport($export->id))->handle(app(DataExportPurgeService::class));

        $this->assertDatabaseMissing('alerts', ['id' => $alert->id]);
        $this->assertSame('completed', $export->fresh()->purge_status);
    }

    public function test_active_export_guard_prevents_queue_spam_and_uses_exports_queue(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('queued-export-alert');

        $this->actingAs($admin)
            ->post(route('settings.data.backup'), $this->payload())
            ->assertSessionHas('success');
        $this->actingAs($admin)
            ->post(route('settings.data.backup'), $this->payload())
            ->assertSessionHas('error');

        $this->assertDatabaseCount('data_exports', 1);
        Queue::assertPushedOn('exports', GenerateDataExport::class);
        Queue::assertPushed(GenerateDataExport::class, 1);
    }

    public function test_purge_uses_exports_queue(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('queued-purge-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();
        Queue::fake();

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertSessionHas('success');

        Queue::assertPushedOn('exports', PurgeDataExport::class);
    }

    public function test_purge_aborts_before_delete_when_hard_row_cap_is_exceeded(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->makeAlert('cap-first-alert');
        $second = $this->makeAlert('cap-second-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();
        config(['data_exports.purge_max_rows' => 1]);

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('alerts', ['id' => $first->id]);
        $this->assertDatabaseHas('alerts', ['id' => $second->id]);
        $this->assertSame('failed', $export->fresh()->purge_status);
    }

    public function test_triage_purge_cap_counts_parent_alerts_in_the_full_workload(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->makeAlert('triage-cap-first-alert');
        $second = $this->makeAlert('triage-cap-second-alert');
        $triage = AlertTriage::create([
            'alert_id' => $first->id,
            'user_id' => $admin->id,
            'action' => 'acknowledge',
            'reason' => 'Cap regression test',
            'created_at' => now(),
        ]);
        $payload = $this->payload();
        $payload['scope'] = 'triages';
        $this->actingAs($admin)->post(route('settings.data.backup'), $payload);
        $export = DataExport::query()->sole();
        config(['data_exports.purge_max_rows' => 2]);

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('alerts', ['id' => $first->id]);
        $this->assertDatabaseHas('alerts', ['id' => $second->id]);
        $this->assertDatabaseHas('alert_triages', ['id' => $triage->id]);
        $this->assertSame('failed', $export->fresh()->purge_status);
    }

    public function test_public_export_disk_is_rejected_before_an_export_row_is_created(): void
    {
        config(['data_exports.disk' => 'public']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('public-disk-alert');

        $this->actingAs($admin)
            ->post(route('settings.data.backup'), $this->payload())
            ->assertSessionHas('error');

        $this->assertDatabaseCount('data_exports', 0);
    }

    public function test_sync_queue_is_rejected_before_an_export_row_or_file_is_created(): void
    {
        Storage::fake('local');
        Queue::fake();
        config([
            'data_exports.require_async_queue' => true,
            'queue.default' => 'sync',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('sync-queue-export-alert');

        $this->actingAs($admin)
            ->post(route('settings.data.backup'), $this->payload())
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('data_exports', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_sync_queue_is_rejected_before_a_purge_is_claimed_or_data_is_deleted(): void
    {
        Storage::fake('local');
        config(['data_exports.require_async_queue' => false]);
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('sync-queue-purge-alert');
        $this->actingAs($admin)->post(route('settings.data.backup'), $this->payload());
        $export = DataExport::query()->sole();

        config([
            'data_exports.require_async_queue' => true,
            'queue.default' => 'sync',
        ]);
        Queue::fake();

        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('alerts', ['id' => $alert->id]);
        $this->assertNull($export->fresh()->purge_status);
        Queue::assertNothingPushed();
    }

    public function test_update_runtime_guard_rejects_new_export_and_purge_requests(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeAlert('update-guard-controller-alert');
        $export = $this->makeQueuedExport($admin, [
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        $guard = Mockery::mock(ApplicationUpdateRuntimeGuard::class);
        $guard->shouldReceive('isActive')->twice()->andReturnTrue();
        $this->app->instance(ApplicationUpdateRuntimeGuard::class, $guard);

        $this->actingAs($admin)
            ->post(route('settings.data.backup'), $this->payload())
            ->assertSessionHas('error');
        $this->actingAs($admin)
            ->post(route('settings.data.exports.purge', $export))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('data_exports', 1);
        $this->assertNull($export->fresh()->purge_status);
        Queue::assertNothingPushed();
    }

    public function test_export_worker_returns_claim_to_queue_if_update_starts_during_claim(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $export = $this->makeQueuedExport($admin);
        $guard = Mockery::mock(ApplicationUpdateRuntimeGuard::class);
        $guard->shouldReceive('isActive')->twice()->andReturn(false, true);
        $archives = Mockery::mock(DataExportArchiveService::class);
        $archives->shouldNotReceive('generate');

        (new GenerateDataExport($export->id))->handle($archives, $guard);

        $this->assertSame('queued', $export->fresh()->status);
    }

    public function test_purge_worker_returns_claim_to_queue_if_update_starts_during_claim(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = $this->makeAlert('update-guard-purge-worker-alert');
        $export = $this->makeQueuedExport($admin, [
            'status' => 'verified',
            'verified_at' => now(),
            'purge_status' => 'queued',
        ]);
        $guard = Mockery::mock(ApplicationUpdateRuntimeGuard::class);
        $guard->shouldReceive('isActive')->twice()->andReturn(false, true);
        $purger = Mockery::mock(DataExportPurgeService::class);
        $purger->shouldNotReceive('purge');

        (new PurgeDataExport($export->id))->handle($purger, $guard);

        $export->refresh();
        $this->assertSame('queued', $export->purge_status);
        $this->assertNull($export->purge_started_at);
        $this->assertDatabaseHas('alerts', ['id' => $alert->id]);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'format' => 'json',
            'scope' => 'alerts',
            'period_mode' => 'range',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ];
    }

    private function makeAlert(string $wazuhAlertId, string $firstSeenAt = '2026-10-16 12:00:00'): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => $wazuhAlertId,
            'rule_description' => 'Export safety test',
            'agent_name' => 'test-agent',
            'rule_id' => '100001',
            'rule_level' => 12,
            'status' => 'new',
            'first_seen_at' => $firstSeenAt,
            'raw_data' => ['test' => true],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeQueuedExport(User $admin, array $overrides = []): DataExport
    {
        return DataExport::create(array_replace([
            'uuid' => (string) Str::uuid(),
            'requested_by_user_id' => $admin->id,
            'requested_by_name' => $admin->name,
            'scope' => 'alerts',
            'format' => 'json',
            'status' => 'queued',
            'period_label' => 'Test update guard',
            'storage_disk' => 'local',
            'filename' => 'update-guard.json',
        ], $overrides));
    }

    private function authorizedDownloadUrl(DataExport $export): string
    {
        return $this->postJson(route('settings.data.exports.authorize-download', $export), ['current_password' => 'password'])
            ->assertOk()->json('download_url');
    }

    private function readExportPayload(DataExport $export): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->storage_path)));
        $payload = $zip->getFromName('data.'.$export->format);
        $zip->close();
        $this->assertIsString($payload);

        return $payload;
    }
}
