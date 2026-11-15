<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\User;
use App\Services\OpenSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WazuhTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public static function sourceTimes(): array
    {
        return [
            'UTC fallback' => ['UTC', '@timestamp', '2026-11-15 08:15:00', '2026-11-15 08:15:00', '15 Nov 2026, 15:15:00'],
            'WIB without offset' => ['Asia/Jakarta', '@timestamp', '2026-11-15 08:15:00', '2026-11-15 01:15:00', '15 Nov 2026, 08:15:00'],
            'legacy timestamp WIB' => ['Asia/Jakarta', 'timestamp', '2026-11-15 08:15:00', '2026-11-15 01:15:00', '15 Nov 2026, 08:15:00'],
            'WIB explicitly overrides UTC setting' => ['UTC', '@timestamp', '2026-11-15T08:15:00+07:00', '2026-11-15 01:15:00', '15 Nov 2026, 08:15:00'],
            'WIB is not converted twice' => ['Asia/Jakarta', '@timestamp', '2026-11-15T08:15:00+07:00', '2026-11-15 01:15:00', '15 Nov 2026, 08:15:00'],
            'explicit UTC overrides WIB setting' => ['Asia/Jakarta', '@timestamp', '2026-11-15T08:15:00Z', '2026-11-15 08:15:00', '15 Nov 2026, 15:15:00'],
            'WITA source' => ['Asia/Makassar', '@timestamp', '2026-11-15 08:15:00', '2026-11-15 00:15:00', '15 Nov 2026, 07:15:00'],
            'WIB across UTC date boundary' => ['Asia/Jakarta', '@timestamp', '2026-11-15 00:15:00', '2026-11-14 17:15:00', '15 Nov 2026, 00:15:00'],
        ];
    }

    #[DataProvider('sourceTimes')]
    public function test_saved_timezone_applies_to_real_imports_without_overriding_explicit_offsets(
        string $timezone, string $field, string $timestamp, string $stored, string $displayed,
    ): void {
        // A saved setting must override the .env fallback and be read by the command.
        config(['services.opensearch.timezone' => 'UTC']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('settings.opensearch'), [
            'opensearch_host' => 'https://indexer.example.test:9200', 'opensearch_username' => 'reader',
            'opensearch_index' => 'wazuh-alerts-*', 'opensearch_timezone' => $timezone,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($timezone, Configuration::get('opensearch_timezone'));
        $this->get(route('settings.index', ['section' => 'opensearch']))->assertOk()
            ->assertSee('Zona waktu Wazuh untuk timestamp tanpa offset')
            ->assertSee('value="'.$timezone.'" selected', false);
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->once()->andReturn([[[
            '_id' => 'source-zone-event', '_index' => 'wazuh-alerts-test',
            '_source' => [$field => $timestamp, 'agent' => ['id' => '001'], 'rule' => ['id' => '1', 'level' => 14]],
        ]]]);
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertSuccessful();
        $alert = Alert::sole();
        $this->assertSame($stored, $alert->getRawOriginal('first_seen_at'));
        $this->get(route('alerts.show', $alert))->assertOk()->assertSee($displayed);
        $this->get('/history/alerts?date_from=2026-11-15&date_to=2026-11-15')->assertOk()
            ->assertViewHas('alerts', fn ($alerts) => $alerts->modelKeys() === [$alert->id]);
    }

    public function test_invalid_timezone_is_rejected_and_older_clients_preserve_the_saved_choice(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Configuration::set('opensearch_timezone', 'Asia/Jakarta');
        $payload = ['opensearch_host' => 'https://indexer.example.test:9200', 'opensearch_username' => 'reader', 'opensearch_index' => 'wazuh-alerts-*'];
        $this->postJson(route('settings.opensearch'), [...$payload, 'opensearch_timezone' => 'Mars/Invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('opensearch_timezone');
        $this->post(route('settings.opensearch'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Asia/Jakarta', Configuration::get('opensearch_timezone'));
    }
}
