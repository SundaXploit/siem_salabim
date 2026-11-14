<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permanent_alert_ignores', function (Blueprint $table) {
            $table->string('rule_id', 64)->nullable()->unique();
            // Keep the original configuration for review.
            $table->text('description')->nullable()->change();
            $table->string('description_hash', 64)->nullable()->change();
        });

        $column = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'CAST(rule_description AS BINARY)',
            'pgsql' => 'rule_description COLLATE "C"',
            default => 'rule_description COLLATE BINARY',
        };
        DB::table('permanent_alert_ignores')->orderBy('id')->chunkById(100, function ($rules) use ($column): void {
            foreach ($rules as $rule) {
                if ($rule->description === null) {
                    continue;
                }
                $ids = DB::table('alerts')->whereRaw("{$column} = ?", [$rule->description])
                    ->whereNotNull('rule_id')->distinct()->orderBy('rule_id')->pluck('rule_id');
                $assigned = false;
                foreach ($ids as $id) {
                    $id = (string) $id;
                    if (! preg_match('/\A[0-9]{1,64}\z/', $id) || DB::table('permanent_alert_ignores')->where('rule_id', $id)->exists()) {
                        continue;
                    }
                    if (! $assigned) {
                        DB::table('permanent_alert_ignores')->where('id', $rule->id)->update(['rule_id' => $id]);
                        $assigned = true;
                    } else {
                        DB::table('permanent_alert_ignores')->insert([
                            'rule_id' => $id, 'description' => null, 'description_hash' => null,
                            'created_by_user_id' => $rule->created_by_user_id, 'created_by_name' => $rule->created_by_name,
                            'created_at' => $rule->created_at,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('permanent_alert_ignores', function (Blueprint $table) {
            $table->dropUnique(['rule_id']);
            $table->dropColumn('rule_id');
        });
    }
};
