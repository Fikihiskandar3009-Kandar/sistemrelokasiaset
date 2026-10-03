# 🗺️ PETA KODE — Sistem Manajemen Relokasi Aset Indomaret

> Dokumen induk untuk membaca & mencari kode. Update: 03-10-2026
> (`[ALUR APPROVAL MGR 03-10-2026]` — approval kini TERPUSAT di MANAGER (MGR) untuk SEMUA
> permintaan: `app/rules.php::resolve_approval_level()` selalu mengembalikan MGR. SPV hanya
> dapat approve bila DIWAKILKAN admin — kirim ulang notif ke SPV + verifikasi password admin
> (`api/resend_notification.php`, kolom `delegated_spv` di tabel `relocations`).
> README.md & Alur Mutasi (§6) ikut disesuaikan.
> Update sebelumnya: 27-09-2026 malam
> (`[RESTORASI KOLOM PENYUSUTAN 27-09-2026]` di `assets.php` & `api/asset_update_bulk.php` —
> kolom **Masa Manfaat, Beban Penyusutan/Bln, Umur Jalan** sempat terhapus saat optimasi
> sore harinya; kini tampil kembali di tabel + bisa disort + ada di modal edit satuan
> (beban & akumulasi readonly, auto-calc rumus 26-09-2026) + bisa diedit massal dengan
> beban & akumulasi dihitung ulang otomatis per baris dari biaya masing-masing aset).
> Malam harinya juga: `[SCROLL HORIZONTAL TABEL ASET 27-09-2026]` di `assets.php` —
> tabel 14 kolom kini dibungkus `.table-scroll` (Shift+Scroll di PC / swipe di HP)
> dan tidak dipaksa `width:100%` lagi sehingga teks tidak dempet; responsif tetap
> prioritas (padding ringkas khusus mobile via media query di blok `<style>` halaman).
> Susulan: `[LABEL KOLOM 2 BARIS 27-09-2026]` — header Masa Manfaat/Beban Penyusutan/
> Umur Jalan/Akumulasi Penyusutan kini dua baris (satuan `(bln)` pindah ke baris kedua).
> Susulan berikutnya: `[HAPUS ASET VERIFIKASI+NOTIF 27-09-2026]` — tombol Hapus Data Aset
> dulu langsung AJAX TANPA NIK/password → server menolak (auth_verify_delete_credentials,
> HTTP 400/403/429) → jQuery `dataType:'json'` gagal parse → popup error "Unexpected ...".
> Kini hapus satuan & massal WAJIB lewat **modal verifikasi** (🔒 NIK + password admin,
> responsif: tombol menumpuk full-width di layar HP) → setelah sukses, `api/asset_delete.php`
> & `api/asset_delete_bulk.php` mengirim **notif Telegram ke SEMUA user** (role admin/spv/mgr
> via `tg_notify_roles`) berisi detail aset terhapus + identitas admin pelaku; bulk API juga
> kini menerima fallback `$_POST`.
> Susulan: `[EMOJI PROFESIONAL 27-09-2026]` — pembersihan emoji di SEMUA halaman & notif
> Telegram: emoji dekoratif AI-style dihapus (💾 Simpan, 🔒 judul modal, ⚠️ banner, 🗑️ tombol/list
> hapus, 🖨 Unduh PDF, ⏳ loading, ⏮◀▶⏭ pagination → tipografi `« ‹ › »`, serta 👤🆔🏢🏪📦🔢📝💰📊🗓️📄
> di pesan Telegram kini label teks polos). Ikon fungsional monochrome DIPERTAHANKAN
> (▲▼ sort, ▶ expand, ▼ menu, ◫◈◎◇◍ sidebar, ◉◈▣ kartu settings, ☰ toggle, ←→ navigasi, • bullet).
>
> **KETENTUAN WAJIB (3, selalu berlaku di setiap perubahan):**
> 1. **Responsif** dijaga di setiap perubahan, sekecil apa pun.
> 2. **Auto-save**: edit tersimpan instan ke disk + marker ber-tanggal di `PETA_KODE.md`.
> 3. **Tampilan profesional**: tanpa emoji AI-style; semua halaman dicek (ikon fungsional
>    monochrome boleh).
> Sore harinya: `[FIX OUTPUT KORUP
> 27-09-2026]` — baris 1 `app/telegram.php` tertempel teks nyasar sebelum
> `<?php` sehingga SETIAP halaman/API meng-echo sampah sebelum DOCTYPE/JSON
> (semua aksi AJAX terlihat gagal, layout & logo kacau karena quirks mode).
> Sebelumnya: 24-09-2026 perapian skripsi).
> Database: **satu saja** → `relokasi_aset_indomaret` (lihat `database/README.md`).

