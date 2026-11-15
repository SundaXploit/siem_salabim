# Catatan perubahan SIEM Salabim 1.1 → 1.2

[README utama](../README.md) · [Galeri aplikasi](../README.md#galeri) · [Kontribusi](../README.md#kontribusi)

Diperbarui **15 November 2026**. Acuan versi 1.1 adalah commit `c4da117` dengan pesan `update V.1.1`; catatan 1.2 ini merangkum perubahan kode dan antarmuka setelah acuan tersebut.

Versi 1.2 berfokus pada keamanan akun dan data, kepemilikan penanganan alert, keandalan notifikasi Telegram, serta kemudahan administrasi SOC. Dashboard, Live Alerts, analisis AI, RBAC per agent, ignore permanen, leaderboard, installer, dan ekspor data sudah tersedia pada 1.1; bagian berikut menjelaskan penambahan dan perbaikannya.

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
| Audit dan UI | Audit fitur baru, ID objek, status akun/template, filter responsif | Aktivitas administrasi lebih mudah ditelusuri pada desktop dan ponsel. |

<a id="akun-sesi-dan-kepemilikan-ack"></a>

## Akun, sesi, dan kepemilikan ACK

### Manajemen pengguna

- Tambah tindakan **nonaktifkan/aktifkan akun** pada Manajemen user. Penonaktifan mempertahankan akun dan riwayatnya.
- Akun nonaktif ditolak saat login dan tidak dapat melanjutkan tindakan dengan sesi lama.
- Perubahan password, status aktif, role, pilihan agent, atau privilege mencabut versi sesi sebelumnya dan remember token.
- Admin aktif terakhir tidak dapat diturunkan menjadi analis atau dinonaktifkan. Pengguna juga tidak dapat menonaktifkan akun sendiri.
- Admin tidak dapat mengedit profil akun admin lain. Admin tetap mengelola akun analis sesuai aturan akses.
- Jika akun dinonaktifkan, alert berstatus ACK yang masih dimilikinya dikembalikan ke **NEW**. Pemilik dan waktu ACK dilepas; setiap pelepasan dicatat pada Log aktivitas.

### Kebijakan sesi

- Sesi kedaluwarsa setelah **lebih dari 15 menit tanpa aktivitas pengguna**, dengan batas absolut **8 jam**.
- Gerakan pointer, klik, sentuhan, pengetikan, dan scroll dapat memperbarui aktivitas. Polling dashboard, refresh otomatis, dan request latar belakang tidak memperpanjang sesi.
- Aktivitas dibagikan antartab untuk sesi browser yang sama. Heartbeat dibatasi agar tidak menghasilkan request pada setiap gerakan pointer.
- Server memeriksa kembali status akun dan versi sesi pada request berikutnya. Penyimpanan aktivitas terpisah mencegah request polling lama menimpa waktu aktivitas terbaru.

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

- Metadata operasional—identitas sumber/deduplikasi, Rule ID/level, status, cluster/index/ID sumber, waktu, dan kepemilikan ACK—tetap dapat diproses SQL. Ini enkripsi selektif; nama agent, deskripsi rule, dan metadata akun/audit tidak seluruhnya dienkripsi.
- Cast mendekripsi atribut ketika diakses. Batch ingestion tetap mengenkripsi payload sebelum upsert, karena upsert tidak menjalankan cast/event model secara otomatis.
- Indeks **HMAC-SHA256** menjaga pencarian exact-match IP dan pencarian kata utuh pada data terenkripsi. Nilai asli tidak disimpan pada tabel indeks.
- Indeks memakai `SIEM_BLIND_INDEX_KEY` yang independen dan stabil, sehingga rotasi `APP_KEY` tidak mengubah kunci lookup.
- Daftar alert tidak mengambil seluruh payload. Dashboard memakai proyeksi MITRE kecil untuk menghindari dekripsi ribuan log pada agregasi rutin.
- Batas indeks default: 512 token unik dan 128 KiB teks yang diproses per log/catatan. `SIEM_SEARCH_MAX_TOKENS=0` menonaktifkan indeks kata; pencarian exact-match pada kolom IP tetap tersedia.
- Indeks menambah penyimpanan/biaya ingest dan mengungkap kesamaan token. Pencarian kata utuh dengan batas token berbeda dari pencarian substring bebas seluruh JSON.
- Perintah migrasi mendukung proses bertahap yang dapat dilanjutkan, pemeriksaan autentikasi/index, serta enkripsi ulang untuk rotasi kunci.

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

<a id="upgrade-dan-operasional"></a>

## Upgrade dan operasional

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

| Area | Rujukan kode/pengujian |
| --- | --- |
| Akun, sesi, dan aturan admin | [SecurityPolicyTest](../tests/Feature/SecurityPolicyTest.php), [aktivitas browser](../tests/js/session-activity.test.mjs) |
| Race condition ACK | [ConcurrentAckTest](../tests/Unit/ConcurrentAckTest.php) |
| Notifikasi tunggal dan massal | [ManualNotificationIdempotencyTest](../tests/Feature/ManualNotificationIdempotencyTest.php), [BulkNotificationTest](../tests/Feature/BulkNotificationTest.php) |
| Manajemen template dan audit | [NotificationTemplateManagementTest](../tests/Feature/NotificationTemplateManagementTest.php), [ActivityLoggingTest](../tests/Feature/ActivityLoggingTest.php) |
| Waktu dan SSL | [WazuhTimezoneTest](../tests/Feature/WazuhTimezoneTest.php), [OpenSearchTlsSettingsTest](../tests/Feature/OpenSearchTlsSettingsTest.php) |
| Enkripsi dan pemulihan SQL | [SensitiveDataEncryptionTest](../tests/Feature/SensitiveDataEncryptionTest.php), [SqlExportRoundTripTest](../tests/Feature/SqlExportRoundTripTest.php) |
| Pemeriksaan UI | [security-policy.mjs](../tests/ui/security-policy.mjs) |

Pemulihan SQL telah diperiksa pada SQLite, MySQL, dan PostgreSQL/PGlite; ini tidak menyatakan seluruh driver Laravel mempunyai tingkat dukungan yang sama. Tes integrasi yang membuat database sementara memerlukan akun pengujian tersendiri. Jangan menjalankan restore atau pengujian terhadap database operasional.

Untuk kontribusi, jalankan tes yang relevan di lingkungan pengujian dengan PDO sesuai, `npm test` untuk JavaScript, dan `npm run build` jika antarmuka berubah. Sertakan cara verifikasi pada pull request. Lihat [cara melaporkan bug dan bergabung sebagai kontributor](../README.md#kontribusi).
