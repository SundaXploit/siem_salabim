<?php

namespace Tests\Feature;

use App\Exceptions\ApplicationUpdateException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function test_application_update_http_endpoints_have_been_removed(): void
    {
        $this->actingAs($this->admin());
        $this->get('/settings/application-update/check')->assertNotFound();
        $this->post('/settings/application-update')->assertNotFound();
        $this->get('/settings/application-update/1/status')->assertNotFound();
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