## 1. Struktur Folder

```
sistem_relokasi_aset_indomaret/
├── index.php                 → gerbang: redirect ke login
├── login.php / logout.php    → autentikasi (NIK + password)
├── forgot-password*.php      → reset password via token
├── dashboard.php             → dashboard semua role
├── assets.php                → data aset (CRUD, filter, bulk)
├── asset_form.php            → tambah/edit aset (admin)
├── asset_import.php          → import Excel/CSV/TXT (admin)
├── requests.php              → permintaan relokasi (approve/reject)
├── request_create.php        → buat permintaan (admin)
├── reports.php               → laporan + export
├── settings.php              → pengaturan + panel Telegram/Ngrok
├── profile.php               → profil user
├── setup_telegram_webhook.ps1→ skrip opsional daftar webhook via PowerShell
│
├── app/    → inti logika (loader, DB, auth, RBAC, Telegram, dsb)
├── api/    → endpoint AJAX + webhook Telegram
├── css/    → gaya tampilan (semua terpakai)
├── js/     → interaksi halaman (semua terpakai)
├── images/ → logo
├── database/→ skema SQL + riwayat patch (lihat README-nya)
├── storage/→ logs/, data/ (file import), integration_state.json
└── tak_terpakai/ → arsip file lama (TIDAK dipakai aplikasi)
```

## 2. Alur Aplikasi (ringkas)

```
login.php ──(NIK+password SHA-256, tabel users JOIN roles)──► session
   │  app/auth.php: auth_require() menjaga SETIAP halaman & API
   │  app/rbac.php: matriks permission 3 role (admin / spv / mgr)
   ▼
Dashboard / Kelola Aset / Permintaan / Laporan / Pengaturan
   │  aksi tulis selalu lewat api/*.php (fetch AJAX)
   │  notifikasi: web (web_notifications) + Telegram (3 bot per role)
   ▼
logout.php ──► session dihancurkan, last_logout dicatat → login.php
```

## 3. Halaman Web (root)

| File | Fungsi | Akses |
|------|--------|-------|
| `index.php` | Redirect ke login | publik |
| `login.php` | Form login NIK+password | publik |
| `logout.php` | Akhiri session | user login |
| `forgot-password.php` | Minta reset (token → `password_reset`) | publik |
| `forgot-password-reset.php` | Password baru via token | publik+token |
| `dashboard.php` | Statistik + permintaan terbaru + sort | semua role |
| `assets.php` | Daftar aset `assets_real` (13 kolom data incl. masa manfaat, beban penyusutan/bln, umur jalan — `[RESTORASI KOLOM PENYUSUTAN 27-09-2026]`): search, filter, pagination, bulk (recalc penyusutan per baris) | semua role lihat |
| `asset_form.php` | Tambah/edit 1 aset + autocomplete | admin |
| `asset_import.php` | Import massal (merge cerdas) | admin |
| `requests.php` | Daftar mutasi: admin=semua, MGR=level MGR (penyetuju utama), SPV=hanya relokasi yang diwakilkan (`delegated_spv`) | admin, spv, mgr |
| `request_create.php` | Buat permintaan tunggal/bulk + no. SJ otomatis | admin |
| `reports.php` | Laporan periode + export | admin, spv, mgr |
| `settings.php` | Profil/password + panel integrasi (admin utk konfigurasi global) | admin, spv, mgr |
| `profile.php` | Profil & ganti password | semua role |

## 4. Modul `app/` (inti, di-load oleh `bootstrap.php`)

