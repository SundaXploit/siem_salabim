<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verdict', 32);
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('summary');
            $table->json('supporting_indicators')->nullable();
            $table->json('legitimate_indicators')->nullable();
            $table->json('recommended_checks')->nullable();
            $table->json('limitations')->nullable();
            $table->string('model', 191);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['alert_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_ai_analyses');
    }
};
