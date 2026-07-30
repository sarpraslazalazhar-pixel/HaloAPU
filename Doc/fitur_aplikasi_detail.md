# Anatomi & Dokumentasi Mendalam: Aplikasi Halo APU V2

Dokumen ini membedah aplikasi **Halo APU V2** secara menyeluruh hingga ke akar-akarnya (UI/UX, Logika Bisnis, Arsitektur, dan Alur Kerja), tidak hanya mendaftar fitur, melainkan memahami *bagaimana* dan *mengapa* fitur tersebut dirancang.

---

## 1. Identitas Visual & UI/UX (Desain)
Halo APU V2 dibangun dengan pendekatan desain modern dan clean. Identitas visualnya terangkum dalam `app.css` dan `tailwind.config.js`:

- **Warna Dominan (Primary)**: `#00a2e8` (Biru Muda / Sky Blue).
  - *Filosofi*: Warna biru ini melambangkan profesionalitas, layanan yang cepat, transparansi, dan komunikasi yang jernih—sangat cocok untuk sistem *Helpdesk* dan pelayanan (Halo).
  - Turunan warna primer meliputi hover `#0081b8`, light `#e0f4fe`, dan dark `#006394`.
- **Warna Sekunder (Secondary)**: `#f39c12` (Oranye / Amber).
  - *Filosofi*: Warna oranye memberikan sentuhan energi, kehangatan, dan menjadi warna *call-to-action* atau *highlight* yang kontras terhadap biru, menarik perhatian pengguna tanpa membuat lelah mata.
- **Tipografi**: Menggunakan font **Geist Variable** (dan font fallback `Inter`, `system-ui`). Font Geist memberikan kesan modern, rapi, dengan tingkat keterbacaan (legibility) yang sangat tinggi pada berbagai ukuran layar.
- **Animasi Mikro**: Memiliki animasi bawaan `--animate-page-in` (efek transisi masuk `0.35s ease-out` saat memuat halaman atau komponen modal) untuk memberikan kesan mulus (smooth) dan tidak kaku.
- **Komponen UI**: Mengimplementasikan kombinasi library React modern seperti `shadcn/ui`, `@radix-ui` (primitif komponen yang dapat diakses), `lucide-react` (untuk ikon), dan desain kaca (*glassmorphism* atau *clean border*) sesuai trend modern.

---

## 2. Tujuan Inti Aplikasi (Core Purpose)
Aplikasi ini bukan sekadar Helpdesk biasa, melainkan gabungan dari **Sistem Helpdesk Tiketing** + **Manajemen Form Dinamis** + **Pemantauan Aset (Booking)**. Tujuannya adalah untuk mengautomasi permintaan layanan dari berbagai Divisi (Unit/Sub Unit), di mana setiap layanan memiliki form pengajuan berbeda, menjamin pengerjaan layanan dengan tenggat waktu pasti (SLA), dan mengukur kepuasan pelanggan secara konkrit (CSAT).

---

## 3. Bedah Fitur Sampai Ke Akarnya

### A. Core Engine: Form Builder Dinamis (Peraturan Form)
Ini adalah "jantung" keluwesan Halo APU V2.
- **Konsep**: Daripada melakukan *hardcode* formulir (misalnya, form perbaikan AC berbeda dengan form pinjam mobil), aplikasi ini menyediakan Form Builder.
- **Cara Kerja**: Admin dapat merakit *field* masukan (Teks, Nomor, Tanggal, File Upload, Dropdown) untuk masing-masing *Sub Unit* layanan. Setiap *field* memiliki status *Required* atau opsional. Data struktur formulir ini akan di-*render* secara otomatis (*on-the-fly*) di sisi pengguna melalui *Ticket Wizard*.
- **Penyimpanan**: Disimpan di database dan dikelola menggunakan JSON/relasi sehingga data pengajuan unik untuk setiap jenis layanan tidak merusak struktur tabel utama tiket.

### B. Workflow Sistem Tiket (Ticket Lifecycle)
Siklus hidup tiket tidak linier, namun interaktif.
- **Fase 1: Ticket Wizard**: Pengguna melalui proses *step-by-step* memilih Divisi -> Unit -> Sub Unit. Kemudian form kustom (dari Form Builder) muncul.
- **Fase 2: Triage & Assignment**: Admin menerima tiket (status awal `Pending`). Admin bisa menugaskan (`Assign`) ke *Operator* spesifik dan mengatur *Priority* (Rendah, Sedang, Tinggi).
- **Fase 3: Pengerjaan (Proses)**: Status berubah menjadi `Proses`. Di tahap ini, fitur **Log & Diskusi** aktif. Pengguna dan operator dapat saling *reply* seperti aplikasi *chat/forum* di dalam detail tiket.
- **Fase 4: Validasi (Accept / Reject)**: Operator menandai tiket sebagai *Selesai*. Namun tiket **belum** benar-benar ditutup. Pengguna (User) berhak melakukan:
  - **Accept Result**: Jika puas, tiket benar-benar ditutup.
  - **Request Revision**: Jika dirasa belum tuntas, pengguna mengisi alasan, dan tiket dikembalikan ke status `Proses` ke operator.