| File | Fungsi |
|------|--------|
| `bootstrap.php` | Loader utama + error reporting + timezone. Di-require semua halaman |
| `db.php` | `env()` baca `.env`, `db()` koneksi PDO tunggal |
| `helpers.php` | `json_out()`, `log_activity()` → storage/logs, `generate_sj_number()` |
| `auth.php` | Session/timeout, login/logout, verifikasi hapus 2 langkah |

## 5. Endpoint `api/`

| File | Fungsi | Akses |
|------|--------|-------|
| `assets_list.php` | Daftar semua aset + daftar lokasi (JSON) | login |
| `asset_autocomplete.php` | Saran isian form (cabang/toko/kategori/keterangan) | login |
| `asset_get.php` | Detail 1 aset | login |
| `asset_create.php` | Tambah aset — beban & akumulasi penyusutan otomatis dari rumus | login (UI admin) |
| `asset_update.php` | Update aset — beban & akumulasi penyusutan otomatis dari rumus | login (UI admin) |
| `asset_update_bulk.php` | Update massal | admin |
| `asset_delete.php` | Hapus aset — **verifikasi NIK+password** + notif Telegram ke semua user `[HAPUS ASET VERIFIKASI+NOTIF 27-09-2026]` | admin |
| `asset_delete_bulk.php` | Hapus massal — **verifikasi NIK+password** + notif Telegram semua user `[HAPUS ASET VERIFIKASI+NOTIF 27-09-2026]` | admin |
| `asset_import_preview.php` | Parse file upload → preview | login |
| `asset_import_save.php` | Simpan hasil import (merge cerdas + rumus penyusutan otomatis) | login |
| `asset_import_template.php` | Unduh template import (csv/xls/txt) | login |
| `request_store.php` | Simpan permintaan tunggal + notif | admin |
| `request_store_bulk.php` | Simpan permintaan bulk (1 SJ = 1 ID, banyak item) | admin |
| `generate_sj.php` | Nomor surat jalan otomatis | login |
| `approve_manual.php` | Approve/reject dengan kode approval — **utama: MGR**; SPV hanya jika `delegated_spv=1` (wakil) | spv, mgr |
| `franchise_token_generate.php` | Token approval toko franchise (30 menit, 1x pakai) | mgr; spv saat diwakilkan |
| `franchise_token_resend.php` | Kirim ulang token | mgr; spv saat diwakilkan |
| `franchise_token_share.php` | Bagikan token ke admin (web+Telegram) | mgr; spv saat diwakilkan |
| `franchise_token_validate.php` | Validasi token → langsung approve | admin |
| `resend_notification.php` | Kirim ulang notif ke MGR (atau SPV=wakil + password admin) | admin |
| `telegram_connect.php` | Pairing/ping/disconnect chat Telegram user | login |
| `telegram_setup.php` | Daftarkan slash-command ke 3 bot | admin |
| `telegram_webhook.php` | **Penerima update Telegram** (dilindungi secret) | publik+secret |
| `integration.php` | Status/konfigurasi/ON-OFF ngrok + webhook | login (ubah: admin) |
| `dashboard_fingerprint.php` | Sidik jari ringan data dashboard — dipakai auto-refresh cerdas (reload hanya saat data berubah) | login |
| `check_notifications.php` | Notifikasi web user | login |
| `mark_notification_read.php` | Tandai dibaca (satu/semua) | login |

## 6. Alur Mutasi (Permintaan → Approval → Aset pindah)

1. **Admin** buat permintaan di `request_create.php` → `api/request_store*.php`
   → insert `relocations` (+`relocation_items` untuk bulk) + `approvals_log`(REQUEST)
   → nomor SJ otomatis `helpers.php::generate_sj_number()` (RA/SA/PS/PA/PP)
2. **Notifikasi** ke atasan: web (`web_notifications`) + **Telegram bot sesuai role**
   (admin bot / spv bot / mgr bot — token per role di `.env`).
3. **Manager menyetujui** (penyetuju UTAMA untuk semua permintaan) lewat: tombol
   inline Telegram / pesan `APP <id> <kode>` / web `requests.php`
   (`api/approve_manual.php`) / token franchise.
   **MGR sibuk/tidak bisa proses?** Admin mewakilkan ke SPV: `api/resend_notification.php`
   target `spv` (wajib verifikasi password admin) → `delegated_spv=1`
   → SPV approve/reject sebagai WAKIL MGR.
