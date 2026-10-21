<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name');
            $table->string('scope', 24);
            $table->string('format', 8);
            $table->string('status', 24)->default('queued');
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->string('period_label');
            $table->dateTime('source_snapshot_at')->nullable();
            $table->json('snapshot_boundaries')->nullable();
            $table->json('record_counts')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->string('storage_disk', 80);
            $table->string('storage_path', 1024)->nullable();
            $table->string('manifest_path', 1024)->nullable();
            $table->string('filename');
            $table->string('checksum', 64)->nullable();
            $table->string('manifest_checksum', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->string('purge_status', 24)->nullable();
            $table->foreignId('purge_requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purge_requested_by_name')->nullable();
            $table->dateTime('purge_requested_at')->nullable();
            $table->dateTime('purge_started_at')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->json('purged_counts')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['purge_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
