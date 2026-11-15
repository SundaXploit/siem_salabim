<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->text('src_ip')->nullable()->change();
            $table->text('dst_ip')->nullable()->change();
            $table->char('src_ip_hash', 64)->nullable()->index();
            $table->char('dst_ip_hash', 64)->nullable()->index();
        });
        Schema::create('encrypted_search_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 20);
            $table->unsignedBigInteger('record_id');
            $table->char('token_hash', 64);
            $table->unique(['entity', 'record_id', 'token_hash'], 'encrypted_tokens_record_unique');
            $table->index(['entity', 'token_hash', 'record_id'], 'encrypted_tokens_lookup');
        });
    }

    public function down(): void
    {
        // Leave the widened IP columns intact: narrowing ciphertext would lose data.
        Schema::dropIfExists('encrypted_search_tokens');
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropIndex('alerts_src_ip_hash_index');
            $table->dropIndex('alerts_dst_ip_hash_index');
            $table->dropColumn(['src_ip_hash', 'dst_ip_hash']);
        });
    }
};
