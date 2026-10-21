# Audit kompatibilitas database — 21 Oktober 2026

## Keputusan

**PostgreSQL 18 dengan minor release terbaru menjadi default instalasi baru.** PostgreSQL dipakai untuk data operasional SOC; OpenSearch/Wazuh Indexer tetap menangani indeks log sumber. MySQL/MariaDB dipertahankan sebagai alternatif, sedangkan SQLite menjadi jalur pengembangan dan pengujian.

Rekomendasi ini didasarkan pada kebutuhan transaksi, pemrosesan oleh beberapa worker, dan operasional jangka panjang, bukan klaim bahwa PostgreSQL selalu lebih cepat dari MySQL. PostgreSQL menyediakan [MVCC](https://www.postgresql.org/docs/18/mvcc-intro.html) dan versi 18 tercatat mendapat dukungan hingga November 2030 menurut [kebijakan versi resmi](https://www.postgresql.org/support/versioning/). Gunakan minor release terbaru dan tetap rencanakan upgrade.

## Perbaikan hasil audit

1. Default wizard, `.env.example`, dan fallback konfigurasi diseragamkan ke PostgreSQL. Hook Composer tidak lagi membuat SQLite atau mencoba migrasi sebelum wizard. `.env` instalasi lama tidak diubah.
2. SQL ekspor mengikuti engine sumber. PostgreSQL memakai quoting dan penanganan konflik yang sesuai serta memperbarui sequence ID setelah restore; SQLite menghasilkan SQL native, MySQL/MariaDB tetap didukung. Header dan manifest mencantumkan engine.
3. Pencarian alert dan riwayat menggunakan pencocokan tanpa membedakan kapitalisasi pada PostgreSQL, termasuk JSON yang diubah menjadi teks.
4. Parser checkpoint menerima timestamp dengan atau tanpa pecahan detik dan mempertahankan mikrodetik dalam UTC. Pengujian MySQL nyata menemukan bahwa nilai `DATETIME(6)` sebelumnya menyebabkan kegagalan impor inkremental.
5. NUL pada kolom teks hasil ekstraksi log ditampilkan sebagai `\u0000`, sehingga PostgreSQL tidak menolak batch. Nilai asli tetap disimpan dalam JSON `raw_data`.
6. Tes ekspor/rollback memakai transaksi database sesungguhnya, tanpa transaksi pembungkus tes yang menghalangi `REPEATABLE READ`. Trigger kegagalan tes disesuaikan dengan masing-masing engine; SQL hasil ekspor dieksekusi langsung tanpa penerjemahan sintaks.
7. Guard pengujian menolak konfigurasi cache, `DB_URL`, serta database server tanpa akhiran `_test`. Database pengujian benar-benar terpisah dari database aplikasi.

## Verifikasi

Lingkungan lokal: Windows, PHP 8.3.14, PHPUnit 12.5.14. Server database berjalan hanya pada loopback dengan direktori data khusus audit; database operasional MySQL tidak dipindahkan atau digunakan untuk tes. Ekstensi PDO diaktifkan melalui argumen PHP untuk proses pengujian.

| Engine yang diuji | Hasil suite |
| --- | --- |
| PostgreSQL 18.6 | 342 tes: 341 lulus, 1 dilewati; 1.924 assertion; 0 error/failure |
| MySQL 8.0.30 (kompatibilitas instalasi lama) | 342 tes: 341 lulus, 1 dilewati; 1.924 assertion; 0 error/failure |
| MySQL 8.4.13 LTS | 342 tes: 341 lulus, 1 dilewati; 1.924 assertion; 0 error/failure |
| MariaDB 11.8.9 | 342 tes: 341 lulus, 1 dilewati; 1.924 assertion; 0 error/failure |
| SQLite in-memory | 342 tes: 339 lulus, 3 dilewati; 1.914 assertion; 0 error/failure |

Satu pengecualian pada setiap engine adalah fixture nama file yang tidak dapat dibuat pada filesystem Windows. Dua pengecualian tambahan SQLite adalah tes khusus server database: instalasi menggunakan koneksi jaringan dan dua worker dengan row lock. Keduanya dijalankan pada engine server.

Cakupan meliputi migrasi dan rollback, autentikasi/otorisasi, dashboard, pencarian, impor dan deduplikasi, checkpoint, triage atomik, ekspor dan purge, integritas arsip, serta alur instalasi. Pengujian tambahan memutar ulang 2.000 event dengan payload log dan memastikan jumlah tetap 2.000 serta status triage tidak berubah. Dua koneksi queue menahan lock secara bersamaan dan harus mendapat job berbeda. Restore SQL diuji dua kali untuk memeriksa replay, teks tidak tepercaya, Unicode, JSON, nilai null, timestamp, dan kelanjutan ID.

Integrasi OpenSearch, Telegram, dan AI tetap menggunakan mock/fake dalam suite. Audit ini tidak mengklaim konektivitas atau kapasitas layanan eksternal produksi. Tes installer server memakai koneksi database nyata dengan integrasi opsional dinonaktifkan.

Rangkaian awal MariaDB/MySQL 8.4 sempat dihentikan oleh batas waktu HTTP 180 detik yang terbawa ke proses PHPUnit. Harness kini mereset batas waktu sebelum setiap tes, sesuai pemisahan request yang sebenarnya; seluruh suite kemudian dijalankan ulang hingga selesai. Batas waktu controller produksi tidak diubah. Tes instalasi dari schema kosong dan dua koneksi worker juga dijalankan ulang secara khusus pada PostgreSQL/MySQL 8.0 setelah penyempurnaan fixture.

Hasil mentah lokal disimpan di `storage/framework/testing/db-audit/` sebagai log dan JUnit XML, di luar file yang di-commit. Tes JavaScript: 6 lulus; build produksi Vite lulus dengan output terpisah di direktori audit. `composer validate` lulus dengan peringatan metadata lisensi yang sudah ada. Audit dependency npm melaporkan 0 kerentanan; Composer melaporkan 0 advisory dan 0 paket abandoned pada tanggal audit.

## Pemeriksaan rilis berikutnya

Workflow `.github/workflows/databases.yml` menguji PostgreSQL 18, MySQL 8.0/8.4, dan MariaDB 11.8 pada pull request/push serta dapat dipanggil ulang. Workflow rilis menunggu seluruh matrix database berhasil sebelum publikasi. Definisi YAML sudah diperiksa; hasil GitHub Actions belum tersedia sebelum perubahan di-push dan workflow dijalankan.

## Batas kapasitas dan migrasi

- Tes 2.000 event adalah tes kebenaran pemrosesan batch, bukan benchmark EPS atau jaminan kapasitas jutaan log. Load test produksi harus memakai ukuran event, retensi, tingkat paralelisme, serta spesifikasi disk/CPU/RAM yang representatif.
- Pencarian substring pada JSON dan statistik seluruh tabel masih dapat mahal pada volume besar. Retensi, filter impor, pemantauan query, serta pengukuran indeks tetap diperlukan. Partisi tabel dan optimasi indeks lanjutan belum diterapkan dalam perubahan ini.
- Pertimbangkan Redis untuk queue/cache bersama setelah infrastrukturnya siap. Default database queue tetap bekerja dan telah diuji dengan dua koneksi worker.
- Ekspor Settings adalah ekspor data terpilih. Restore memerlukan schema cocok dan pengguna yang direferensikan. Untuk pemulihan penuh PostgreSQL, siapkan [backup native dan WAL/PITR](https://www.postgresql.org/docs/18/backup.html), serta backup `APP_KEY` dan file bukti.
- Pergantian MySQL/SQLite ke PostgreSQL pada instalasi berisi data memerlukan migrasi data dan verifikasi tersendiri. Perubahan default ini tidak menjalankan migrasi data operasional.
