# Product Requirement Document (PRD)
# Sistem Tiketing Internal "Halo APU"

| | |
|---|---|
| **Nama Produk** | Halo APU |
| **Jenis Dokumen** | Product Requirement Document — v2 (Final Stack) |
| **Versi** | 2.0 |
| **Tanggal** | Juli 2026 |
| **Tech Stack** | Laravel (Full-stack Framework) + Inertia.js + React (Frontend) + MySQL bawaan cPanel (Database) + cPanel Shared Hosting + Custom Domain |

---

## 0. Changelog dari PRD v1

| Area | v1 | v2 (dokumen ini) |
|---|---|---|
| Backend/API | Google Apps Script (Web App) | Laravel (Controller + Eloquent), tanpa API terpisah |
| Frontend | React murni (fetch ke Apps Script) | React tetap, disajikan lewat Inertia (server-driven, satu origin) |
| Database | Google Sheet | MySQL bawaan cPanel |
| Lampiran | Google Drive | Laravel Storage (disk lokal cPanel) — Google Drive tetap bisa jadi opsi backup |
| Modul SLA | 1 ambang waktu global (15/30/60 menit) | **Didesain ulang**: SLA per Layanan, 2 jenis (Respon & Penyelesaian), jam kerja, jeda saat Pending, dashboard kepatuhan — lihat Bab 6 |
| Modul Reminder | Hanya reminder H-1 booking + suara tiket baru | **Didesain ulang**: 4 jenis reminder, multi-channel, Notification Center — lihat Bab 7 |

> **Catatan asumsi**: Bab 6 dan 7 berisi sejumlah keputusan desain (jam kerja, jeda SLA saat Pending, 4 jenis reminder, dsb.) yang belum secara eksplisit diminta di requirement awal, tapi ditambahkan supaya modul ini benar-benar dipakai sehari-hari, bukan sekadar alarm yang diabaikan. Silakan koreksi bila ada yang tidak sesuai kebutuhan.

---

## 1. Latar Belakang & Tujuan

Karyawan mengajukan permohonan layanan ke unit **GA**, **IT**, dan **Humas** (dapat ditambah unit lain ke depannya). Dibutuhkan satu platform terstruktur untuk pengajuan, form dinamis per layanan, lampiran, SLA, monitoring, dan CSAT.

**Tujuan:**
1. Satu pintu pengajuan untuk semua unit layanan internal.
2. Form pengajuan yang menyesuaikan otomatis dengan jenis layanan (sub unit).
3. SLA yang benar-benar mencerminkan beban kerja tiap layanan, bukan patokan tunggal yang sama untuk semua.
4. Reminder yang sampai ke admin lewat kanal yang tepat, bukan cuma bunyi sesaat yang mudah terlewat.
5. Visibilitas pemakaian ruang & kendaraan secara real-time.
6. Data CSAT yang representatif untuk evaluasi kualitas layanan tiap unit.

---

## 2. Arsitektur Sistem

Laravel + Inertia disebut pola **"modern monolith"**: tidak ada REST API terpisah antara frontend dan backend. Controller Laravel langsung mengirim data + komponen React yang harus dirender lewat `Inertia::render()`, satu origin, tanpa CORS.

```
┌───────────────────────────────────────────────────────────────┐
│                 Laravel Application (cPanel + Domain)          │
│                                                                 │
│   ┌────────────┐   Inertia::render()    ┌──────────────────┐   │
│   │ Controller │ ──────────────────────▶│  React Pages     │   │
│   │ + Eloquent │ ◀────────────────────  │  (Inertia Client)│   │
│   └─────┬──────┘  visit / usePoll()     └──────────────────┘   │
│         │                                                       │
│   ┌─────▼───────┐   ┌────────────────┐   ┌───────────────────┐ │
│   │   MySQL     │   │ Laravel Queue  │   │ Laravel Scheduler │ │
│   │  (cPanel)   │   │ (driver: db)   │   │ (cron tiap menit) │ │
│   └─────────────┘   └───────┬────────┘   └─────────┬─────────┘ │
│                              │                       │           │
│                     ┌────────▼───────────────────────▼────────┐ │
│                     │  Laravel Notification (Notifiable)      │ │
│                     │  channel: database | mail | custom-WA   │ │
│                     └──────────────────────────────────────────┘│
└───────────────────────────────────────────────────────────────┘
```

**Hal yang wajib dicek/disiapkan di cPanel sebelum development:**

