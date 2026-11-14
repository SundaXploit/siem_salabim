<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permanent_alert_ignores', function (Blueprint $table) {
            $table->id();
            $table->text('description');
            // A digest preserves exact, case-sensitive uniqueness even on a
            // MySQL database whose default text collation ignores case.
            $table->string('description_hash', 64)->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permanent_alert_ignores');
    }
};
