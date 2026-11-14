<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automatic_notification_quotas', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key', 64);
            $table->date('quota_day');
            $table->json('event_keys');
            $table->timestamps();
            $table->unique(['group_key', 'quota_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automatic_notification_quotas');
    }
};
