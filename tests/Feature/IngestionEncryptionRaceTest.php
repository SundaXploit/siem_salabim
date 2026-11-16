<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Services\EncryptedSearchIndex;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestionEncryptionRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_insert_indexes_the_preserved_event_when_another_writer_wins_the_race(): void
    {
        Queue::fake();
        config(['data_security.allow_legacy_plaintext' => false]);
        $source = json_decode(file_get_contents(base_path('tests/Fixtures/wazuh-pam-session.json')), true, 512, JSON_THROW_ON_ERROR);
        $source['rule']['level'] = 14;
        $source['data']['message'] = 'original-evidence-before-race';
        $original = ['_id' => 'encryption-race', '_index' => 'wazuh-race', '_source' => $source];
        $incoming = $original;
        $incoming['_source']['data']['message'] = 'conflicting-evidence-after-race';
        $insertedByOtherWriter = false;
        $table = DB::connection()->getQueryGrammar()->wrapTable('alerts');
        DB::connection()->beforeExecuting(function (string $query) use (&$insertedByOtherWriter, $table, $original): void {
            // Reproduce the window between the initial existence check and
            // the conflict-safe INSERT without a timing-dependent sleep.
            if (! $insertedByOtherWriter && str_starts_with(strtolower($query), 'insert into '.$table.' ')) {
                $insertedByOtherWriter = true;
                Alert::create(OpenSearchService::parseHit($original));
            }
        });
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->once()->andReturn([[$incoming]]);
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertSuccessful();
        $this->assertTrue($insertedByOtherWriter);
        $alert = Alert::sole();
        $this->assertSame('original-evidence-before-race', data_get($alert->raw_data, 'data.message'));
        $this->assertSame(0, Alert::searchTerms('conflicting-evidence-after-race')->count());
        $this->assertSame(1, Alert::searchTerms('original-evidence-before-race')->count());
        $this->assertTrue(app(EncryptedSearchIndex::class)->matches('alert', $alert->id, $alert->id, [$alert->raw_data, $alert->src_ip, $alert->dst_ip]));
    }
}
