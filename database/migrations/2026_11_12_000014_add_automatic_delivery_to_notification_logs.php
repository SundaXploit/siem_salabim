<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('automatic_key', 64)->nullable()->unique();
            $table->string('delivery_state', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropUnique(['automatic_key']);
            $table->dropIndex(['delivery_state']);
            $table->dropColumn(['automatic_key', 'delivery_state']);
            // Keep user_id nullable to preserve system-owned notification history.
        });
    }
};
