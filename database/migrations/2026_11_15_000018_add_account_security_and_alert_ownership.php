<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
            $table->string('session_version', 64)->nullable();
        });
        DB::table('users')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'session_version' => Str::random(64), 'remember_token' => null,
                ]);
            }
        });
        Schema::table('alerts', function (Blueprint $table): void {
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
        });
        // Recover the first recorded ACK. Old auto-ACKs may only have a notify event.
        DB::table('alerts')->whereIn('status', ['acknowledged', 'ignored'])->orderBy('id')->chunkById(500, function ($alerts): void {
            foreach ($alerts as $alert) {
                $first = DB::table('alert_triages')->where('alert_id', $alert->id)
                    ->where('action', 'acknowledge')->orderBy('created_at')->orderBy('id')->first();
                $first ??= DB::table('alert_triages')->where('alert_id', $alert->id)
                    ->where('action', 'notify')->orderBy('created_at')->orderBy('id')->first();
                if ($first) {
                    DB::table('alerts')->where('id', $alert->id)->update([
                        'acknowledged_by_user_id' => $first->user_id, 'acknowledged_at' => $first->created_at,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acknowledged_by_user_id');
            $table->dropColumn('acknowledged_at');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['is_active', 'session_version']);
        });
    }
};
