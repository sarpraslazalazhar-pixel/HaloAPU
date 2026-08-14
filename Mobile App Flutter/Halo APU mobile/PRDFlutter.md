# HALO APU MOBILE
### Product Requirements Document (PRD)
**Aplikasi Helpdesk Internal Berbasis Flutter**

| | |
|---|---|
| **Versi** | 2.0 (Extended) |
| **Platform** | Android · iOS · Flutter 3.x |
| **Referensi Web App** | dev.haloapu.id |
| **Tanggal** | 28 Juli 2026 |

---

## Daftar Isi

1. [Ringkasan Eksekutif](#1-ringkasan-eksekutif)
2. [Latar Belakang & Konteks](#2-latar-belakang--konteks)
3. [Tujuan Bisnis & Success Metrics](#3-tujuan-bisnis--success-metrics)
4. [Target Pengguna & Persona](#4-target-pengguna--persona)
5. [Ruang Lingkup Produk (MVP)](#5-ruang-lingkup-produk-mvp)
6. [Di Luar Ruang Lingkup](#6-di-luar-ruang-lingkup-out-of-scope)
7. [User Journey](#7-user-journey)
8. [Informasi Arsitektur & Navigasi](#8-informasi-arsitektur--navigasi)
9. [Spesifikasi Layar](#9-spesifikasi-layar-screen-specifications)
10. [Sistem Desain UI/UX](#10-sistem-desain-uiux)
11. [Arsitektur Teknis Flutter](#11-arsitektur-teknis-flutter)
12. [Strategi Offline & Caching](#12-strategi-offline--caching)
13. [Strategi Push Notification](#13-strategi-push-notification)
14. [Keamanan & Non-Functional Requirements](#14-keamanan--non-functional-requirements)
15. [Strategi Testing & QA](#15-strategi-testing--qa)
16. [Roadmap Pengembangan](#16-roadmap-pengembangan)
17. [Rekomendasi Package Flutter](#17-rekomendasi-package-flutter)
18. [Risiko & Mitigasi](#18-risiko--mitigasi)
19. [Lampiran](#19-lampiran)

---

## 1. Ringkasan Eksekutif

Halo APU Mobile adalah perluasan dari sistem helpdesk internal Halo APU yang saat ini sudah berjalan sebagai aplikasi web di **dev.haloapu.id**. Dokumen ini menjadi acuan pengembangan aplikasi mobile berbasis Flutter yang terhubung langsung ke backend/API yang sudah ada di web, sehingga data tiket, user, dan status tetap satu sumber kebenaran *(single source of truth)*.

Tujuan utama aplikasi mobile adalah memindahkan aktivitas harian pegawai — membuat tiket, memantau progres, membalas admin, menerima notifikasi, memberi rating — ke perangkat yang selalu ada di tangan, tanpa mendup­likasi fitur backoffice yang memang lebih tepat dikerjakan di web.

> **Vision Statement:**
> *"Seluruh proses helpdesk dapat dilakukan dalam kurang dari 2 menit dari perangkat mobile."*

### 1.1 Prinsip Utama

- Mobile-first untuk aktivitas end-user, web tetap menjadi pusat kendali admin/backoffice.
- Satu backend, satu API — mobile mengonsumsi API yang sama dengan dev.haloapu.id (tidak ada backend terpisah).
- Desain modern, minimal, cepat, dan konsisten dengan identitas warna **Ocean Water** & **Brilliant Blue**.
- Realtime by default — status tiket dan notifikasi terasa "hidup" tanpa perlu refresh manual.
- Resilient terhadap koneksi buruk (offline draft, retry otomatis, caching lokal).

---

## 2. Latar Belakang & Konteks

Aplikasi web Halo APU (dev.haloapu.id) sudah menjadi sistem utama untuk pengelolaan tiket, master data, konfigurasi, dan pelaporan. Aplikasi mobile dibangun sebagai *companion app* yang fokus pada pengalaman end-user (Karyawan/Amil/Nadzir/Staff) dan Admin Lite, tanpa mengubah arsitektur backend yang sudah berjalan.

### 2.1 Asumsi Integrasi dengan Web Existing

- Backend web menyediakan (atau diperluas untuk menyediakan) REST API/JSON yang dapat dikonsumsi mobile — direkomendasikan endpoint terpisah, misal `/api/v1/mobile/...`, agar perubahan di mobile tidak memengaruhi web.
- Autentikasi menggunakan token-based auth (disarankan Laravel Sanctum/Passport bila backend berbasis Laravel).
- Realtime menggunakan broker yang sama dengan web (Laravel Echo + Pusher/Soketi) agar admin di web dan user di mobile menerima update pada waktu yang sama.
- Struktur data tiket, kategori layanan, dan status di mobile mengikuti skema yang sudah ada di web — tidak ada normalisasi ulang skema database.

> **Catatan:** detail teknis endpoint aktual perlu dikonfirmasi dengan tim backend dev.haloapu.id sebelum Sprint 0 dimulai.

---

## 3. Tujuan Bisnis & Success Metrics

| Objective | Metrik Keberhasilan (KPI) | Target |
|---|---|---|
| Mengurangi waktu respon tiket | Average First Response Time | Turun ≥ 30% dalam 3 bulan |
| Meningkatkan CSAT | Skor rating rata-rata pasca tiket selesai | ≥ 4.5 / 5 |
| Meningkatkan engagement | Push notification open rate | ≥ 60% |
| Adopsi aplikasi | Jumlah pegawai aktif bulanan (MAU) vs total pegawai | ≥ 80% dalam 2 bulan rilis |
| Efisiensi proses | Rata-rata waktu pembuatan tiket end-to-end | < 2 menit |
| Stabilitas aplikasi | Crash-free session rate | ≥ 99.5% |

---

## 4. Target Pengguna & Persona

### 4.1 Peran Pengguna

| Peran | Fokus Utama | Aktivitas Kunci |
|---|---|---|
| Karyawan / Staff | Pengguna umum internal | Buat tiket, lihat progres, upload foto, chat admin, terima notifikasi |
| Amil / Nadzir | Pengguna operasional lapangan | Sama seperti karyawan, dengan kebutuhan mobilitas & konektivitas terbatas |
| Admin (Lite) | Penanganan tiket harian | Lihat tiket masuk, assign, ubah status, balas tiket, notifikasi SLA |

### 4.2 Persona Ringkas

**Persona 1 — Brando (Staff Kantor)**
- Usia 25–35 tahun, terbiasa dengan aplikasi mobile modern (perbankan, e-commerce).
- Kebutuhan: membuat tiket IT/GA dengan cepat di sela pekerjaan, ingin tahu status tanpa harus menelepon admin.
- Frustrasi saat ini: harus membuka laptop/web hanya untuk cek status tiket sederhana.

**Persona 2 — Amil Lapangan**
- Sering berada di lokasi dengan sinyal tidak stabil.
- Kebutuhan: dapat membuat draft tiket saat offline dan otomatis terkirim saat online kembali.

**Persona 3 — Admin Lite (Helpdesk Officer)**
- Menangani puluhan tiket per hari, butuh notifikasi SLA agar tidak ada tiket terlewat.
- Tidak butuh dashboard analitik kompleks di HP — cukup aksi cepat: assign, balas, ubah status.

---

## 5. Ruang Lingkup Produk (MVP)

### 5.1 Authentication

| Fitur | Deskripsi | Acceptance Criteria |
|---|---|---|
| Login | Login dengan akun pegawai (email/username & password), terhubung ke sistem akun yang sama dengan web | Login berhasil < 3 detik; pesan error jelas untuk kredensial salah |
| Remember Login | Sesi tetap aktif menggunakan refresh token tersimpan aman | User tidak perlu login ulang selama token belum expired/revoked |
| Biometrics | Login cepat via fingerprint/Face ID setelah login pertama berhasil | Opsi aktif dari Profile > Keamanan; fallback ke password bila biometrik gagal 3x |
| Forgot Password | Reset password via email/OTP terhubung ke mekanisme reset yang sama dengan web | Link/OTP reset terkirim < 1 menit, expired dalam 15 menit |

### 5.2 Dashboard

- Header greeting personal (nama, waktu, avatar, ikon notifikasi dengan badge unread).
- Statistic Card horizontal: **Active, Processing, Solved, Rejected** — data realtime dari API.
- Quick Action: Ajukan Tiket, Riwayat, Live Monitor, CSAT.
- Recent Ticket: 3–5 tiket terakhir dengan status badge, info SLA, dan tanggal.
- Announcement banner dari admin (dapat di-dismiss).

> Acceptance: seluruh data dashboard termuat < 2 detik pada koneksi 4G normal, dengan skeleton/shimmer loading saat fetching.

### 5.3 Ticket Management

**5.3.1 Create Ticket (Wizard 4 Langkah)**
1. **Choose Service** — card grid berisi kategori layanan (IT, GA, HR, dsb.) sesuai master data dari web.
2. **Dynamic Form** — form berbeda per kategori, dirender dari konfigurasi form builder di web.
3. **Attachment** — kamera, galeri, atau file, dengan preview & kompresi otomatis.
4. **Review & Submit** — ringkasan data sebelum dikirim, dengan validasi wajib isi.

**5.3.2 My Ticket & Ticket Detail**
- Daftar tiket dengan filter status (All, Active, Processing, Solved, Rejected) dan pencarian.
- Ticket Detail: timeline status, riwayat chat, attachment, riwayat revisi, tombol Accept Solution.
- Reply Ticket: chat dua arah dengan admin (teks, foto, file).
- Cancel Ticket: hanya tersedia selama status masih Open/Belum diproses.
- Request Revision: user menolak solusi dan meminta revisi dengan catatan alasan.
- Accept Solution: tiket berpindah ke status Solved dan memicu permintaan rating CSAT.

### 5.4 Notification

- Realtime Push Notification via Firebase Cloud Messaging (dipicu Laravel Echo).
- In-App Notification Center: dikelompokkan per tanggal.
- Status Read/Unread, swipe-to-delete.
- Notification History tersimpan minimal 30 hari.

### 5.5 Profile

- Edit Profile: foto, nama tampilan, kontak (data inti kepegawaian read-only, bersumber dari HRIS/web).
- Informasi Department, Division, Position (read-only).
- Change Password, Logout, Dark Mode toggle, Version App.

### 5.6 CSAT (Customer Satisfaction)

- Rating bintang (1–5) muncul otomatis setelah Accept Solution.
- Comment opsional.
- History CSAT: riwayat rating yang pernah diberikan.

---

## 6. Di Luar Ruang Lingkup (Out of Scope)

| Modul | Alasan Tetap di Web |
|---|---|
| Form Builder | Kebutuhan konfigurasi kompleks, lebih efisien di layar besar |
| Master Data | Data referensi lintas sistem, perubahan jarang dan berisiko tinggi bila salah input |
| Configuration & SLA Configuration | Bersifat administratif, frekuensi penggunaan rendah |
| Analytics & Report | Membutuhkan visualisasi kompleks yang lebih sesuai di layar besar |
| User Management & Role Permission | Aspek keamanan sensitif, lebih aman dikelola terpusat di web |

---

## 7. User Journey

```
Splash Screen
     ↓
Login (password / biometrik)
     ↓
Dashboard (ringkasan status & quick action)
     ↓
Create Ticket (wizard 4 langkah)
     ↓
Submit (konfirmasi & nomor tiket)
     ↓
Receive Notification (update dari admin)
     ↓
Admin Response (web / Admin Lite)
     ↓
User Reply (jika diperlukan)
     ↓
Solved (Accept / Request Revision)
     ↓
Rate Service (CSAT + komentar)
```

---

## 8. Informasi Arsitektur & Navigasi

### 8.1 Bottom Navigation

| Ikon | Label | Fungsi |
|---|---|---|
| 🏠 | Home | Dashboard utama |
| 🎫 | Tickets | Daftar & riwayat tiket (My Ticket) |
| ➕ | (FAB) | Shortcut membuat tiket baru dari mana saja |
| 🔔 | Notifications | Pusat notifikasi in-app |
| 👤 | Profile | Profil, pengaturan, logout |

> FAB (Floating Action Button) di tengah bottom navigation menggunakan gradient **Ocean Water → Brilliant Blue** agar konsisten dengan Component Style pada bagian 10.

---

## 9. Spesifikasi Layar (Screen Specifications)

### 9.1 Dashboard

```
────────────────────────────────
 Halo 👋  Brando               🔔
 Selamat Pagi
────────────────────────────────
[ Active 12 ] [ Processing 5 ]
[ Solved 120 ] [ Rejected 3 ]
────────────────────────────────
 Quick Action
 ➕ Ticket   📋 History
 📊 Monitor  ⭐ Rating
────────────────────────────────
 Recent Ticket
 [ Card ]  [ Card ]  [ Card ]
────────────────────────────────
```

### 9.2 Ticket Flow (Wizard)

| Step | Nama | Komponen UI |
|---|---|---|
| 1 | Choose Service | Card grid 2 kolom, ikon kategori, badge jumlah tiket aktif per kategori |
| 2 | Dynamic Form | Form field dinamis (text, dropdown, date picker, checkbox) sesuai konfigurasi backend |
| 3 | Attachment | Tab Camera / Gallery / File, preview thumbnail, opsi hapus sebelum submit |
| 4 | Review & Submit | Ringkasan data, tombol Submit dengan loading state & progress upload |

### 9.3 Ticket Detail

- Timeline vertikal status (Open → Assigned → Processing → Solved/Rejected).
- Tab: Chat, Attachment, History/Revision.
- Tombol aksi kontekstual (Accept Solution, Request Revision, Cancel) sesuai status tiket.

### 9.4 Notification Center

- List dikelompokkan per hari (Hari ini, Kemarin, Minggu ini).
- Swipe kiri untuk hapus, tap untuk menuju detail tiket terkait.

### 9.5 Profile

- Foto profil bulat, nama, department/division/position, versi aplikasi di bagian bawah.
- Toggle Dark Mode langsung mengubah tema tanpa restart aplikasi.

---

## 10. Sistem Desain UI/UX

### 10.1 Bahasa Desain

Modern · Minimal · Rounded · Glass Effect · Friendly · Fast · Professional — konsisten di seluruh layar.

### 10.2 Palet Warna

| Peran | Nama | Hex | Contoh Penggunaan |
|---|---|---|---|
| 🟦 Primary | Ocean Water | `#00B8D9` | Header, tombol utama, ikon aktif |
| 🔵 Secondary | Brilliant Blue | `#0066FF` | Gradient tombol, aksen link |
| 🟢 Success | Green | `#22C55E` | Status Solved, konfirmasi |
| 🟠 Warning | Amber | `#F59E0B` | Status Processing, reminder SLA |
| 🔴 Danger | Red | `#EF4444` | Status Rejected, tombol hapus/cancel |
| ⬜ Background | Light | `#F7FAFC` | Latar layar mode terang |
| ⬛ Dark Background | Dark Navy | `#0F172A` | Latar layar mode gelap |

**Gradient Tombol Utama:** `#00B8D9` → `#0066FF`

### 10.3 Tipografi

| Elemen | Style |
|---|---|
| Font | Plus Jakarta Sans |
| Heading | Bold |
| Body | Medium |
| Caption | Regular |

### 10.4 Border Radius

| Komponen | Radius (px) | Catatan |
|---|---|---|
| Card | 20 | Termasuk statistic card & recent ticket card |
| Button | 18 | Semua varian tombol |
| Input | 16 | Text field, dropdown |
| Bottom Sheet | 28 | Modal aksi & filter |

### 10.5 Ikon & Komponen

- **Gaya ikon:** Rounded, Outline — Lucide Icons.
- **Card:** soft shadow, floating, rounded, gradient opsional untuk highlight.
- **Button Primary:** gradient Ocean Water → Brilliant Blue.
- **Button Secondary:** putih dengan border biru.
- **Button Danger:** merah solid. **Button Success:** hijau solid.

### 10.6 Animasi & Microinteraction

- Lottie untuk Splash Screen dan Hero Animation pada empty state.
- Transisi Fade & Slide antar halaman.
- Pull-to-refresh pada seluruh list.
- Ripple effect pada tombol & card yang bisa ditekan.
- Shimmer loading menggantikan spinner tradisional.

### 10.7 Design Tokens (8px Grid)

| Token | Nilai | Keterangan |
|---|---|---|
| Spacing | 8 / 16 / 24 / 32 / 40 | Kelipatan 8px untuk margin & padding |
| Shadow Blur | 18 | Radius blur bayangan card |
| Shadow Opacity | 8% | Kehalusan bayangan agar tidak berat |
| Button Height | 56 | Tinggi standar tombol utama |
| Card Radius | 20 | Selaras dengan tabel border radius |
| Avatar Size | 52 | Ukuran avatar standar di header/profile |
| Icon Size | 24 | Ukuran ikon standar di seluruh aplikasi |

---

## 11. Arsitektur Teknis Flutter

### 11.1 Clean Architecture — 3 Layer

```
Presentation
│  ├── Riverpod (state management)
│  ├── Go Router (navigasi)
│  └── Flutter Hooks (lifecycle & controller helper)
│
Domain
│  ├── UseCases (logika bisnis murni)
│  └── Repository interface (kontrak akses data)
│
Data
   ├── API Client (Dio)
   ├── Local Database (Hive)
   └── Cache layer
```

### 11.2 Stack Teknologi

| Kebutuhan | Teknologi | Alasan |
|---|---|---|
| State Management | Riverpod | Testable, tidak bergantung BuildContext |
| Navigation | Go Router | Deklaratif, mendukung deep link dari push notification |
| Storage Lokal | Hive | Ringan, cepat, cocok untuk cache & draft offline |
| HTTP Client | Dio | Interceptor untuk auth token, retry, dan logging |
| Realtime | Laravel Echo (via WebSocket bridge) | Selaras dengan sistem realtime yang sudah dipakai di web |
| Push Notification | Firebase Cloud Messaging | Standar industri untuk Android & iOS |

### 11.3 Integrasi API dengan Web Existing (dev.haloapu.id)

- Autentikasi mobile diarahkan ke endpoint auth yang sama dengan web, mengembalikan access token + refresh token.
- Interceptor Dio menangani auto-refresh token dan retry request yang gagal karena token expired (401).
- Versioning API disarankan (`/api/v1/mobile/tickets`) agar backend bebas mengubah response web tanpa memutus mobile.
- Upload attachment menggunakan `multipart/form-data` dengan kompresi gambar sisi client.
- Realtime channel di-subscribe per user (private channel) agar notifikasi hanya diterima pemilik/terkait tiket.

---

## 12. Strategi Offline & Caching

- **Draft Ticket** — form yang belum disubmit disimpan otomatis ke Hive setiap perubahan.
- **Cached Ticket** — daftar & detail tiket terakhir tetap dapat dibuka tanpa koneksi (read-only).
- **Retry Upload** — attachment gagal terkirim disimpan dalam antrian dan otomatis dicoba ulang saat online.
- Indikator status koneksi (banner tipis) muncul saat aplikasi mendeteksi offline.

---

## 13. Strategi Push Notification

| Event | Trigger | Deep Link Tujuan |
|---|---|---|
| New Ticket | Tiket berhasil dibuat & diterima sistem | Ticket Detail |
| Ticket Assigned | Admin meng-assign tiket ke petugas | Ticket Detail |
| Reply | Ada balasan baru dari admin | Ticket Detail > Chat |
| Solved | Tiket ditandai selesai oleh admin | Ticket Detail > Accept Solution |
| Revision | User meminta revisi / admin merespons | Ticket Detail > History |
| Rejected | Tiket ditolak dengan alasan | Ticket Detail |
| Reminder CSAT | 24 jam setelah Solved tanpa rating | CSAT Rating |
| SLA Reminder | Tiket mendekati/melewati batas SLA (Admin Lite) | Admin Ticket List |

---

## 14. Keamanan & Non-Functional Requirements

### 14.1 Keamanan
- Token disimpan menggunakan `flutter_secure_storage` (Keychain iOS, Keystore Android).
- Biometric login hanya lapisan akses lokal ke sesi tersimpan, bukan pengganti autentikasi server.
- Auto-logout/token revoke saat password diubah dari web atau mobile.
- Seluruh komunikasi API wajib melalui HTTPS/TLS.

### 14.2 Performa
- Cold start < 2.5 detik pada perangkat kelas menengah.
- Render dashboard < 2 detik pada koneksi 4G normal.

### 14.3 Kompatibilitas
- Android minimum versi 8.0 (API 26), iOS minimum versi 13.
- Mendukung dark mode penuh (mengikuti sistem atau toggle manual).

### 14.4 Aksesibilitas
- Kontras warna teks vs latar mengikuti standar WCAG AA minimal.
- Tap target minimal 44x44px untuk semua elemen interaktif.

---

## 15. Strategi Testing & QA

| Jenis Pengujian | Cakupan |
|---|---|
| Unit Test | UseCases pada layer Domain & logic parsing/formatting |
| Widget Test | Form wizard, ticket card, chat bubble |
| Integration Test | Login → buat tiket → terima notifikasi → rating |
| Manual QA / UAT | Bersama perwakilan Karyawan, Amil/Nadzir, dan Admin Lite |
| Performance Test | Perangkat low-end & koneksi jaringan lemah (simulasi 3G) |

---

## 16. Roadmap Pengembangan

| Fase | Durasi | Deliverable Utama | Output |
|---|---|---|---|
| **Phase 1** | 3–4 minggu | Login & autentikasi, Dashboard user, Daftar tiket, Detail tiket, Push notification | Aplikasi dapat login, melihat & memantau tiket |
| **Phase 2** | 3 minggu | Wizard pengajuan tiket, Upload foto & dokumen, Balas tiket, Live Monitor, CSAT | User dapat membuat tiket end-to-end & memberi rating |
| **Phase 3** | 2 minggu | Admin Lite, Dark mode, Offline draft, Biometric login, Optimasi performa | Aplikasi siap rilis penuh ke seluruh pegawai |

> Total estimasi 8–9 minggu pengembangan inti, belum termasuk UAT, submission ke Play Store/App Store, dan buffer rilis (disarankan tambahan 1–2 minggu).

### 16.1 Rekomendasi Tahapan Tambahan

1. **Sprint 0** (1 minggu, sebelum Phase 1) — finalisasi kontrak API mobile bersama tim backend web, setup project Flutter, setup CI/CD dasar.
2. **UAT & Bug Fixing** (1 minggu, setelah Phase 3) — pengujian bersama perwakilan tiap peran pengguna.
3. **Store Submission & Soft Launch** (1 minggu) — rilis terbatas ke satu divisi sebelum rilis penuh.

---

## 17. Rekomendasi Package Flutter

| Kebutuhan | Package |
|---|---|
| State Management | `flutter_riverpod` |
| Navigasi | `go_router` |
| HTTP Client | `dio` |
| Model Generation | `freezed`, `json_serializable` |
| Local Storage | `hive` |
| Secure Storage | `flutter_secure_storage` |
| Push Notification | `firebase_messaging`, `flutter_local_notifications` |
| Media | `image_picker`, `file_picker`, `cached_network_image` |
| Loading & Animasi | `shimmer`, `lottie`, `flutter_animate` |
| Utilitas | `intl` |

---

## 18. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| API mobile belum tersedia/berbeda dari asumsi | Keterlambatan Phase 1 | Sprint 0 khusus finalisasi kontrak API bersama tim backend |
| Konektivitas lemah di lapangan (Amil/Nadzir) | Data tiket gagal terkirim, user frustrasi | Offline draft & retry upload sejak Phase 1/2 |
| Perbedaan versi OS Android/iOS di perangkat pegawai | Tampilan tidak konsisten, bug spesifik device | Testing di rentang device representatif, komponen adaptif |
| Adopsi user rendah pasca rilis | KPI engagement tidak tercapai | Sosialisasi internal, push notification onboarding, insentif CSAT |
| Notifikasi tidak realtime (batasan background iOS) | User terlambat merespons tiket | Kombinasi FCM + in-app polling ringan sebagai fallback |

---

## 19. Lampiran

### 19.1 Glossary

- **CSAT** — Customer Satisfaction Score, skor kepuasan layanan berbasis rating.
- **SLA** — Service Level Agreement, batas waktu maksimal penyelesaian tiket.
- **Admin Lite** — peran admin dengan akses terbatas di mobile, versi ringan dari admin penuh di web.
- **Draft Ticket** — tiket yang sedang diisi namun belum dikirim, tersimpan lokal di perangkat.

### 19.2 Referensi

- Aplikasi Web Existing: **dev.haloapu.id**
- Dokumen PRD versi awal (v1.0) — dasar penyusunan dokumen ini.

---

> **Catatan Penutup:** Dokumen ini bersifat *living document* — disarankan direview ulang setiap akhir fase (Phase 1, 2, 3) untuk menyesuaikan dengan temuan lapangan, hasil UAT, dan masukan tim backend web. Perubahan besar pada scope sebaiknya dicatat dengan versi baru (v2.1, v2.2, dst.) agar riwayat keputusan tetap terlacak.