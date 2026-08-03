# Dokumentasi Fitur Halo APU V2

Dokumen ini merangkum seluruh fitur yang tersedia dalam sistem **Halo APU V2**, berdasarkan struktur kode, model database, dan *routing* aplikasi.

## 1. Autentikasi & Otorisasi
- **Login Multi-Peran**: Sistem login terpisah untuk Pengguna Umum (User) dan Admin/Operator.
- **Registrasi Pengguna**: Pendaftaran mandiri bagi pengguna baru.
- **Lupa Password**: Mekanisme pemulihan akun melalui reset password.
- **Manajemen Peran dan Izin (Role & Permission)**: Pengaturan akses berjenjang berbasis role menggunakan library `spatie/laravel-permission`.

## 2. Portal Pengguna (User Panel)
- **Dashboard Pengguna**: Halaman utama pengguna dengan ringkasan status dan aktivitas tiket pribadi.
- **Ticket Wizard**: Fitur pembuatan tiket layanan baru dengan formulir langkah-demi-langkah yang menyesuaikan otomatis dengan jenis layanan yang dipilih (berbasis *Form Builder*).
- **Riwayat & Interaksi Tiket**: 
  - Melihat daftar tiket aktif dan riwayat tiket lama.
  - Berdiskusi (reply) pada tiket aktif dengan operator.
  - Menyetujui hasil penyelesaian tiket (Accept Result).
  - Meminta revisi pengerjaan jika belum sesuai (Request Revision).
  - Membatalkan pengajuan tiket (Cancel).
- **Preview & Unduh Lampiran**: Fitur bawaan untuk mengunduh dan melakukan *preview* (melihat langsung) lampiran tiket (gambar/dokumen).
- **Profil Pengguna**: Manajemen informasi akun dan penggantian foto profil (Avatar).
- **Survei Kepuasan (CSAT)**: 
  - Memberikan rating kepuasan (1-5 bintang) dan ulasan setelah tiket dinyatakan selesai oleh operator.
  - Halaman riwayat penilaian CSAT.
- **Live Monitor Aset**: Fitur pemantauan real-time untuk status ketersediaan ruang dan kendaraan dinas.

## 3. Portal Admin & Operator (Helpdesk)
- **Dashboard Admin**: Panel pemantauan statistik utama yang mencakup tiket masuk, penyelesaian SLA, dan beban kerja operator.
- **Profil Admin**: Manajemen informasi dan avatar untuk akun admin/operator.
- **Manajemen Tiket Masuk**:
  - Melihat seluruh daftar tiket berdasarkan departemen/layanan.
  - Mendistribusikan (*assign*) tiket ke operator spesifik.
  - Mengubah status progres tiket (Pending, Proses, Selesai, dll).
  - Menetapkan tingkat prioritas tiket (Low, Medium, High).
  - Mengunduh dan melihat lampiran pendukung dari pengguna.
- **Notifikasi Terpusat (Web Push & In-App)**: 
  - Mendapatkan pemberitahuan *real-time* tentang tiket baru atau balasan pengguna.
  - Fitur manajemen notifikasi: *Read* (tandai dibaca), *Snooze* (tunda), dan *Done* (tandai selesai).
- **Laporan & Analitik**:
  - Rekap Laporan Tiket (berdasarkan waktu, status, unit).
  - Dashboard Laporan CSAT (analisis rata-rata nilai kepuasan, distribusi rating, evaluasi per unit layanan).
- **Monitor Status Admin**: Panel bagi admin untuk mengecek status aset (ruangan/kendaraan) yang dipinjam atau digunakan via tiket terintegrasi.

## 4. Manajemen Master Data & Konfigurasi Sistem
- **Master Data Layanan**: 
  - Unit Layanan utama (misalnya: IT, GA, dll).
  - Sub Unit Layanan (kategori layanan spesifik di bawah unit).
- **Master Data Organisasi (SDM)**:
  - Manajemen struktur Divisi.
  - Unit Organisasi pelaksana.
  - Jabatan & pengurutannya (Reorder).
- **Manajemen Akun (User Management)**:
  - Manajemen akun seluruh Pengguna (User).
  - Manajemen akun seluruh Operator & Admin.
  - Pengelolaan Master Role/Peran.
- **Form Builder (Peraturan Form)**: Fitur *drag-and-drop/builder* canggih untuk membuat aturan *field* form dinamis (Teks, Angka, Dropdown, File) secara spesifik untuk masing-masing "Sub Unit" layanan.
- **Konfigurasi SLA (Service Level Agreement)**: Pengaturan batas maksimal waktu respons dan waktu penyelesaian berdasarkan jenis layanan dan tingkat prioritas tiket.
- **Konfigurasi Pengingat (Reminder Config)**: Pengaturan jadwal/interval untuk notifikasi email dan push notification.
- **Pengaturan Aplikasi (System Config)**:
  - Mengubah identitas aplikasi (Nama Sistem, Deskripsi).
  - Mengunggah aset visual seperti Logo, Banner Login, dan Favicon.
  - Mengunggah dan mengatur suara (*sound alert*) khusus untuk notifikasi.

## 5. Otomatisasi Sistem (Scheduler / Cron Jobs)
- **SLA Check**: Skrip terjadwal untuk memantau status tiket dan memberikan status pelanggaran (*SLA breach*) jika melewati batas waktu penyelesaian yang ditentukan.
- **Booking Reminder**: Skrip untuk mengingatkan pihak terkait mengenai peminjaman ruangan atau kendaraan yang akan segera berlangsung.
- **Pending Reminder**: Mengirimkan pengingat ke pengguna atau operator jika sebuah tiket dibiarkan menggantung (*pending*) terlalu lama tanpa tindakan lanjut.
- **CSAT Reminder**: Mengirim notifikasi penagihan pengisian survei kepuasan ke pengguna apabila tiket sudah berstatus selesai tapi belum diberikan *rating*.

## 6. Fitur Publik & API Lainnya
- **TV Dashboard**: Tampilan dasbor khusus publik yang dirancang untuk layar kaca (TV/Monitor) untuk menampilkan statistik interaktif performa layanan.
- **Web Push Subscriptions**: Sistem pendaftaran notifikasi langsung ke perangkat (*browser push notifications*).
- **Dynamic Dropdown Endpoint API**: Menangani permintaan data berjenjang (misalnya saat pengguna memilih divisi, aplikasi secara asinkron memanggil data unit terkait via API).
- **Optimasi Hosting Mode (*Shared Hosting*)**: Endpoint khusus (`/system/optimize` & `/system/clear`) yang ditujukan untuk melakukan *clearing* dan *caching* konfigurasi Laravel secara langsung melalui URL di lingkungan produksi non-SSH.
