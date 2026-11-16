# Catatan perubahan SIEM Salabim 1.1 → 1.2

Audit PostgreSQL native dan panduan konfigurasi terbaru: [PostgreSQL: audit, konfigurasi, dan pemeriksaan](POSTGRESQL.md), 16 November 2026.

[README utama](../README.md) · [Galeri aplikasi](../README.md#galeri) · [Kontribusi](../README.md#kontribusi)

Diperbarui **15 November 2026**. Acuan versi 1.1 adalah commit `c4da117` dengan pesan `update V.1.1`; catatan 1.2 ini merangkum perubahan kode dan antarmuka setelah acuan tersebut.

Versi 1.2 berfokus pada keamanan akun dan data, kepemilikan penanganan alert, keandalan notifikasi Telegram, serta kemudahan administrasi SOC. Dashboard, Live Alerts, analisis AI, RBAC per agent, ignore permanen, leaderboard, installer, dan ekspor data sudah tersedia pada 1.1; bagian berikut menjelaskan penambahan dan perbaikannya.

Catatan ini mencakup patch keamanan yang telah diterapkan sampai tanggal pembaruan: [akun, sesi, dan ACK](#akun-sesi-dan-kepemilikan-ack), [identitas agent dan TLS](#integrasi-wazuh-waktu-dan-ssl), [notifikasi](#notifikasi-telegram-dan-template), [enkripsi dan rotasi kunci](#enkripsi-data-dan-pencarian), [backup](#backup-sql-dan-pemulihan), serta [SSRF, validasi tanggal, dan dependency](#perbaikan-keamanan-koneksi). Langkah pemasangan patch untuk instalasi 1.2 yang sudah berjalan tersedia pada [upgrade dan operasional](#upgrade-dan-operasional).

## Ringkasan perubahan

| Area | Perubahan pada 1.2 | Dampak penggunaan |
| --- | --- | --- |
| Akun dan sesi | Penonaktifan akun, pencabutan sesi, idle timeout, perlindungan admin terakhir | Admin dapat menghentikan akses tanpa menghilangkan riwayat pengguna. |
| Penanganan alert | Pemilik ACK, transaksi untuk tindakan bersamaan, aturan ACK tunggal dan massal | Alert ditangani oleh pengguna yang berhasil ACK lebih dahulu. |
| Identitas agent | Pemetaan Telegram berdasarkan cluster dan Wazuh Agent ID | Agent dengan nama sama tetap memiliki tujuan dan cakupan akses yang berbeda. |
| Waktu dan koneksi | Zona waktu sumber yang dapat diatur, verifikasi SSL, diagnosis sertifikat | Timestamp ber-offset tidak dikonversi dua kali; masalah TLS lebih mudah ditelusuri. |
| Telegram | ID operasi, receipt per tujuan/lampiran, satu pesan teks massal per tujuan | Klik ganda dan retry identik tidak membuat kiriman tambahan. |
| Template notifikasi | Editor bersama, Custom / Pesan Bebas, manajemen template di Settings | Admin dapat menambah, mengedit, menonaktifkan, dan menghapus template tanpa mengubah riwayat. |
| Enkripsi | AES-256-GCM, pembacaan data lama, rotasi kunci, indeks HMAC | Payload sensitif terlindungi dengan metadata operasional tetap dapat dikueri SQL. |
| Backup | SQL sesuai engine, pemulihan sequence PostgreSQL, metadata keamanan lengkap | Backup mengikuti struktur dan relasi data versi baru. |
| AI | Batas permintaan dan konkurensi provider, pencatatan percobaan gagal | Kapasitas AI dibatasi bersama di sisi server. |
| Koneksi dan dependency | Izin otomatis dari alamat tersimpan, DNS pinning, redirect nonaktif, patch Laravel/Symfony/Vite | Integrasi tetap mendukung IP lokal/publik dan port pilihan admin tanpa daftar izin terpisah. |
| Audit dan UI | Audit fitur baru, ID objek, status akun/template, filter responsif | Aktivitas administrasi lebih mudah ditelusuri pada desktop dan ponsel. |

<a id="akun-sesi-dan-kepemilikan-ack"></a>

## Akun, sesi, dan kepemilikan ACK

### Manajemen pengguna

- Tambah tindakan **nonaktifkan/aktifkan akun** pada Manajemen user. Penonaktifan mempertahankan akun dan riwayatnya.
- Akun nonaktif ditolak saat login dan tidak dapat melanjutkan tindakan dengan sesi lama.
- Perubahan password, status aktif, role, pilihan agent, atau privilege mencabut versi sesi sebelumnya dan remember token.
- Penggantian password dari akun sendiri meregenerasi ID sesi dan memulai ulang sesi yang valid; sesi lama tetap dicabut.
- Admin aktif terakhir tidak dapat diturunkan menjadi analis atau dinonaktifkan. Pengguna juga tidak dapat menonaktifkan akun sendiri.
- Admin tidak dapat mengedit profil akun admin lain. Admin tetap mengelola akun analis sesuai aturan akses.
- Jika akun dinonaktifkan, alert berstatus ACK yang masih dimilikinya dikembalikan ke **NEW**. Pemilik dan waktu ACK dilepas; setiap pelepasan dicatat pada Log aktivitas.

### Kebijakan sesi

- Sesi kedaluwarsa setelah **lebih dari 15 menit tanpa aktivitas pengguna**, dengan batas absolut **8 jam**.
- Gerakan pointer, klik, sentuhan, pengetikan, dan scroll dapat memperbarui aktivitas. Polling dashboard, refresh otomatis, dan request latar belakang tidak memperpanjang sesi.
- Aktivitas dibagikan antartab untuk sesi browser yang sama. Heartbeat dibatasi agar tidak menghasilkan request pada setiap gerakan pointer.
- Server memeriksa kembali status akun dan versi sesi pada request berikutnya. Penyimpanan aktivitas terpisah mencegah request polling lama menimpa waktu aktivitas terbaru.
- Installer baru menyiapkan `SESSION_ENCRYPT=true`. Secure cookie disesuaikan dengan URL HTTPS aplikasi; instalasi lama perlu memeriksa konfigurasi efektifnya.

### Triage tunggal dan massal

- Alert menyimpan **pemilik ACK** dan **waktu ACK**. Ignore serta notifikasi manual hanya boleh dilakukan pemilik ACK, termasuk untuk admin.
- ACK memakai transaksi, penguncian baris, dan update bersyarat. Saat dua pengguna mencoba ACK alert yang sama, satu pengguna menjadi pemilik; audit ACK tidak digandakan.
- ACK massal memakai aturan yang sama. Alert yang sudah dimiliki pengguna lain dilewati; pilihan yang berhasil menjadi milik pengguna dipertahankan untuk tindak lanjut.
- Ignore dan notifikasi massal menolak pilihan yang bercampur antara NEW, ACK milik sendiri, dan ACK milik orang lain. ACK dahulu atau pilih hanya alert yang sudah dimiliki.
- Detail massal menampilkan pemilik ACK dan menyediakan tautan ke subset ACK milik sendiri.
- Pengiriman jaringan Telegram dilakukan setelah transaksi klaim selesai agar lock database tidak ditahan selama menunggu jaringan.
- Migrasi mencoba memulihkan pemilik ACK lama dari riwayat ACK pertama, atau riwayat notifikasi jika ACK lama tidak tersedia. ACK tanpa pemilik memerlukan klaim sebelum tindak lanjut.

<a id="integrasi-wazuh-waktu-dan-ssl"></a>

## Integrasi Wazuh, waktu, dan SSL

### Identitas agent dan routing

- Pemetaan Telegram kini memakai **source cluster + Wazuh Agent ID**, menggantikan nama agent sebagai dasar routing.
- Nama tetap ditampilkan untuk membantu operator, tetapi nama sama tidak menyamakan identitas, akses, atau tujuan pengiriman.
- Pemetaan lama berdasarkan nama dimigrasikan hanya jika ditemukan satu identitas yang pasti. Nama yang ambigu atau tidak ditemukan ditandai untuk dipilih ulang oleh admin.
- Worker memeriksa kembali pemetaan agent sebelum mengirim notifikasi yang masih pending.
- Notifikasi otomatis dan pengelompokan kuotanya juga memakai identitas agent, sehingga dua agent bernama sama tidak berbagi grup kuota hanya karena namanya sama. Pesan otomatis menyertakan Agent ID.

### Zona waktu

- Settings OpenSearch menyediakan zona waktu sumber untuk timestamp yang **tidak mempunyai offset**. Pilih `UTC` atau zona yang sesuai, misalnya `Asia/Jakarta` untuk WIB.
- Timestamp yang sudah memuat `Z` atau offset seperti `+07:00` mengikuti offset tersebut; pilihan zona sumber tidak menimpanya.
- Penyimpanan waktu alert dinormalisasi ke UTC. Tampilan dan filter tanggal menggunakan hari WIB, sehingga sumber yang sudah WIB tetap ditampilkan pada jam WIB yang sama.
- Pengaturan ini digunakan pada parsing impor; mengubah zona sumber tidak otomatis menulis ulang timestamp alert lama.

### Verifikasi sertifikat

- Installer dan **Settings → Konfigurasi OpenSearch & Alert** menyediakan pilihan verifikasi SSL **true/false**, dengan default instalasi baru **true**.
- Tombol tes koneksi memakai pilihan formulir yang sedang diisi. Worker membaca ulang konfigurasi koneksi pada fetch berikutnya.
- Kegagalan sertifikat menampilkan saran pemeriksaan CA, hostname, atau masa berlaku. Untuk diagnosis pada jaringan tepercaya, operator dapat memilih **false sementara**.
- Petunjuk kegagalan TLS tersedia pada status ingestion, Live Alerts, dan Settings. Aplikasi tidak otomatis mematikan verifikasi lalu mengirim ulang request.
- Pilihan SSL ini mengatur koneksi Wazuh/OpenSearch; koneksi Telegram menggunakan HTTPS tersendiri.

<a id="notifikasi-telegram-dan-template"></a>

## Notifikasi Telegram dan template

### Pengiriman tunggal

- Setiap operasi manual mempunyai UUID dan fingerprint pesan/lampiran. Retry identik menggunakan UUID yang sama; payload berbeda dengan UUID yang sudah dipakai ditolak.
- Status disimpan **per tujuan**, serta **per pesan dan gambar**: pending, sending, sent, partial, failed, unconfirmed, atau cancelled sesuai tahap dan hasilnya.
- Tindakan dibatasi enam POST per pengguna per menit. Pesan maksimal 4096 karakter; maksimal lima gambar, masing-masing 10 MB, tetap mengikuti batas PHP/web server.
- Klik ganda dicegah pada formulir. Status operasi dapat diperiksa tanpa mengirim ulang pesan.
- Penghapusan riwayat notifikasi saja mempertahankan penanda operasi agar request lama tidak mengirim kembali.
- Telegram tidak menyediakan idempotency key. Hasil yang tidak pasti tidak otomatis dikirim ulang; operator perlu memeriksa chat sebelum sengaja membuat operasi baru.

### Detail alert atau Log analis error 500 setelah notifikasi otomatis

Pada patch sebelumnya, klaim pengiriman otomatis memperbarui `notification_logs.message` langsung melalui query sehingga melewati cast enkripsi. Dengan `SIEM_ALLOW_LEGACY_PLAINTEXT=false`, detail alert atau halaman **Log analis (`/analyst-log`)** yang membaca pesan tersebut gagal dengan `Legacy plaintext requires an explicit migration mode.` Satu pesan lama pada halaman hasil sudah cukup menyebabkan 500, termasuk ketika dibuka admin. Patch ini mengenkripsi pesan saat klaim dan memperbarui indeks pencarian dalam transaksi yang sama. Pengiriman otomatis tetap tidak mengambil ACK atau mengubah keputusan triage. Memasang kode saja tidak memperbaiki pesan lama; jalankan pemulihan di bawah.

Untuk instalasi yang sudah terkena:

1. Cadangkan database, `.env`, dan kunci enkripsi sesuai prosedur backup. Hentikan scheduler/worker SIEM secara terkendali melalui Supervisor, systemd, atau terminal yang menjalankannya, lalu aktifkan maintenance dengan `sudo -u www-data /usr/bin/php artisan down`. Tunggu job aktif selesai sebelum memperbarui kode. Nama layanan mengikuti konfigurasi server Anda.
2. Pasang patch ini, termasuk `AutomaticAlertNotifier.php` dan `EncryptSensitiveData.php`. Dari direktori proyek, jalankan perintah berikut sebagai pengguna PHP-FPM yang mempunyai izin baca `.env` dan tulis `storage`/`bootstrap/cache` (contoh Debian/Ubuntu: `www-data`):

   ```bash
   cd /var/www/html/siem_salabim
   sudo -u www-data /usr/bin/php artisan optimize:clear
   sudo -u www-data /usr/bin/php artisan siem:encrypt-data --allow-legacy --chunk=100
   sudo -u www-data /usr/bin/php artisan siem:encrypt-data --check
   ```

   Opsi `--allow-legacy` hanya membuka pembacaan plaintext selama perintah pemulihan itu berjalan, termasuk ketika konfigurasi dicache. Opsi ini tidak mengubah `.env`, `APP_KEY`, atau pengaturan pembacaan pada request web. Pesan lama dienkripsi dan indeks pencariannya dibangun ulang; pesan Telegram tidak dikirim ulang. Perintah dapat diulang jika terhenti. Pemeriksaan `--check` harus sukses dengan nol entri tertunda sebelum melanjutkan.
3. Bila pemeriksaan berhasil, jalankan `sudo -u www-data /usr/bin/php artisan up`, lalu mulai kembali scheduler dan worker menggunakan kode terbaru. Jika PHP-FPM memakai OPcache tanpa pemeriksaan timestamp, muat ulang layanan PHP-FPM. Untuk aplikasi yang dikelola dengan metode lain, sesuaikan akun dan perintah pengelola prosesnya.
4. Buka kembali `/analyst-log` tanpa filter, dengan filter **Notifikasi**, dan dengan pencarian kata dari pesan lama. Buka juga detail alert yang bermasalah sebagai admin atau analis yang mempunyai akses agent tersebut. Kedua halaman harus dapat dibuka setelah pemulihan. Analisis AI memerlukan privilege analisis dan provider yang dikonfigurasi. Ambil ACK sebelum ignore atau notifikasi manual; tindakan lanjutan tetap milik pengguna pemegang ACK.

Jika dekripsi tetap gagal karena ciphertext rusak atau kunci tidak cocok, hentikan pemulihan dan periksa backup/kunci sebelumnya. Jangan mengganti `APP_KEY`, menghapus database/riwayat, atau mengaktifkan mode legacy secara permanen untuk menyembunyikan kegagalan autentikasi.

### Satu pesan massal per tujuan

- Toolbar Live Alerts dan Detail & analisis massal dapat mengirim notifikasi untuk pilihan hingga **100 alert** yang sudah ACK milik pengguna.
- Beberapa alert/agent yang menuju chat sama digabung menjadi **satu pesan teks**, bukan satu pesan untuk setiap alert. Tujuan berbeda menerima subset alert dari agent yang dipetakan kepadanya.
- Dialog massal memakai tampilan dan komponen editor yang sama dengan notifikasi tunggal: tujuan otomatis, tombol template, pesan bebas, penghitung karakter, serta evidence opsional.
- Jika terdapat beberapa tujuan, operator dapat mengedit pesan masing-masing melalui tombol tujuan. Pesan hasil edit dikirim apa adanya, tanpa memproses ulang kurung kurawal sebagai variabel.
- Perubahan pilihan atau anggota pemetaan tujuan setelah preview menyebabkan penolakan sebelum intent pengiriman dibuat; buka ulang dialog untuk memuat keadaan terbaru.
- Worker memeriksa ulang akun, privilege, akses agent, pemilik ACK, dan pemetaan seluruh anggota grup. Jika satu anggota tidak lagi valid sebelum klaim, pesan grup yang masih pending dibatalkan.
- Dua worker yang mencoba grup sama hanya menghasilkan satu klaim pengiriman. Receipt dan audit tetap tersedia pada setiap alert anggota.
- Gambar dikirim sebagai pesan foto terpisah setelah teks, satu kali per tujuan. Lampiran antrean disimpan sementara dalam bentuk terenkripsi di disk private dan dihapus setelah seluruh tujuan mencapai hasil akhir. Operasi yang masih pending/sending mempertahankan file untuk penanganan lanjutan.
- Salinan plaintext gambar hanya dibuat sementara saat dipakai API foto dan dihapus setelah panggilan selesai. Arsip JSON/SQL membawa receipt dan metadata, bukan file gambar tersebut.
- Riwayat menampilkan pesan gabungan utuh hanya bagi admin atau pengguna yang dapat melihat seluruh alert anggota. Pengguna dengan akses lebih sempit melihat konteks alert yang diizinkan.

### Pengelolaan template

- Tambah bagian **Settings → Template notifikasi** untuk admin: tambah, edit, nonaktifkan, dan hapus template.
- Template aktif yang sama digunakan oleh notifikasi tunggal dan massal. Template nonaktif tetap dapat dikelola, tetapi tidak muncul pada pemilih.
- **Custom / Pesan Bebas** tetap satu template bersama. Default isinya menyediakan judul, agent, IP sumber/tujuan, detail temuan, rekomendasi, dan prioritas dengan nilai kosong untuk diisi sendiri.
- Migrasi mengganti body Custom bawaan yang belum dimodifikasi; body yang sudah diedit administrator dipertahankan.
- Nama template ditampilkan sebagai teks saja. Input/API metadata ikon dihilangkan dan kolom `notification_templates.icon` dihapus. Ikon menu Settings memakai SVG bawaan aplikasi.
- Pesan antrean dan riwayat menyimpan snapshot terenkripsi. Mengubah atau menghapus template tidak menulis ulang pesan yang sudah disiapkan/dikirim.
- API lama dengan `template_id` atau `custom_template` tetap tersedia; mode tersebut tidak boleh digabung dengan array pesan hasil edit `messages`.

Variabel bersama: `{alert_id}`, `{agent_id}`, `{agent_name}`, `{agent_ip}`, `{src_ip}`, `{dst_ip}`, `{rule_id}`, `{rule_level}`, `{rule_description}`, `{source_cluster}`, `{timestamp}`, dan `{wazuh_id}`. Pada massal, nilai tunggal yang relevan menjadi daftar unik dalam grup tujuan. Variabel ringkasan tambahan: `{alert_count}`, `{alert_ids}`, `{agent_count}`, `{agents}`, `{agent_ids}`, `{agent_names}`, `{rule_ids}`, `{max_level}`, dan `{alerts}`. Nilai dari alert di-escape saat template dirender untuk HTML Telegram.

<a id="enkripsi-data-dan-pencarian"></a>

## Enkripsi data dan pencarian

Cipher default menjadi **AES-256-GCM**, dengan verifikasi integritas bawaan. Pembaca kompatibel tetap memverifikasi ciphertext Laravel AES-256-CBC lama. Ciphertext rusak atau kunci salah menyebabkan kegagalan dekripsi; kegagalan autentikasi tidak dialihkan ke fallback plaintext.

| Penyimpanan | Field/data yang dilindungi |
| --- | --- |
| Alert | `raw_data`, IP sumber, IP tujuan |
| Analisis AI alert | Ringkasan, indikator pendukung/normal, rekomendasi pemeriksaan, keterbatasan |
| Notifikasi | Pesan, rincian delivery, snapshot pesan gabungan, file lampiran antrean massal |
| Triage dan insight | Alasan triage, ringkasan insight, snapshot sumber |
| Ekspor dan konfigurasi | Password arsip, password OpenSearch, bot token Telegram, API key AI |
| Cache AI massal | Hasil analisis tersimpan |

- Metadata operasional-identitas sumber/deduplikasi, Rule ID/level, status, cluster/index/ID sumber, waktu, dan kepemilikan ACK-tetap dapat diproses SQL. Ini enkripsi selektif; nama agent, deskripsi rule, dan metadata akun/audit tidak seluruhnya dienkripsi.
- Cast mendekripsi atribut ketika diakses. Batch ingestion tetap mengenkripsi payload sebelum upsert, karena upsert tidak menjalankan cast/event model secara otomatis.
- Indeks **HMAC-SHA256** menjaga pencarian exact-match IP dan pencarian kata utuh pada data terenkripsi. Nilai asli tidak disimpan pada tabel indeks.
- Indeks memakai `SIEM_BLIND_INDEX_KEY` yang independen dan stabil, sehingga rotasi `APP_KEY` tidak mengubah kunci lookup.
- Daftar alert tidak mengambil seluruh payload. Dashboard memakai proyeksi MITRE kecil untuk menghindari dekripsi ribuan log pada agregasi rutin.
- Batas indeks default: 512 token unik dan 128 KiB teks yang diproses per log/catatan. `SIEM_SEARCH_MAX_TOKENS=0` menonaktifkan indeks kata; pencarian exact-match pada kolom IP tetap tersedia.
- Indeks menambah penyimpanan/biaya ingest dan mengungkap kesamaan token. Pencarian kata utuh dengan batas token berbeda dari pencarian substring bebas seluruh JSON.
- Perintah migrasi mendukung proses bertahap yang dapat dilanjutkan, pemeriksaan autentikasi/index, serta enkripsi ulang untuk rotasi kunci.
- Pembuatan kunci indeks oleh migrator memakai penulis konfigurasi yang mempertahankan `APP_KEY`. Jalur pembaruan runtime ini menolak perubahan `APP_KEY`, sehingga menambahkan kunci pencarian tidak mengganti kunci dekripsi data yang sudah ada.

### Rotasi kunci

Pertahankan kunci lama di `APP_PREVIOUS_KEYS` saat mengganti `APP_KEY`, dan pertahankan `SIEM_BLIND_INDEX_KEY`. Setelah konfigurasi pembaca diperbarui pada seluruh node, gunakan:

```bash
php artisan siem:encrypt-data --rotate --chunk=50
php artisan siem:encrypt-data --check
```

Simpan kunci lama selama masih dibutuhkan data, backup, atau file lampiran pending. Perintah rotasi memproses database/cache, bukan file lampiran sementara. Jangan menjalankan `key:generate` pada instalasi berisi data sebagai cara upgrade.

Perlindungan ini mencakup isi sensitif database yang dibaca tanpa kunci. Enkripsi volume/TDE, TLS database, HTTPS aplikasi, pengelolaan kunci terpisah, dan keamanan host tetap menjadi konfigurasi deployment. Data didekripsi di RAM saat digunakan; penguasaan host aplikasi beserta kuncinya memungkinkan dekripsi. Migrasi field juga tidak menghapus plaintext yang mungkin masih ada pada backup lama atau halaman disk terdahulu. Aplikasi tetap memerlukan parameter binding, validasi, dan otorisasi untuk mencegah penyalahgunaan akses.

<a id="backup-sql-dan-pemulihan"></a>

## Backup SQL dan pemulihan

- Ekspor SQL kini mengikuti driver aktif: **PostgreSQL**, **MySQL/MariaDB**, atau **SQLite**. SQL Server belum didukung oleh writer ekspor SQL ini.
- Output menyertakan transaksi, quoting/literal sesuai engine, metadata migrasi schema, dan penyesuaian sequence ID PostgreSQL. Benturan ID menjadi error, tanpa overwrite atau skip diam-diam.
- Backup JSON/SQL mencakup pemilik/waktu ACK, cakupan agent, penanda operasi notifikasi, hasil delivery, hash IP, proyeksi MITRE, dan indeks pencarian yang terkait cakupan ekspor.
- Field terenkripsi diekspor sebagai ciphertext. Metadata memuat fingerprint kunci, bukan kuncinya.
- Pemeriksaan fingerprint dan purge memperhitungkan record keamanan serta indeks baru. Data yang berubah setelah backup memerlukan backup baru sebelum purge.
- Penghapusan hanya riwayat notifikasi tidak menghapus penanda operasi. Retensi indeks mengikuti record yang dihapus.
- Arsip lama tetap dipertahankan; buat backup baru setelah upgrade sebelum menggunakan fitur purge pada data yang berubah.

### Prasyarat restore

1. Gunakan engine database yang sama dan schema aplikasi yang sesuai dengan metadata backup. SQL sesuai engine bukan konversi otomatis antar-engine.
2. Hentikan writer aplikasi saat restore. Gunakan lingkungan pemulihan terisolasi untuk verifikasi pertama.
3. Sediakan akun pengguna yang dirujuk dengan ID aslinya. Ekspor operasional mengikuti cakupan/periode; `users`, konfigurasi global, dan kunci tidak otomatis menjadi backup seluruh instalasi.
4. Pulihkan `APP_KEY` asli atau kunci sebelumnya, beserta `SIEM_BLIND_INDEX_KEY` yang sesuai. Mengimpor ciphertext tanpa kunci tidak membuatnya dapat dibaca.
5. Impor melalui client database dengan penghentian saat error, lalu jalankan `php artisan siem:encrypt-data --check` dan periksa dekripsi, relasi, serta pencarian.

Untuk backup plaintext dari versi lama, aktifkan mode migrasi hanya di lingkungan pemulihan yang dikendalikan, migrasikan datanya, lalu nonaktifkan kembali. Backup SQL/JSON operasional tidak menggantikan backup file aplikasi, kunci, konfigurasi, dan media yang diperlukan deployment.

<a id="analisis-ai"></a>

## Analisis AI

- Provider mempunyai batas permintaan bersama per menit dan jumlah request bersamaan di sisi server; default masing-masing **20 request/menit** dan **3 request bersamaan**, dapat disesuaikan melalui konfigurasi.
- Percobaan generasi yang gagal setelah kuota dicatat tetap mengonsumsi batas percobaan. Kegagalan tidak dipakai sebagai cara melewati kuota retry.
- Batas manual tiga percobaan dalam sepuluh menit diperiksa menurut cakupan fiturnya: pengguna/periode untuk dashboard, pengguna/alert untuk analisis tunggal, dan pengguna untuk analisis massal.
- Cache hasil analisis massal disimpan terenkripsi. Cache dan akses tetap mengikuti pengguna serta pilihan agent yang diizinkan.
- Analisis AI satu alert, massal, dan ringkasan dashboard merupakan fitur yang sudah ada; 1.2 memperkuat pengendalian kapasitas serta perlindungan hasilnya.

AI digunakan untuk membantu membaca evidence dan memberi rekomendasi pemeriksaan. Analis memvalidasi kesimpulan terhadap log asli. Pemilihan provider cloud atau model lokal menentukan ke mana evidence tersanitasi dikirim; sanitasi tidak menjamin seluruh informasi sensitif hilang.

## Audit, antarmuka, dan dokumentasi

- Log aktivitas mencakup perubahan template melalui endpoint Settings maupun endpoint lama, ID template, status aktif yang diminta, dan hasil berhasil/gagal. Perubahan verifikasi SSL masuk daftar field audit.
- Penonaktifan/aktivasi akun dan pelepasan ACK dicatat tanpa menghapus riwayat aktor. Perubahan konfigurasi tidak menyalin password, token, atau isi template ke log aktivitas.
- Status aktif/nonaktif tampil pada baris audit yang relevan. Filter aktivitas mencakup kategori baru tersebut.
- Filter `/history/activity` memakai grid responsif agar pencarian, dropdown aktivitas, tanggal, dan tombol tidak saling menempel. Lebar desktop, tablet, dan ponsel diperiksa.
- Pilihan template pada tunggal/massal diseragamkan. ID input, dialog, pencegahan submit ganda, serta batas karakter mengikuti editor yang sama.
- Galeri README mempertahankan 49 gambar asli dalam bagian terstruktur, lengkap dengan kegunaan halaman dan navigasi preview. Screenshot dapat mewakili tampilan sebelum beberapa penyempurnaan 1.2.
- README utama memperjelas tujuan aplikasi SIEM, cakupan monitoring Wazuh, pengguna SOC/CSIRT, serta jalur masukan, laporan bug, dan kontribusi.

<a id="perbaikan-keamanan-koneksi"></a>

## Perbaikan keamanan koneksi dan dependency

### OpenSearch dan provider AI

Kebijakan koneksi bersama berlaku pada tes OpenSearch di Settings/installer, pengambilan log/agent OpenSearch, tes AI, daftar model, dan analisis AI operasional:

- Setelah instalasi, tujuan yang diizinkan otomatis dibaca dari konfigurasi integrasi yang disimpan admin. Protokol, host, port, dan base path harus sama; request tes/model tidak dapat menambahkan izin dari URL yang dikirimkannya. Bentuk ekuivalen seperti port HTTPS default dan slash akhir dinormalisasi.
- Mengganti konfigurasi mengganti izin tujuan, tanpa menyimpan daftar historis. Transport OpenSearch memeriksa konfigurasi aktif pada setiap request; tujuan lama pada client yang tersimpan dalam memori ditolak. Endpoint kosong yang tersimpan menonaktifkan fallback environment. Fallback `.env` hanya digunakan bila konfigurasi terkait belum memiliki entri database.
- HTTP/HTTPS saja, tanpa userinfo, query, fragment, karakter kontrol, atau path ambigu pada base URL. Indeks OpenSearch tidak boleh menyisipkan URL atau perpindahan direktori.
- Alamat metadata cloud, link-local, unspecified, multicast, serta representasi IP yang ambigu ditolak sebelum koneksi. Pemeriksaan mencakup IPv4, IPv6, dan seluruh hasil resolusi DNS.
- DNS hasil pemeriksaan dipasang ke cURL melalui `CURLOPT_RESOLVE`; URL host asli tetap dipakai untuk TLS. Semua alamat cadangan diperiksa dan redirect HTTP dinonaktifkan. Proxy dari environment tidak digunakan untuk koneksi ini agar pemeriksaan tujuan tidak dilewati.
- Password OpenSearch tersimpan hanya dipakai kembali ketika URL tujuan dan username sama. Mengganti protokol, host, port, base path, atau username dengan password kosong ditolak bila ada password tersimpan. API key AI tersimpan hanya digunakan kembali untuk provider dan endpoint yang sama.
- Tes AI mengembalikan konfirmasi tetap, bukan potongan isi respons endpoint. Katalog model tetap menampilkan identitas model yang diperlukan fitur tersebut. Tes OpenSearch di Settings dan installer mensyaratkan respons JSON dengan `count` berupa bilangan bulat tidak negatif; status HTTP 200 atau JSON sembarang tidak cukup untuk menyatakan koneksi berhasil.

### Mengganti tujuan tanpa mengelola daftar izin

1. Isi URL integrasi, termasuk port/base path yang diperlukan, melalui Settings dan pilih **Simpan konfigurasi**. Perubahan konfigurasi disimpan dalam transaksi dan dicatat pada Log aktivitas. IP privat, loopback, domain, dan IP publik tetap didukung; alamat metadata dan alamat khusus tetap ditolak.
2. Jalankan **Test Koneksi** untuk alamat tersimpan. Kredensial, indeks, dan pilihan verifikasi SSL dapat diuji tanpa menyimpannya bila tujuannya tetap sama. URL baru pada request tes tidak langsung mendapat izin.
3. Untuk AI baru yang belum diketahui modelnya, simpan endpoint/provider/API key dengan model kosong, kemudian **Ambil daftar model**. Pilih model dan simpan kembali untuk mengaktifkan analisis. Menyimpan endpoint saja tidak menjalankan analisis maupun tes koneksi.

Saat instalasi pertama belum ada konfigurasi tersimpan. Wizard dengan token dan sesi instalasi yang valid boleh menguji pilihan OpenSearch dan memuat katalog AI; mode awal ini tidak tersedia setelah aplikasi terpasang. Pilihan yang disimpan menjadi sumber izin untuk penggunaan berikutnya.

Izin ini mengikat hostname yang dipilih, bukan membuka akses langsung ke semua IP hasil DNS atau seluruh port host tersebut. DNS tetap dapat berubah untuk kebutuhan operasional; hasil setiap resolusi diperiksa dan koneksi dipasang ke alamat hasil pemeriksaan. Identitas layanan juga bergantung pada verifikasi TLS bila menggunakan HTTPS. Pendekatan tujuan tepercaya dan pembatasan redirect dijelaskan pada [panduan SSRF OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html).

**Batas kepercayaan:** admin tetap berwenang mengganti tujuan lewat penyimpanan konfigurasi. Admin yang sengaja menyimpan alamat layanan internal lain dapat mengizinkan alamat itu, selama bukan alamat terlarang. Penguasaan hak tulis database juga dapat mengubah konfigurasi sumber izin. Fitur ini membatasi request ke tujuan resmi yang disimpan; pembatasan kewenangan admin atau isolasi jaringan keluar memerlukan kontrol terpisah. Pemilihan provider `ollama`/lokal tidak membolehkan request melampaui tujuan tersimpan.

PHP cURL diperlukan. Base URL tetap diisi melalui installer atau Settings tanpa konfigurasi daftar izin tambahan. Jika jaringan bergantung pada forward proxy, siapkan rute langsung ke integrasi karena proxy environment sengaja dilewati.

### Leaderboard

Filter periode divalidasi sebelum perhitungan. Periode harus termasuk pilihan yang didukung; rentang custom wajib berisi tanggal kalender `YYYY-MM-DD`, tanggal akhir minimal sama dengan awal, dan maksimal **366 hari**. Input salah atau rentang berlebihan mengembalikan HTTP **422**, bukan exception parsing/HTTP 500.

Permintaan browser memakai `Accept: application/json`. Pesan validasi ditampilkan pada halaman, ranking sebelumnya dipertahankan, dan pengguna dapat memperbaiki rentang lalu mencoba kembali. Array, teks tanggal relatif, tanggal kalender tidak valid, dan rentang yang terlalu besar tidak diteruskan ke perhitungan leaderboard.

### Dependency dan penilaian advisory

Pemeriksaan tanggal 15 November 2026 menemukan delapan advisory Composer pada lima paket. Semua paket tersebut diperbarui, termasuk yang jalur eksploitasinya tidak ditemukan pada kode aplikasi; tidak ada advisory yang diabaikan melalui konfigurasi audit.

| Paket | Sebelum | Sesudah |
| --- | --- | --- |
| `laravel/framework` | 13.32.0 | 13.42.0 |
| `symfony/http-foundation` | 7.4.19 | 7.4.23 |
| `symfony/http-kernel` | 7.4.19 | 7.4.23 |
| `symfony/mime` | 7.4.19 | 7.4.22 |
| `symfony/routing` | 7.4.18 | 7.4.23 |

`laravel/prompts` ikut diperbarui ke 0.3.27 sesuai kebutuhan framework. Audit npm juga menemukan dua paket rentan tambahan; `vite` diperbarui dari 8.3.0 ke 8.3.8 dan `follow-redirects` dari 1.16.0 ke 1.16.3 bersama dependency yang diperlukan. Setelah pembaruan, `composer audit --locked` dan `npm audit` melaporkan nol advisory; hasil ini mengikuti database advisory saat pemeriksaan.

Delapan advisory Composer yang tercakup pembaruan tersebut:

| Advisory | Paket | Masalah yang diperbaiki upstream | Versi terpasang setelah patch |
| --- | --- | --- | --- |
| [CVE-2026-128083](https://github.com/advisories/GHSA-63hm-g96r-2m2q) | `laravel/framework` | Injeksi keluaran proses Laravel Concurrency | 13.42.0 |
| [CVE-2026-121619](https://github.com/symfony/symfony/security/advisories/GHSA-xg2q-4rrq-657q) | `symfony/http-foundation` | Penafsiran alamat proxy khusus pada shortcut `private_ranges` | 7.4.23 |
| [CVE-2026-118514](https://symfony.com/cve-2026-118514) | `symfony/http-foundation` | Ketidaksesuaian pencocokan HEAD/GET pada aturan akses berbasis metode | 7.4.23 |
| [PKSA-pr6q-2w5s-kzzf](https://github.com/symfony/symfony/security/advisories/GHSA-3h27-hjv7-fqjx) | `symfony/http-foundation` | Prefix nama script dapat melewati ACL path di depan PHP | 7.4.23 |
| [PKSA-b2gg-vk1g-6d5q](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693) | `symfony/http-foundation` | Path request dapat memengaruhi host pada URL yang dibangkitkan | 7.4.23 |
| [CVE-2026-123339](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9) | `symfony/http-kernel` | Header forwarded pada inline fragment dapat memengaruhi URL request utama | 7.4.23 |
| [CVE-2026-45070](https://symfony.com/cve-2026-45070) | `symfony/mime` | Injeksi header melalui nama parameter MIME | 7.4.22 |
| [CVE-2026-118516](https://symfony.com/cve-2026-118516) | `symfony/routing` | Pencocokan host placeholder dapat melampaui batas host ke path | 7.4.23 |

Audit npm sebelum patch mencatat sembilan advisory pada dua paket berikut. Paket rentan pada lockfile diperbarui tanpa mengabaikan advisory atau memakai pembaruan major secara paksa:

| Paket setelah patch | Cakupan advisory | Rujukan |
| --- | --- | --- |
| `follow-redirects` 1.16.3 | Header kredensial dapat ikut pada redirect lintas origin akibat penanganan format header, regex, atau array lintas realm | [GHSA-q3jh-wrc6-jgq8](https://github.com/advisories/GHSA-q3jh-wrc6-jgq8), [GHSA-8r9p-f939-6h3c](https://github.com/advisories/GHSA-8r9p-f939-6h3c), [GHSA-mrwp-f3q8-3g9p](https://github.com/advisories/GHSA-mrwp-f3q8-3g9p) |
| `vite` 8.3.8 | Escaping, penafsiran argumen editor, dan pemeriksaan path pada integrasi launch-editor | [GHSA-698v-4r3x-3vcv](https://github.com/advisories/GHSA-698v-4r3x-3vcv), [GHSA-r825-w8mm-5pxw](https://github.com/advisories/GHSA-r825-w8mm-5pxw), [GHSA-587h-7wmj-h5pm](https://github.com/advisories/GHSA-587h-7wmj-h5pm) |
| `vite` 8.3.8 | Eksekusi script lintas origin melalui middleware dan pembacaan file di luar batas filesystem server dev | [GHSA-9jrq-w75r-8gcw](https://github.com/advisories/GHSA-9jrq-w75r-8gcw), [GHSA-rq7h-c2jc-7f22](https://github.com/advisories/GHSA-rq7h-c2jc-7f22), [GHSA-vfpm-58rq-9qcg](https://github.com/advisories/GHSA-vfpm-58rq-9qcg) |

Keberadaan advisory pada dependency tidak menyatakan seluruh jalur eksploitasi tersedia pada deployment aplikasi. Pembaruan tetap mencakup seluruh paket yang ditandai audit. Produksi menggunakan aset hasil build; Vite dev server tidak diperlukan untuk melayani aplikasi.

Untuk dua advisory yang bergantung pada infrastruktur:

- **PKSA-pr6q-2w5s-kzzf:** request seperti `/index.phpadmin` sebelumnya dapat dipetakan sebagai `/admin`. Risiko bypass berlaku untuk ACL path pada Nginx/Apache/CDN/WAF di depan PHP. Otorisasi aplikasi memakai middleware Laravel; contoh konfigurasi README tidak membuat ACL admin di luar aplikasi. Konfigurasi server produksi lain tetap perlu diperiksa. Patch dan regresi memastikan prefix tersebut tidak berubah menjadi `/admin`. Lihat [advisory resmi Symfony tentang path](https://github.com/symfony/symfony/security/advisories/GHSA-3h27-hjv7-fqjx).
- **PKSA-b2gg-vk1g-6d5q:** rewrite tertentu dapat membuat path `//...` atau `/\\...` memengaruhi base URL. Ini relevan karena aplikasi membangkitkan URL, termasuk `URL::temporarySignedRoute` untuk unduhan; tanda tangan tidak dengan sendirinya membersihkan base URL. Document root `public/` pada contoh deployment menghindari rewrite root ke subdirektori `public/`, tetapi kondisi server aktif lain belum dianggap terverifikasi. Patch dan regresi memeriksa path berbahaya tidak menjadi host URL. Lihat [advisory resmi Symfony tentang URL](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693).

Kode aplikasi tidak menggunakan Laravel Concurrency atau shortcut proxy `private_ranges`. Jalur email masih ada melalui notifikasi verifikasi akun/konfigurasi SMTP; tidak ditemukan input pengguna yang dijadikan nama parameter header MIME. Karena itu, ketiadaan penggunaan email pada suatu deployment tidak dipakai sebagai alasan melewatkan patch [CVE-2026-45070](https://symfony.com/cve-2026-45070).

Regresi khusus tersedia di [izin tujuan tersimpan](../tests/Feature/ConfiguredIntegrationDestinationTest.php), [kebijakan endpoint](../tests/Unit/OutboundEndpointPolicyTest.php), [integrasi](../tests/Feature/IntegrationEndpointSecurityTest.php), [validasi leaderboard](../tests/Feature/LeaderboardValidationTest.php), dan [perilaku path/base URL Symfony](../tests/Unit/HttpFoundationSecurityTest.php).

<a id="upgrade-dan-operasional"></a>

## Upgrade dan operasional

### Memasang patch pada instalasi 1.2 yang sudah berjalan

Siapkan backup dan gunakan prosedur deployment yang sesuai server. Setelah menempatkan kode serta lockfile versi patch, pasang versi dependency yang dikunci dan bangun ulang aset:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan config:clear
php artisan migrate --force
php artisan view:clear
php artisan queue:restart
```

Gunakan `composer install` dan `npm ci` agar versi mengikuti lockfile patch. Dependency build diperlukan saat menjalankan Vite, sehingga jangan menghilangkannya sebelum `npm run build`. `queue:restart` menghentikan worker setelah job aktif selesai; jalankan kembali worker terminal, atau pastikan Supervisor memulainya lagi. Muat ulang proses aplikasi yang menyimpan kode/config dalam memori sesuai deployment.

Patch pembatasan tujuan dan validasi tanggal tidak menambah migrasi tersendiri. Instalasi 1.2 dengan schema lengkap tidak perlu mengulang migrasi enkripsi, mengganti `APP_KEY`, atau menjalankan reset. Alamat integrasi yang sudah tersimpan otomatis menjadi sumber izin; untuk menggantinya, simpan konfigurasi baru sebelum tes koneksi atau pemuatan model. Periksa versi paket dan advisory setelah deployment dengan `composer audit --locked` dan `npm audit`.

### Migrasi yang ditambahkan

| Akhiran migrasi | Perubahan |
| --- | --- |
| `000018` | Status aktif akun, versi sesi, pemilik/waktu ACK, pemulihan kepemilikan lama dari audit. |
| `000019` | Pemetaan tujuan Telegram berbasis identitas agent. |
| `000020` | Penanda operasi notifikasi manual dan rincian delivery. |
| `000021` | Kolom IP untuk ciphertext, hash IP, serta tabel indeks pencarian terenkripsi. |
| `000022` | Proyeksi metadata MITRE untuk agregasi tanpa membaca seluruh payload. |
| `000023` | Persiapan Custom / Pesan Bebas dengan isian manual, mempertahankan edit admin. |
| `000024` | Penghapusan kolom ikon template. |

### Urutan upgrade dari 1.1

1. Buat backup database dan file instalasi, termasuk kunci, konfigurasi, arsip private, dan media yang masih diperlukan. Verifikasi pemulihannya di lingkungan terpisah.
2. Deploy kode pembaca CBC/GCM yang kompatibel. Untuk banyak node, pertahankan penulisan CBC selama rollout awal jika masih ada pembaca lama; aktifkan GCM setelah seluruh node dapat membacanya.
3. Pertahankan `APP_KEY` asli. Atur `APP_CIPHER=AES-256-GCM` dan sementara `SIEM_ALLOW_LEGACY_PLAINTEXT=true` untuk migrasi data lama. Provision kunci indeks yang sama pada semua node; migrator dapat membuatnya pada instalasi tunggal jika belum ada.
4. Jalankan:

```bash
php artisan config:clear
php artisan migrate --force
php artisan siem:encrypt-data --chunk=50
php artisan siem:encrypt-data --check
```

5. Setelah pemeriksaan menunjukkan nol entri yang memerlukan migrasi/perbaikan, ubah `SIEM_ALLOW_LEGACY_PLAINTEXT=false`. Simpan kunci indeks yang dibuat ke pengelolaan secret dan backup kunci.
6. Bersihkan cache konfigurasi/view, muat ulang proses aplikasi yang menyimpan konfigurasi, dan restart worker secara terkendali. `php artisan queue:restart` memberi sinyal berhenti setelah job selesai; worker terminal perlu dijalankan kembali, sedangkan supervisor harus dikonfigurasi untuk memulainya lagi.
7. Masuk ulang, periksa admin aktif, pemilik ACK, pemetaan Telegram yang belum terselesaikan, zona waktu sumber, serta verifikasi SSL. Buat backup baru setelah upgrade.

Migrator memakai chunk kecil dan lock proses lokal. Jalankan satu migrasi data untuk satu database; lock lokal tidak mengoordinasikan beberapa host. Setelah data menggunakan GCM, rollback kode memerlukan pembaca yang tetap memahami GCM dan schema yang sesuai.

### Worker

Pertahankan worker `ai,default`, worker `exports`, dan satu scheduler per instalasi. Notifikasi massal memerlukan antrean asynchronous; mode sync ditolak. Timeout job notifikasi massal adalah 600 detik, sehingga timeout worker dan `retry_after` perlu lebih panjang. Contoh perintah proses tersedia di [operasional README utama](../README.md#operasional).

Kunci aplikasi dan kunci indeks tidak boleh dipublikasikan atau dibuang ketika masih diperlukan backup. Gunakan akun database yang dibatasi pada kebutuhan aplikasi. Pembatasan akun MySQL pada lingkungan penerapan lokal dilakukan terpisah; migrasi aplikasi tidak otomatis mencabut privilege akun database pada server pengguna lain.

<a id="pengujian"></a>

## Pengujian dan penelusuran implementasi

Regresi mencakup ACK bersamaan dari dua proses, retry notifikasi, pembatalan grup, scope agent, keamanan sesi, enkripsi dan rotasi, restore SQL, template yang dihapus, audit aktivitas, serta tampilan responsif. Pengujian Telegram menggunakan tiruan layanan.

Verifikasi kode terakhir pada **15 November 2026** memeriksa **693 kasus PHP**: 692 lulus, satu dilewati, dan tidak ada kegagalan/error. Tes benturan nama file dilewati karena filesystem Windows; `InstallerDevelopmentServerTest` dikecualikan dari suite tersebut. **30 tes JavaScript lulus**, termasuk pesan validasi leaderboard dan pemulihan tampilan setelah input diperbaiki. Build produksi berhasil setelah pembaruan dependency. Pengujian PHP memakai database SQLite pengujian, bukan database operasional.

Sebanyak **17 tes khusus tujuan integrasi** memeriksa penolakan host/port/protokol/base path yang belum disimpan, pencabutan tujuan lama, penyimpanan endpoint AI sebelum model dipilih, audit perubahan, dan penolakan mode installer setelah aplikasi terpasang. Pemeriksaan transport cURL dengan listener lokal terkontrol memverifikasi DNS pinning dan tidak adanya request lanjutan ke tujuan redirect. Hasil audit dependency pada tanggal yang sama adalah nol advisory Composer dan nol kerentanan npm; hasil ini bukan jaminan terhadap advisory yang terbit kemudian.

| Area | Rujukan kode/pengujian |
| --- | --- |
| Akun, sesi, dan aturan admin | [SecurityPolicyTest](../tests/Feature/SecurityPolicyTest.php), [aktivitas browser](../tests/js/session-activity.test.mjs) |
| Race condition ACK | [ConcurrentAckTest](../tests/Unit/ConcurrentAckTest.php) |
| Notifikasi tunggal dan massal | [ManualNotificationIdempotencyTest](../tests/Feature/ManualNotificationIdempotencyTest.php), [BulkNotificationTest](../tests/Feature/BulkNotificationTest.php) |
| Manajemen template dan audit | [NotificationTemplateManagementTest](../tests/Feature/NotificationTemplateManagementTest.php), [ActivityLoggingTest](../tests/Feature/ActivityLoggingTest.php) |
| Waktu dan SSL | [WazuhTimezoneTest](../tests/Feature/WazuhTimezoneTest.php), [OpenSearchTlsSettingsTest](../tests/Feature/OpenSearchTlsSettingsTest.php) |
| SSRF dan izin tujuan otomatis | [ConfiguredIntegrationDestinationTest](../tests/Feature/ConfiguredIntegrationDestinationTest.php), [IntegrationEndpointSecurityTest](../tests/Feature/IntegrationEndpointSecurityTest.php), [OutboundEndpointPolicyTest](../tests/Unit/OutboundEndpointPolicyTest.php) |
| Validasi dan tampilan leaderboard | [LeaderboardValidationTest](../tests/Feature/LeaderboardValidationTest.php), [pesan validasi browser](../tests/js/leaderboard-validation.test.mjs) |
| Patch penafsiran path dan base URL | [HttpFoundationSecurityTest](../tests/Unit/HttpFoundationSecurityTest.php) |
| Enkripsi dan pemulihan SQL | [SensitiveDataEncryptionTest](../tests/Feature/SensitiveDataEncryptionTest.php), [SqlExportRoundTripTest](../tests/Feature/SqlExportRoundTripTest.php) |
| Pemeriksaan UI | [security-policy.mjs](../tests/ui/security-policy.mjs) |

Pemulihan SQL telah diperiksa pada SQLite, MySQL, dan PostgreSQL/PGlite; ini tidak menyatakan seluruh driver Laravel mempunyai tingkat dukungan yang sama. Tes integrasi yang membuat database sementara memerlukan akun pengujian tersendiri. Jangan menjalankan restore atau pengujian terhadap database operasional.

Untuk kontribusi, jalankan tes yang relevan di lingkungan pengujian dengan PDO sesuai, `npm test` untuk JavaScript, dan `npm run build` jika antarmuka berubah. Sertakan cara verifikasi pada pull request. Lihat [cara melaporkan bug dan bergabung sebagai kontributor](../README.md#kontribusi).
