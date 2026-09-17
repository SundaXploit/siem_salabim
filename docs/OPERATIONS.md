# Panduan operasional SIEM Salabim

[Kembali ke README](../README.md) · [Konfigurasi contoh](../.env.example)

Panduan lengkap untuk integrasi, deployment, backup, dan pengujian. Untuk instalasi cepat dan akun bawaan, ikuti README terlebih dahulu.

## Menggunakan MySQL/MariaDB

Buat database dan pengguna aplikasi terlebih dahulu, kemudian ganti konfigurasi database **sebelum menjalankan migrasi**:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=siem_salabim
DB_USERNAME=siem_app
DB_PASSWORD=
```

Isi password pengguna database hanya di `.env` lokal. Setelah itu jalankan `php artisan migrate` dan `php artisan siem:create-admin` untuk membuat administrator dengan password pilihan Anda. Untuk akun bawaan, jalankan `php artisan db:seed`. Migrasi membuat struktur tabel; aplikasi tidak menyediakan migrasi data otomatis dari SQLite ke MySQL.

## Konfigurasi integrasi

Login sebagai admin dan buka **Settings** untuk menyimpan konfigurasi serta menguji koneksi. Host/kredensial OpenSearch, token/chat Telegram, serta endpoint/API key/model AI dan pengaturan analisis dikelola di sana dan disimpan ke database. Password/token yang dibiarkan kosong pada formulir mempertahankan nilai sebelumnya.

`.env.example` mencantumkan konfigurasi server dan parameter teknis yang belum tersedia di Settings. Instalasi lama tetap dapat membaca fallback integrasi dari `.env` untuk kompatibilitas; nilai yang sudah disimpan di Settings memiliki prioritas.

### OpenSearch / Wazuh Indexer

| Pengaturan di Settings | Default | Keterangan |
| --- | --- | --- |
| Host OpenSearch | `https://localhost:9200` | Endpoint indexer. |
| Username / password OpenSearch | Kosong | Kredensial pembaca alert. |
| Indeks OpenSearch | `wazuh-alerts-*` | Pola indeks atau alias yang dibaca. |
| Ambang level impor | `12` | Filter `rule.level`; tersedia di bagian pengaturan analisis. |

Parameter teknis koneksi tetap dikonfigurasi melalui `.env`:

| Variabel `.env` | Default | Keterangan |
| --- | --- | --- |
| `OPENSEARCH_VERIFY_SSL` | `true` | Verifikasi sertifikat TLS. Konfigurasikan CA tepercaya pada PHP untuk sertifikat internal. |
| `OPENSEARCH_SIZE` | `100` | Batas fetch terbaru sekaligus ukuran batch pemeriksaan menyeluruh. |
| `OPENSEARCH_FETCH_PAGE_DELAY_MS` | `250` | Jeda antarbatch untuk mengurangi lonjakan permintaan. |

Fetch reguler memeriksa **100 alert terbaru hari ini** secara default. Hari dihitung dari 00:00 hingga sebelum 00:00 berikutnya dalam zona **Asia/Jakarta**, kemudian dikonversi untuk query OpenSearch. Scheduler menjalankannya setiap menit; refresh tampilan Live Alerts berlangsung setiap 30 detik dan dijeda selama triage massal.

| Perintah | Cakupan |
| --- | --- |
| `php artisan siem:fetch-alerts` | Alert terbaru hari ini sesuai batas ukuran dan level. |
| `php artisan siem:fetch-alerts --today` | Seluruh halaman alert hari ini yang memenuhi filter; dipakai juga oleh tombol fetch manual di Settings. |
| `php artisan siem:fetch-alerts --all` | Seluruh riwayat yang cocok; dapat memerlukan waktu dan sumber daya besar. |
| `php artisan siem:fetch-alerts --today --dry-run` | Memeriksa kandidat impor tanpa menyimpan alert; tetap mengirim query ke OpenSearch. |

