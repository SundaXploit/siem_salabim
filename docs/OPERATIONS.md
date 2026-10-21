# Panduan operasional SIEM Salabim

[Kembali ke README](../README.md) · [Konfigurasi contoh](../.env.example)

Panduan lengkap untuk integrasi, deployment, backup, dan pengujian. Untuk instalasi melalui wizard dan pembuatan administrator awal, ikuti README terlebih dahulu.

## Database untuk instalasi baru

Gunakan **PostgreSQL 18 dengan minor release terbaru** sebagai default produksi. Wizard `/install`, `.env.example`, dan fallback konfigurasi menggunakan PostgreSQL. Instalasi lama tetap memakai `DB_CONNECTION` yang tersimpan; pembaruan kode tidak memindahkan atau mengonversi data otomatis.

PostgreSQL menyimpan alert operasional, triage, pengguna, hasil AI, dan metadata ekspor. OpenSearch/Wazuh Indexer tetap menjadi sumber indeks log. Untuk volume besar, tetapkan batas retensi dan filter alert yang diimpor; jangan mengandalkan pergantian DBMS saja sebagai solusi kapasitas.

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=siem_salabim
DB_USERNAME=siem_app
DB_PASSWORD=
DB_SSLMODE=prefer
```

Siapkan database UTF-8 dan role khusus aplikasi yang dapat membuat/mengubah tabel di schema `public` untuk migrasi. Aktifkan `pdo_pgsql` pada **PHP web dan CLI/worker**. Untuk server terpisah gunakan TLS dengan verifikasi sertifikat (`verify-full`) dan CA yang sesuai. Jangan memakai akun superuser sebagai akun aplikasi.

Pilihan `/install` adalah PostgreSQL, MySQL, dan MariaDB. SQLite hanya untuk pengembangan/pengujian dan tidak ditawarkan dalam wizard. Matrix CI menjalankan seluruh suite pada PostgreSQL 18, MySQL 8.0/8.4, dan MariaDB 11.8. Hasil eksekusi lokal serta batas verifikasinya dicatat di [audit database](DATABASE_AUDIT.md).

### Alternatif MySQL/MariaDB

Buat database dan pengguna aplikasi terlebih dahulu, kemudian ganti konfigurasi database **sebelum menjalankan migrasi**:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=siem_salabim
DB_USERNAME=siem_app
DB_PASSWORD=
```

Untuk MariaDB gunakan `DB_CONNECTION=mariadb`. Untuk instalasi baru, masukkan koneksi melalui `/install`; wizard menguji database, menjalankan migrasi tanpa seeder, lalu membuat administrator. Jalur CLI `php artisan migrate` dan `php artisan siem:create-admin` tetap tersedia. Perpindahan engine pada instalasi berisi data membutuhkan migrasi data terpisah dan uji restore; mengganti `.env` saja tidak memindahkan data.

### Kapasitas dan pemrosesan log

- Jalankan ingestion, AI, dan ekspor melalui worker asynchronous. Untuk deployment besar, pertimbangkan Redis sebagai queue/cache bersama agar pekerjaan antrean tidak menambah beban database alert; siapkan Redis terlebih dahulu sebelum mengubah konfigurasinya.
- Pertahankan indeks identitas sumber untuk deduplikasi. Import membaca halaman dan menulis batch; besar batch dan jumlah worker harus diukur dengan ukuran log nyata serta kapasitas CPU, RAM, dan IOPS server.
- Batasi retensi melalui ekspor terverifikasi dan purge bertahap. Atur rollover/retensi indeks OpenSearch secara terpisah.
- Pantau query lambat, jumlah koneksi, pertumbuhan tabel/indeks, autovacuum, disk, dan umur transaksi ekspor. Pencarian substring pada seluruh JSON serta statistik seluruh tabel masih bisa memindai banyak baris; belum ada klaim kapasitas jutaan log atau EPS tertentu.
- Untuk pemulihan lengkap PostgreSQL, gunakan backup native (`pg_dump`/`pg_restore`, atau physical backup dan WAL untuk PITR), disertai backup `APP_KEY`, konfigurasi, dan file bukti. Ekspor aplikasi hanya mencakup data yang dipilih.

