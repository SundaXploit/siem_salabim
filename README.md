# SIEM Salabim

**SIEM Salabim** adalah aplikasi web untuk membantu tim Security Operations Center (SOC) memantau dan menangani alert keamanan Wazuh. Aplikasi mengambil alert dari OpenSearch/Wazuh Indexer, menyimpannya ke database aplikasi, lalu menyediakan dashboard, triage, riwayat aktivitas, notifikasi Telegram, dan bantuan analisis AI.

Tujuannya adalah memusatkan pekerjaan analyst: meninjau alert, menentukan tindak lanjut, mendokumentasikan keputusan, dan mengirim informasi insiden kepada pihak terkait. Deteksi awal tetap dilakukan oleh Wazuh; data yang tampil di Salabim mengikuti periode, ambang level, dan batas impor yang dikonfigurasi.

## Fitur

| Fitur | Fungsi |
| --- | --- |
| Dashboard SOC | Ringkasan alert, distribusi severity, tren, dan kesimpulan AI untuk bulan berjalan. |
| Live Alerts | Daftar alert dengan filter, pencarian, pagination 25/50/100 baris, dan refresh tampilan otomatis. |
| Detail alert | Informasi rule, agent, bukti alert, status penanganan, dan analisis AI per alert. |
| Triage individual dan massal | ACK atau abaikan alert dengan alasan. Klik **Triage massal** untuk menampilkan checkbox dan tombol tindakan; maksimum 100 alert per permintaan. |
| Riwayat dan audit | Menelusuri alert, notifikasi, serta aktivitas penanganan oleh pengguna. |
| Notifikasi Telegram | Mengirim laporan alert, menggunakan template, dan melampirkan bukti gambar. Admin mengelola template dan konfigurasi bot. |
| Analisis AI | Membantu merangkum pola dashboard dan menilai detail alert melalui AmanAI. Hasilnya tetap perlu ditinjau analyst. |
| Leaderboard | Ringkasan aktivitas penanganan alert oleh analyst. |
| Administrasi | Pengaturan integrasi, pengujian koneksi, ambang level impor, fetch manual, dan pengelolaan akun. |
| Pengelolaan data | Preview cakupan data, ekspor JSON/SQL, serta penghapusan berdasarkan periode dan cakupan oleh admin. |
| Profil | Mengelola informasi akun, mengganti password, dan menghapus akun sendiri. |

Registrasi publik dinonaktifkan. Administrator membuat akun analyst melalui **Settings**. Endpoint aplikasi memerlukan login; konfigurasi dan pengelolaan pengguna dibatasi untuk admin.

## Alur aplikasi

```text
Wazuh agents -> Wazuh Manager -> OpenSearch / Wazuh Indexer
                                         |
                                  fetch alert terfilter
                                         |
                                 Database SIEM Salabim
                                         |
                         Dashboard / Live Alerts / Triage
                                  |               |
                             Analisis AI      Telegram
```

Stack aplikasi: PHP, Laravel 13, Blade, Alpine.js, Tailwind CSS, Vite, serta database relasional. Queue dan cache memakai database secara default.

## Persyaratan

- PHP **8.3 atau lebih baru**, Composer 2, serta ekstensi Laravel: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, dan XML.
- Driver database PHP: `pdo_sqlite` dan `sqlite3` untuk instalasi SQLite/pengujian, atau `pdo_mysql` untuk MySQL/MariaDB.
- Node.js **22.12+**. Node.js 20.19+ juga memenuhi persyaratan tool build yang digunakan.
- SQLite untuk instalasi lokal sederhana, atau MySQL/MariaDB untuk lingkungan operasional.
- OpenSearch/Wazuh Indexer yang dapat dijangkau, dengan akun yang berhak membaca indeks alert dan menjalankan pencarian/scroll.
- Opsional: token bot Telegram dan chat tujuan; API key AmanAI untuk fitur AI.

Pastikan PHP di terminal dan PHP web server memiliki versi serta ekstensi yang sesuai. Di Laragon, keduanya dapat menggunakan konfigurasi `php.ini` yang berbeda.

## Instalasi baru

Contoh berikut untuk clone baru. Ganti `<URL_REPOSITORY>` dengan alamat repository Anda.

```bash
git clone <URL_REPOSITORY> SIEM_Salabim
cd SIEM_Salabim
composer install
npm ci
```

Salin konfigurasi contoh. Pada Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Pada Linux/macOS:

```bash
cp .env.example .env
```

Sesuaikan `APP_URL` di `.env`, misalnya `http://127.0.0.1:8000` untuk server Artisan atau `http://siem_salabim.test` untuk Laragon. File `.env.example` memakai SQLite. Buat file database lokal dan siapkan aplikasi:

```bash
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan key:generate
php artisan migrate
php artisan siem:create-admin
npm run build
```

Perintah `siem:create-admin` meminta nama, email, dan password melalui prompt. Password disembunyikan dan harus minimal 12 karakter. Tidak ada akun/password admin bawaan; seeder tidak membuat akun atau mengubah password akun yang sudah ada.

**Jalankan `key:generate` hanya saat instalasi baru.** Simpan `APP_KEY` secara privat bersama backup operasional. Menggantinya pada instalasi yang sudah berisi konfigurasi terenkripsi dapat membuat kredensial integrasi tidak dapat dibaca. Jangan menimpa `.env` instalasi yang sudah berjalan dengan file contoh.

