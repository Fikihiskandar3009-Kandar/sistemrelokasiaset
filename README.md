# Sistem Manajemen Relokasi Aset Indomaret (SRA Parung)

Aplikasi web PHP native + MySQL untuk mengelola **mutasi/relokasi aset** cabang
Indomaret Parung: permintaan relokasi, approval digital terpusat pada **Manager**
(MGR = penyetuju utama; SPV hanya wakil via delegasi admin + verifikasi password),
nomor Surat Jalan otomatis, deteksi anomali, serta notifikasi & approval real-time
via **Telegram Bot** (3 bot terpisah: admin / spv / mgr).

> 📖 **Cara membaca kode**: buka **[`PETA_KODE.md`](PETA_KODE.md)** — peta lengkap
> semua halaman, endpoint API, alur mutasi, alur Telegram, dan database.
> Struktur database saat ini: [`database/schema_terkini.sql`](database/schema_terkini.sql).

## Teknologi

- PHP 8.x (native, tanpa framework) + PDO MySQL
- MariaDB/MySQL 5.7+ (XAMPP)
- Telegram Bot API + tunnel ngrok (domain statis)
- Vanilla JS + CSS kustom (tanpa build step)

## Menjalankan

1. Letakkan folder ini di `htdocs` (XAMPP), nyalakan **Apache** + **MySQL**.
2. Salin `.env.example` → `.env`, sesuaikan koneksi DB & token bot.
3. Import `database/schema_terkini.sql` ke database `relokasi_aset_indomaret`.
4. Buka `http://localhost/sistem_relokasi_aset_indomaret/` → login dengan NIK.

## Role & Hak Akses

| Role | Akses |
|------|-------|
| **admin** | Penuh: CRUD aset, import, buat permintaan relokasi, kelola user & konfigurasi global |
| **spv** | Lihat aset, laporan, pengaturan pribadi. Approval HANYA jika diwakilkan admin (wakil MGR via delegasi + verifikasi password admin) |
| **mgr** | Lihat aset, approve/reject level MGR (persetujuan utama), laporan, pengaturan pribadi |

Detail lengkap: `app/rbac.php` (matriks permission) & `PETA_KODE.md`.

## Keamanan yang Terpasang

- Password SHA-256; session timeout idle (`SESSION_TIMEOUT`), `session_regenerate_id()` saat login
- RBAC server-side di **setiap** halaman & endpoint (`auth_require()` + cek role)
- Hapus aset = verifikasi 2 langkah NIK+password (maks 5x salah → kunci 5 menit)
- Webhook Telegram dilindungi `TELEGRAM_WEBHOOK_SECRET`
- SQL query memakai prepared statements (PDO); output di-escape (`htmlspecialchars`)

## Struktur Folder

```
app/            inti logika (loader, db, auth, rbac, telegram, dsb)
api/            endpoint AJAX + webhook Telegram
css/  js/       tampilan & interaksi (semua terpakai)
database/       schema_terkini.sql + riwayat patch (README di dalamnya)
storage/        logs harian, file import, integration_state.json
tak_terpakai/   arsip file lama yang tidak dipakai aplikasi
```

## Pemeliharaan

- Log harian: `storage/logs/YYYY-MM-DD.log`, error PHP: `storage/logs/php-error.log`
- Status webhook: panel **Pengaturan → Integrasi**, atau `getWebhookInfo` Telegram
- Backup: dump database (phpMyAdmin) — struktur acuan = `database/schema_terkini.sql`
