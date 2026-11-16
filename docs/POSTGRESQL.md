# PostgreSQL: audit, konfigurasi, dan pemeriksaan

Audit **16 November 2026**, untuk kode aplikasi **0.1.2**. Panduan instalasi lengkap Nginx, Apache, Linux lokal, dan Windows tersedia di [situs dokumentasi](https://siem.sundaxploit.id/instalasi.html).

## Lingkungan dan batas pemeriksaan

Pengujian memakai **PostgreSQL 16.16 native**, PHP 8.3.14, dan `pdo_pgsql` pada Windows. Database sementara terpisah dari data aplikasi lokal. Role aplikasi bukan superuser, tidak memiliki `CREATEDB`, `CREATEROLE`, atau `REPLICATION`, dan hanya memiliki database pengujian. Pemeriksaan TLS terpisah memakai autentikasi SCRAM dan sertifikat CA pengujian.

Ini merupakan audit kompatibilitas PostgreSQL dan kontrol aplikasi yang tercakup dalam pengujian. Server Linux pengguna, konfigurasi firewall/SELinux/AppArmor, sertifikat produksi, failover, dan kapasitas dataset produksi belum diperiksa. OpenSearch, AI, SMTP, dan Telegram pada suite aplikasi menggunakan layanan tiruan; tes konkurensi notifikasi tidak mengirim pesan keluar.

## Hasil akhir

| Pemeriksaan | Hasil |
| --- | --- |
| Suite aplikasi pada PostgreSQL 16.16 | **701 kasus: 700 lulus, 1 dilewati; 4.809 assertion; 0 failure/error.** |
| Regresi SQLite setelah perubahan | **701 kasus: 696 lulus, 5 dilewati; 4.788 assertion; 0 failure/error.** |
| Migrasi | Seluruh 31 migrasi berjalan; rollback penuh pada database kosong lalu migrasi ulang lulus. Upgrade metadata dengan data lama dan penolakan rollback yang akan memotong data lulus. |
| Konkurensi PostgreSQL | Enam skenario dua proses: ACK tunggal/massal tumpang tindih, retry notifikasi inline, antrean, dan ringkasan massal lulus. |
| Ekspor dan restore SQL aplikasi | Relasi, UUID, data terenkripsi, pencarian, teks menyerupai SQL, Unicode, NULL, dan sequence ID lulus; konflik ID serta dependensi user yang hilang menolak restore. |
| Backup penuh native | `pg_dump -Fc` dan `pg_restore --exit-on-error --no-owner --no-privileges` memakai role non-superuser; isi **30 tabel / 45 baris fixture** identik setelah restore ke database lain. |
| TLS dan autentikasi | `verify-full` dengan CA/hostname benar berhasil melalui TLS 1.3, termasuk koneksi installer; CA salah, hostname salah, password salah, dan koneksi tanpa TLS ditolak sesuai kebijakan fixture. |
| Database runtime | Cache, lock cache, queue database, serta baca/tulis/hapus sesi lulus. |
| Batas target tes | Nama database produksi biasa, host non-loopback, URL override, schema lain, dan opt-in yang hilang ditolak sebelum koneksi/migrasi. |
| Pemeriksaan berkas | Pint dan `git diff --check` lulus; XML PHPUnit terbaca dan YAML workflow berhasil diparse. Dokumentasi profil memiliki ID, tombol salin, serta tautan fragmen yang valid. |

Tes benturan nama path rilis dilewati pada filesystem Windows. Pada SQLite, empat tes yang khusus PostgreSQL juga dilewati. `InstallerDevelopmentServerTest` dikecualikan dari kedua suite lokal karena penghentian proses `artisan serve` menggantung dalam sandbox Windows; tes tersebut memeriksa proses server pengembangan, bukan perilaku database. Workflow rilis Linux tetap menjalankan suite tanpa pengecualian ini.

Suite PostgreSQL memakai PostgreSQL sebagai koneksi default. Tes unit/berkas dan tes reset yang sengaja membuat fixture SQLite terisolasi tetap menggunakan target masing-masing.

Pengujian mencakup login/sesi, akses per agent, dashboard/history/leaderboard, ingest idempoten/checkpoint, triage, notifikasi, enkripsi/rotasi/pencarian, ekspor/purge, installer, serta alur update aplikasi yang tersedia pada suite. Tidak ditemukan error PostgreSQL pada cakupan akhir tersebut. Angka fixture backup tidak mewakili uji kapasitas produksi.

Artefak lokal tersimpan di `storage/framework/testing/pgsql-audit-20261116/`: laporan JUnit `pgsql-regression.xml` dan `sqlite-regression.xml`, log suite, `tls-results.txt`, serta hash per tabel pada `backup-results.json`. Berkas sementara ini tidak masuk Git. Tidak ada database produksi yang dipakai atau diubah dalam audit.

Server database sementara sudah dihentikan; cluster, binary, arsip unduhan, sertifikat pengujian, dan dump fixture sudah dihapus. Laporan dan log tetap tersedia untuk penelusuran lokal.

## Temuan dan perbaikan

| Temuan | Dampak sebelum perbaikan | Perbaikan |
| --- | --- | --- |
| `agent_name` dibatasi 100 karakter, `rule_id` 20 karakter | Impor nama agent panjang atau rule ID 64 karakter gagal pada PostgreSQL, sementara SQLite dapat menerima data tersebut. | Migrasi `2026_11_16_000025_widen_alert_metadata` mengubah nama agent menjadi `TEXT` dan rule ID menjadi 64 karakter. Rollback memeriksa panjang data sebelum mengubah kolom. |
| NUL pada deskripsi/nama agent | PDO PostgreSQL pada lingkungan audit menyimpan hanya bagian sebelum NUL, tanpa menggagalkan impor. | Setter model mengubah NUL dalam kedua metadata tampilan menjadi teks literal `\u0000`. `raw_data` terenkripsi mempertahankan payload asli, termasuk NUL dan bagian setelahnya. Tidak memulihkan metadata lama yang sudah terpotong. |
| Ekspor PostgreSQL dimulai dalam transaksi yang sudah aktif | Pengaturan snapshot dapat menghasilkan error SQL dan membatalkan transaksi pemanggil. | Ekspor menolak transaksi bersarang sebelum menjalankan SQL PostgreSQL; transaksi pemanggil tetap dapat dipakai. Worker ekspor normal tetap memakai snapshot `REPEATABLE READ`. |
| Lokasi CA PostgreSQL belum dipetakan dari `.env` | Administrator tidak dapat menetapkan CA khusus melalui konfigurasi aplikasi yang tersedia. | `DB_SSLROOTCERT` dipetakan ke `sslrootcert`; nilai kosong diperlakukan sebagai `null` agar tidak merusak DSN. |
| Suite utama berorientasi SQLite | Trigger SQLite gagal pada PostgreSQL; pembungkus transaksi tes menghalangi pengujian snapshot ekspor yang sebenarnya. | Konfigurasi `phpunit.pgsql.xml`, trigger PostgreSQL, pengujian restore native, dan konkurensi dua proses memakai PostgreSQL. Database dibangun ulang antartes agar transaksi aplikasi dapat benar-benar commit. |
| Rilis belum mewajibkan tes PostgreSQL | Regresi khusus PostgreSQL dapat lolos pemeriksaan rilis SQLite. | Workflow rilis menambahkan service PostgreSQL 16.16 dan menjalankan suite menggunakan role non-superuser sebelum publikasi. Workflow belum dijalankan di GitHub pada audit lokal ini. |

**Catatan runtime lokal:** `PDO::ATTR_CLIENT_VERSION` dan berkas DLL menunjukkan **libpq 11.4** pada PHP Laragon yang dipakai untuk audit. Perbarui paket PHP beserta pustaka PostgreSQL yang kompatibel sebelum menjadikan runtime tersebut produksi. Cabang PostgreSQL 11 sudah tidak didukung komunitas; lihat [kebijakan versi PostgreSQL](https://www.postgresql.org/support/versioning/). Versi server PostgreSQL dan versi pustaka client PHP adalah dua hal berbeda.

Pada pengujian lokal, client 11.4 gagal memverifikasi sertifikat yang hanya mempunyai IP SAN `127.0.0.1`; client `psql` 16.16 menerima sertifikat yang sama. Tes PHP dengan hostname `localhost` dan sertifikat DNS yang sesuai berhasil. Jangan mengatasi masalah sertifikat dengan menurunkan `verify-full`; periksa hostname/SAN dan perbarui client.

## Konfigurasi aplikasi

Contoh untuk database **pada mesin aplikasi yang sama**, melalui TCP loopback:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=siem_salabim
DB_USERNAME=siem_app
DB_PASSWORD="ISI_PASSWORD_KUAT"
DB_CHARSET=utf8
DB_SSLMODE=prefer
```

Hapus `DB_URL` lama agar tidak menimpa isian `DB_*`. Jangan memakai `DB_CHARSET=utf8mb4` dari konfigurasi MySQL. Pilihan `prefer` pada contoh loopback tidak memberikan jaminan verifikasi identitas server; untuk database melalui jaringan, gunakan konfigurasi TLS berikut.

```dotenv
DB_HOST=db.internal.example
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/etc/siem-salabim/postgresql-ca.crt
```

Gunakan hostname yang cocok dengan sertifikat server. CA harus berasal dari pengelola database dan dapat dibaca akun PHP-FPM serta worker. Contoh pemasangan CA pada Linux, setelah mengganti `/path/ca.crt` dengan lokasi CA yang benar:

```bash
sudo install -d -o root -g www-data -m 0750 /etc/siem-salabim
sudo install -o root -g www-data -m 0640 /path/ca.crt /etc/siem-salabim/postgresql-ca.crt
sudo -u www-data test -r /etc/siem-salabim/postgresql-ca.crt
```

Pada Windows, contoh lokasi CA tanpa spasi adalah `D:/laragon/certs/postgresql-ca.crt`. Akun yang menjalankan Apache/PHP harus dapat membacanya. Pilih `verify-full` di wizard dan siapkan `DB_SSLROOTCERT` sebelum pengujian koneksi. Installer mempertahankan konfigurasi CA tersebut.

`verify-full` memeriksa rantai CA dan kecocokan hostname; `prefer` dapat memakai koneksi tanpa TLS. Rujukan: [dokumentasi TLS libpq](https://www.postgresql.org/docs/16/libpq-ssl.html).

## Role, schema, dan akses jaringan

Untuk database baru pada Ubuntu, jalankan sebagai akun deployment biasa. Buat role/database hanya jika belum ada:

```bash
sudo apt update
sudo apt install -y postgresql postgresql-client php8.3-pgsql
sudo systemctl enable --now postgresql
sudo -u postgres createuser --login --no-superuser --no-createdb --no-createrole --pwprompt siem_app
sudo -u postgres createdb --owner=siem_app --encoding=UTF8 siem_salabim
psql -h 127.0.0.1 -U siem_app -d siem_salabim -W \
  -c 'SELECT current_database(), current_user;'
```

Role pemilik database dapat menjalankan migrasi dan mengubah objek database aplikasi. Ini membatasi hak pada cluster dibanding akun `postgres`, tetapi belum memisahkan hak DDL deployment dari hak DML runtime. Jika kebijakan organisasi mensyaratkan pemisahan tersebut, DBA perlu menyediakan role migrasi terpisah dan memberi runtime izin tabel/sequence yang diperlukan. Installer, migrasi, dan self-update yang menjalankan migrasi memerlukan role pemilik objek; jangan mengaktifkan self-update dengan role yang hanya memiliki DML.

Pada instalasi dengan database khusus aplikasi, DBA dapat membatasi hak bawaan berikut:

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d siem_salabim \
  -c 'REVOKE CONNECT, TEMPORARY ON DATABASE siem_salabim FROM PUBLIC;' \
  -c 'GRANT CONNECT ON DATABASE siem_salabim TO siem_app;' \
  -c 'REVOKE CREATE ON SCHEMA public FROM PUBLIC;' \
  -c 'GRANT USAGE, CREATE ON SCHEMA public TO siem_app;'
```

Untuk database bersama, sesuaikan grant bagi pengguna sah lainnya sebelum menjalankan pembatasan tersebut. Pada database lama, `GRANT` pada database/schema tidak memberikan kepemilikan tabel yang dibuat role lain. Periksa owner tabel dan sequence sebelum migrasi; minta DBA menyesuaikan objek aplikasi yang tepat. Rujukan: [hak akses dan kepemilikan PostgreSQL](https://www.postgresql.org/docs/16/ddl-priv.html).

Untuk database satu mesin, pertahankan listener loopback dan jangan buka port 5432 ke internet. Untuk database terpisah, batasi listener ke interface internal, firewall hanya dari IP aplikasi, dan gunakan aturan `hostssl` spesifik pada `pg_hba.conf`, misalnya:

```text
# Ganti 192.0.2.10/32 dengan alamat sumber server aplikasi yang sebenarnya.
hostssl   siem_salabim   siem_app   192.0.2.10/32   scram-sha-256
hostnossl siem_salabim   siem_app   192.0.2.10/32   reject
```

Aturan `pg_hba.conf` diperiksa berurutan: tempatkan aturan khusus sebelum aturan lebih luas yang dapat mengizinkan koneksi. Server database harus sudah menyediakan TLS yang valid; `DB_SSLMODE` pada aplikasi tidak mengaktifkan TLS di server.

## Pemeriksaan sebelum dan sesudah deployment

Jalankan dari proyek menggunakan PHP yang sama dengan aplikasi:

```bash
php --ri pdo_pgsql
php --ri pgsql
composer check-platform-reqs --no-dev
```

`pdo_pgsql` diperlukan oleh koneksi aplikasi. Keluaran `php --ri pdo_pgsql` juga menunjukkan versi libpq. Periksa konfigurasi PHP-FPM/Apache secara terpisah dari CLI; restart layanan PHP-FPM setelah memasang ekstensi. Jangan mengandalkan `composer check-platform-reqs` saja untuk memilih driver database opsional.

Untuk instalasi yang sudah berjalan: ambil backup penuh database, kunci aplikasi/indeks, dan berkas private terlebih dahulu; gunakan prosedur maintenance deployment. Contoh berikut menganggap akun migrasi aplikasi adalah `www-data`, proyek sudah diperbarui, dan `.env` sudah benar:

```bash
cd /var/www/html/siem_salabim
sudo -u www-data /usr/bin/php artisan config:clear
sudo -u www-data /usr/bin/php artisan migrate --force
sudo -u www-data /usr/bin/php artisan migrate:status
sudo -u www-data /usr/bin/php artisan config:cache
sudo -u www-data /usr/bin/php artisan queue:restart
```

Jika memakai role deployment terpisah, jalankan migrasi dengan kredensial role itu, kemudian bangun ulang cache konfigurasi runtime dengan kredensial runtime. Restart worker Supervisor/Horizon yang memakai konfigurasi berbeda sesuai deployment. Jangan menjalankan `migrate:fresh`, `siem:reset-installation`, atau mengganti `APP_KEY` pada database operasional.

Sesudah patch ini, pastikan migrasi `2026_11_16_000025_widen_alert_metadata` berstatus **Ran**. Database yang sudah memiliki data tidak dikonversi otomatis dari SQLite/MySQL dengan mengganti `DB_CONNECTION`; pemindahan engine memerlukan migrasi data yang direncanakan dan diuji.

| Pesan/gejala | Pemeriksaan |
| --- | --- |
| `could not find driver` | Aktifkan `pdo_pgsql` pada PHP CLI dan PHP-FPM/Apache yang benar. |
| `password authentication failed` | Periksa role/password dan urutan aturan `pg_hba.conf`; uji `psql -h ... -U ... -W` dari server aplikasi. |
| `permission denied for schema public` / `must be owner` | Periksa hak schema serta owner tabel/sequence; grant database saja tidak cukup untuk migrasi. |
| `invalid value for parameter client_encoding` | Gunakan `DB_CHARSET=utf8`, bersihkan cache konfigurasi lama. |
| `value too long for type character varying(100/20)` saat impor | Terapkan migrasi metadata baru; jangan memotong data mentah agar error hilang. |
| CA tidak ditemukan / sertifikat tidak cocok | Periksa path CA, izin akun runtime, hostname/SAN, jam sistem, dan versi libpq. |
| Koneksi CLI berhasil tetapi web gagal | Bandingkan versi PHP, ekstensi, konfigurasi efektif, akun runtime, dan izin baca CA. |
| Error ekspor transaksi bersarang | Jalankan ekspor melalui worker `exports` setelah transaksi pemanggil selesai. |

Backup SQL dari menu aplikasi berisi data operasional sesuai scope, bukan seluruh database atau seluruh akun/konfigurasi. Backup produksi juga harus mencakup database penuh, kunci enkripsi, dan file private. Uji pemulihan pada database terpisah dengan engine/schema dan kunci yang sesuai.

## Menjalankan ulang suite PostgreSQL

Gunakan checkout pengembangan dan **database sementara tanpa data penting**: suite menjalankan `migrate:fresh` berulang. Target harus loopback, schema awal `public`, dan nama database `siem_pgsql_test` atau `siem_pgsql_test_<suffix>`. Guard menolak host lain, URL override, nama produksi biasa, atau opt-in yang hilang sebelum migrasi. Nama yang lolos guard tetap harus benar-benar database sementara.

Contoh pembuatan fixture Linux, hanya jika belum ada:

```bash
sudo -u postgres createuser --login --no-superuser --no-createdb --no-createrole --pwprompt siem_test
sudo -u postgres createdb --owner=siem_test --encoding=UTF8 siem_pgsql_test
composer install
php artisan config:clear
```

Linux Bash; password dibaca tanpa ditampilkan:

```bash
export DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=siem_pgsql_test DB_USERNAME=siem_test
read -rs -p 'Password database uji: ' DB_PASSWORD
printf '\n'
export DB_PASSWORD
php vendor/bin/phpunit --configuration phpunit.pgsql.xml
unset DB_PASSWORD
```

Windows PowerShell; `pdo_pgsql` harus sudah aktif pada PHP terminal:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'siem_pgsql_test'
$env:DB_USERNAME = 'siem_test'
$pgTestCredential = Get-Credential -UserName siem_test -Message 'Password database PostgreSQL khusus pengujian'
$env:DB_PASSWORD = $pgTestCredential.GetNetworkCredential().Password
try {
    php vendor/bin/phpunit --configuration phpunit.pgsql.xml
} finally {
    Remove-Item Env:DB_PASSWORD
    $pgTestCredential = $null
}
```

Konfigurasi tes menetapkan opt-in `SIEM_TEST_PGSQL=1`, menonaktifkan `DB_URL`, dan memakai driver PostgreSQL untuk restore SQL serta race ACK/notifikasi. Rilis menunggu suite SQLite dan PostgreSQL lulus. Kredensial fixture pada workflow hanya untuk service CI sementara.