| Item | Catatan |
|---|---|
| Versi PHP | Minimal PHP 8.2 (Laravel 12) atau PHP 8.3+ (Laravel 13, direkomendasikan PHP 8.4). Cek/atur lewat **MultiPHP Manager** |
| Akses SSH & Composer | Jika ada SSH → `composer install` langsung di server. Jika tidak → build & `composer install` di lokal, upload folder `vendor/` via File Manager/FTP |
| Document root | Harus mengarah ke folder `/public` milik Laravel, bukan root project — atur di pengaturan domain/subdomain cPanel, atau symlink jika tidak bisa diubah |
| Cron Job | Wajib untuk Laravel Scheduler (`php artisan schedule:run` tiap menit) — dipakai untuk seluruh cek SLA & Reminder di Bab 6 & 7 |
| Queue Driver | Gunakan driver `database` (bukan `sync`) supaya notifikasi tidak memperlambat request user, dieksekusi via cron (`queue:work --stop-when-empty`) tiap menit berbarengan dengan scheduler |

---

## 3. Role Pengguna

| Role | Akses |
|---|---|
| **User (Karyawan)** | Login, ajukan tiket, riwayat tiket & respon admin, isi CSAT, live monitor ruang/kendaraan |
| **Admin** | Akses penuh seluruh modul termasuk Master Data |
| **Operator** | Sama seperti Admin, **kecuali Master Data** (dikontrol via package `spatie/laravel-permission`) |

---

## 4. Modul & Fitur — Sisi User

### 4.1 Autentikasi
Login (username/email + password, toggle lihat password), lupa password, copyright di card login. Sign Up tetap ditampilkan tapi mengarahkan ke pesan "Hubungi Admin" — akun dibuatkan admin, bukan self-registrasi. Dibangun di atas starter kit resmi Laravel untuk React + Inertia.

### 4.2 Dashboard User
Ringkasan status ruang/kendaraan yang sedang dipakai (live monitor), ringkasan status tiket milik user, shortcut "Ajukan Tiket Baru".

### 4.3 Pengajuan Tiket (5 Step Wizard)
| Step | Nama | Detail |
|---|---|---|
| 1 | Data Pengaju | Divisi, Unit (organisasi), Jabatan (termasuk "Staff") |
| 2 | Pilih Unit & Layanan | Unit tujuan (GA/IT/Humas) → Sub Unit/Layanan |
| 3 | Form Dinamis | Sesuai konfigurasi admin di Peraturan Form, termasuk form cabang |
| 4 | Lampiran | Upload ke storage, ada yang wajib & opsional per field |
| 5 | Review & Submit | Ringkasan sebelum kirim final |

### 4.4 Riwayat Tiket
List tiket + status (Open/On Proses/Pending/Solve/Reject), detail form, lampiran, dan riwayat respon admin (timeline).

### 4.5 Riwayat CSAT
Rating CSAT yang pernah diberikan per tiket.

### 4.6 Live Monitor
Status pemakaian ruang & kendaraan real-time (di-refresh berkala via `usePoll()` dari Inertia, tanpa reload halaman).

---

## 5. Modul & Fitur — Sisi Admin

### 5.1 Login Admin
Terpisah dari login user.

### 5.2 Dashboard Admin
Statistik status tiket (Open/On Proses/Pending/Solve/Reject), grafik bulanan & tahunan per Unit dan per Sub Unit, ranking Top User pengaju tiket terbanyak, **plus kartu Kepatuhan SLA** (lihat 6.6).

### 5.3 Modul Tiketing
- **List tiket**: seluruh tiket dari semua user, dengan filter lengkap (Unit, Sub Unit, status, rentang tanggal, divisi/unit pengaju) dan tombol **"Terapkan Filter"**, plus badge warna status SLA per tiket (lihat 6.5).
- **Tombol Detail**: per tiket, membuka halaman detail pengajuan secara lengkap — seluruh isian form dinamis yang diisi user (sesuai Peraturan Form pada layanan terkait), data pengaju (divisi/unit/jabatan), dan lampiran yang diupload. Dari halaman detail ini juga admin melakukan aksi eksekusi tiket: Open → On Proses/Reject/Pending; On Proses → Solve/Pending.

### 5.4 Master Data *(khusus Admin)*
CRUD Unit, CRUD Sub Unit/Layanan, CRUD Divisi/Unit/Jabatan (organisasi pengaju).

### 5.5 Peraturan Form (Form Builder)
CRUD field per Sub Unit dengan filter, 12 tipe field (Teks Pendek, Teks Panjang, Angka, Tanggal, Waktu, Dropdown, Radio, Checkbox, Multi-Pilih, Upload Gambar/File, Nominal Rp, Info/Peraturan Baca Saja), form cabang (conditional field), serta pengaturan wajib/opsional per field.

