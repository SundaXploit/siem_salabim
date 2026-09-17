<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('wazuh_alert_id', 100)->unique()->comment('_id dari OpenSearch');
            $table->text('rule_description')->nullable();
            $table->string('src_ip', 45)->nullable();
            $table->string('dst_ip', 45)->nullable();
            $table->string('agent_name', 100)->nullable();
            $table->string('rule_id', 20)->nullable();
            $table->unsignedTinyInteger('rule_level')->default(0);
            $table->json('raw_data')->nullable()->comment('Full JSON alert dari OpenSearch');
            $table->enum('status', ['new', 'acknowledged', 'ignored'])->default('new');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('rule_level');
            $table->index('first_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