- **Fitur Tambahan**: Pengguna juga bisa membatalkan (`Cancel`) tiket jika belum dikerjakan.

### C. Service Level Agreement (SLA) & Scheduler (Otomatisasi)
Aplikasi memastikan tidak ada tiket yang diterlantarkan (diabaikan).
- **Pengaturan SLA**: Admin mengatur target waktu (misal: "Respon maksimal 2 jam, Pengerjaan maksimal 3 hari").
- **SLA Engine**: Terdapat Model `TicketSlaTracking` yang menghitung otomatis kapan tenggat waktu habis.
- **Cron Jobs / Scheduler Otomatis**: Fitur ini bekerja di belakang layar:
  - **SLA Check**: Skrip mengecek apakah waktu saat ini melebihi target SLA. Jika ya, tiket ditandai melanggar (*SLA Breach*) dan notifikasi peringatan dikirim.
  - **Pending & Booking Reminder**: Pengingat otomatis saat hari peminjaman ruang/kendaraan tiba, atau pengingat ke admin jika ada tiket menggantung.
  - **CSAT Reminder**: Mengingatkan pengguna mengisi rating jika lupa setelah menekan *Accept Result*.

### D. CSAT (Customer Satisfaction Score)
Evaluasi kinerja operasional secara *real-time*.
- Setelah tiket `Selesai`, antarmuka (`CsatDialog`) otomatis muncul menghadang (intercept) pengguna untuk memberikan 1 - 5 Bintang.
- **Analitik CSAT**: Data tidak hanya dikumpulkan, tapi diolah di **Dashboard Admin** (`Laporan CSAT`). Admin bisa melihat Rata-Rata Kepuasan, Distribusi Skor, dan memfilter kepuasan spesifik per *Unit Layanan* (mana departemen yang kerjanya paling bagus/buruk).

### E. Live Monitor (Booking Ruang & Kendaraan)
Fitur spesifik untuk manajemen aset.
- **Konsep**: Menggunakan komponen React dengan fitur *Auto-polling* (menggunakan custom hook `usePoll` atau setInterval) yang secara diam-diam memanggil data dari `/monitor` setiap X detik tanpa perlu *refresh* (muat ulang halaman).
- **Tampilan**: `MonitorGrid.tsx` menampilkan wujud grid (kotak-kotak visual) aset (Ruangan / Kendaraan).
- **Status Warna/Teks**: Berubah secara dinamis antara "Tersedia" (Available), "Dipesan" (Booked/Menunggu Jam), atau "Sedang Dipakai" (In-Use), lengkap dengan nama pemesan dan waktu (Jam Mulai - Jam Selesai).

### F. Real-time Web Push Notification System
Pemberitahuan yang canggih (tidak hanya *bell icon*).
- **Integrasi Web Push**: Menggunakan API browser Push Subscription. Ketika browser tidak terbuka, pengguna tetap mendapat notifikasi pop-up OS (seperti WhatsApp Web).
- **Aksi Notifikasi**: Notifikasi yang masuk di aplikasi bisa di-tindak lanjuti dengan fungsi:
  - **Read**: Sekadar dibaca.
  - **Snooze**: Ditunda (disembunyikan sementara dan akan muncul lagi).
  - **Done**: Dihapus dari *inbox* aktif.

### G. Konfigurasi Sistem Dinamis (White Label)
Aplikasi ini memungkinkan penyesuaian visual (*branding*) langsung dari panel admin tanpa menyentuh kode (White-labeling).
- **Sistem Config**: Upload `Logo`, `Banner Login`, `Favicon` di-handle dinamis menggunakan `SystemConfigController`.
- **Custom Sound**: Bahkan, suara (*sound effect*) notifikasi saat ada tiket masuk dapat di-*upload* dan di-*replace* oleh admin di pengaturan.

### H. Infrastruktur Backend
- **Laravel 11/13**: Menggunakan iterasi Laravel terbaru yang teroptimasi, dibantu `artisan` dan Composer.
- **Shared Hosting Compatibility**: Menyediakan *route* rahasia `/system/optimize` dan `/system/clear` sehingga developer/admin dapat melakukan *clear cache* atau mematikan web di server tradisional yang tidak memiliki akses terminal (SSH).
- **Security**: Autentikasi ketat via Sanctum/Session, pemisahan tabel User dan Admin (di beberapa konfigurasi), serta validasi request API secara utuh.

---
**Kesimpulan**: 
Halo APU V2 didesain sebagai sistem yang "hidup" dan interaktif. Dengan dominasi warna biru yang menenangkan serta dukungan performa tinggi (Inertia + React), aplikasi ini bukan sekadar formulir digital, melainkan asisten cerdas yang menegakkan disiplin (melalui SLA), mengevaluasi performa (CSAT), dan mengelola aset secara *real-time* (Monitor), semuanya dibungkus dalam *form builder* yang dinamis.
