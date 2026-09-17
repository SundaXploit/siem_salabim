<?php

namespace Tests\Unit;

use App\Services\OpenSearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OpenSearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.opensearch.fetch_page_delay_ms' => 0]);
    }

    public function test_single_fetch_preserves_the_level_filter_and_page_size(): void
    {
        $hits = [$this->hit('alert-1')];
        $history = [];
        $service = $this->serviceWithQueue([$this->page($hits)], $history);

        $this->assertSame($hits, $service->fetchAlerts(7, 25));
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/wazuh-alerts-*/_search', $request->getUri()->getPath());
        $this->assertSame(['gte' => 7], $body['query']['range']['rule.level']);
        $this->assertSame(25, $body['size']);
        $this->assertSame([['@timestamp' => 'desc']], $body['sort']);
        $this->assertLowShardConcurrency($history[0]);
    }

    public function test_latest_fetch_uses_daily_patterns_and_keeps_the_date_and_level_filters(): void
    {
        $timeRange = [
            'gte' => '2026-09-16T17:00:00+00:00',
            'lt' => '2026-09-17T17:00:00+00:00',
        ];
        $hits = [$this->hit('latest-alert')];
        $history = [];
        $service = $this->serviceWithQueue([$this->page($hits)], $history);

        $this->assertSame($hits, $service->fetchAlerts(5, 25, $timeRange));
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(
            '/wazuh-alerts-*2026.09.17,wazuh-alerts-*2026.09.16/_search',
            rawurldecode($request->getUri()->getPath()),
        );
        $this->assertSame([
            'bool' => ['filter' => [
                ['range' => ['rule.level' => ['gte' => 5]]],
                ['range' => ['@timestamp' => $timeRange]],
            ]],
        ], $body['query']);
        $this->assertSame([['@timestamp' => 'desc']], $body['sort']);
        $this->assertSame(25, $body['size']);
        $this->assertArrayNotHasKey('aggs', $body);
        $this->assertLowShardConcurrency($history[0]);
    }

    public function test_scroll_keeps_equal_timestamps_and_continues_short_pages_until_empty(): void
    {
        // Both batches are smaller than the requested size and share a timestamp.
        $first = [$this->hit('alert-1')];
        $second = [$this->hit('alert-2')];
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page($first, 'cursor-1'),
            $this->page($second, 'cursor-2'),
            $this->page([], 'cursor-3'),
            $this->cleared(),
        ], $history);

        $batches = iterator_to_array($service->fetchAlertBatches(9, 100), false);

        $this->assertSame([$first, $second], $batches);
        $this->assertCount(5, $history);
        $this->assertDiscoveryRequest($history[0], 9);
        $initial = $history[1]['request'];
        parse_str($initial->getUri()->getQuery(), $query);
        $body = json_decode((string) $initial->getBody(), true);
        $this->assertNotEmpty($query['scroll'] ?? null);
        $this->assertSame('/wazuh-alerts-2026.09.17/_search', $initial->getUri()->getPath());
        $this->assertSame(['gte' => 9], $body['query']['range']['rule.level']);
        $this->assertSame(100, $body['size']);
        $this->assertSame(['_doc'], $body['sort']);
        $this->assertNotSame(false, $body['track_total_hits'] ?? null);
        $this->assertLowShardConcurrency($history[1]);
        $this->assertScrollRequest($history[2], 'cursor-1');
        $this->assertScrollRequest($history[3], 'cursor-2');
        $this->assertClearedCursor($history[4], 'cursor-3');
    }

    public function test_scroll_clears_its_context_when_the_next_page_request_fails(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page([$this->hit('alert-1')], 'cursor-1'),
            new Response(401, [], '{"error":"private-upstream-details"}'),
            $this->cleared(),
        ], $history);

        $error = null;
        $received = [];
        try {
            foreach ($service->fetchAlertBatches() as $batch) {
                $received = array_merge($received, $batch);
            }
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('Autentikasi', $error->getMessage());
        $this->assertStringNotContainsString('private-upstream-details', $error->getMessage());
        $this->assertSame(['alert-1'], array_column($received, '_id'));
        $this->assertCount(4, $history);
        $this->assertClearedCursor($history[3], 'cursor-1');
    }

    public function test_scroll_cleans_up_the_latest_cursor_even_when_that_page_is_partial(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page([$this->hit('alert-1')], 'cursor-1'),
            $this->page([$this->hit('alert-2')], 'cursor-2', ['timed_out' => true]),
            $this->cleared(),
        ], $history);

        $received = [];
        $error = null;
        try {
            foreach ($service->fetchAlertBatches() as $batch) {
                $received = array_merge($received, $batch);
            }
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertSame(['alert-1'], array_column($received, '_id'));
        $this->assertCount(4, $history);
        $this->assertClearedCursor($history[3], 'cursor-2');
    }

    public function test_scroll_cleans_up_when_the_consumer_stops_after_one_batch(): void
    {
        $first = [$this->hit('alert-1')];
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page($first, 'cursor-1'),
            $this->cleared(),
        ], $history);

        $consumeOneBatch = static function () use ($service): array {
            foreach ($service->fetchAlertBatches() as $batch) {
                return $batch;
            }

            return [];
        };

        $this->assertSame($first, $consumeOneBatch());
        $this->assertCount(3, $history);
        $this->assertClearedCursor($history[2], 'cursor-1');
    }

    public function test_an_empty_initial_scroll_is_closed_without_requesting_another_page(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page([], 'empty-cursor'),
            $this->cleared(),
        ], $history);

        $this->assertSame([], iterator_to_array($service->fetchAlertBatches(), false));
        $this->assertCount(3, $history);
        $this->assertClearedCursor($history[2], 'empty-cursor');
    }

    public function test_a_nonempty_scroll_without_a_cursor_is_rejected(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page([$this->hit('alert-1')]),
        ], $history);

        $this->expectException(RuntimeException::class);

        iterator_to_array($service->fetchAlertBatches(), false);
    }

    public function test_archive_scan_closes_each_concrete_index_before_opening_the_next(): void
    {
        $newer = 'wazuh-alerts-2026.09.17';
        $older = 'wazuh-alerts-2026.09.16';
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices([$newer, $older]),
            $this->page([$this->hit('newer-alert')], 'newer-cursor'),
            $this->page([], 'newer-final-cursor'),
            $this->cleared(),
            $this->page([$this->hit('older-alert')], 'older-cursor'),
            $this->page([], 'older-final-cursor'),
            $this->cleared(),
        ], $history);

        $batches = iterator_to_array($service->fetchAlertBatches(13, 100), false);

        $this->assertSame(['newer-alert', 'older-alert'], array_column(array_merge(...$batches), '_id'));
        $this->assertCount(7, $history);
        $this->assertDiscoveryRequest($history[0], 13);
        $this->assertSame("/{$newer}/_search", $history[1]['request']->getUri()->getPath());
        $this->assertClearedCursor($history[3], 'newer-final-cursor');
        $this->assertSame("/{$older}/_search", $history[4]['request']->getUri()->getPath());
        $this->assertClearedCursor($history[6], 'older-final-cursor');

        foreach ($history as $transaction) {
            $request = $transaction['request'];
            parse_str($request->getUri()->getQuery(), $parameters);
            if (isset($parameters['scroll'])) {
                $this->assertStringNotContainsString('*', $request->getUri()->getPath());
            }
        }
    }

    public function test_index_discovery_continues_using_the_composite_after_key(): void
    {
        $newer = 'wazuh-alerts-2026.09.17';
        $older = 'wazuh-alerts-2026.09.16';
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices([$newer], ['index' => $newer]),
            $this->page([$this->hit('first-page-alert')], 'newer-cursor'),
            $this->page([], 'newer-cursor'),
            $this->cleared(),
            $this->indices([$older], ['index' => $older]),
            $this->page([$this->hit('later-page-alert')], 'older-cursor'),
            $this->page([], 'older-cursor'),
            $this->cleared(),
            $this->indices([]),
        ], $history);

        $batches = iterator_to_array($service->fetchAlertBatches(13, 100), false);

        $this->assertSame(['first-page-alert', 'later-page-alert'], array_column(array_merge(...$batches), '_id'));
        $this->assertCount(9, $history);
        $this->assertDiscoveryRequest($history[0], 13);
        $this->assertDiscoveryRequest($history[4], 13, ['index' => $newer]);
        $this->assertDiscoveryRequest($history[8], 13, ['index' => $older]);
        $this->assertClearedCursor($history[3], 'newer-cursor');
        $this->assertClearedCursor($history[7], 'older-cursor');
    }

    public function test_today_fetch_only_searches_its_two_utc_dates_without_historical_index_discovery(): void
    {
        $timeRange = [
            'gte' => '2026-09-16T17:00:00+00:00',
            'lt' => '2026-09-17T17:00:00+00:00',
        ];
        $newer = 'wazuh-alerts-*2026.09.17';
        // The early morning in Jakarta is still the previous UTC date.
        $older = 'wazuh-alerts-*2026.09.16';
        $earlyMorningHit = $this->hit('today-early-morning');
        $earlyMorningHit['_source']['@timestamp'] = '2026-09-16T17:30:00.000Z';
        $history = [];
        $service = $this->serviceWithQueue([
            $this->page([$this->hit('today-recent')], 'newer-cursor'),
            $this->page([], 'newer-final-cursor'),
            $this->cleared(),
            $this->page([$earlyMorningHit], 'older-cursor'),
            $this->page([], 'older-final-cursor'),
            $this->cleared(),
        ], $history);

        $batches = iterator_to_array($service->fetchAlertBatches(13, 100, $timeRange), false);

        $this->assertSame(['today-recent', 'today-early-morning'], array_column(array_merge(...$batches), '_id'));
        $this->assertCount(6, $history);

        foreach ([0 => $newer, 3 => $older] as $position => $index) {
            $request = $history[$position]['request'];
            parse_str($request->getUri()->getQuery(), $parameters);
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame("/{$index}/_search", rawurldecode($request->getUri()->getPath()));
            $this->assertNotEmpty($parameters['scroll'] ?? null);
            $this->assertSame([
                'bool' => ['filter' => [
                    ['range' => ['rule.level' => ['gte' => 13]]],
                    ['range' => ['@timestamp' => $timeRange]],
                ]],
            ], $body['query']);
            $this->assertSame(['_doc'], $body['sort']);
            $this->assertSame(100, $body['size']);
            $this->assertArrayNotHasKey('aggs', $body);
            $this->assertArrayNotHasKey('aggregations', $body);
            $this->assertNotSame(false, $body['track_total_hits'] ?? null);
            $this->assertLowShardConcurrency($history[$position]);
        }

        $this->assertScrollRequest($history[1], 'newer-cursor');
        $this->assertScrollRequest($history[4], 'older-cursor');
        $this->assertClearedCursor($history[2], 'newer-final-cursor');
        $this->assertClearedCursor($history[5], 'older-final-cursor');
    }

    public function test_a_missing_daily_index_still_checks_the_other_utc_date(): void
    {
        $timeRange = [
            'gte' => '2026-09-16T17:00:00+00:00',
            'lt' => '2026-09-17T17:00:00+00:00',
        ];
        $history = [];
        $service = $this->serviceWithQueue([
            $this->page([], null, ['_shards' => ['total' => 0, 'failed' => 0]]),
            $this->page([$this->hit('early-morning')], 'older-cursor'),
            $this->page([], 'older-final-cursor'),
            $this->cleared(),
        ], $history);

        $batches = iterator_to_array($service->fetchAlertBatches(5, 100, $timeRange), false);

        $this->assertSame(['early-morning'], array_column(array_merge(...$batches), '_id'));
        $this->assertCount(4, $history);
        $this->assertSame('/wazuh-alerts-*2026.09.17/_search', rawurldecode($history[0]['request']->getUri()->getPath()));
        $this->assertSame('/wazuh-alerts-*2026.09.16/_search', rawurldecode($history[1]['request']->getUri()->getPath()));
        parse_str($history[0]['request']->getUri()->getQuery(), $parameters);
        $this->assertSame('true', $parameters['allow_no_indices'] ?? null);
        $this->assertClearedCursor($history[3], 'older-final-cursor');
    }

    #[DataProvider('dateScopedIndexPatterns')]
    public function test_date_scope_preserves_the_configured_index_expression(string $configuredIndex, string $expectedIndex): void
    {
        // The exclusive UTC midnight must not cause the next day's index to be searched.
        $timeRange = [
            'gte' => '2026-09-17T00:00:00+00:00',
            'lt' => '2026-09-18T00:00:00+00:00',
        ];
        $history = [];
        $service = $this->serviceWithQueue([
            $this->page([$this->hit('scoped-alert')], 'scope-cursor'),
            $this->page([], 'scope-cursor'),
            $this->cleared(),
        ], $history, $configuredIndex);

        $batches = iterator_to_array($service->fetchAlertBatches(5, 50, $timeRange), false);

        $this->assertSame(['scoped-alert'], array_column(array_merge(...$batches), '_id'));
        $this->assertCount(3, $history);
        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame("/{$expectedIndex}/_search", rawurldecode($request->getUri()->getPath()));
        $this->assertSame([
            'bool' => ['filter' => [
                ['range' => ['rule.level' => ['gte' => 5]]],
                ['range' => ['@timestamp' => $timeRange]],
            ]],
        ], $body['query']);
        $this->assertSame(['_doc'], $body['sort']);
        $this->assertArrayNotHasKey('aggs', $body);
        $this->assertArrayNotHasKey('aggregations', $body);
        $this->assertNotSame(false, $body['track_total_hits'] ?? null);
        $this->assertLowShardConcurrency($history[0]);
    }

    public static function dateScopedIndexPatterns(): iterable
    {
        yield 'default Wazuh indices' => ['wazuh-alerts-*', 'wazuh-alerts-*2026.09.17'];
        yield 'versioned Wazuh indices' => ['wazuh-alerts-4.x-*', 'wazuh-alerts-4.x-*2026.09.17'];
        yield 'filtered alias' => ['tenant-alerts', 'tenant-alerts'];
        yield 'custom index pattern' => ['custom-alerts-*', 'custom-alerts-*'];
        yield 'explicit index' => ['wazuh-alerts-4.x-2026.09.17', 'wazuh-alerts-4.x-2026.09.17'];
    }

    public function test_scroll_follow_up_requests_observe_the_configured_page_delay(): void
    {
        config(['services.opensearch.fetch_page_delay_ms' => 35]);
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page([$this->hit('first-alert')], 'first-cursor'),
            $this->page([$this->hit('second-alert')], 'second-cursor'),
            $this->page([], 'last-cursor'),
            $this->cleared(),
        ], $history);

        $this->assertCount(2, iterator_to_array($service->fetchAlertBatches(), false));
        $this->assertSame(35, $history[2]['options']['delay']);
        $this->assertSame(35, $history[3]['options']['delay']);
        $this->assertScrollRequest($history[2], 'first-cursor');
        $this->assertScrollRequest($history[3], 'second-cursor');
        $this->assertClearedCursor($history[4], 'last-cursor');
    }

    public function test_no_matching_indices_does_not_open_a_scroll(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([$this->indices([])], $history);

        $this->assertSame([], iterator_to_array($service->fetchAlertBatches(13), false));
        $this->assertCount(1, $history);
        $this->assertDiscoveryRequest($history[0], 13);
    }

    #[DataProvider('invalidIndexDiscoveryResponses')]
    public function test_invalid_or_partial_index_discovery_never_starts_importing(string $body): void
    {
        $history = [];
        $service = $this->serviceWithQueue([new Response(200, [], $body)], $history);

        try {
            foreach ($service->fetchAlertBatches() as $batch) {
                $this->fail('Discovery failures must not yield any alerts.');
            }
            $this->fail('Expected an index discovery failure.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertCount(1, $history);
    }

    public static function invalidIndexDiscoveryResponses(): iterable
    {
        yield 'invalid JSON' => ['{invalid'];
        yield 'missing aggregation' => ['{"hits":{"hits":[]}}'];
        yield 'non-list buckets' => ['{"hits":{"hits":[]},"aggregations":{"alert_indices":{"buckets":"invalid"}}}'];
        yield 'missing index key' => ['{"hits":{"hits":[]},"aggregations":{"alert_indices":{"buckets":[{"key":{},"doc_count":1}]}}}'];
        yield 'timed out' => ['{"timed_out":true,"hits":{"hits":[]},"aggregations":{"alert_indices":{"buckets":[]}}}'];
        yield 'failed shard' => ['{"_shards":{"failed":1},"hits":{"hits":[]},"aggregations":{"alert_indices":{"buckets":[]}}}'];
    }

    public function test_first_scroll_page_with_failed_shards_cleans_up_without_yielding_partial_hits(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page([$this->hit('incomplete-alert')], 'partial-cursor', [
                '_shards' => ['total' => 3, 'successful' => 2, 'failed' => 1],
            ]),
            $this->cleared(),
        ], $history);

        try {
            foreach ($service->fetchAlertBatches() as $batch) {
                $this->fail('Incomplete scroll pages must not be imported.');
            }
            $this->fail('Expected a shard failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('shard', $exception->getMessage());
        }

        $this->assertCount(3, $history);
        $this->assertClearedCursor($history[2], 'partial-cursor');
    }

    public function test_scroll_context_exhaustion_has_a_specific_error_without_upstream_details(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->page([], null, [
                '_shards' => [
                    'total' => 546,
                    'successful' => 500,
                    'failed' => 46,
                    'failures' => [[
                        'reason' => [
                            'type' => 'exception',
                            'reason' => 'Trying to create too many scroll contexts. Must be less than or equal to: [500]. private-upstream-details',
                        ],
                    ]],
                ],
            ]),
        ], $history);

        try {
            $service->fetchAlerts();
            $this->fail('Expected a scroll context capacity failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('scroll context', $exception->getMessage());
            $this->assertStringNotContainsString('private-upstream-details', $exception->getMessage());
        }
    }

    public function test_circuit_breaker_response_explains_memory_pressure_without_echoing_body(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            new Response(429, [], json_encode([
                'error' => [
                    'type' => 'circuit_breaking_exception',
                    'reason' => 'private-upstream-details: Data too large for parent breaker',
                ],
                'status' => 429,
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        try {
            $service->fetchAlerts();
            $this->fail('Expected a circuit breaker failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('memori', $exception->getMessage());
            $this->assertStringNotContainsString('private-upstream-details', $exception->getMessage());
        }
    }

    public function test_memory_admission_retry_reuses_the_same_scroll_cursor_and_keeps_both_batches(): void
    {
        $first = [$this->hit('before-retry')];
        $second = [$this->hit('after-retry')];
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page($first, 'cursor-before-retry'),
            $this->memoryRejection('[<http_request>]'),
            $this->page($second, 'cursor-after-retry'),
            $this->page([], 'cursor-final'),
            $this->cleared(),
        ], $history);

        $batches = iterator_to_array($service->fetchAlertBatches(13, 100), false);

        $this->assertSame([$first, $second], $batches);
        $this->assertCount(6, $history);
        $this->assertScrollRequest($history[2], 'cursor-before-retry');
        $this->assertScrollRequest($history[3], 'cursor-before-retry');
        $this->assertScrollRequest($history[4], 'cursor-after-retry');
        $this->assertClearedCursor($history[5], 'cursor-final');
    }

    public function test_memory_failure_after_admission_is_not_retried_and_closes_the_scroll(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->indices(['wazuh-alerts-2026.09.17']),
            $this->page([$this->hit('before-failure')], 'active-cursor'),
            $this->memoryRejection('[indices:data/read/search]'),
            $this->cleared(),
        ], $history);

        $received = [];
        try {
            foreach ($service->fetchAlertBatches() as $batch) {
                $received = array_merge($received, $batch);
            }
            $this->fail('Expected the ambiguous memory failure to stop the scan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('memori', $exception->getMessage());
        }

        $this->assertSame(['before-failure'], array_column($received, '_id'));
        $this->assertCount(4, $history);
        $this->assertScrollRequest($history[2], 'active-cursor');
        $this->assertClearedCursor($history[3], 'active-cursor');
    }

    public function test_repeated_memory_admission_rejections_stop_after_four_attempts(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->memoryRejection('[<http_request>]'),
            $this->memoryRejection('[<http_request>]'),
            $this->memoryRejection('[<http_request>]'),
            $this->memoryRejection('[<http_request>]'),
        ], $history);

        try {
            $service->fetchAlerts(13, 100);
            $this->fail('Expected memory retries to stop at the configured attempt limit.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('memori', $exception->getMessage());
        }

        $this->assertCount(4, $history);
        $firstRequest = $history[0]['request'];
        foreach ($history as $transaction) {
            $request = $transaction['request'];
            $this->assertSame($firstRequest->getMethod(), $request->getMethod());
            $this->assertSame((string) $firstRequest->getUri(), (string) $request->getUri());
            $this->assertSame((string) $firstRequest->getBody(), (string) $request->getBody());
        }
    }

    public function test_timeout_is_distinguished_from_failed_shards(): void
    {
        $history = [];
        $service = $this->serviceWithQueue([
            $this->page([], null, ['timed_out' => true]),
        ], $history);

        try {
            $service->fetchAlerts();
            $this->fail('Expected a search timeout.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('batas waktu', $exception->getMessage());
            $this->assertStringNotContainsString('atau shard', $exception->getMessage());
        }
    }

    #[DataProvider('invalidSearchResponses')]
    public function test_single_fetch_rejects_malformed_and_partial_responses(string $body): void
    {
        $history = [];
        $service = $this->serviceWithQueue([new Response(200, [], $body)], $history);

        $this->expectException(RuntimeException::class);

        $service->fetchAlerts();
    }

    public static function invalidSearchResponses(): iterable
    {
        yield 'invalid JSON' => ['{invalid'];
        yield 'missing hits' => ['{}'];
        yield 'non-array hits' => ['{"hits":{"hits":"invalid"}}'];
        yield 'timed out' => ['{"timed_out":true,"hits":{"hits":[]}}'];
        yield 'failed shard' => ['{"_shards":{"failed":1},"hits":{"hits":[]}}'];
    }

    private function serviceWithQueue(array $queue, array &$history, string $index = 'wazuh-alerts-*'): OpenSearchService
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $client = new Client([
            'base_uri' => 'https://indexer.example.test:9200',
            'handler' => $stack,
        ]);

        return new class($client, $index) extends OpenSearchService
        {
            public function __construct(Client $client, string $index)
            {
                $this->client = $client;
                $this->index = $index;
            }
        };
    }

    private function hit(string $id): array
    {
        return [
            '_id' => $id,
            '_source' => [
                '@timestamp' => '2026-09-17T10:00:00.000Z',
                'rule' => ['level' => 12, 'description' => 'Test alert'],
            ],
        ];
    }

    private function page(array $hits, ?string $cursor = null, array $overrides = []): Response
    {
        $body = array_merge([
            'timed_out' => false,
            '_shards' => ['failed' => 0],
            'hits' => ['hits' => $hits],
        ], $overrides);
        if ($cursor !== null) {
            $body['_scroll_id'] = $cursor;
        }

        return new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function cleared(): Response
    {
        return new Response(200, [], '{"succeeded":true,"num_freed":1}');
    }

    private function memoryRejection(string $requestLabel): Response
    {
        return new Response(429, [], json_encode([
            'error' => [
                'type' => 'circuit_breaking_exception',
                'reason' => "[parent] Data too large, data for {$requestLabel} would exceed the heap limit.",
            ],
            'status' => 429,
        ], JSON_THROW_ON_ERROR));
    }

    private function indices(array $indices, ?array $afterKey = null): Response
    {
        $aggregation = ['buckets' => array_map(static fn (string $index): array => [
            'key' => ['index' => $index],
            'doc_count' => 1,
        ], $indices)];
        if ($afterKey !== null) {
            $aggregation['after_key'] = $afterKey;
        }

        return new Response(200, [], json_encode([
            'timed_out' => false,
            '_shards' => ['total' => 546, 'successful' => 546, 'failed' => 0],
            'hits' => ['hits' => []],
            'aggregations' => ['alert_indices' => $aggregation],
        ], JSON_THROW_ON_ERROR));
    }

    private function assertDiscoveryRequest(
        array $transaction,
        int $minLevel,
        ?array $after = null,
        ?array $timeRange = null,
    ): void
    {
        $request = $transaction['request'];
        parse_str($request->getUri()->getQuery(), $parameters);
        $body = json_decode((string) $request->getBody(), true);
        $aggregations = $body['aggs'] ?? $body['aggregations'] ?? [];
        $composite = $aggregations['alert_indices']['composite'] ?? [];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/wazuh-alerts-*/_search', $request->getUri()->getPath());
        $this->assertArrayNotHasKey('scroll', $parameters);
        $this->assertSame(0, $body['size']);
        if ($timeRange === null) {
            $this->assertSame(['range' => ['rule.level' => ['gte' => $minLevel]]], $body['query']);
        } else {
            $this->assertSame([
                'bool' => ['filter' => [
                    ['range' => ['rule.level' => ['gte' => $minLevel]]],
                    ['range' => ['@timestamp' => $timeRange]],
                ]],
            ], $body['query']);
        }
        $this->assertSame(100, $composite['size'] ?? null);
        $this->assertSame([['index' => ['terms' => ['field' => '_index', 'order' => 'desc']]]], $composite['sources'] ?? null);
        $this->assertSame($after, $composite['after'] ?? null);
        $this->assertLowShardConcurrency($transaction);
    }

    private function assertLowShardConcurrency(array $transaction): void
    {
        parse_str($transaction['request']->getUri()->getQuery(), $parameters);

        $this->assertSame('1', (string) ($parameters['max_concurrent_shard_requests'] ?? ''));
        $this->assertSame('1', (string) ($parameters['pre_filter_shard_size'] ?? ''));
    }

    private function assertScrollRequest(array $transaction, string $cursor): void
    {
        $request = $transaction['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/_search/scroll', $request->getUri()->getPath());
        $this->assertSame($cursor, $body['scroll_id']);
        $this->assertNotEmpty($body['scroll'] ?? null);
    }

    private function assertClearedCursor(array $transaction, string $cursor): void
    {
        $request = $transaction['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('/_search/scroll', $request->getUri()->getPath());
        $this->assertSame([$cursor], $body['scroll_id']);
    }
}
