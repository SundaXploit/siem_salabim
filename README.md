<a id="top"></a>

<div align="center">

<img src="public/images/brand/siem-salabim-logo.png" width="128" alt="Logo SIEM Salabim">

# SIEM Salabim

**Dashboard Wazuh dan OpenSearch untuk monitoring keamanan, investigasi, dan operasi SOC.**

[Dokumentasi lengkap](https://siem.sundaxploit.id) · [Instalasi](https://siem.sundaxploit.id/instalasi.html) · [Laporkan bug](https://github.com/SundaXploit/siem_salabim/issues)

![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square)
![Integrasi](https://img.shields.io/badge/Integrasi-Wazuh%20%2F%20OpenSearch-00A9E5?style=flat-square)

</div>

<a id="tentang"></a>

## Tentang

SIEM Salabim adalah aplikasi **self-hosted** berbasis Laravel untuk membantu analis SOC dan tim CSIRT mengelola alert dari Wazuh Indexer/OpenSearch. Tim dapat memeriksa evidence, membagi penanganan, mencatat investigasi, dan mengomunikasikan temuan dari satu dashboard.

<a id="fitur"></a>

## Fitur utama

- **Monitoring alert:** dashboard, severity, filter, tren, dan konteks MITRE dari evidence.
- **Triage dan investigasi:** ACK, ignore dengan alasan, pemilik penanganan, dan kronologi alert.
- **Analisis AI:** dukungan provider cloud maupun lokal sebagai bahan pendukung investigasi.
- **Telegram:** template, notifikasi tunggal/massal, serta routing berdasarkan agent.
- **Akses tim:** role, privilege, cakupan agent, dan pengelolaan akun oleh admin.
- **Audit dan data:** log aktivitas, ekspor, backup JSON/SQL, dan retensi.

<a id="galeri"></a>

## Preview

[![Dashboard SOC SIEM Salabim](docs/asset/admin-dashboard.png)](https://siem.sundaxploit.id/#preview)

[Lihat preview dan alur aplikasi →](https://siem.sundaxploit.id/#preview)

<a id="instalasi"></a>
<a id="instalasi-linux"></a>
<a id="linux-apache"></a>
<a id="linux-nginx"></a>
<a id="linux-worker"></a>
<a id="operasional"></a>
<a id="panduan"></a>

## Instalasi dan dokumentasi

**Panduan lengkap tersedia di [siem.sundaxploit.id](https://siem.sundaxploit.id).** Ikuti alur sesuai lingkungan agar runtime, izin file, database, dan proses latar belakang disiapkan dengan benar.

| Kebutuhan | Panduan |
| --- | --- |
| Komputer lokal | [Linux lokal](https://siem.sundaxploit.id/instalasi.html#linux-lokal) · [Windows / Laragon](https://siem.sundaxploit.id/instalasi.html#windows-lokal) |
| Server Linux | [Apache](https://siem.sundaxploit.id/instalasi.html#linux-apache) · [Nginx](https://siem.sundaxploit.id/instalasi.html#linux-nginx) |
| Hardening dan operasional | [HTTPS dan izin](https://siem.sundaxploit.id/instalasi.html#linux-hardening) · [Worker dan scheduler](https://siem.sundaxploit.id/instalasi.html#linux-worker) |
| Kendala instalasi | [File not found / permission](https://siem.sundaxploit.id/instalasi.html#linux-permissions) · [Token dan ownership runtime](https://siem.sundaxploit.id/instalasi.html#linux-installer-token) |
| Penggunaan dan perubahan | [Dokumentasi aplikasi](https://siem.sundaxploit.id/dokumentasi.html) · [Changelog](https://siem.sundaxploit.id/changelog.html) |

Persyaratan dasar: **PHP 8.3+**, **Composer 2**, **Node.js 22.12+**, npm, serta PostgreSQL/MySQL/MariaDB dengan database khusus aplikasi. Administrator pertama dibuat melalui wizard; tidak ada akun atau password bawaan. Worker dan scheduler diperlukan agar pekerjaan latar belakang berjalan.

<a id="keamanan"></a>

## Keamanan

Pertahankan `APP_KEY` dan simpan backup konfigurasi secara privat. Gunakan HTTPS untuk operasional serta akun runtime yang sesuai saat menjalankan perintah server. Rincian akses, enkripsi, backup, dan batas perlindungan tersedia pada [panduan keamanan](https://siem.sundaxploit.id/dokumentasi.html#keamanan).

<a id="dukungan"></a>

## Dukungan

Dikembangkan oleh [SundaXploit](https://github.com/SundaXploit) dan didukung oleh **[CSIRT – Diskominfo Subang](https://csirt.subang.go.id/profil)**.

<a id="kontribusi"></a>

## Kontribusi

Masukan, laporan bug, dan kontribusi kode/dokumentasi dapat diajukan melalui [Issues](https://github.com/SundaXploit/siem_salabim/issues) atau [Pull requests](https://github.com/SundaXploit/siem_salabim/pulls). Sertakan langkah reproduksi dan verifikasi yang relevan. Hindari menyertakan `.env`, token, password, atau data insiden sensitif pada laporan publik.

[Catatan teknis perubahan](docs/README.md) · [Kembali ke atas](#top)
