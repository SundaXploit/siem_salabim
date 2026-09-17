<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_insights', function (Blueprint $table) {
            $table->id();
            $table->string('period_key', 7)->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('completed');
            $table->longText('summary')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->string('provider', 80)->default('amanai');
            $table->string('model', 255)->nullable();
            $table->string('trigger', 20)->default('scheduled');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('next_refresh_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['period_key', 'status', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_insights');
    }
};
