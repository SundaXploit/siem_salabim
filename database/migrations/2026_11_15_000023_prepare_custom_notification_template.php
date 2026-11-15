<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyBody = <<<'TPL'
🚨 <b>SOC Subang - Notifikasi Alert</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🌐 <b>Source IP</b> : {src_ip}
🎯 <b>Dest IP</b>   : {dst_ip}
🕐 <b>Waktu</b>     : {timestamp}

[Tambahkan narasi analisis di sini]

<b>⚠️ Rekomendasi:</b>
[Tambahkan rekomendasi di sini]

<b>💥 Impact:</b>
[Tambahkan dampak potensial di sini]
TPL;
        $existing = DB::table('notification_templates')->where('name', 'Custom / Pesan Bebas')->first();
        if ($existing !== null) {
            // Preserve an administrator's edited body. Only replace the shipped default.
            if (str_replace(mb_chr(8212, 'UTF-8'), '-', $existing->body) === $legacyBody) {
                DB::table('notification_templates')->where('id', $existing->id)->update([
                    'body' => NotificationTemplate::CUSTOM_BODY, 'updated_at' => now(),
                ]);
            }

            return;
        }
        DB::table('notification_templates')->insert([
            'name' => 'Custom / Pesan Bebas',
            'icon' => '✍️',
            'category' => 'Custom',
            'body' => NotificationTemplate::CUSTOM_BODY,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep templates and their administrator edits when rolling back.
    }
};