### Menggunakan MySQL/MariaDB

Buat database dan pengguna aplikasi terlebih dahulu, kemudian ganti konfigurasi database **sebelum menjalankan migrasi**:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=siem_salabim
DB_USERNAME=siem_app
DB_PASSWORD=
```

Isi password pengguna database hanya di `.env` lokal. Setelah itu jalankan `php artisan migrate` dan `php artisan siem:create-admin` seperti di atas. Migrasi membuat struktur tabel; aplikasi tidak menyediakan migrasi data otomatis dari SQLite ke MySQL.

## Menjalankan aplikasi

Untuk pengembangan lokal:

```bash
composer run dev
```

Perintah ini menjalankan server aplikasi, worker queue `ai,default`, scheduler, dan Vite secara bersamaan. Buka `http://127.0.0.1:8000`, lalu login dengan akun yang dibuat sebelumnya.

Jika memakai virtual host Laragon, arahkan **document root ke folder `public/`**. Jalankan komponen pendukung di terminal terpisah:

```bash
npm run dev
php artisan queue:work --queue=ai,default --sleep=3 --tries=2 --timeout=180
php artisan schedule:work
```

Jangan menjalankan dua scheduler untuk instalasi yang sama. Build produksi menggunakan `npm run build` dan tidak memerlukan server Vite.

## Konfigurasi integrasi

Login sebagai admin dan buka **Settings** untuk menyimpan konfigurasi serta menguji koneksi. Nilai yang tersimpan di database mengungguli fallback `.env`; mengubah `.env` tidak menimpa nilai yang sudah disimpan melalui Settings. Password/token yang dibiarkan kosong pada formulir mempertahankan nilai sebelumnya.

### OpenSearch / Wazuh Indexer

| Variabel `.env` | Default | Keterangan |
| --- | --- | --- |
| `OPENSEARCH_HOST` | `https://localhost:9200` | Endpoint indexer. |
| `OPENSEARCH_USERNAME` / `OPENSEARCH_PASSWORD` | Kosong | Kredensial pembaca alert; isi melalui `.env` atau Settings. |
| `OPENSEARCH_INDEX` | `wazuh-alerts-*` | Pola indeks atau alias yang dibaca. |
| `OPENSEARCH_VERIFY_SSL` | `true` | Verifikasi sertifikat TLS. Konfigurasikan CA tepercaya pada PHP untuk sertifikat internal. |
| `OPENSEARCH_MIN_LEVEL` | `12` | Ambang `rule.level`; pengaturan admin mengungguli fallback ini. |
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

Isi token bot di Settings atau `TELEGRAM_BOT_TOKEN`, lalu simpan daftar chat tujuan di Settings dan pilih penerimanya pada alur notifikasi. Bot harus memiliki akses mengirim pesan ke chat tersebut. Konfigurasi timeout dan retry tersedia di `.env.example`.

Notifikasi dapat memuat informasi alert dan bukti yang dipilih pengguna. Lampiran gambar dibatasi 10 MB per file. Pengiriman lampiran tidak membutuhkan symlink `public/storage`.

### Analisis AI

Isi API key dan model di Settings atau melalui variabel `AMANAI_*` di `.env.example`. Default model adalah `amanai/deepseek-v4.1-flash`; pastikan model tersedia untuk akun layanan Anda. Profil dashboard dan detail alert meminta `reasoning_effort=none` agar tidak menggunakan thinking. Dukungan parameter ini bergantung pada model/provider.

- **Dashboard:** AI menerima agregat dan pola yang diizinkan dari alert yang sudah diimpor. Raw log, alamat IP, payload, dan kredensial tidak dimasukkan ke snapshot dashboard. Metrik agregat mencakup seluruh alert pada bulan berjalan; ekstraksi MITRE lokal memakai sampel hingga 10.000 alert terbaru. Cakupan sampel ditampilkan pada dashboard.
- **Detail alert:** AI menerima konteks alert dan bukti yang disanitasi. Data seperti IP atau path yang relevan untuk investigasi dapat termasuk di dalamnya. Tinjau kebijakan pengiriman data organisasi sebelum mengaktifkan integrasi.
- **Otomatis:** scheduler memeriksa kebutuhan pembaruan pada 02:10 WIB setiap hari; interval analisis default 10 hari dan dapat diubah di Settings. Job otomatis diproses pada queue `ai`.
- **Manual:** analisis yang diminta pengguna menunggu respons layanan. Timeout detail default 60 detik, dashboard 90 detik. `none` mengurangi pekerjaan model tetapi tidak menjamin ketersediaan atau waktu respons layanan.

Jalankan worker dengan `--queue=ai,default --timeout=180`. Default `DB_QUEUE_RETRY_AFTER=240` harus tetap lebih besar daripada timeout worker untuk menghindari job diambil ulang sebelum eksekusi selesai.

## Deployment dan operasional

- Gunakan HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, URL aplikasi yang benar, dan cookie sesi aman (`SESSION_SECURE_COOKIE=true`) untuk deployment HTTPS.
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

Pastikan `.env.example` hanya berisi placeholder. Jangan memasukkan token, password, data alert nyata, alamat infrastruktur privat, chat ID operasional, atau screenshot insiden ke README, fixture, dan aset publik.

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

## Lisensi

Lisensi distribusi kode aplikasi SIEM Salabim belum ditetapkan dalam repository ini. Dependency pihak ketiga tetap mengikuti lisensinya masing-masing.
