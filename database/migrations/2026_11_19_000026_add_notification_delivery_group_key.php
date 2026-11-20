<?php

use App\Services\SensitiveDataCipher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resumable on engines where ALTER TABLE is not transactional.
        if (! Schema::hasColumn('notification_logs', 'delivery_group_key')) {
            Schema::table('notification_logs', function (Blueprint $table): void {
                $table->char('delivery_group_key', 64)->nullable()->index();
            });
        }

        DB::table('notification_logs')->whereNull('delivery_group_key')->whereNotNull('delivery_details')
            ->select(['id', 'delivery_details'])->orderBy('id')->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $details = json_decode($row->delivery_details, true, 512, JSON_THROW_ON_ERROR);
                    if (is_string($details)) {
                        $details = SensitiveDataCipher::decryptJson($row->delivery_details);
                    }
                    $groupId = data_get($details, 'bulk_group.id');
                    if (is_string($groupId) && $groupId !== '') {
                        DB::table('notification_logs')->where('id', $row->id)
                            ->update(['delivery_group_key' => hash('sha256', $groupId)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropIndex(['delivery_group_key']);
            $table->dropColumn('delivery_group_key');
        });
    }
};