### 5.6 Manajemen User & Manajemen Admin
CRUD akun user (username, email, password, no. WhatsApp) dan akun admin dengan role Admin/Operator.

### 5.7 Modul CSAT (Admin)
Melihat seluruh rating CSAT per tiket, per unit, per periode.

### 5.8 Modul Konfigurasi
Logo, banner, nama sistem (default "Halo APU"), email admin, API key & URL gateway WhatsApp, nomor WA notifikasi utama **dan nomor WA fallback/supervisor** (dipakai eskalasi Tier 3 di Bab 6), serta **jam kerja operasional** (dipakai perhitungan SLA di Bab 6).

---

## 6. Modul SLA (Didesain Ulang)

Tujuan desain ulang: SLA bukan sekadar 1 angka waktu yang sama untuk semua tiket, tapi mencerminkan beban kerja nyata tiap layanan, adil untuk admin, dan datanya benar-benar dipakai untuk evaluasi — bukan cuma alarm yang diabaikan.

### 6.1 SLA per Layanan (Sub Unit), Bukan Global Tunggal
Admin mengatur ambang waktu SLA **per Sub Unit/Layanan** (misal "Reset Password IT" vs "Pengadaan Barang GA" wajar punya SLA berbeda), dengan opsi nilai default global yang bisa dioverride per layanan. Tombolnya tetap **Edit** seperti requirement awal — tidak dipatok (hardcode).

### 6.2 Dua Jenis SLA per Tiket
| Jenis | Definisi |
|---|---|
| **SLA Respon** | Waktu maksimal dari tiket dibuat sampai admin pertama kali merespon (status berubah dari Open) |
| **SLA Penyelesaian** | Waktu maksimal dari tiket dibuat sampai status menjadi Solve |

### 6.3 Tiga Tier Eskalasi dengan Aksi Konkret
Ambang waktu tiap tier tetap dapat diedit admin (default mengikuti requirement awal: 15/30/60 menit), tapi tiap tier sekarang punya **aksi**, bukan cuma badge:

| Tier | Default | Aksi |
|---|---|---|
| 1 | 15 menit belum direspon | Notifikasi in-app + (opsional) email ke Admin/Operator penanggung jawab Unit terkait |
| 2 | 30 menit belum dieksekusi | Badge tiket berubah warna, tiket naik ke urutan atas (priority sort) di list tiket, notifikasi diulang |
| 3 | 60 menit belum dieksekusi | Badge merah "SLA Breach", eskalasi ke nomor WA fallback/supervisor (bukan cuma admin biasa) |

### 6.4 Jam Kerja & Jeda Saat Pending
- **Jam kerja operasional** (misal Senin–Jumat 08:00–17:00, diatur di Modul Konfigurasi) dipakai untuk menghitung deadline SLA — tiket yang masuk di luar jam kerja tidak langsung dianggap telat begitu jam kerja mulai lagi.
- **SLA berhenti berjalan (paused)** selama tiket berstatus **Pending** (misal menunggu info tambahan dari user), dan lanjut lagi saat kembali ke On Proses. Ini supaya SLA adil — bukan salah admin kalau sedang menunggu user.

Ilustrasi struktur data untuk mendukung logika ini:
```sql
CREATE TABLE ticket_sla_tracking (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sla_response_deadline DATETIME NOT NULL,
    sla_resolution_deadline DATETIME NOT NULL,
    responded_at DATETIME NULL,
    resolved_at DATETIME NULL,
    paused_at DATETIME NULL,
    total_paused_minutes INT UNSIGNED DEFAULT 0,
    current_tier TINYINT UNSIGNED DEFAULT 0,
    is_response_breached BOOLEAN DEFAULT FALSE,
    is_resolution_breached BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id)
);
```

### 6.5 Indikator Visual di List Tiket
Badge warna per tiket di Modul Tiketing: **hijau** (aman) → **kuning** (mendekati Tier 1) → **oranye** (Tier 2) → **merah** (Tier 3/breach). Sekali lihat, admin tahu tiket mana yang perlu diprioritaskan duluan.

### 6.6 Dashboard Kepatuhan SLA
Kartu/grafik baru di Dashboard Admin: persentase tiket selesai **dalam SLA** vs **breach**, per Unit, per Sub Unit, per periode (bulanan/tahunan). Inilah yang membuat data SLA benar-benar "kepakai" — jadi bahan evaluasi kinerja tiap unit, bukan sekadar warning yang lewat begitu saja.