Alert yang sudah tersimpan dilewati berdasarkan identitas alert. `New: 0` bisa berarti seluruh hasil sudah pernah diimpor, atau tidak ada hasil untuk kombinasi tanggal, indeks, dan level. Koneksi yang berhasil tidak menjamin ada alert yang cocok dengan filter tersebut.

Pada volume tinggi, jumlah alert baru di antara dua fetch dapat melebihi batas fetch terbaru. Gunakan pemeriksaan `--today` untuk melengkapi hari berjalan dan sesuaikan jadwal/batas berdasarkan kapasitas server. Pemeriksaan massal memakai scroll per indeks, jeda batch, lock, dan penanganan batas memori; hindari menjalankan backfill arsip berulang tanpa kebutuhan.

### Telegram

Isi token bot dan daftar chat tujuan di **Settings**, lalu pilih penerimanya pada alur notifikasi. Bot harus memiliki akses mengirim pesan ke chat tersebut. Parameter teknis koneksi, timeout, dan retry tersedia di `.env.example`.

Notifikasi dapat memuat informasi alert dan bukti yang dipilih pengguna. Lampiran gambar dibatasi 10 MB per file. Pengiriman lampiran tidak membutuhkan symlink `public/storage`.

### Analisis AI

Isi endpoint, API key, dan model di **Settings**. Batas sampel, ambang level impor, serta interval analisis juga dikelola melalui Settings. Pada formulir saat ini, penyimpanan pertama pengaturan analisis memerlukan API key AI. Default model adalah `amanai/deepseek-v4.1-flash`; pastikan model tersedia untuk akun layanan Anda. Parameter timeout, reasoning, dan batas panjang respons tetap tersedia di `.env.example`. Profil dashboard dan detail alert meminta `reasoning_effort=none` agar tidak menggunakan thinking. Dukungan parameter ini bergantung pada model/provider.

- **Dashboard:** AI menerima agregat dan pola yang diizinkan dari alert yang sudah diimpor. Raw log, alamat IP, payload, dan kredensial tidak dimasukkan ke snapshot dashboard. Metrik agregat mencakup seluruh alert pada bulan berjalan; ekstraksi MITRE lokal memakai sampel hingga 10.000 alert terbaru. Cakupan sampel ditampilkan pada dashboard.
- **Detail alert:** AI menerima konteks alert dan bukti yang disanitasi. Data seperti IP atau path yang relevan untuk investigasi dapat termasuk di dalamnya. Tinjau kebijakan pengiriman data organisasi sebelum mengaktifkan integrasi.
- **Otomatis:** scheduler memeriksa kebutuhan pembaruan pada 02:10 WIB setiap hari; interval analisis default 10 hari dan dapat diubah di Settings. Job otomatis diproses pada queue `ai`.
- **Manual:** analisis yang diminta pengguna menunggu respons layanan. Timeout detail default 60 detik, dashboard 90 detik. `none` mengurangi pekerjaan model tetapi tidak menjamin ketersediaan atau waktu respons layanan.

Jalankan worker dengan `--queue=ai,default --timeout=180`. Default `DB_QUEUE_RETRY_AFTER=240` harus tetap lebih besar daripada timeout worker untuk menghindari job diambil ulang sebelum eksekusi selesai.

## Deployment dan operasional

- Gunakan HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, URL aplikasi yang benar, dan cookie sesi aman (`SESSION_SECURE_COOKIE=true`) untuk deployment HTTPS.
- Ganti password akun bawaan sebelum aplikasi dibuka ke publik, atau gunakan akun pribadi melalui `php artisan siem:create-admin` dan hapus akun bawaan yang tidak diperlukan. Login menggunakan email dan password.
- Web server hanya mengekspos `public/`; berikan izin tulis aplikasi pada `storage/` dan `bootstrap/cache/`.
- Instal dependency menggunakan `composer install --no-dev --optimize-autoloader`, bangun aset dengan `npm ci` lalu `npm run build`, dan jalankan migrasi sesuai prosedur backup lingkungan Anda.
- Setelah memperbarui `.env`/kode, jalankan `php artisan config:cache`, `php artisan view:cache`, dan restart worker dengan `php artisan queue:restart`.
- Kelola queue worker dengan process manager. Worker queue dan scheduler perlu tetap berjalan setelah terminal ditutup.
- `MAIL_MAILER=log` hanya menulis email ke log. Konfigurasikan SMTP/provider email agar reset password benar-benar terkirim.

