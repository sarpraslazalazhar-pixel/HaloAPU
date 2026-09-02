# Plan Fase 2 — Modul Tiketing Admin & Dashboard Admin Dasar

## 1. Modul Tiketing Admin
### A. Tampilan List Tiket (`Admin/Tiketing/Index.tsx`)
- **Route**: `GET /admin/tiket`
- **Controller**: `Admin\TicketController@index`
- **Komponen Utama**:
  - `DataTable` untuk menampilkan list tiket dari semua user.
  - Kolom tabel: ID Tiket, Tgl Pengajuan, Pengaju (Nama, Divisi), Layanan (Unit, Sub Unit), Status, Aksi (Tombol Detail).
  - **Badge Status**: 
    - Open = Biru
    - On Proses = Kuning
    - Pending = Abu-abu
    - Solve = Hijau
    - Reject = Merah

### B. Filter & Pencarian
- Filter di atas DataTable dengan tombol **"Terapkan Filter"** (jangan auto-filter saat on-change untuk mengurangi request).
- Opsi filter:
  - Unit (Dropdown, fetch dari master data)
  - Sub Unit (Dropdown dependent ke Unit terpilih)
  - Status (Multi-select)
  - Rentang Tanggal (Date Range Picker)
  - Divisi Pengaju (Dropdown)
  - Unit Organisasi Pengaju (Dropdown)

### C. Halaman Detail Tiket (`Admin/Tiketing/Detail.tsx`)
- **Route**: `GET /admin/tiket/{id}`
- **Controller**: `Admin\TicketController@show`
- **Konten Halaman**:
  - **Data Pengaju**: Nama, email, no WA, divisi, unit organisasi, jabatan.
  - **Data Pengajuan**: Tampilkan seluruh isian form dinamis berdasarkan `form_data` (JSON) di tiket.
  - **Lampiran**: List file yang diupload user, berikan link untuk download/view.
  - **Timeline/Log**: Riwayat perubahan status (dari `ticket_logs`), misalnya kapan tiket diubah menjadi "On Proses" dan oleh siapa, beserta catatannya.

### D. Eksekusi Status Tiket (Aksi Admin)
- Di halaman detail, ada Form Aksi untuk mengubah status tiket.
- **Route**: `PATCH /admin/tiket/{id}/status`
- **Controller**: `Admin\TicketController@updateStatus`
- **Aturan Transisi Status**:
  - **Open** → bisa diubah ke **On Proses**, **Reject**, atau **Pending**.
  - **On Proses** → bisa diubah ke **Solve** atau **Pending**.
  - **Pending** → bisa diubah ke **On Proses**.
- Setiap perubahan status WAJIB mengisi **Catatan Admin** (textarea).
- Simpan perubahan status di tabel `tickets` dan rekam aksi + catatan di tabel `ticket_logs`.

## 2. Dashboard Admin Dasar
### A. Tampilan Dashboard (`Admin/Dashboard/Index.tsx`)
- **Route**: `GET /admin/dashboard`
- **Controller**: `Admin\DashboardController@index`
- **Filter Dashboard**: Rentang bulan dan tahun.

### B. Statistik Status Tiket (Card Metrics)
- 5 Kartu angka utama menampilkan total tiket dengan status:
  - Open
  - On Proses
  - Pending
  - Solve
  - Reject
- Menghitung berdasarkan filter periode yang dipilih.

### C. Grafik Statistik (Menggunakan Recharts)
- **Grafik Bulanan per Unit**: 
  - Tipe: Bar Chart atau Line Chart.
  - X-Axis: Bulan (Jan, Feb, ...).
  - Series/Legend: Unit (GA, IT, Humas).
- **Grafik Tahunan per Unit**:
  - Mirip dengan grafik bulanan tapi aggregasi tahunan.
- **Grafik per Sub Unit**:
  - Tipe: Bar Chart.
  - User dapat memilih Unit dari dropdown, grafik akan menampilkan distribusi tiket per Sub Unit di bawah Unit tersebut.

### D. Ranking Top User
- Tabel sederhana di Dashboard menampilkan 5 user yang paling banyak mengajukan tiket pada periode terkait.
- Kolom: Nama User, Divisi, Jumlah Tiket.

### E. Kepatuhan SLA (Persiapan Fase 3)
- Buat placeholder card kosong untuk Modul SLA ("Kepatuhan SLA - Akan diimplementasi di Fase 3").

## 3. File-by-File Checklist
- [x] `app/Http/Controllers/Admin/TicketController.php`
- [x] `app/Http/Controllers/Admin/DashboardController.php`
- [x] `resources/js/Pages/Admin/Tiketing/Index.tsx`
- [x] `resources/js/Pages/Admin/Tiketing/Detail.tsx`
- [x] `resources/js/Pages/Admin/Dashboard/Index.tsx`
- [x] `resources/js/Components/Charts/MonthlyUnitChart.tsx`
- [x] `resources/js/Components/Charts/SubUnitChart.tsx`
- [x] `routes/web.php` (menambahkan route `/admin/tiket`, `/admin/tiket/{id}`, `/admin/tiket/{id}/status`, `/admin/dashboard`)
