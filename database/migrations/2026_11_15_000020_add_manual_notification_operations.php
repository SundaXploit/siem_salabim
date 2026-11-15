<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the operation even when notification history alone is purged.
        // Replaying an old request must never recreate deleted deliveries.
        Schema::create('manual_notification_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('payload_hash', 64);
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->uuid('manual_operation_id')->nullable();
            $table->json('delivery_details')->nullable();
            $table->unique(['manual_operation_id', 'chat_id'], 'notification_manual_destination_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropUnique('notification_manual_destination_unique');
            $table->dropColumn(['manual_operation_id', 'delivery_details']);
        });
        Schema::dropIfExists('manual_notification_operations');
    }
};