Rujukan: [MVCC PostgreSQL](https://www.postgresql.org/docs/18/mvcc-intro.html), [masa dukungan versi](https://www.postgresql.org/support/versioning/), dan [retensi indeks OpenSearch](https://docs.opensearch.org/latest/im-plugin/ism/index/).

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
| `OPENSEARCH_SIZE` | `100` | Ukuran setiap batch fetch; bukan batas total alert per proses. |
| `OPENSEARCH_FETCH_PAGE_DELAY_MS` | `250` | Jeda antarbatch untuk mengurangi lonjakan permintaan. |
| `OPENSEARCH_SOURCE_CLUSTER` | `wazuh-primary` | Nama logis cluster yang stabil meskipun endpoint berubah. |
| `OPENSEARCH_FETCH_OVERLAP_SECONDS` | `900` | Rentang waktu yang dibaca ulang untuk menangkap alert terlambat. |
| `OPENSEARCH_RECONCILE_LOOKBACK_DAYS` | `2` | Jumlah hari kalender WIB (hari ini + hari sebelumnya) yang di-scan ulang berkala; rentang valid 2-30 hari. |
| `OPENSEARCH_FETCH_INSERT_BATCH_SIZE` | `100` | Jumlah alert per bulk upsert ke database. |
| `OPENSEARCH_FETCH_LOCK_SECONDS` | `3600` | TTL lock lintas scheduler, queue, dan CLI untuk satu proses ingestion. |
| `OPENSEARCH_PIT_ENABLED` | `false` | Opt-in PIT + `search_after`; default aman memakai scroll snapshot. |
| `OPENSEARCH_PIT_TIEBREAKER_FIELD` | Kosong | Field keyword/doc_values yang unik dan selalu terisi pada setiap concrete index; wajib bila PIT aktif. Jangan gunakan `_id`. |
| `OPENSEARCH_PIT_KEEP_ALIVE_SECONDS` | `120` | Masa hidup snapshot PIT yang diperpanjang pada setiap halaman. |

Fetch reguler memakai checkpoint persisten. Proses pertama dimulai dari awal hari **Asia/Jakarta**; proses berikutnya membaca dari checkpoint dikurangi overlap hingga waktu mulai proses. Checkpoint hanya maju setelah seluruh halaman berhasil disimpan, sehingga kegagalan di tengah proses akan dibaca ulang secara idempoten. Scheduler menjalankannya setiap menit. Setiap enam jam, rekonsiliasi juga memindai ulang hari ini dan hari sebelumnya untuk menangkap dokumen yang baru terindeks setelah event-time melewati overlap. Refresh tampilan Live Alerts berlangsung setiap 30 detik dan dijeda selama triage massal.

| Perintah | Cakupan |
| --- | --- |
| `php artisan siem:fetch-alerts` | Seluruh halaman pada window incremental sejak checkpoint. |
| `php artisan siem:fetch-alerts --today` | Seluruh halaman alert hari ini yang memenuhi filter; dipakai juga oleh tombol fetch manual di Settings. |
| `php artisan siem:fetch-alerts --lookback-days=2` | Rekonsiliasi seluruh halaman hari ini dan satu hari sebelumnya (Asia/Jakarta). |
| `php artisan siem:fetch-alerts --all` | Seluruh riwayat yang cocok; dapat memerlukan waktu dan sumber daya besar. |
| `php artisan siem:fetch-alerts --today --dry-run` | Memeriksa kandidat impor tanpa menyimpan alert; tetap mengirim query ke OpenSearch. |

Alert yang sudah tersimpan dilewati berdasarkan kombinasi cluster, indeks konkret, dan ID dokumen. `New: 0` bisa berarti seluruh hasil sudah pernah diimpor, atau tidak ada hasil untuk kombinasi tanggal, indeks, dan level. Koneksi yang berhasil tidak menjamin ada alert yang cocok dengan filter tersebut.

Pada volume tinggi, fetch reguler tidak berhenti pada `OPENSEARCH_SIZE`: nilai tersebut hanya mengatur ukuran halaman. Default memakai scroll snapshot yang kompatibel. PIT dengan `search_after` dapat diaktifkan setelah operator memverifikasi field tiebreaker yang unik, selalu terisi, dan memiliki doc values pada seluruh concrete index. Aplikasi hanya fallback ke scroll bila pembukaan PIT ditolak/tidak tersedia sebelum hit dikonsumsi; konfigurasi tiebreaker yang salah atau kegagalan di tengah pagination menggagalkan proses agar checkpoint tidak maju. Late arrival di dalam lookback rekonsiliasi akan ditemukan secara idempoten; untuk data yang terlambat melebihi SLA lookback, jalankan `--all` atau rekonsiliasi manual dengan rentang 2-30 hari yang sesuai kapasitas. Hindari backfill arsip penuh berulang tanpa kebutuhan.

### Telegram

Isi token bot dan daftar chat tujuan di **Settings**, lalu pilih penerimanya pada alur notifikasi. Bot harus memiliki akses mengirim pesan ke chat tersebut. Parameter teknis koneksi, timeout, dan retry tersedia di `.env.example`.

Notifikasi dapat memuat informasi alert dan bukti yang dipilih pengguna. Lampiran gambar dibatasi 10 MB per file. Pengiriman lampiran tidak membutuhkan symlink `public/storage`.

### Analisis AI

Isi endpoint, API key, dan model di **Settings**. Batas sampel, ambang level impor, serta interval analisis juga dikelola melalui Settings. Pada formulir saat ini, penyimpanan pertama pengaturan analisis memerlukan API key AI. Default model adalah `amanai/deepseek-v4.1-flash`; pastikan model tersedia untuk akun layanan Anda. Parameter timeout, reasoning, dan batas panjang respons tetap tersedia di `.env.example`. Profil dashboard dan detail alert meminta `reasoning_effort=none` agar tidak menggunakan thinking. Dukungan parameter ini bergantung pada model/provider.

- **Dashboard:** AI menerima agregat dan pola yang diizinkan dari alert yang sudah diimpor. Raw log, alamat IP, payload, dan kredensial tidak dimasukkan ke snapshot dashboard. Metrik agregat mencakup seluruh alert pada bulan berjalan; ekstraksi MITRE lokal memakai sampel hingga 10.000 alert terbaru. Cakupan sampel ditampilkan pada dashboard.
- **Detail alert:** AI menerima konteks alert dan bukti yang disanitasi. Data seperti IP atau path yang relevan untuk investigasi dapat termasuk di dalamnya. Tinjau kebijakan pengiriman data organisasi sebelum mengaktifkan integrasi.
- **Otomatis:** scheduler memeriksa kebutuhan pembaruan pada 02:10 WIB setiap hari; interval analisis default 10 hari dan dapat diubah di Settings. Job otomatis diproses pada queue `ai`.
- **Manual:** analisis yang diminta pengguna menunggu respons layanan. Timeout detail default 60 detik, dashboard 90 detik. `none` mengurangi pekerjaan model tetapi tidak menjamin ketersediaan atau waktu respons layanan.

Jalankan worker ingestion/AI dengan `--queue=ai,default --timeout=1800`. Ekspor memakai worker terpisah `--queue=exports --timeout=1800` agar pekerjaan besar tidak menahan ingestion. Pastikan nama queue sama dengan `SIEM_EXPORT_QUEUE`.

## Deployment dan operasional

- Gunakan HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, URL aplikasi yang benar, dan cookie sesi aman (`SESSION_SECURE_COOKIE=true`) untuk deployment HTTPS.
- Tidak ada akun bawaan. Buat administrator awal melalui wizard dan gunakan password organisasi yang unik; login menggunakan email dan password.
- Web server hanya mengekspos `public/`; berikan izin tulis aplikasi pada `storage/` dan `bootstrap/cache/`.
- Instal dependency menggunakan `composer install --no-dev --optimize-autoloader`, bangun aset dengan `npm ci` lalu `npm run build`, dan jalankan migrasi sesuai prosedur backup lingkungan Anda.
- Setelah memperbarui `.env`/kode, jalankan `php artisan config:cache`, `php artisan view:cache`, dan restart worker dengan `php artisan queue:restart`.
- Kelola queue worker dengan process manager. Worker queue dan scheduler perlu tetap berjalan setelah terminal ditutup.
- Jalankan worker `ai,default` dan `exports` sebagai proses terpisah. Jika self-update diaktifkan, gunakan satu worker khusus `updates` dengan timeout 3.600 detik. Atur graceful-stop process manager lebih lama daripada timeout worker terkait.
- `MAIL_MAILER=log` hanya menulis email ke log. Konfigurasikan SMTP/provider email agar reset password benar-benar terkirim.

Contoh cron Linux untuk satu scheduler:

```cron
* * * * * cd /path/to/SIEM_Salabim && php artisan schedule:run >> /dev/null 2>&1
```

Pada Windows, buat Task Scheduler yang menjalankan `php artisan schedule:run` setiap menit dengan working directory proyek. `schedule:work` cocok untuk terminal pengembangan.

### Update aplikasi terverifikasi

Settings dapat memeriksa GitHub Release stabil terbaru dari repository `SundaXploit/siem_salabim`. Pemeriksaan versi tetap tersedia saat self-update dinonaktifkan. Aplikasi tidak memasang branch `main`, source archive otomatis, prerelease, atau draft. Update hanya menerima tag `vX.Y.Z` dan asset bernama `siem-salabim-vX.Y.Z.zip` yang memiliki digest SHA-256 dari GitHub serta manifest internal per file.

Self-update sengaja **nonaktif secara default** dan ditujukan untuk satu node aplikasi dengan filesystem lokal. Untuk beberapa node/container, immutable image, atau shared deployment, gunakan pipeline/orchestrator eksternal agar rollout dan rollback terkoordinasi. Sebelum mengaktifkannya:

1. buat dan uji restore backup penuh database, `.env`, `APP_KEY`, bukti/unggahan, dan storage privat;
2. deploy dari worktree bersih atau paket release, tanpa perubahan file terlacak;
3. gunakan queue asynchronous dan jalankan satu worker khusus update dengan akun OS yang memiliki izin menulis kode aplikasi;
4. pastikan tidak ada ingestion, ekspor, atau purge yang sedang berjalan;
5. baru atur flag server berikut dan bangun ulang cache konfigurasi.

```dotenv
SIEM_SELF_UPDATE_ENABLED=true
SIEM_UPDATE_BACKUP_CONFIRMED=true
SIEM_UPDATE_QUEUE=updates
SIEM_UPDATE_REFUSE_DIRTY_WORKTREE=true
SIEM_UPDATE_PROCESS_TIMEOUT=900
```

```bash
php artisan config:cache
php artisan queue:work --queue=updates --sleep=3 --tries=1 --timeout=3600
```

`SIEM_UPDATE_BACKUP_CONFIRMED` adalah konfirmasi operator, bukan proses backup otomatis. Backup kode internal di `storage/app/private/application-updates/` hanya dipakai untuk rollback sebelum migrasi dimulai dan bukan pengganti backup penuh. Nilai `retry_after` queue database/Redis/Beanstalkd harus lebih dari timeout update; konfigurasi bawaan menjaganya minimal 3.660 detik.

Saat admin mengajukan update, server memeriksa kembali release, password admin, frasa konfirmasi, dan kesiapan queue. Worker kemudian mengunduh asset melalui URL API release yang dipatok ke repository/asset ID, memverifikasi ukuran dan SHA-256, menolak traversal/symlink/path runtime, membuat backup kode terverifikasi, masuk maintenance mode, memasang file, menjalankan migrasi, membangun cache, me-restart worker, lalu keluar dari maintenance mode. `.env`, `storage/`, database SQLite lokal, `bootstrap/cache/`, dan symlink tidak pernah berasal dari paket update.

Jika kegagalan terjadi sebelum migrasi, updater mencoba memulihkan kode lama dan keluar dari maintenance mode. Setelah migrasi mulai dijalankan, updater tidak melakukan rollback database secara otomatis; status menjadi `recovery_required` dan maintenance dipertahankan. Pulihkan aplikasi **dan database** dari backup penuh yang cocok, periksa record serta event pada `application_updates`, lalu hapus `storage/framework/application-update.lock` dan jalankan `php artisan up` hanya setelah versi kode/database dinyatakan konsisten. Jangan mengantrekan update baru selama status pemulihan belum diselesaikan.

Untuk menerbitkan release, ubah `VERSION`, commit perubahan, lalu push tag yang persis cocok (misalnya `v0.2.0`). Workflow `.github/workflows/release.yml` menjalankan test/build dan audit advisory, memasang dependency production, merakit paket hanya dari file Git yang terlacak beserta `vendor/` dan hasil build frontend, lalu membuat manifest hash. Release dibuat sebagai draft saat asset diunggah dan baru dipublikasikan setelah ZIP serta `SHA256SUMS` selesai diverifikasi. Tanpa GitHub Release resmi yang memenuhi kontrak tersebut, UI akan melaporkan bahwa belum ada release dan tidak menawarkan pemasangan.

Asset `siem-salabim-vX.Y.Z.zip` adalah paket **khusus updater**, bukan paket instalasi baru. Paket itu sengaja tidak membawa `.env`, `storage/`, `bootstrap/cache/`, database lokal, atau file runtime lain agar data instalasi tidak tertimpa. Untuk instalasi pertama, gunakan alur clone dan wizard pada README sehingga struktur runtime dibuat dari repository lalu dikonfigurasi secara lokal.

### Backup dan penghapusan data

Ekspor pada Settings mengikuti cakupan dan periode pilihan pengguna. Format SQL mengikuti engine sumber (PostgreSQL, MySQL/MariaDB, atau SQLite), yang tercantum pada header SQL dan manifest. Restore memerlukan schema aplikasi yang sesuai dan record pengguna yang direferensikan sudah tersedia. PostgreSQL memulihkan sequence ID setelah insert. Arsip SQL lama tetap berformat MySQL; gunakan JSON untuk pertukaran lintas engine, dengan proses impor terpisah. Aplikasi belum menyediakan UI restore. Pembuatan ekspor berjalan melalui queue asynchronous dan membaca record dalam chunk, sehingga request web tidak menampung seluruh backup di memori. Aplikasi menolak ekspor/purge bila koneksi queue berjalan di request (`sync`, `deferred`, `null`, atau failover yang memuatnya).

| Variabel `.env` | Default | Keterangan |
| --- | --- | --- |
| `SIEM_EXPORT_DISK` | `local` | Disk privat dan durable untuk arsip. |
| `SIEM_EXPORT_DIRECTORY` | `siem-exports` | Prefix/direktori objek ekspor. |
| `SIEM_EXPORT_TEMP_DIRECTORY` | `storage/app/private/siem-export-tmp` | Direktori privat di luar web root dengan ruang cukup untuk satu arsip. |
| `SIEM_EXPORT_CHUNK_SIZE` | `500` | Record yang dibaca per chunk. Sesuaikan setelah load test. |
| `SIEM_EXPORT_PURGE_MAX_ROWS` | `100000` | Batas seluruh row yang diverifikasi/dikunci dalam satu purge; pecah rentang tanggal bila terlampaui. |
| `SIEM_EXPORT_QUEUE` | `exports` | Harus sama dengan nama queue pada worker ekspor. |
| `SIEM_EXPORT_REQUIRE_ASYNC_QUEUE` | `true` | Tolak eksekusi ekspor/purge di request web. Jangan nonaktifkan di produksi. |

Setiap ekspor disimpan pada disk `SIEM_EXPORT_DISK` di direktori `SIEM_EXPORT_DIRECTORY`, bersama manifest berisi batas snapshot, jumlah record, fingerprint sumber, ukuran file, dan SHA-256. Status baru menjadi **Terverifikasi** setelah aplikasi membaca kembali objek dari storage dan mencocokkan checksum serta ukurannya. Worker queue `exports` harus aktif agar ekspor dan purge diproses.

Job ekspor memiliki timeout 1.800 detik. Karena koneksi queue yang sama juga dapat menjalankan updater, `retry_after` database, Redis, dan Beanstalkd dijaga minimal 3.660 detik agar job yang masih berjalan tidak diambil worker kedua. Untuk SQS, atur visibility timeout queue di AWS lebih dari timeout job terpanjang karena nilainya tidak dikendalikan aplikasi.

Penghapusan langsung dan alur "backup lalu hapus" dalam satu request dinonaktifkan. Administrator harus membuat ekspor, menunggu status Terverifikasi, lalu memulai Purge sebagai tindakan terpisah. Sebelum menghapus, worker memverifikasi ulang manifest, checksum arsip, jumlah serta fingerprint record sumber. Bila data berubah atau ada record turunan baru, purge dibatalkan secara aman dan administrator perlu membuat ekspor baru.

Disk `local` berada di `storage/app/private` dan tidak dilayani oleh web server, tetapi aplikasi tidak melakukan enkripsi file sendiri. Untuk produksi, gunakan volume dengan encryption-at-rest atau disk S3 privat dengan bucket-side encryption, versioning/object lock sesuai kebijakan organisasi. Adapter `league/flysystem-aws-s3-v3` belum disertakan proyek ini dan harus dipasang serta dikonfigurasi sebelum memakai disk S3. Ekspor Settings bukan pengganti backup lengkap database, file bukti, konfigurasi storage, dan `APP_KEY`; uji prosedur restore secara berkala.

Transaksi `REPEATABLE READ` dipertahankan selama file sementara dibuat pada MySQL/MariaDB dan PostgreSQL agar isi antartabel konsisten. SQLite memakai snapshot transaksinya. Transaksi panjang dapat menahan versi row/MVCC pada ekspor sangat besar; jadwalkan ekspor di luar jam sibuk, pantau undo/transaction age, dan lakukan uji integrasi pada engine database produksi.

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

Untuk pengujian database server, buat database **kosong khusus tes** yang namanya berakhiran `_test`. Suite melakukan `migrate:fresh`/rollback dan dapat menghapus seluruh tabel; jangan arahkan ke database operasional. Guard pengujian menolak konfigurasi yang di-cache dan nama database server tanpa akhiran tersebut. Contoh PowerShell dalam terminal khusus pengujian:

```powershell
$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'siem_audit_test'
$env:DB_USERNAME = 'siem_test'
$env:DB_PASSWORD = '<password-role-test>'
php -d extension=pdo_pgsql -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```

Gunakan driver/port yang sesuai untuk MySQL atau MariaDB. Tes `ServerDatabaseTest` memeriksa installer tanpa mock database dan reservasi queue dengan dua koneksi serta row lock nyata. Tes ekspor menjalankan SQL hasil ekspor langsung di engine sumber, menguji replay, integritas isi, rollback, dan sequence. Workflow `databases.yml` dipanggil sebelum job publikasi rilis; rilis tidak dilanjutkan bila salah satu engine gagal. Workflow yang ditambahkan secara lokal belum dianggap lulus sampai benar-benar dijalankan di GitHub Actions.

## Sebelum commit dan push

Repository menyertakan kode aplikasi, migrasi, pengujian, `.env.example`, `composer.lock`, dan `package-lock.json`. Lock file perlu ikut di-commit agar instalasi menggunakan versi dependency yang sama.

`.gitignore` mengecualikan konfigurasi lokal `.env` beserta variasinya, dependency terpasang, hasil build, log, dan isi runtime `storage/`. File `.gitignore` di dalam `storage/` tetap dipertahankan untuk struktur direktori. Database atau hasil ekspor di luar direktori yang diabaikan perlu diperiksa melalui `git status` sebelum commit.

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
