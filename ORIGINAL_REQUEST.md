# Original User Request

## Initial Request — 2026-07-13T11:16:20+08:00

Implementasi Modul CSAT dan Live Monitor dari spesifikasi `Doc/PLAN-FASE-5.md` untuk sistem tiketing internal Halo APU v2. Tujuan saat ini adalah membuat prototipe/demo fungsional agar fitur dapat dilihat beroperasi.

Working directory: c:\Users\LAZ AL AZHAR\Documents\Halo APU V2
Integrity mode: development

## Requirements

### R1. Modul CSAT
Kembangkan fitur CSAT yang memungkinkan pengguna memberikan rating (1-5 bintang) dan komentar pada tiket yang telah selesai. Buatkan form rating pada sisi user dan halaman laporan/riwayat CSAT di sisi admin dan user.

### R2. Live Monitor
Buat halaman Live Monitor untuk melihat status ketersediaan ruang rapat dan kendaraan secara real-time berdasarkan data pemesanan. Halaman harus memperbarui dirinya secara otomatis menggunakan mekanisme polling.

## Acceptance Criteria

### CSAT
- [ ] Pengguna hanya bisa memberikan rating 1 kali pada tiket yang berstatus 'Solve' atau 'Selesai'.
- [ ] Laporan CSAT pada sisi admin dapat memunculkan rata-rata rating dan distribusi rating.
- [ ] Dibuatkan automated test (PHPUnit/Pest) yang memvalidasi keberhasilan insert rating dan aturan validasinya di backend.

### Live Monitor
- [ ] Dashboard Live Monitor berhasil membedakan status aset (Tersedia, Dipesan, Sedang Dipakai).
- [ ] Mekanisme auto-refresh (polling) terpasang di frontend sehingga data terupdate tanpa refresh manual.
- [ ] Dibuatkan automated test (PHPUnit/Pest) untuk memvalidasi logika penentuan status (Tersedia/Dipesan/Dipakai) di backend.

## Follow-up — 2026-07-13T04:32:50Z

Selesaikan implementasi fitur setting waktu SLA (Service Level Agreement) fullstack (Database Migration, Backend Controller, dan Frontend React) berdasarkan `Doc/PLAN-FASE-3.md`, karena saat ini belum bisa digunakan untuk mengatur waktu SLA dari halaman admin.

Working directory: c:\Users\LAZ AL AZHAR\Documents\Halo APU V2
Integrity mode: development

## Requirements

### R1. Database dan Backend
Implementasikan tabel `sla_configs`, model `SlaConfig` dengan scope dan static helper yang diperlukan, route admin, dan controller `Admin\SlaConfigController` untuk menangani CRUD konfigurasi SLA.

### R2. Frontend UI
Buat halaman React Inertia `Admin/SlaConfig/Index.tsx` untuk menampilkan antarmuka konfigurasi SLA (default global dan override per sub unit) yang memungkinkan admin menyimpan perubahan.

### R3. Pengujian Otomatis
Implementasikan pengujian otomatis (PHPUnit/Pest) untuk memverifikasi logika kalkulasi waktu kerja pada `SlaCalculator` dan fungsionalitas Endpoint CRUD SLA sesuai dengan "Unit Test Plan" di dalam dokumen `PLAN-FASE-3.md`.

## Acceptance Criteria

### Fungsionalitas Backend
- [ ] Migration untuk `sla_configs` berhasil dijalankan.
- [ ] Endpoint `PUT /admin/sla-config` dapat menerima request update batch tanpa error server.

### Fungsionalitas Frontend
- [ ] File `Admin/SlaConfig/Index.tsx` berhasil di-compile tanpa error.

### Pengujian Otomatis
- [ ] Script testing `tests/Unit/SlaCalculatorTest.php` berhasil ditulis.
- [ ] Test suite dapat dieksekusi dan seluruh unit test untuk SLA berhasil PASSED (`php artisan test`).