4. **Setelah APPROVED**: `approved_by` tercatat, lokasi aset diperbarui
   (`assets` + `assets_real`), log APPROVE dicatat.
5. **Anomali** diperiksa tiap permintaan (`rules.php::check_anomalies`):
   tanpa SJ (`NO_SJ`), frekuensi mutasi berlebih (`FREQ_OVER`), duplikat lokasi (`DUP_LOC`)
   → tersimpan di `anomaly_alerts` + alert ke admin.

## 7. Integrasi Telegram & Ngrok

- **3 bot terpisah**: `TELEGRAM_BOT_TOKEN_ADMIN / _SPV / _MGR` di `.env`.
- **Webhook per bot**: `https://<domain-ngrok>/.../api/telegram_webhook.php?secret=...&bot=<role>`
- **Panel integrasi** (`settings.php` → `js/integration-panel.js` → `api/integration.php`):
  - Konfigurasi (domain, authtoken, email, path ngrok) **persisten di `.env`** — tetap ada walau ngrok OFF.
  - Status runtime ON/OFF: `storage/integration_state.json`.
  - ON = buka tunnel `ngrok.exe` + daftar webhook semua bot; OFF = lepas webhook + matikan ngrok.
- **User terhubung** ke bot via `/start` → `telegram_chat_id` tersimpan di tabel `users`.
- Cek cepat webhook terdaftar: `https://api.telegram.org/bot<TOKEN>/getWebhookInfo`.

## 8. Database (satu database: `relokasi_aset_indomaret`)

12 tabel — detail & cara restore: lihat **`database/README.md`**.
Struktur terkini (identik phpMyAdmin): `database/schema_terkini.sql`.

| Inti | Pendukung |
|------|-----------|
| `users`, `roles`, `locations` | `web_notifications`, `password_reset` |
| `assets`, `assets_real` (data utama) | `approvals_log`, `anomaly_alerts` |
| `relocations`, `relocation_items` | `franchise_tokens` |

> Database lain di phpMyAdmin (agen_sales, db_toko, inventory, dst.) milik project
> lain di localhost yang sama — aplikasi ini sama sekali tidak memakainya.

## 9. Konfigurasi `.env` (penting untuk sidang)

| Key | Fungsi |
|-----|--------|
| `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` | Koneksi database (satu DB) |
| `APP_URL`, `APP_DEBUG` | URL publik (ngrok) & mode debug |
| `SESSION_TIMEOUT` | Auto-logout idle (detik; 1800 = 30 menit) |
| `TELEGRAM_BOT_TOKEN_*` | Token 3 bot (admin/spv/mgr) |
| `TELEGRAM_WEBHOOK_SECRET` | Kunci webhook (wajib cocok dgn URL terdaftar) |
| `APPROVAL_LIMIT_SPV/MGR`, `ANOMALY_FREQ_LIMIT` | Aturan approval & anomali |
| `NGROK_*` | Konfigurasi tunnel (persisten walau OFF) |

## 10. Folder `tak_terpakai/`

Arsip file yang **tidak dipakai** aplikasi (skrip migrasi
lama, dokumentasi versi lama, legacy Laravel, dll). Rincian: `tak_terpakai/README.md`.
Aman dihapus; disimpan sebagai jejak pengembangan.

| `rbac.php` | Matriks permission 3 role + label + menu |
| `rules.php` | Level approval (MGR; SPV=wakil delegasi) + `check_anomalies()` + kolom delegasi |
| `telegram.php` | `tg_token_for_role()` 3 token bot, `tg_send()` + dedup pesan |
| `integration.php` | Konfigurasi ngrok/webhook (`.env` + `storage/integration_state.json`) |
| `anomaly.php` | Simpan alert anomali ke `anomaly_alerts` |
| `notifications.php` | Insert/list notifikasi web `web_notifications` |
| `header-sidebar.php` | Komponen header + sidebar tiap halaman |
| `loading-helper.php` | `asset_css()/asset_js()` cache-busting + animasi loading |
| `cache_helper.php` | `clearAssetFiltersCache()` — dipanggil API saat data aset berubah |
