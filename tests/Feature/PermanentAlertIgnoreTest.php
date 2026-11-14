<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\IngestionCheckpoint;
use App\Models\PermanentAlertIgnore;
use App\Models\User;
use App\Services\OpenSearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermanentAlertIgnoreTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['name' => 'Admin SOC', 'role' => 'admin']);
        $this->actingAs($user);

        return $user;
    }

    private function hit(string $id, ?string $description, ?string $ruleId = '10001'): array
    {
        return ['_id' => $id, '_index' => 'wazuh-alerts-4.x-2026.11.13', '_source' => [
            '@timestamp' => now('UTC')->toIso8601String(), 'rule' => ['id' => $ruleId, 'level' => 12, 'description' => $description],
            'agent' => ['name' => 'Staging'],
        ]];
    }

    private function alert(string $description): Alert
    {
        return Alert::create(OpenSearchService::parseHit($this->hit(fake()->uuid(), $description)));
    }

    private function rule(string $id = '10001'): PermanentAlertIgnore
    {
        return PermanentAlertIgnore::create(['rule_id' => $id, 'created_by_name' => 'Admin SOC']);
    }

    public function test_admin_can_add_an_unseen_rule_id_and_remove_it_without_touching_alert_history(): void
    {
        $admin = $this->admin();
        $old = $this->alert('Original description');
        $raw = $old->getRawOriginal('raw_data');
        $this->post(route('settings.permanent-ignores.store'), ['_section' => 'permanent-ignore', 'rule_id' => '40101'])
            ->assertSessionHasNoErrors()->assertRedirect(route('settings.index', ['section' => 'permanent-ignore']));
        $this->assertSame(['40101'], PermanentAlertIgnore::ruleIds());
        $this->assertDatabaseHas('permanent_alert_ignores', ['rule_id' => '40101', 'created_by_user_id' => $admin->id, 'description' => null]);
        $this->postJson(route('settings.permanent-ignores.store'), ['rule_id' => '40101'])->assertUnprocessable()->assertJsonValidationErrors('rule_id');
        $this->get(route('settings.index', ['section' => 'permanent-ignore']))->assertOk()->assertSee('Rule ID')->assertSee('40101')->assertDontSee('permanent-ignore-search');
        $this->delete(route('settings.permanent-ignores.destroy', PermanentAlertIgnore::firstOrFail()))->assertSessionHas('success');
        $this->assertDatabaseCount('permanent_alert_ignores', 0);
        $this->assertSame($raw, $old->fresh()->getRawOriginal('raw_data'));
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('notification_logs', 0);
        $log = ActivityLog::where('action', 'permanent_ignore_added')->where('result', 'success')->sole();
        $this->assertSame(['rule_id'], $log->context['fields']);
        $this->assertStringContainsString('Rule ID 40101', $log->subject);
        $this->assertDatabaseHas('activity_logs', ['action' => 'permanent_ignore_removed', 'result' => 'success']);
        $this->assertSame(0, $this->get(route('analyst-log.index'))->assertOk()->viewData('logs')->total());
    }

    public function test_invalid_rule_ids_and_non_admins_cannot_change_the_rules(): void
    {
        $this->admin();
        foreach (['', '*', '40*', '40101,40102', '-1', 'description', str_repeat('1', 65)] as $id) {
            $this->postJson(route('settings.permanent-ignores.store'), ['rule_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('rule_id');
        }
        $this->postJson(route('settings.permanent-ignores.store'), ['description' => 'Old payload', 'alert_id' => 1])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson(route('settings.permanent-ignores.store'), ['rule_id' => '40101'])->assertForbidden();
        $this->assertDatabaseCount('permanent_alert_ignores', 0);
        $rule = $this->rule();
        $this->deleteJson(route('settings.permanent-ignores.destroy', $rule))->assertForbidden();
        $this->assertDatabaseCount('permanent_alert_ignores', 1);
    }

    public function test_legacy_notes_are_visible_for_review_but_are_not_active_filters(): void
    {
        $this->admin();
        $this->rule('40101');
        DB::table('permanent_alert_ignores')->insert(['description' => 'Legacy title', 'description_hash' => hash('sha256', 'Legacy title'), 'created_by_name' => 'Original admin']);
        $this->assertSame(['40101'], PermanentAlertIgnore::ruleIds());
        $response = $this->get(route('settings.index', ['section' => 'permanent-ignore']))->assertOk()->assertSee('Legacy title')->assertSee('catatan aturan lama');
        $this->assertSame(1, $response->viewData('permanentIgnores')->total());
        $this->assertSame(1, $response->viewData('legacyPermanentIgnores')->total());
    }

    #[DataProvider('fetchModes')]
    public function test_all_fetch_modes_match_only_rule_id_and_preserve_old_alerts(array $arguments): void
    {
        $this->rule();
        $old = $this->alert('Original title');
        $old->update(['status' => 'acknowledged']);
        $raw = $old->getRawOriginal('raw_data');
        $hits = [
            $this->hit('blocked-renamed', 'Different title'), $this->hit('blocked-no-title', null),
            $this->hit('same-title-other-rule', 'Original title', '10002'),
            $this->hit('prefix-is-not-equal', 'Original title', '100010'),
            $this->hit('leading-zero-is-not-equal', 'Original title', '010001'),
            $this->hit('no-rule-id', 'Original title', null),
        ];
        $hits[1]['_source']['agent']['name'] = 'Another organization';
        $method = $arguments === [] ? 'fetchIncrementalAlertBatches' : 'fetchAlertBatches';
        $this->mock(OpenSearchService::class)->shouldReceive($method)->once()->andReturn([$hits]);
        $this->artisan('siem:fetch-alerts', $arguments)->assertSuccessful();
        $this->assertDatabaseMissing('alerts', ['source_id' => 'blocked-renamed']);
        $this->assertDatabaseMissing('alerts', ['source_id' => 'blocked-no-title']);
        $this->assertSame('acknowledged', $old->fresh()->status);
        $this->assertSame($raw, $old->fresh()->getRawOriginal('raw_data'));
        $this->assertDatabaseCount('alerts', isset($arguments['--dry-run']) ? 1 : 5);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public static function fetchModes(): array
    {
        return ['incremental' => [[]], 'today' => [['--today' => true]], 'archive' => [['--all' => true]], 'reconcile' => [['--lookback-days' => 2]], 'dry run' => [['--all' => true, '--dry-run' => true]]];
    }

    public function test_a_rule_added_while_source_pages_are_in_flight_takes_effect_before_the_next_insert(): void
    {
        $first = $this->hit('first', 'Allowed first event', '20002');
        $blocked = $this->hit('blocked', 'Any title');
        $allowed = $this->hit('second', 'Allowed second event', '20002');
        $pages = (function () use ($first, $blocked, $allowed) {
            yield [$first];
            $this->rule();
            yield [$blocked, $allowed];
        })();
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->once()->andReturn($pages);
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertSuccessful();
        $this->assertDatabaseCount('alerts', 2);
        $this->assertDatabaseMissing('alerts', ['source_id' => 'blocked']);
        $this->assertDatabaseCount('alert_triages', 0);
    }

    public function test_an_entirely_ignored_page_still_advances_the_incremental_checkpoint(): void
    {
        $this->rule();
        $this->mock(OpenSearchService::class)->shouldReceive('fetchIncrementalAlertBatches')->once()->andReturn([[$this->hit('blocked', 'Any title')]]);
        $this->artisan('siem:fetch-alerts')->assertSuccessful();
        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('ingestion_checkpoints', 1);
        $this->assertNotNull(IngestionCheckpoint::firstOrFail()->cursor_at);
    }

    public function test_one_long_lived_source_service_reloads_rule_ids_on_each_fetch(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler(array_map(fn () => new Response(200, [], '{"hits":{"hits":[]}}'), range(1, 3))));
        $stack->push(Middleware::history($history));
        $service = new class(new Client(['base_uri' => 'https://indexer.example.test:9200', 'handler' => $stack])) extends OpenSearchService
        {
            public function __construct(Client $client) { $this->client = $client; $this->index = 'wazuh-alerts-*'; }
        };
        $service->fetchAlerts(5, 10);
        $rule = $this->rule();
        $service->fetchAlerts(5, 10);
        $rule->delete();
        $service->fetchAlerts(5, 10);
        $queries = array_map(fn ($item) => json_decode((string) $item['request']->getBody(), true)['query'], $history);
        $this->assertSame(['range' => ['rule.level' => ['gte' => 5]]], $queries[0]);
        $this->assertSame([['terms' => ['rule.id' => ['10001']]]], $queries[1]['bool']['must_not']);
        $this->assertSame($queries[0], $queries[2]);
    }
}
