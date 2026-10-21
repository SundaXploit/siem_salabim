<?php

namespace Tests\Feature;

use App\Exceptions\ApplicationUpdateException;
use App\Jobs\ApplyApplicationUpdate;
use App\Models\ApplicationUpdate;
use App\Models\User;
use App\Services\AlertIngestionCoordinator;
use App\Services\ApplicationUpdateArtifact;
use App\Services\ApplicationUpdateAudit;
use App\Services\ApplicationUpdateFilesystem;
use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\ApplicationUpdateService;
use App\Services\ApplicationVersionService;
use App\Services\GitHubReleaseService;
use App\Services\QueueExecutionPolicy;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST_RELEASE_URL = 'https://api.github.com/repos/SundaXploit/siem_salabim/releases/latest';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_github_check_reports_that_no_official_release_exists_on_404(): void
    {
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response([], 404),
        ]);

        $result = app(GitHubReleaseService::class)->check(true);

        $this->assertSame('no_release', $result['state']);
        $this->assertSame($this->currentVersion(), $result['current_version']);
        $this->assertNull($result['latest_version']);
        $this->assertFalse($result['update_available']);
        $this->assertSame(config('application_updates.releases_url'), $result['releases_url']);
        Http::assertSent(fn ($request): bool => $request->url() === self::LATEST_RELEASE_URL
            && $request->hasHeader('Accept', 'application/vnd.github+json')
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
    }

    public function test_github_check_accepts_a_newer_stable_release_with_a_pinned_asset_and_digest(): void
    {
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($this->validRelease(), 200),
        ]);

        $result = app(GitHubReleaseService::class)->check(true);

        $this->assertSame('update_available', $result['state']);
        $this->assertSame($this->currentVersion(), $result['current_version']);
        $this->assertSame($this->nextVersion(), $result['latest_version']);
        $this->assertTrue($result['update_available']);
        $this->assertSame(901, $result['release_id']);
        $this->assertSame('v'.$this->nextVersion(), $result['release_tag']);
        $this->assertSame([
            'id' => 902,
            'name' => 'siem-salabim-v'.$this->nextVersion().'.zip',
            'api_url' => 'https://api.github.com/repos/SundaXploit/siem_salabim/releases/assets/902',
            'size' => 1_048_576,
            'sha256' => str_repeat('a', 64),
        ], $result['asset']);
    }

    public function test_github_check_rejects_a_malformed_or_non_stable_release(): void
    {
        $release = $this->validRelease();
        $release['tag_name'] = '0.2.0-beta.1';

        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($release, 200),
        ]);

        $this->expectException(ApplicationUpdateException::class);
        $this->expectExceptionMessage('SemVer stabil');

        app(GitHubReleaseService::class)->check(true);
    }

    public function test_github_check_rejects_an_asset_without_a_github_sha256_digest(): void
    {
        $release = $this->validRelease();
        unset($release['assets'][0]['digest']);

        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($release, 200),
        ]);

        $this->expectException(ApplicationUpdateException::class);
        $this->expectExceptionMessage('digest SHA-256');

        app(GitHubReleaseService::class)->check(true);
    }

    public function test_version_check_is_available_only_to_an_authenticated_admin(): void
    {
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($this->validRelease(), 200),
        ]);

        $this->get(route('settings.application-updates.check'))
            ->assertRedirect(route('login'));
        Http::assertNothingSent();

        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->getJson(route('settings.application-updates.check'))
            ->assertForbidden();
        Http::assertNothingSent();

        $admin = $this->admin();
        $this->actingAs($admin)
            ->getJson(route('settings.application-updates.check'))
            ->assertOk()
            ->assertJsonPath('state', 'update_available')
            ->assertJsonPath('latest_version', $this->nextVersion());

        Http::assertSentCount(1);
    }

    public function test_update_submission_is_available_only_to_an_authenticated_admin(): void
    {
        Queue::fake();
        Http::fake();
        $payload = $this->validSubmission();

        $this->post(route('settings.application-updates.store'), $payload)
            ->assertRedirect(route('login'));

        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->post(route('settings.application-updates.store'), $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('application_updates', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_admin_can_enqueue_a_verified_update_without_executing_it_in_the_web_request(): void
    {
        $this->enableAsynchronousSelfUpdate();
        Queue::fake();
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($this->validRelease(), 200),
        ]);
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $this->validSubmission());

        $response
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $update = ApplicationUpdate::sole();
        $this->assertSame('queued', $update->status);
        $this->assertSame('requested', $update->phase);
        $this->assertNull($update->started_at);
        $this->assertNull($update->completed_at);
        $this->assertSame($admin->id, $update->requested_by_user_id);
        $this->assertSame(str_repeat('a', 64), $update->asset_sha256);
        $this->assertDatabaseCount('application_update_events', 1);
        $this->assertDatabaseMissing('application_updates', [
            'requested_by_name' => 'password',
        ]);

        Queue::assertPushedOn('updates', ApplyApplicationUpdate::class);
        Queue::assertPushed(
            ApplyApplicationUpdate::class,
            fn (ApplyApplicationUpdate $job): bool => $job->applicationUpdateId === $update->id,
        );
        Http::assertSentCount(1);
    }

    public function test_stale_unique_worker_lock_is_reported_instead_of_leaving_a_phantom_queued_update(): void
    {
        $this->enableAsynchronousSelfUpdate();
        Queue::fake();
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($this->validRelease(), 200),
        ]);
        $lock = new UniqueLock(app(CacheRepository::class));
        $staleJob = new ApplyApplicationUpdate(999999);
        $this->assertTrue($lock->acquire($staleJob));

        try {
            $response = $this->actingAs($this->admin())
                ->from(route('settings.index'))
                ->post(route('settings.application-updates.store'), $this->validSubmission());

            $response
                ->assertRedirect(route('settings.index'))
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'masih memegang kunci'));
        } finally {
            $lock->release($staleJob);
        }

        $update = ApplicationUpdate::sole();
        $this->assertSame('failed', $update->status);
        $this->assertSame('dispatch_blocked', $update->phase);
        $this->assertNotNull($update->completed_at);
        $this->assertDatabaseHas('application_update_events', [
            'application_update_id' => $update->id,
            'phase' => 'dispatch_blocked',
            'level' => 'error',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_unique_lock_backend_failure_is_recorded_instead_of_leaving_a_queued_update(): void
    {
        $this->enableAsynchronousSelfUpdate();
        Queue::fake();
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($this->validRelease(), 200),
        ]);
        $this->mock(CacheRepository::class, function ($cache): void {
            $cache->shouldReceive('lock')
                ->once()
                ->andThrow(new \RuntimeException('cache credentials leaked only if returned'));
        });

        $response = $this->actingAs($this->admin())
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $this->validSubmission());

        $response
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas(
                'error',
                'Update gagal dimasukkan ke antrean; aplikasi belum diubah.',
            );

        $update = ApplicationUpdate::sole();
        $this->assertSame('failed', $update->status);
        $this->assertSame('dispatch_failed', $update->phase);
        $this->assertNotNull($update->completed_at);
        $this->assertStringNotContainsString('cache credentials', (string) $update->error_message);
        $this->assertDatabaseHas('application_update_events', [
            'application_update_id' => $update->id,
            'phase' => 'dispatch_failed',
            'level' => 'error',
            'message' => 'Kunci antrean update tidak dapat diperoleh dari cache.',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_update_submission_requires_the_current_password_exact_confirmation_and_backup_acknowledgement(): void
    {
        $this->enableAsynchronousSelfUpdate();
        Queue::fake();
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($this->validRelease(), 200),
        ]);
        $admin = $this->admin();

        $badPassword = $this->validSubmission(['current_password' => 'not-the-password']);
        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $badPassword)
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('current_password');

        $badConfirmation = $this->validSubmission([
            'confirmation' => 'UPDATE '.$this->nextVersion(),
        ]);
        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $badConfirmation)
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('confirmation');

        $missingAcknowledgement = $this->validSubmission();
        unset($missingAcknowledgement['backup_acknowledged']);
        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $missingAcknowledgement)
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('backup_acknowledged');

        $this->assertDatabaseCount('application_updates', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_server_side_feature_backup_and_async_queue_guards_all_fail_closed(): void
    {
        Queue::fake();
        Http::fake([
            self::LATEST_RELEASE_URL => Http::response($this->validRelease(), 200),
        ]);
        $admin = $this->admin();

        config([
            'application_updates.self_update_enabled' => false,
            'application_updates.backup_confirmed' => true,
            'queue.default' => 'database',
        ]);
        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $this->validSubmission())
            ->assertSessionHas('error', 'Self-update dinonaktifkan oleh administrator server.');

        config([
            'application_updates.self_update_enabled' => true,
            'application_updates.backup_confirmed' => false,
        ]);
        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $this->validSubmission())
            ->assertSessionHas('error', 'Update dibatalkan: server belum mengonfirmasi backup penuh aplikasi dan database.');

        config([
            'application_updates.backup_confirmed' => true,
            'queue.default' => 'sync',
        ]);
        $this->actingAs($admin)
            ->from(route('settings.index'))
            ->post(route('settings.application-updates.store'), $this->validSubmission())
            ->assertSessionHas('error', 'Update memerlukan queue asynchronous dan worker khusus queue updates.');

        $this->assertDatabaseCount('application_updates', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_update_status_exposes_only_sanitized_operational_fields(): void
    {
        $admin = $this->admin();
        $secret = 'github-token-and-internal-path-must-not-leak';
        $update = ApplicationUpdate::create([
            'uuid' => (string) Str::uuid(),
            'requested_by_user_id' => $admin->id,
            'requested_by_name' => $admin->name,
            'from_version' => $this->currentVersion(),
            'target_version' => $this->nextVersion(),
            'release_id' => 901,
            'release_tag' => 'v'.$this->nextVersion(),
            'release_url' => 'https://github.com/SundaXploit/siem_salabim/releases/tag/v'.$this->nextVersion(),
            'asset_id' => 902,
            'asset_name' => 'siem-salabim-v'.$this->nextVersion().'.zip',
            'asset_api_url' => "https://api.github.com/private/{$secret}",
            'asset_size' => 1_048_576,
            'asset_sha256' => str_repeat('b', 64),
            'status' => 'failed',
            'phase' => 'failed',
            'backup_path' => "C:/private/{$secret}",
            'completed_at' => now(),
            'error_message' => 'Update dibatalkan dengan aman.',
            'metadata' => ['private_detail' => $secret],
        ]);
        $update->events()->create([
            'phase' => 'failed',
            'level' => 'error',
            'message' => 'Update dibatalkan sebelum migrasi.',
            'context' => ['raw_process_output' => $secret],
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('settings.application-updates.status', $update))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('phase', 'failed')
            ->assertJsonPath('error_message', 'Update dibatalkan dengan aman.')
            ->assertJsonPath('events.0.message', 'Update dibatalkan sebelum migrasi.');

        $body = $response->getContent();
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('asset_api_url', $body);
        $this->assertStringNotContainsString('asset_sha256', $body);
        $this->assertStringNotContainsString('backup_path', $body);
        $this->assertStringNotContainsString('metadata', $body);
        $this->assertStringNotContainsString('context', $body);
    }

    public function test_update_status_is_not_visible_to_a_guest_or_analyst(): void
    {
        $admin = $this->admin();
        $update = $this->storedUpdate($admin);

        $this->get(route('settings.application-updates.status', $update))
            ->assertRedirect(route('login'));

        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->getJson(route('settings.application-updates.status', $update))
            ->assertForbidden();
    }

    public function test_update_refuses_a_direct_cli_ingestion_holding_the_shared_fetch_lock(): void
    {
        $this->enableAsynchronousSelfUpdate();
        $marker = storage_path('framework/testing/update-cli-ingestion-'.uniqid('', true).'.lock');
        config([
            'application_updates.refuse_dirty_worktree' => false,
            'application_updates.runtime_marker' => $marker,
        ]);

        $admin = $this->admin();
        $update = $this->storedUpdate($admin);
        $artifacts = \Mockery::mock(ApplicationUpdateArtifact::class);
        $artifacts->shouldNotReceive('prepare');
        $filesystem = \Mockery::mock(ApplicationUpdateFilesystem::class);
        $filesystem->shouldNotReceive('backup');
        $fetchLock = Cache::lock('siem_fetch_running', 600);
        $this->assertTrue($fetchLock->get());

        $service = new ApplicationUpdateService(
            $artifacts,
            $filesystem,
            app(ApplicationUpdateAudit::class),
            app(ApplicationVersionService::class),
            app(ApplicationUpdateRuntimeGuard::class),
            app(QueueExecutionPolicy::class),
            app(AlertIngestionCoordinator::class),
        );

        try {
            $service->apply($update);
            $this->fail('Update must stop while a direct ingestion command owns the shared fetch lock.');
        } catch (ApplicationUpdateException $exception) {
            $this->assertStringContainsString('ingestion Wazuh', $exception->getMessage());
        } finally {
            $fetchLock->release();
            if (is_file($marker)) {
                unlink($marker);
            }
        }

        $update->refresh();
        $this->assertSame('failed', $update->status);
        $this->assertSame('failed', $update->phase);
        $this->assertFileDoesNotExist($marker);
    }

    /** @return array<string, mixed> */
    private function validRelease(?string $version = null): array
    {
        $version ??= $this->nextVersion();

        return [
            'id' => 901,
            'tag_name' => "v{$version}",
            'draft' => false,
            'prerelease' => false,
            'html_url' => "https://github.com/SundaXploit/siem_salabim/releases/tag/v{$version}",
            'published_at' => '2026-10-16T10:00:00Z',
            'assets' => [[
                'id' => 902,
                'name' => "siem-salabim-v{$version}.zip",
                'url' => 'https://api.github.com/repos/SundaXploit/siem_salabim/releases/assets/902',
                'size' => 1_048_576,
                'digest' => 'sha256:'.str_repeat('a', 64),
            ]],
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validSubmission(array $overrides = []): array
    {
        return array_replace([
            'target_version' => $this->nextVersion(),
            'confirmation' => 'UPDATE v'.$this->nextVersion(),
            'current_password' => 'password',
            'backup_acknowledged' => '1',
        ], $overrides);
    }

    private function enableAsynchronousSelfUpdate(): void
    {
        config([
            'application_updates.self_update_enabled' => true,
            'application_updates.backup_confirmed' => true,
            'application_updates.queue' => 'updates',
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'password' => 'password',
        ]);
    }

    private function storedUpdate(User $requester): ApplicationUpdate
    {
        return ApplicationUpdate::create([
            'uuid' => (string) Str::uuid(),
            'requested_by_user_id' => $requester->id,
            'requested_by_name' => $requester->name,
            'from_version' => $this->currentVersion(),
            'target_version' => $this->nextVersion(),
            'release_id' => 901,
            'release_tag' => 'v'.$this->nextVersion(),
            'release_url' => 'https://github.com/SundaXploit/siem_salabim/releases/tag/v'.$this->nextVersion(),
            'asset_id' => 902,
            'asset_name' => 'siem-salabim-v'.$this->nextVersion().'.zip',
            'asset_api_url' => 'https://api.github.com/repos/SundaXploit/siem_salabim/releases/assets/902',
            'asset_size' => 1_048_576,
            'asset_sha256' => str_repeat('a', 64),
            'status' => 'queued',
            'phase' => 'requested',
        ]);
    }

    private function currentVersion(): string
    {
        return trim((string) file_get_contents(base_path('VERSION')));
    }

    private function nextVersion(): string
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', $this->currentVersion()));

        return implode('.', [$major, $minor, $patch + 1]);
    }
}
