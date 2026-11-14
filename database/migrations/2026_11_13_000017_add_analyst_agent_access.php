<?php

use App\Models\Alert;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No implicit agent grants for new or existing analysts.
            $table->json('allowed_agents')->nullable();
            $table->json('privileges')->nullable();
        });
        Schema::table('alerts', fn (Blueprint $table) => $table->string('agent_scope_key', 64)->nullable()->index());
        Schema::table('dashboard_insights', function (Blueprint $table) {
            $table->string('access_scope', 64)->default('all');
            $table->index(['period_key', 'access_scope']);
        });
        DB::table('alerts')->select('id', 'source_cluster', 'raw_data')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $source = json_decode($row->raw_data ?? '{}', true);
                DB::table('alerts')->where('id', $row->id)->update([
                    'agent_scope_key' => $row->source_cluster ? Alert::agentScopeKey($row->source_cluster, data_get($source, 'agent.id')) : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_insights', function (Blueprint $table) {
            $table->dropIndex(['period_key', 'access_scope']);
            $table->dropColumn('access_scope');
        });
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['agent_scope_key']);
            $table->dropColumn('agent_scope_key');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['allowed_agents', 'privileges']));
    }
};