---

## 7. Modul Reminder & Notifikasi (Didesain Ulang)

### 7.1 Empat Jenis Reminder
| Jenis | Trigger | Default lead time |
|---|---|---|
| **Reminder Booking** | Sebelum jadwal pakai ruang/kendaraan | H-1 (dapat diubah ke H-2/H-3 dst oleh admin) |
| **Reminder SLA** | Tiket masuk Tier 1/2/3 (terhubung ke Bab 6.3) | Real-time saat tier berubah |
| **Reminder Tiket Pending Lama** | Tiket berstatus Pending tanpa update > X hari | Default 2 hari (dapat diubah admin) |
| **Reminder CSAT Belum Diisi** | Tiket sudah Solve tapi user belum kasih rating > X hari | Default 3 hari (dapat diubah admin) |

### 7.2 Multi-Channel per Jenis Reminder
Tiap jenis reminder dapat dikonfigurasi kanal pengirimannya secara independen: **in-app**, **email**, **WhatsApp**, dan/atau **suara** (khusus reminder real-time). Dibangun di atas fitur **Laravel Notification** (`Notifiable` trait) dengan kombinasi channel `database` (untuk in-app), `mail` (email), dan custom channel untuk WhatsApp Gateway (mengirim lewat HTTP client Laravel ke API key/URL WA yang diatur di Modul Konfigurasi).

### 7.3 Notification Center (Ikon Lonceng)
Riwayat seluruh notifikasi/reminder yang pernah masuk untuk tiap admin (dibaca maupun belum), dengan badge counter unread — supaya reminder tidak cuma lewat sebagai bunyi sesaat lalu terlupakan begitu admin sedang tidak di depan layar.

### 7.4 Snooze & Tandai Selesai
Admin dapat **snooze** reminder ("ingatkan lagi 1 jam lagi") atau **mark as done**, supaya reminder yang sama tidak terus-menerus muncul untuk hal yang sudah ditindaklanjuti.

### 7.5 Notifikasi Suara ("Ting-Ting-Ting")
Tetap ada, tapi dibatasi hanya untuk **2 event real-time**: tiket baru masuk & SLA breach (Tier 3) — supaya admin tidak jadi mengabaikan semua notifikasi karena terlalu sering berbunyi untuk hal-hal yang kurang mendesak.

### 7.6 Implementasi Teknis
- **Laravel Scheduler** (cron tiap menit) menjalankan pengecekan berkala: booking H-X, status tier SLA tiap tiket aktif, tiket Pending lama, CSAT yang belum diisi.
- Hasil pengecekan → dibuat sebagai **Notification** record (tabel `notifications`), lalu dikirim ke channel yang aktif via **Queue** (driver `database`) supaya tidak membebani request lain.
- Frontend (React + Inertia) menampilkan badge counter & memicu suara memakai **`usePoll()`** dari Inertia v2 — polling ringan berkala ke jumlah notifikasi unread & tiket baru, tanpa perlu infrastruktur WebSocket.

---

## 8. Struktur Data (MySQL)

| Tabel | Kolom Utama |
|---|---|
| `users` | id, username, email, password, no_wa, divisi_id, org_unit_id, jabatan_id, created_at |
| `admins` | id, username, email, password, role (admin/operator via spatie/laravel-permission), created_at |
| `units` | id, nama_unit, deskripsi, aktif |
| `sub_units` | id, unit_id (FK), nama_layanan, deskripsi, aktif |
| `org_divisi` | id, nama_divisi |
| `org_unit` | id, nama_unit_organisasi, divisi_id (FK) |
| `org_jabatan` | id, nama_jabatan |
| `form_fields` | id, sub_unit_id (FK), label, tipe_field, wajib, opsi (JSON), parent_field_id, trigger_value, urutan |
| `tickets` | id, user_id (FK), divisi_id, org_unit_id, jabatan_id, unit_id, sub_unit_id, form_data (JSON), status, created_at, updated_at |
| `ticket_attachments` | id, ticket_id (FK), field_id, file_path, wajib |
| `ticket_logs` | id, ticket_id (FK), admin_id (FK), aksi, catatan, timestamp |
| `ticket_sla_tracking` | *(lihat 6.4)* |
| `sla_configs` | id, sub_unit_id (FK, nullable=default global), tier, jenis (respon/penyelesaian), threshold_minutes |
| `reminder_configs` | id, jenis_reminder, lead_time_value, channel_aktif (JSON), aktif |
| `notifications` | id (uuid), type, notifiable_type, notifiable_id, data (JSON), channel, read_at, created_at |
| `csats` | id, ticket_id (FK), user_id (FK), rating, komentar, created_at |
| `room_vehicle_bookings` | id, ticket_id (FK), tipe (ruang/kendaraan), nama_aset, tanggal_mulai, tanggal_selesai, status |
| `system_configs` | key, value (logo, banner, nama_sistem, email_notif, wa_api_key, wa_api_url, wa_number, wa_number_fallback, jam_kerja) |

