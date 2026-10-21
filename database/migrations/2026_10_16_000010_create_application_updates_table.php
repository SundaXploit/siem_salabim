<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_updates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name');
            $table->string('from_version', 64);
            $table->string('target_version', 64);
            $table->unsignedBigInteger('release_id');
            $table->string('release_tag', 80);
            $table->string('release_url', 2048);
            $table->unsignedBigInteger('asset_id');
            $table->string('asset_name', 255);
            $table->string('asset_api_url', 2048);
            $table->unsignedBigInteger('asset_size');
            $table->char('asset_sha256', 64);
            $table->string('status', 32)->default('queued');
            $table->string('phase', 64)->default('requested');
            $table->string('backup_path', 1024)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('application_update_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_update_id')
                ->constrained('application_updates')
                ->restrictOnDelete();
            $table->string('phase', 64);
            $table->string('level', 16)->default('info');
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['application_update_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_update_events');
        Schema::dropIfExists('application_updates');
    }
};
