# Fase 2 — Completion Report

> **Proyek**: Halo APU v2 — Sistem Tiketing Internal
> **Fase**: 2 of 5
> **Status**: **✅ SELESAI (100%)**
> **Tanggal**: 10 Juli 2026
> **Tech Stack**: Laravel 13, Inertia.js 3, React 19, TypeScript, shadcn/ui, Tailwind CSS v4, MySQL, Recharts

---

## Daftar Isi

- [Ringkasan](#ringkasan)
- [Struktur File Baru/Diubah](#struktur-file-baruubah)
- [Controllers & Methods](#controllers--methods)
- [Routes](#routes)
- [React Pages](#react-pages)
- [React Components](#react-components)
- [Bugs Fixed During Development](#bugs-fixed-during-development)
- [PLAN Checklist vs Realitas](#plan-checklist-vs-realitas)
- [Deviations from PLAN](#deviations-from-plan)
- [Catatan Tambahan](#catatan-tambahan)

---

## Ringkasan

Fase 2 mencakup **Modul Tiketing Admin** (list, filter, detail, status transitions) dan **Dashboard Admin** (stat cards, follow-up tickets, top users, 3 grafik Recharts dengan filter). Seluruh fitur telah diimplementasikan dan build berhasil.

| Modul | Status | File Count |
|-------|--------|-----------|
| Admin Tiketing — List (DataTable + filter multi-kriteria) | ✅ | 1 controller, 1 page |
| Admin Tiketing — Detail (form data mapped ke label + lampiran + timeline) | ✅ | 1 controller, 1 page |
| Admin Tiketing — Status Transitions (validasi rule + catatan wajib) | ✅ | 1 controller method |
| Dashboard — Stat Cards (5 status) | ✅ | 1 controller method |
| Dashboard — Follow-Up Tickets | ✅ | (bonus, not in PLAN) |
| Dashboard — Top 5 Users | ✅ | 1 query |
| Dashboard — Grafik Bulanan per Unit (stacked bar, Recharts) | ✅ | 1 component |
| Dashboard — Grafik Tahunan per Unit (toggle) | ✅ | 1 component (reused) |
| Dashboard — Grafik per Sub Unit (horizontal bar, dropdown unit) | ✅ | 1 component |
| Dashboard — Filter periode (bulan/tahun) | ✅ | 2 selects |

---

## Struktur File Baru/Diubah

```
halo-apu-v2/
├── app/
│   └── Http/
│       └── Controllers/
│           └── Admin/
│               ├── TicketController.php          # NEW — index, show, updateStatus, downloadAttachment
│               └── DashboardController.php       # NEW — index (stats + charts + top users)
├── resources/
│   └── js/
│       ├── Components/
│       │   ├── Charts/
│       │   │   ├── MonthlyUnitChart.tsx          # NEW — stacked bar chart (xKey prop added later)
│       │   │   └── SubUnitChart.tsx              # NEW — horizontal bar chart
│       │   ├── ui/
│       │   │   └── date-range-picker.tsx         # NEW — DateRangePicker component
│       │   └── StatusBadge.tsx                   # MODIFIED — Pending color: orange→grey
│       └── Pages/
│           └── Admin/
│               ├── Tiketing/
│               │   ├── Index.tsx                 # NEW — DataTable + filters
│               │   └── Detail.tsx                # NEW — form data + attachments + timeline + status form
│               └── Dashboard/
│                   └── Index.tsx                 # MODIFIED — added charts, toggle, filter
├── routes/
│   └── web.php                                  # MODIFIED — added 4 admin tiket routes + dashboard
└── package.json                                 # MODIFIED — recharts added
```

---

## Controllers & Methods

### Admin Tiket — `TicketController.php`

| Method | Route | Description |
|--------|-------|-------------|
| `index(Request)` | `GET /admin/tiket` | List tiket (paginate 10) + filter: unit_id, sub_unit_id, status (multi), date_from, date_to, divisi_id, org_unit_id |
| `show(Ticket)` | `GET /admin/tiket/{ticket}` | Detail tiket: eager load user+divisi+orgUnit+jabatan, unit+subUnit, attachments+field, logs+admin |
| `updateStatus(Request, Ticket)` | `PATCH /admin/tiket/{ticket}/status` | Validasi transisi status + catatan wajib → update tickets + create ticket_logs |
| `downloadAttachment(TicketAttachment)` | `GET /admin/tiket/download/{attachment}` | Download file dari storage/public |

### Admin Dashboard — `DashboardController.php`

| Method | Route | Description |
|--------|-------|-------------|
| `index(Request)` | `GET /admin/dashboard` | 5 stat cards (open, on_proses, pending, solve, reject), top 5 users, follow-up tickets (open+pending), monthly stacked bar chart (by year filter), yearly chart (all years), sub-unit chart per unit + aggregate (`_all`) |

### Data Dikirim ke Frontend (Dashboard)

| Key | Type | Description |
|-----|------|-------------|
| `totalTickets` | int | Jumlah total tiket (sesuai filter) |
| `statusCounts` | object | `{open, on_proses, pending, solve, reject}` |
| `topUsers` | array | Top 5 users: `[{id, username, nama_divisi, total_tiket}]` |
| `followUpTickets` | array | 10 tiket open/pending terbaru dengan relasi user.divisi, unit, subUnit |
| `monthlyChartData` | array | Per bulan: `[{bulan, GA, IT, Humas}]` — hanya jika tahun dipilih |
| `yearlyChartData` | array | Per tahun: `[{tahun, GA, IT, Humas}]` — semua tahun |
| `subUnitChartData` | array | Per unit: `{unit_id: [{name, value}], _all: [{name, value}]}` |
| `units` | array | Semua unit aktif untuk dropdown |
| `filters` | object | `{month, year}` |

### Status Transitions

```
Open       → on_proses, reject, pending
On Proses  → solve, pending
Pending    → on_proses
```

---

## Routes

### Admin Tiketing Routes (prefix `/admin/tiket`, name prefix `admin.tiket.`)

| Method | URI | Name | Controller Method |
|--------|-----|------|-------------------|
| GET | `/admin/tiket` | `admin.tiket.index` | `index` |
| GET | `/admin/tiket/download/{attachment}` | `admin.tiket.download` | `downloadAttachment` |
| GET | `/admin/tiket/{ticket}` | `admin.tiket.show` | `show` |
| PATCH | `/admin/tiket/{ticket}/status` | `admin.tiket.status` | `updateStatus` |

**Catatan penting**: Route download diletakkan SEBELUM route `{ticket}` untuk mencegah Laravel menginterpretasi "download" sebagai ID tiket.

### Admin Dashboard Route

| Method | URI | Name | Controller Method |
|--------|-----|------|-------------------|
| GET | `/admin/dashboard` | `admin.dashboard` | `DashboardController@index` |

---

## React Pages

### 1. `Admin/Tiketing/Index.tsx`

**Features**:
- **DataTable** dengan 6 kolom: ID Tiket, Tgl Pengajuan, Pengaju (Nama + Divisi), Layanan (Unit + Sub Unit), Status (StatusBadge), Aksi (Tombol Detail)
- **Filter bar** (grid 4 kolom):
  - Unit dropdown → Sub Unit dropdown (dependent fetch via `/api/sub-units/{unitId}`)
  - Status multi-select (5 checkboxes)
  - Date Range Picker (`<input type="date">` pair wrapped in `DateRangePicker` component)
  - Divisi dropdown
  - Unit Organisasi dropdown
- **"Terapkan Filter" button** — tidak auto-filter on change
- **Pagination** via `Pagination` component

**State management**:
```
unitId, subUnitId, selectedStatuses[], dateFrom, dateTo, divisiId, orgUnitId
```

### 2. `Admin/Tiketing/Detail.tsx`

**Layout** (2-column grid):
- **Kiri (2/3)**: Data Pengaju card, Data Pengajuan card (form_data matched ke FormField labels via `renderFormValue`), Timeline/Log card
- **Kanan (1/3)**: Aksi Status form, Lampiran list

**Data Pengaju**:
| Field | Source |
|-------|--------|
| Nama | `ticket.user.username` |
| Email | `ticket.user.email` |
| No WA | `ticket.user.no_wa` |
| Divisi | `ticket.org_divisi.nama_divisi` |
| Unit Organisasi | `ticket.org_unit.nama_unit_organisasi` |
| Jabatan | `ticket.jabatan.nama_jabatan` |

**Data Pengajuan**: Iterate `formFields`, render value dari `form_data` via helper function `renderFormValue` yang handle semua tipe field (termasuk array untuk checkbox/multi_pilih, nominal_rp dengan format currency, upload dengan nama file).

**Timeline/Log**: Iterate `ticket.logs` descending by timestamp, tampilkan:
- Badge status (warna sesuai status aksi)
- Catatan
- Admin name + timestamp

**Aksi Status**: Form dengan:
- Dropdown status tujuan (hanya transisi valid)
- Textarea catatan (required, max 1000)
- Submit button

**Lampiran**: List file dengan link download langsung (route `admin.tiket.download`)

### 3. `Admin/Dashboard/Index.tsx`

**Layout**:
- **Header**: 2 selects (Bulan + Tahun) + "Terapkan" button
- **5 Stat Cards** (grid 5 kolom): Open (blue), On Proses (yellow), Pending (grey), Selesai (green), Ditolak (red)
- **Row 2** (grid 2 kolom):
  - Kiri: **Tiket Perlu Ditindak Lanjuti** — list 10 open/pending terbaru dengan navigasi detail
  - Kanan: **Top Pengaju Tiket** — 5 users dengan peringkat + jumlah tiket
- **Row 3** (grid 2 kolom):
  - Kiri: **Grafik Bulanan/Tahunan** — toggle button (Bulanan/Tahunan), stacked bar chart via `MonthlyUnitChart`
  - Kanan: **Grafik per Sub Unit** — dropdown unit selector, horizontal bar chart via `SubUnitChart`
- **SLA Placeholder** card (border dashed)

**Filter behavior**:
- Default: "Semua Bulan" + "Semua Tahun" → menampilkan **semua data** (tidak difilter)
- Pilih bulan/tahun → filter diterapkan sesuai periode
- Data grafik dan stat cards merespon filter yang sama

---

## React Components

### Charts (Recharts)

| Component | Location | Description |
|-----------|----------|-------------|
| `MonthlyUnitChart` | `Components/Charts/MonthlyUnitChart.tsx` | Stacked bar chart, `xKey` prop (default `'bulan'`, bisa `'tahun'`), series otomatis dari key selain xKey, warna dari COLORS array |
| `SubUnitChart` | `Components/Charts/SubUnitChart.tsx` | Horizontal bar chart, layout="vertical", single bar dataKey="value", radius kanan |

### New UI Component

| Component | Location | Description |
|-----------|----------|-------------|
| `DateRangePicker` | `Components/ui/date-range-picker.tsx` | Wrapper 2 `<input type="date">` dengan label "Tanggal:" dan separator "-", style border rounded |

### Modified Components

| Component | Change |
|-----------|--------|
| `StatusBadge` | Pending color dari `bg-orange-100 text-orange-700` → `bg-gray-100 text-gray-700` (abu-abu sesuai PLAN) |

---

## Bugs Fixed During Development

| # | Bug | Root Cause | Fix |
|---|-----|------------|-----|
| 1 | Route `/admin/tiket/download/{attachment}` conflict dengan `{ticket}` | Route order — download ditangkap sebagai ID tiket | Pindahkan route download SEBELUM route `{ticket}` |
| 2 | `useForm` gagal kirim PATCH ke `/admin/tiket/{id}/status` | Inertia `useForm` perlu method POST dengan `_method: 'PATCH'` | Ganti `patch()` ke `post()` + `_method: 'PATCH'` |
| 3 | `User` model akses `orgDivisi` vs `divisi` | Relasi User bernama `divisi` (bukan `orgDivisi`) | Ubah eager load dari `user.orgDivisi` ke `user.divisi` |
| 4 | Conditional fields gagal validasi di server | Logika validasi hanya untuk field parent (tanpa parent) | Filter field dengan `is_null('parent_field_id')` untuk validasi required; validasi conditional dilakukan setelah form_data lengkap |
| 5 | `form_data` jadi nested object saat ada upload file | FormData mengubah form_data array jadi string "[object Object]" | Wizard handleSubmit gunakan `transform()` untuk `JSON.stringify(form_data)` sebelum append ke FormData; server decode `json_decode($data['form_data'], true)` |
| 6 | Enter key submit form sebelum wizard selesai | Form submit default behavior | Guard di `handleSubmit`: jika `activeStep !== STEPS.length - 1` maka return prevent submit |
| 7 | `renderFormValue` render "undefined" untuk falsy values (0, empty string) | `value || '-'` menganggap 0 sebagai falsy | Ganti ke `value ?? '-'` |

---

## PLAN Checklist vs Realitas

### Modul Tiketing Admin

| Item | Status | Notes |
|------|--------|-------|
| Route `GET /admin/tiket` — TicketController@index | ✅ | |
| DataTable list tiket | ✅ | 6 kolom sesuai PLAN |
| Badge: Open=Biru, On Proses=Kuning, Pending=Abu-abu, Solve=Hijau, Reject=Merah | ✅ | Pending diperbaiki dari orange ke abu-abu |
| Filter Unit (dropdown) | ✅ | |
| Filter Sub Unit (dependent) | ✅ | Fetch via `/api/sub-units/{unitId}` |
| Filter Status (multi-select) | ✅ | 5 checkboxes |
| Filter Rentang Tanggal (Date Range Picker) | ✅ | `DateRangePicker` component |
| Filter Divisi Pengaju | ✅ | |
| Filter Unit Organisasi Pengaju | ✅ | |
| Tombol "Terapkan Filter" (no auto-filter) | ✅ | |
| Route `GET /admin/tiket/{id}` — show | ✅ | |
| Data Pengaju: Nama, Email, No WA, Divisi, Unit Organisasi, Jabatan | ✅ | |
| Data Pengajuan: form_data mapped ke FormField labels | ✅ | `renderFormValue` helper handle semua tipe |
| Lampiran: list file + download link | ✅ | |
| Timeline/Log: status + catatan + admin name + timestamp | ✅ | |
| Form Aksi Status | ✅ | |
| Route `PATCH /admin/tiket/{id}/status` — updateStatus | ✅ | |
| Transisi: Open→On Proses/Reject/Pending | ✅ | |
| Transisi: On Proses→Solve/Pending | ✅ | |
| Transisi: Pending→On Proses | ✅ | |
| Catatan Admin (textarea, required) | ✅ | validation: `required\|string\|max:1000` |
| Simpan ke tickets + ticket_logs | ✅ | |

### Dashboard Admin

| Item | Status | Notes |
|------|--------|-------|
| Route `GET /admin/dashboard` | ✅ | |
| Filter Bulan + Tahun | ✅ | Default "Semua" (tidak difilter) |
| 5 Stat Cards (Open, On Proses, Pending, Solve, Reject) | ✅ | Merespon filter periode |
| Grafik Bulanan per Unit (Bar chart, X=month, series=unit) | ✅ | Stacked bar, hanya jika tahun dipilih |
| Grafik Tahunan per Unit (toggle) | ✅ | Toggle Bulanan/Tahunan, agregasi semua tahun |
| Grafik per Sub Unit (Bar chart, dropdown unit) | ✅ | Horizontal bar, default semua unit (`_all`) |
| Top 5 Users (Nama, Divisi, Jumlah) | ✅ | |
| SLA Kepatuhan placeholder | ✅ | Card dashed border |

---

## Deviations from PLAN

### Intentional Changes

| Item | PLAN Asli | Realitas | Alasan |
|------|-----------|----------|--------|
| **Date Range Picker** | Third-party date range picker component | `DateRangePicker` wrapper 2 native `<input type="date">` | Tidak perlu dependensi tambahan; native date input sudah cukup untuk use case ini |
| **Dashboard filter default** | Current month/year | "Semua Bulan" + "Semua Tahun" (tampilkan semua data) | User request — ingin melihat gambaran lengkap tanpa filter |
| **Sub Unit chart default** | Harus pilih unit | Tampilkan `_all` aggregate semua unit jika tidak ada unit dipilih | User request — ingin melihat distribusi semua sub unit |
| **Tiket Perlu Ditindak Lanjuti** | Tidak ada di PLAN | Card bonus di dashboard | Berguna untuk admin memprioritaskan tiket open/pending |
| **Grafik Tahunan** | Card terpisah | Toggle dalam satu card (Bulanan/Tahunan) | Hemat space, UX lebih bersih |

### Fixed During Review

| Item | Sebelum | Sesudah |
|------|---------|---------|
| `StatusBadge` Pending color | Orange (`bg-orange-100`) | Abu-abu (`bg-gray-100`) — sesuai PLAN |
| Date filter | 2 `<input type="date">` terpisah | `DateRangePicker` component terintegrasi |

### Fully Implemented (no gaps)

Semua fitur di PLAN-FASE-2 sudah 100% implemented:
- ✅ Modul Tiketing Admin (List, Filter, Detail, Status)
- ✅ Dashboard Admin (Stats, Grafik, Top Users, SLA placeholder)

---

## Catatan Tambahan

### Admin Tiketing — Key UI/UX
- Route download attachment ditempatkan SEBELUM route `{ticket}` untuk mencegah conflict routing
- `updateStatus` menggunakan method PATCH via `_method: 'PATCH'` dalam POST form (Inertia workaround)
- Eager load `user.divisi` (bukan `user.orgDivisi`) sesuai nama relasi di model User
- Pagination: 10 tiket per halaman dengan `withQueryString()` untuk mempertahankan filter

### Dashboard — Data Logic
- Filter bulan+tahun diterapkan pada: `statusCounts`, `topUsers`, `subUnitChartData`
- Filter bulan+tahun **tidak** diterapkan pada: `yearlyChartData` (selalu semua tahun), `followUpTickets` (selalu open/pending terbaru)
- `monthlyChartData` hanya dihitung jika tahun dipilih (array kosong jika tidak)
- Sub unit chart punya key `_all` yang berisi agregasi semua unit untuk mode "Semua Unit"
- Grafik menggunakan Recharts `stackId="a"` untuk stacked bar dan `layout="vertical"` untuk horizontal bar

### Package.json Changes
```json
"dependencies": {
    "recharts": "^2.x"  // NEW
}
```

---

*Report generated: 10 Juli 2026*