---

## 9. Routing (Laravel + Inertia)

Tidak ada REST API terpisah — setiap route memanggil Controller yang mengembalikan `Inertia::render()` (untuk halaman) atau redirect back (untuk aksi tulis). Contoh peta rute utama:

| Route | Controller | Keterangan |
|---|---|---|
| `GET /login`, `POST /login` | `AuthController` | Login user/admin |
| `GET /dashboard` | `DashboardController` | Dashboard user |
| `GET /tiket/buat`, `POST /tiket` | `TicketWizardController` | Wizard 5 step pengajuan |
| `GET /tiket/riwayat` | `TicketHistoryController` | Riwayat tiket user |
| `POST /csat/{ticket}` | `CsatController` | Submit rating |
| `GET /admin/dashboard` | `Admin\DashboardController` | Dashboard admin + grafik + kepatuhan SLA |
| `GET /admin/tiket`, `PATCH /admin/tiket/{id}/status` | `Admin\TicketController` | List, filter, eksekusi status tiket |
| `GET|POST|PUT|DELETE /admin/master-data/unit` | `Admin\UnitController` | CRUD Unit |
| `GET|POST|PUT|DELETE /admin/peraturan-form/{subUnit}` | `Admin\FormFieldController` | CRUD field + form cabang |
| `GET|PUT /admin/sla-config` | `Admin\SlaConfigController` | Edit ambang waktu SLA per layanan |
| `GET|PUT /admin/reminder-config` | `Admin\ReminderConfigController` | Edit jenis & channel reminder |
| `GET /admin/notifications` (polling) | `Admin\NotificationController` | Notification Center + badge counter |

---

## 10. Non-Functional Requirements

- **Keamanan**: password via `Hash` facade (bcrypt/argon2), CSRF protection bawaan Laravel, role-based access via `spatie/laravel-permission`.
- **Performansi**: cache data master yang jarang berubah (Unit, Sub Unit, Form Fields) dengan Laravel Cache; polling `usePoll()` dibatasi interval wajar (misal 10–15 detik) untuk mengurangi beban server shared hosting.
- **Reliabilitas Notifikasi**: gunakan Queue (driver `database`) supaya kegagalan kirim WA/email tidak mengganggu proses utama, dengan retry otomatis Laravel Queue.

---

## 11. Batasan & Risiko

| Risiko | Mitigasi |
|---|---|
| Shared hosting umumnya tidak bisa menjalankan proses queue worker terus-menerus | Driver queue `database` + trigger `queue:work --stop-when-empty` via cron tiap menit |
| Gateway WhatsApp adalah layanan pihak ketiga (bisa gagal/limit) | Custom Notification channel dengan retry & logging kegagalan kirim |
| Perhitungan SLA berbasis jam kerja lebih kompleks dari sekadar hitung mundur biasa | Sentralisasi logika di satu Service class (mis. `SlaCalculator`) agar konsisten & mudah diuji |
| Volume data tiket besar dapat memperlambat query dashboard/grafik | Index kolom `status`, `unit_id`, `sub_unit_id`, `created_at`; pagination di semua list |

---

## 12. Roadmap Pengembangan

| Fase | Cakupan |
|---|---|
| **Fase 1** | Auth, Master Data, Peraturan Form, Wizard Pengajuan Tiket, Riwayat Tiket |
| **Fase 2** | Modul Tiketing Admin (eksekusi status, filter), Dashboard Admin dasar |
| **Fase 3** | Modul SLA lengkap (per layanan, 2 jenis, jam kerja, jeda Pending, badge, kepatuhan) |
| **Fase 4** | Modul Reminder & Notification Center (4 jenis, multi-channel, snooze) |
| **Fase 5** | CSAT, Live Monitor, grafik statistik, Konfigurasi Sistem, Manajemen Admin (role), hardening keamanan, deployment cPanel |

---

*Dokumen ini adalah revisi v2 dari PRD awal, menyesuaikan tech stack final (Laravel + Inertia + React + MySQL cPanel) dan memperdalam desain Modul SLA & Reminder agar lebih dapat digunakan sehari-hari.*