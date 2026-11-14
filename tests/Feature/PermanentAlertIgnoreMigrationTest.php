<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\PermanentAlertIgnore;
use App\Services\OpenSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PermanentAlertIgnoreMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_converts_exact_legacy_titles_and_preserves_unresolved_and_overlapping_notes(): void
    {
        Schema::table('permanent_alert_ignores', function (Blueprint $table) {
            $table->dropUnique(['rule_id']);
            $table->dropColumn('rule_id');
        });
        foreach ([['10001', 'Exact Title'], ['10002', 'Exact Title'], ['10003', 'exact title'], ['10001', 'Another title']] as $index => [$id, $description]) {
            Alert::create(OpenSearchService::parseHit(['_id' => 'legacy-'.$index, '_index' => 'wazuh-alerts-4.x-2026.11.13', '_source' => [
                '@timestamp' => now()->toIso8601String(), 'rule' => ['id' => $id, 'level' => 12, 'description' => $description],
            ]]));
        }
        $original = Alert::orderBy('id')->pluck('raw_data')->all();
        foreach (['Exact Title', 'No remaining source alert', 'Another title'] as $description) {
            DB::table('permanent_alert_ignores')->insert([
                'description' => $description, 'description_hash' => hash('sha256', $description), 'created_by_name' => 'Original admin',
            ]);
        }
        $migration = require database_path('migrations/2026_11_13_000016_use_rule_ids_for_permanent_alert_ignores.php');
        $migration->up();

        $this->assertSame(['10001', '10002'], PermanentAlertIgnore::ruleIds());
        $this->assertSame(2, PermanentAlertIgnore::whereNull('rule_id')->count());
        foreach (['Exact Title', 'No remaining source alert', 'Another title'] as $description) {
            $this->assertDatabaseHas('permanent_alert_ignores', ['description' => $description, 'created_by_name' => 'Original admin']);
        }
        $this->assertSame($original, Alert::orderBy('id')->pluck('raw_data')->all());
        $this->assertDatabaseCount('alert_triages', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        PermanentAlertIgnore::create(['rule_id' => '90001', 'created_by_name' => 'New admin']);
        $this->assertDatabaseHas('permanent_alert_ignores', ['rule_id' => '90001', 'description' => null, 'description_hash' => null]);
    }
}
