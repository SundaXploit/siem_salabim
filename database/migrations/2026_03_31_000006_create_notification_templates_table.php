<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('icon', 10)->default('📢');
            $table->string('category', 50)->default('General');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default templates
        $templates = [
            [
                'name'     => 'Brute Force Attack',
                'icon'     => '🔓',
                'category' => 'Authentication',
                'body'     => <<<'TPL'
🚨 <b>SOC Subang mendeteksi adanya aktivitas Brute Force Attack</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🌐 <b>Source IP</b> : {src_ip}
🎯 <b>Dest IP</b>   : {dst_ip}
🕐 <b>Waktu</b>     : {timestamp}

<b>📊 Detail Serangan:</b>
SoC mendeteksi adanya aktivitas gagal login berulang (Brute Force) pada sistem target.

<b>⚠️ Rekomendasi:</b>
- Blokir Source IP mencurigakan pada firewall atau WAF
- Terapkan rate limiting untuk percobaan login
- Nonaktifkan atau rename akun default (sa, root, admin)
- Gunakan password yang kuat dan kompleks
- Aktifkan 2FA jika tersedia

<b>💥 Impact Potensial:</b>
- Risiko akses tidak sah ke sistem
- Potensi kompromi akun dengan kredensial lemah
- Beban tinggi pada server target
TPL,
            ],
            [
                'name'     => 'Malware / Virus Terdeteksi',
                'icon'     => '🦠',
                'category' => 'Malware',
                'body'     => <<<'TPL'
🚨 <b>SOC Subang mendeteksi adanya Malware / Virus</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🌐 <b>Source IP</b> : {src_ip}
🕐 <b>Waktu</b>     : {timestamp}

<b>🔎 Detail Temuan:</b>
Terdeteksi indikasi malware/virus aktif pada endpoint yang dimonitor.

<b>⚠️ Rekomendasi:</b>
- Isolasi endpoint yang terinfeksi dari jaringan segera
- Jalankan full scan menggunakan antivirus terbaru
- Periksa log untuk aktivitas mencurigakan terkait
- Laporkan ke tim IT untuk investigasi lanjutan
- Pertimbangkan reimaging jika infeksi berat

<b>💥 Impact Potensial:</b>
- Risiko penyebaran malware ke sistem lain di jaringan
- Potensi pencurian data sensitif
- Gangguan layanan pada sistem yang terinfeksi
TPL,
            ],
            [
                'name'     => 'Port Scan / Reconnaissance',
                'icon'     => '🔍',
                'category' => 'Network',
                'body'     => <<<'TPL'
🚨 <b>SOC Subang mendeteksi aktivitas Port Scanning</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🌐 <b>Source IP</b> : {src_ip}
🎯 <b>Dest IP</b>   : {dst_ip}
🕐 <b>Waktu</b>     : {timestamp}

<b>🔎 Detail Temuan:</b>
Terdeteksi aktivitas port scanning dan reconnaissance dari IP eksternal. Hal ini mengindikasikan adanya upaya pemetaan jaringan sebelum serangan lebih lanjut.

<b>⚠️ Rekomendasi:</b>
- Monitor Source IP untuk aktivitas lanjutan
- Pastikan firewall mengatur aturan DROP untuk port scan
- Review konfigurasi IDS/IPS
- Tambahkan Source IP ke watchlist

<b>💥 Impact Potensial:</b>
- Potensi sebagai tahap awal serangan (pre-attack recon)
- Eksposur informasi topologi jaringan internal
TPL,
            ],
            [
                'name'     => 'Unauthorized Access / Akses Tidak Sah',
                'icon'     => '🚫',
                'category' => 'Access Control',
                'body'     => <<<'TPL'
🚨 <b>SOC Subang mendeteksi Unauthorized Access</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🌐 <b>Source IP</b> : {src_ip}
🎯 <b>Dest IP</b>   : {dst_ip}
🕐 <b>Waktu</b>     : {timestamp}

<b>🔎 Detail Temuan:</b>
Terdapat upaya akses tanpa autorisasi yang tepat ke sumber daya sistem yang dilindungi.

<b>⚠️ Rekomendasi:</b>
- Periksa dan audit log akses sistem
- Verifikasi apakah akses berhasil atau gagal
- Review kebijakan access control dan privilege
- Notifikasi pemilik sistem terkait

<b>💥 Impact Potensial:</b>
- Potensi pelanggaran kebijakan keamanan informasi
- Risiko akses ke data rahasia/sensitif
- Pelanggaran kepatuhan regulasi
TPL,
            ],
            [
                'name'     => 'File Integrity / Perubahan File',
                'icon'     => '📁',
                'category' => 'Integrity',
                'body'     => <<<'TPL'
🚨 <b>SOC Subang mendeteksi perubahan File Integrity</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🕐 <b>Waktu</b>     : {timestamp}

<b>🔎 Detail Temuan:</b>
Terdeteksi perubahan tidak terduga pada file sistem yang dimonitor oleh FIM (File Integrity Monitoring).

<b>⚠️ Rekomendasi:</b>
- Verifikasi apakah perubahan file diotorisasi
- Periksa siapa yang melakukan perubahan (audit trail)
- Kembalikan file ke versi sebelumnya jika perubahan tidak sah
- Investigasi kemungkinan adanya ransomware atau webshell

<b>💥 Impact Potensial:</b>
- Indikasi kompromi sistem atau aktivitas ransomware
- Potensi backdoor / webshell pada server
TPL,
            ],
            [
                'name'     => 'DDoS / Traffic Anomaly',
                'icon'     => '🌊',
                'category' => 'Network',
                'body'     => <<<'TPL'
🚨 <b>SOC Subang mendeteksi anomali traffic / DDoS</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🌐 <b>Source IP</b> : {src_ip}
🎯 <b>Dest IP</b>   : {dst_ip}
🕐 <b>Waktu</b>     : {timestamp}

<b>🔎 Detail Temuan:</b>
Terdeteksi lonjakan traffic yang tidak normal yang berpotensi merupakan serangan DDoS atau traffic flood yang dapat mengganggu layanan.

<b>⚠️ Rekomendasi:</b>
- Aktifkan DDoS protection / rate limiting pada firewall
- Block Source IP jika teridentifikasi sebagai bot
- Koordinasikan dengan ISP untuk null-routing jika diperlukan
- Monitor availability layanan secara terus-menerus

<b>💥 Impact Potensial:</b>
- Gangguan ketersediaan layanan (downtime)
- Beban berlebih pada server dan jaringan
- Degradasi performa layanan production
TPL,
            ],
            [
                'name'     => 'Custom / Pesan Bebas',
                'icon'     => '✍️',
                'category' => 'Custom',
                'body'     => <<<'TPL'
🚨 <b>SOC Subang — Notifikasi Alert</b>

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
TPL,
            ],
        ];

        foreach ($templates as $tpl) {
            DB::table('notification_templates')->insert([
                'name'       => $tpl['name'],
                'icon'       => $tpl['icon'],
                'category'   => $tpl['category'],
                'body'       => $tpl['body'],
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
