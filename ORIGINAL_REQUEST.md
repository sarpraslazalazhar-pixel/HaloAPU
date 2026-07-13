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
