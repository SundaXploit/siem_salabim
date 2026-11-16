<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->text('agent_name')->nullable()->change();
            $table->string('rule_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Check before either ALTER so a rollback cannot truncate evidence or
        // leave a partially narrowed schema on engines without transactional DDL.
        DB::table('alerts')->select(['id', 'agent_name', 'rule_id'])->orderBy('id')
            ->chunkById(500, function ($alerts): void {
                foreach ($alerts as $alert) {
                    if (mb_strlen($alert->agent_name ?? '', 'UTF-8') > 100
                        || mb_strlen($alert->rule_id ?? '', 'UTF-8') > 20) {
                        throw new RuntimeException('Rollback metadata alert dibatalkan: agent_name melebihi 100 karakter atau rule_id melebihi 20 karakter. Pertahankan schema baru agar data tidak terpotong.');
                    }
                }
            });

        Schema::table('alerts', function (Blueprint $table) {
            $table->string('agent_name', 100)->nullable()->change();
            $table->string('rule_id', 20)->nullable()->change();
        });
    }
};
