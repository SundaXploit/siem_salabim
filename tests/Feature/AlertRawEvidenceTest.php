<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\PermanentAlertIgnore;
use App\Models\User;
use App\Services\OpenSearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertRawEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function event(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/wazuh-pam-session.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function hit(array $event): array
    {
        return ['_index' => 'wazuh-alerts-4.x-2026.11.12', '_id' => 'source-document-id', '_source' => $event];
    }

    private function evidence(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertSame(1, preg_match('/<script type="application\/json" x-ref="rawEvidence">(.*?)<\/script>/s', $response->getContent(), $matches));

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_import_and_detail_preserve_the_full_wazuh_event_including_unmapped_fields(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->forAgent('007')->create());
        Configuration::set('min_alert_level', 12);
        config(['services.opensearch.fetch_page_delay_ms' => 0]);
        $source = $this->event();
        $source['custom_metadata'] = ['caseSensitiveKey' => '  Exact value.  ', 'enabled' => false, 'unknown' => null, 'payload' => '</script><img src=x onerror=alert(1)>'];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['_scroll_id' => 'first-page', 'hits' => ['hits' => [$this->hit($source)]]], JSON_THROW_ON_ERROR)),
            new Response(200, [], '{"hits":{"hits":[]}}'),
            new Response(200, [], '{"succeeded":true}'),
        ]));
        $history = [];
        $stack->push(Middleware::history($history));
        $client = new Client(['base_uri' => 'https://indexer.example.test:9200', 'handler' => $stack]);
        $this->app->instance(OpenSearchService::class, new class($client) extends OpenSearchService
        {
            public function __construct(Client $client)
            {
                $this->client = $client;
                $this->index = 'wazuh-source-alias';
            }
        });

        $this->artisan('siem:fetch-alerts', ['--today' => true])->assertSuccessful();
        $alert = Alert::sole();
        $this->assertSame($source, $alert->raw_data);
        $this->assertSame('System user successfully logged to the system.', $alert->raw_data['rule']['description']);
        $this->assertArrayNotHasKey('description', $alert->raw_data);
        $this->assertSame($source, $this->evidence($this->get(route('alerts.show', $alert))));
        $this->assertSame($source, $this->evidence($this->get(route('alerts.bulk-detail', ['alert_ids' => [$alert->id]])))['alert_'.$alert->id]);
        $this->assertArrayNotHasKey('_source', json_decode((string) $history[0]['request']->getBody(), true));
        $this->assertCount(3, $history);
        Http::assertNothingSent();
    }

    #[DataProvider('historicalStatuses')]
    public function test_permanent_ignore_and_replay_never_rewrite_old_alert_metadata_or_detail(string $status): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $source = $this->event();
        $hit = $this->hit($source);
        $alert = Alert::create([...OpenSearchService::parseHit($hit), 'status' => $status]);
        $alert->forceFill(['created_at' => now()->subMonth()])->save();
        $original = $alert->refresh()->getRawOriginal();

        $this->post(route('settings.permanent-ignores.store'), ['rule_id' => $alert->rule_id])->assertSessionHasNoErrors();
        $rule = PermanentAlertIgnore::sole();
        $this->assertSame($original, $alert->fresh()->getRawOriginal());
        $this->assertSame($source, $this->evidence($this->get(route('alerts.show', $alert))));

        $changedHit = $hit;
        $changedHit['_source']['full_log'] = 'Changed upstream event must not overwrite saved evidence';
        $changedHit['_source']['rule']['mitre']['id'] = ['T0000'];
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->twice()->andReturn([[$changedHit]]);
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertSuccessful();
        $this->assertSame($original, $alert->fresh()->getRawOriginal());
        $this->delete(route('settings.permanent-ignores.destroy', $rule))->assertSessionHasNoErrors();
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertSuccessful();

        $this->assertSame($original, $alert->fresh()->getRawOriginal());
        $this->assertSame($source, $this->evidence($this->get(route('alerts.show', $alert))));
        $this->assertSame($source, $this->evidence($this->get(route('alerts.bulk-detail', ['alert_ids' => [$alert->id]])))['alert_'.$alert->id]);
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public static function historicalStatuses(): array
    {
        return ['NEW' => ['new'], 'ACK' => ['acknowledged'], 'IGNORED' => ['ignored']];
    }
}