Contoh cron Linux untuk satu scheduler:

```cron
* * * * * cd /path/to/SIEM_Salabim && php artisan schedule:run >> /dev/null 2>&1
```

Pada Windows, buat Task Scheduler yang menjalankan `php artisan schedule:run` setiap menit dengan working directory proyek. `schedule:work` cocok untuk terminal pengembangan.

### Backup dan penghapusan data

Ekspor pada Settings mengikuti cakupan dan periode pilihan pengguna. Format SQL menargetkan MySQL/MariaDB; gunakan JSON untuk pertukaran data lintas database. Aplikasi belum menyediakan UI restore.

Ekspor dari Settings bukan pengganti backup lengkap database, file bukti, dan `APP_KEY`. Simpan backup secara privat dan uji prosedur pemulihan sebelum menghapus data operasional. Periksa preview cakupan sebelum memakai tombol hapus atau backup sekaligus hapus.

## Pengujian

```bash
composer validate --no-check-publish
php artisan test
npm test
npm run build
composer audit --locked
npm audit
```

Tes PHP memakai database SQLite `:memory:` dan cache/session array sesuai `phpunit.xml`. Aktifkan `pdo_sqlite` dan `sqlite3` pada PHP CLI. Jika ekstensi sudah tersedia tetapi belum aktif, contoh Windows:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```

Audit dependency memerlukan koneksi internet. Jalankan ulang sebelum rilis karena advisory dapat berubah. Tes otomatis memakai fake/mock untuk integrasi; lakukan uji koneksi lingkungan melalui Settings secara terpisah.

## Sebelum commit dan push

Repository menyertakan kode aplikasi, migrasi, pengujian, `.env.example`, `composer.lock`, dan `package-lock.json`. Lock file perlu ikut di-commit agar instalasi menggunakan versi dependency yang sama.

`.gitignore` mengecualikan konfigurasi lokal `.env` beserta variasinya, dependency terpasang, hasil build, database lokal, dump/backup, log, session/cache, file unggahan, key/sertifikat privat, keluaran tes, dan skrip diagnostik Telegram lokal. File `.gitignore` di dalam `storage/` tetap dipertahankan untuk struktur direktori.

Setelah repository Git diinisialisasi, periksa daftar file:

```bash
git status --short --ignored
git check-ignore -v .env database/database.sqlite storage/logs/laravel.log
git diff --cached --name-only
git diff --cached
```

Pastikan `.env.example` hanya berisi placeholder. Jangan memasukkan token, password operasional, data alert nyata, alamat infrastruktur privat, chat ID operasional, atau screenshot insiden ke README, fixture, dan aset publik.

Aturan ignore tidak mengeluarkan file yang sudah terlanjur dilacak. Jika pernah membagikan kredensial melalui commit, keluarkan file tersebut dari pelacakan dan rotasi kredensial yang terpapar; menghapusnya pada commit berikutnya tidak menghilangkan salinannya dalam riwayat.

## Struktur proyek

```text
app/Console/Commands/    Perintah impor, pembaruan insight, dan pembuatan admin
app/Http/Controllers/   Dashboard, alert, triage, notifikasi, dan Settings
app/Jobs/               Job analisis dashboard
app/Models/             Alert, pengguna, konfigurasi, serta riwayat
app/Services/           OpenSearch, Telegram, AI, dan logika triage
config/                 Konfigurasi aplikasi dan integrasi
database/migrations/    Struktur database
resources/views/        Antarmuka Blade
resources/js/           Interaksi antarmuka
routes/                 Route web, autentikasi, dan scheduler
tests/                  Tes PHP dan JavaScript
```
