<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_triages', function (Blueprint $table): void {
            $table->index(['created_at', 'action', 'alert_id'], 'alert_triages_ai_activity_idx');
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->index(['sent_at', 'response_status', 'alert_id'], 'notification_logs_ai_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('alert_triages', function (Blueprint $table): void {
            $table->dropIndex('alert_triages_ai_activity_idx');
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropIndex('notification_logs_ai_delivery_idx');
        });
    }
};
