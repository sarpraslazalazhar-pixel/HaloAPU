# Fase 1 — Completion Report

> **Proyek**: Halo APU v2 — Sistem Tiketing Internal  
> **Fase**: 1 of 5  
> **Status**: **✅ SELESAI (100%)**  
> **Tanggal**: 10 Juli 2026  
> **Tech Stack**: Laravel 13, Inertia.js 3, React 19, TypeScript, shadcn/ui, Tailwind CSS v4, MySQL

---

## Daftar Isi

- [Ringkasan](#ringkasan)
- [Struktur File Lengkap](#struktur-file-lengkap)
- [Database & Migrations](#database--migrations)
- [Models](#models)
- [Controllers & Methods](#controllers--methods)
- [Routes](#routes)
- [Seeders](#seeders)
- [React Pages](#react-pages)
- [React Components](#react-components)
- [Layouts](#layouts)
- [Hooks & Utilities](#hooks--utilities)
- [Config Files](#config-files)
- [Middleware](#middleware)
- [PLAN Checklist vs Realitas](#plan-checklist-vs-realitas)
- [Data Master](#data-master)
- [Form Fields (Peraturan Form)](#form-fields-peraturan-form)
- [Deviations from PLAN](#deviations-from-plan)

---

## Ringkasan

Fase 1 mencakup **Auth Multi-Guard, Master Data CRUD (5 modul), Peraturan Form Builder (drag-drop + conditional fields), Wizard Pengajuan Tiket (5-step), dan Riwayat Tiket (list + filter + detail + download)**. Seluruh fitur telah diimplementasikan dan berjalan di environment production-ready.

| Modul | Status | File Count |
|-------|--------|-----------|
| Setup Project (Laravel + Inertia + React + Tailwind + shadcn) | ✅ | 20+ config |
| Database (11 migrasi + Spatie permissions) | ✅ | 12 migration files |
| Models (11 model + relasi lengkap) | ✅ | 11 PHP files |
| Auth (User Login, Admin Login, Register, Lupa Password) | ✅ | 3 controllers, 5 pages |
| Master Data CRUD (Unit, SubUnit, Divisi, UnitOrganisasi, Jabatan) | ✅ | 5 controllers, 5 pages |
| Peraturan Form Builder (Index + Builder + CRUD + Reorder) | ✅ | 1 controller, 2 pages, 4+ components |
| Wizard Tiket (5-step: Data Pengaju → Pilih → Isi → Lampir → Kirim) | ✅ | 1 controller, 1 page |
| Riwayat Tiket (List + Filter + Pagination + Detail + Download) | ✅ | 1 controller, 2 pages |
| Layouts (User, Admin, Guest + responsive sidebar) | ✅ | 3 layouts |

---

## Struktur File Lengkap

```
halo-apu-v2/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   ├── AdminLoginController.php          # Login admin
│   │   │   │   ├── ForgotPasswordController.php      # Lupa password user
│   │   │   │   └── UserLoginController.php           # Login + register user
│   │   │   ├── Admin/
│   │   │   │   ├── DivisiController.php              # CRUD divisi
│   │   │   │   ├── FormFieldController.php           # Peraturan Form builder
│   │   │   │   ├── JabatanController.php             # CRUD jabatan (+reorder)
│   │   │   │   ├── SubUnitController.php             # CRUD sub unit
│   │   │   │   ├── UnitController.php                # CRUD unit
│   │   │   │   └── UnitOrganisasiController.php      # CRUD unit organisasi
│   │   │   └── User/
│   │   │       ├── TicketHistoryController.php       # Riwayat + detail + download
│   │   │       └── TicketWizardController.php        # Wizard create + store
│   │   └── Middleware/
│   │       ├── EnsureAdminAuthenticated.php          # Guard admin
│   │       ├── EnsureUserAuthenticated.php           # Guard user (not used in routes)
│   │       └── HandleInertiaRequests.php             # Share auth + flash
│   ├── Mail/
│   │   └── ResetPasswordMail.php                     # (not created — uses built-in Notification)
│   ├── Models/
│   │   ├── Admin.php                                 # + HasRoles, guard_name=admin
│   │   ├── FormField.php                             # + TIPE_FIELDS const, isUpload(), parent/child
│   │   ├── OrgDivisi.php                             # hasMany orgUnits, users
│   │   ├── OrgJabatan.php                            # (simple)
│   │   ├── OrgUnit.php                               # belongsTo divisi
│   │   ├── SubUnit.php                               # belongsTo unit, hasMany formFields, tickets
│   │   ├── Ticket.php                                # belongsTo user/unit/subUnit, hasMany attachments/logs
│   │   ├── TicketAttachment.php                      # belongsTo ticket, field
│   │   ├── TicketLog.php                             # belongsTo ticket, admin
│   │   ├── Unit.php                                  # hasMany subUnits, tickets
│   │   └── User.php                                  # + HasRoles, belongsTo divisi/orgUnit/jabatan
│   └── Notifications/
│       └── CustomResetPasswordNotification.php       # Custom reset password email
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   ├── 2026_07_09_065629_create_permission_tables.php  # Spatie
│   │   ├── 2026_07_10_000001_create_org_divisi_table.php
│   │   ├── 2026_07_10_000002_create_org_unit_table.php
│   │   ├── 2026_07_10_000003_create_org_jabatan_table.php  # + urutan column
│   │   ├── 2026_07_10_000004_create_users_table.php
│   │   ├── 2026_07_10_000005_create_admins_table.php       # + admin_password_reset_tokens
│   │   ├── 2026_07_10_000006_create_units_table.php
│   │   ├── 2026_07_10_000007_create_sub_units_table.php
│   │   ├── 2026_07_10_000008_create_form_fields_table.php  # + parent_field_id, trigger_value
│   │   ├── 2026_07_10_000009_create_tickets_table.php
│   │   ├── 2026_07_10_000010_create_ticket_attachments_table.php
│   │   └── 2026_07_10_000011_create_ticket_logs_table.php
│   └── seeders/
│       ├── DatabaseSeeder.php                       # Runner + FK disable
│       ├── OrgDivisiSeeder.php                      # 4 divisi (real)
│       ├── OrgUnitSeeder.php                        # 11 unit organisasi
│       ├── OrgJabatanSeeder.php                     # 4 jabatan + urutan
│       ├── UnitSeeder.php                           # 3 unit
│       ├── SubUnitSeeder.php                        # 17 sub unit
│       ├── FormFieldSeeder.php                      # 94 form fields
│       ├── AdminSeeder.php                          # 2 admin + roles
│       └── UserSeeder.php                           # 1 user
├── resources/
│   ├── css/
│   │   └── app.css                                  # Tailwind v4 entry
│   ├── js/
│   │   ├── app.tsx                                  # Inertia entry + ThemeProvider
│   │   ├── bootstrap.ts                             # Axios + Echo config
│   │   ├── types/index.d.ts                        # Semua TypeScript interfaces
│   │   ├── lib/utils.ts                            # cn(), formatDate(), formatCurrency()
│   │   ├── hooks/
│   │   │   ├── useDebounce.ts                      # Debounce untuk search
│   │   │   └── useDependentDropdown.ts             # Fetch dependent dropdown
│   │   ├── Components/
│   │   │   ├── ui/ (18 shadcn components)
│   │   │   ├── DataTable.tsx                       # Generic data table
│   │   │   ├── SearchInput.tsx                     # Search with debounce
│   │   │   ├── Pagination.tsx                      # Pagination component
│   │   │   ├── ConfirmDialog.tsx                   # Delete confirmation
│   │   │   ├── StatusBadge.tsx                     # Ticket status badge
│   │   │   ├── Stepper.tsx                         # Wizard stepper
│   │   │   ├── ThemeProvider.tsx                   # Dark/light mode
│   │   │   ├── ThemeToggle.tsx                     # Theme toggle button
│   │   │   ├── FormBuilder/
│   │   │   │   ├── FieldCard.tsx                   # Drag-and-drop field card
│   │   │   │   ├── FieldConfigDialog.tsx           # Add/edit field dialog
│   │   │   │   ├── FieldRenderer.tsx               # Render all 13 field types
│   │   │   │   └── FormPreview.tsx                 # Preview form values
│   │   │   └── DynamicForm/
│   │   │       ├── DynamicField.tsx                # Render + conditional logic
│   │   │       └── ConditionalField.tsx            # (standalone version)
│   │   ├── Layouts/
│   │   │   ├── UserLayout.tsx                      # User sidebar + header
│   │   │   ├── AdminLayout.tsx                     # Admin sidebar + header
│   │   │   └── GuestLayout.tsx                     # Guest (login, register)
│   │   └── Pages/
│   │       ├── Auth/
│   │       │   ├── UserLogin.tsx                   # Login user
│   │       │   ├── AdminLogin.tsx                  # Login admin
│   │       │   ├── Register.tsx                    # Info pendaftaran
│   │       │   ├── ForgotPassword.tsx              # Lupa password
│   │       │   └── ResetPassword.tsx               # Reset password form
│   │       ├── Admin/
│   │       │   ├── Dashboard/Index.tsx             # Admin dashboard
│   │       │   ├── MasterData/
│   │       │   │   ├── Unit/Index.tsx              # CRUD Unit
│   │       │   │   ├── SubUnit/Index.tsx           # CRUD Sub Unit
│   │       │   │   ├── Divisi/Index.tsx            # CRUD Divisi
│   │       │   │   ├── UnitOrganisasi/Index.tsx    # CRUD Unit Organisasi
│   │       │   │   └── Jabatan/Index.tsx           # CRUD Jabatan
│   │       │   └── PeraturanForm/
│   │       │       ├── Index.tsx                   # Daftar sub unit + link builder
│   │       │       └── Builder.tsx                 # Drag-drop form builder
│   │       └── User/
│   │           ├── Dashboard.tsx                   # User dashboard
│   │           └── Tiket/
│   │               ├── Wizard.tsx                  # 5-step wizard
│   │               ├── Riwayat.tsx                 # Daftar tiket + filter
│   │               └── Detail.tsx                  # Detail tiket + log
│   └── views/
│       └── app.blade.php                           # Inertia root template
├── routes/
│   └── web.php                                     # Semua routes (89 lines)
├── config/
│   ├── auth.php                                    # Multi-guard: web + admin
│   ├── permission.php                              # guard_name = 'admin'
│   └── app.php                                     # APP_NAME = "Halo APU"
├── vite.config.ts                                  # Laravel + React + Tailwind
└── tsconfig.json                                   # Strict TypeScript
```

---

## Database & Migrations

### Total: 12 migration files + Spatie (4 tables)

| # | File | Table | Key Columns |
|---|------|-------|-------------|
| 1 | `0001_01_01_000001` | `cache` | key, value, expiration |
| 2 | `0001_01_01_000002` | `jobs` | queue, payload, attempts |
| 3 | `2026_07_09_065629` | Spatie: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` |
| 4 | `000001` | `org_divisi` | id, nama_divisi |
| 5 | `000002` | `org_unit` | id, nama_unit_organisasi, divisi_id (FK) |
| 6 | `000003` | `org_jabatan` | id, nama_jabatan, **urutan** |
| 7 | `000004` | `users` | id, username, email, password, no_wa, divisi_id, org_unit_id, jabatan_id |
| 8 | `000005` | `admins` | id, username, email, password |
| 8b | `000005` | `admin_password_reset_tokens` | email, token, created_at |
| 9 | `000006` | `units` | id, nama_unit, deskripsi, aktif |
| 10 | `000007` | `sub_units` | id, unit_id (FK), nama_layanan, deskripsi, aktif |
| 11 | `000008` | `form_fields` | id, sub_unit_id (FK), label, tipe_field, wajib, opsi (JSON), parent_field_id (self FK), trigger_value, urutan |
| 12 | `000009` | `tickets` | id, user_id (FK), divisi_id, org_unit_id, jabatan_id, unit_id (FK), sub_unit_id (FK), form_data (JSON), status |
| 13 | `000010` | `ticket_attachments` | id, ticket_id (FK), field_id (FK), file_path, original_name, mime_type, file_size, wajib |
| 14 | `000011` | `ticket_logs` | id, ticket_id (FK), admin_id (FK), aksi, catatan, timestamp |

### Indexes
- `form_fields`: composite index `[sub_unit_id, urutan]`
- `tickets`: indexes on `status`, `user_id`, `[unit_id, sub_unit_id]`, `created_at`
- `ticket_logs`: index on `ticket_id`
- `ticket_attachments`: FK index on `ticket_id`, `field_id`

### Foreign Keys
| Source | Target | On Delete |
|--------|--------|-----------|
| org_unit.divisi_id | org_divisi.id | CASCADE |
| users.divisi_id | org_divisi.id | SET NULL |
| users.org_unit_id | org_unit.id | SET NULL |
| users.jabatan_id | org_jabatan.id | SET NULL |
| sub_units.unit_id | units.id | CASCADE |
| form_fields.sub_unit_id | sub_units.id | CASCADE |
| form_fields.parent_field_id | form_fields.id | SET NULL |
| tickets.user_id | users.id | CASCADE |
| tickets.unit_id | units.id | CASCADE |
| tickets.sub_unit_id | sub_units.id | CASCADE |
| tickets.divisi_id | org_divisi.id | SET NULL |
| tickets.org_unit_id | org_unit.id | SET NULL |
| tickets.jabatan_id | org_jabatan.id | SET NULL |
| ticket_attachments.ticket_id | tickets.id | CASCADE |
| ticket_attachments.field_id | form_fields.id | SET NULL |
| ticket_logs.ticket_id | tickets.id | CASCADE |
| ticket_logs.admin_id | admins.id | SET NULL |

---

## Models

### Total: 11 Models

| Model | Table | Traits | Key Relations |
|-------|-------|--------|---------------|
| `User` | `users` | HasFactory, Notifiable, **HasRoles** | belongsTo: divisi, orgUnit, jabatan |
| `Admin` | `admins` | Notifiable, **HasRoles** | guard_name = 'admin' |
| `Unit` | `units` | — | hasMany: subUnits, tickets |
| `SubUnit` | `sub_units` | — | belongsTo: unit; hasMany: formFields, tickets |
| `OrgDivisi` | `org_divisi` | — | hasMany: orgUnits, users |
| `OrgUnit` | `org_unit` | — | belongsTo: divisi |
| `OrgJabatan` | `org_jabatan` | — | (standalone) |
| `FormField` | `form_fields` | — | belongsTo: subUnit, parentField; hasMany: childFields; **TIPE_FIELDS** constant (13 types); **isUpload()** helper |
| `Ticket` | `tickets` | — | belongsTo: user, unit, subUnit, orgDivisi, orgUnit, jabatan; hasMany: attachments, logs |
| `TicketAttachment` | `ticket_attachments` | — | belongsTo: ticket, field |
| `TicketLog` | `ticket_logs` | — | belongsTo: ticket, admin |

### FormField TIPE_FIELDS (13 types)
```
teks_pendek, teks_panjang, angka, tanggal, waktu,
dropdown, radio, checkbox, multi_pilih, upload_gambar,
upload_file, nominal_rp, info_peraturan
```

### FormField TIPE_DENGAN_OPSI (3 types)
```
dropdown, radio, multi_pilih
```

---

## Controllers & Methods

### Total: 11 Controllers

#### Auth (3)

| Controller | Methods | Routes |
|-----------|---------|--------|
| `UserLoginController` | `showLoginForm()` (GET), `login()` (POST), `logout()` (POST), `register()` (GET) | `/login`, `/daftar`, `/logout` |
| `AdminLoginController` | `showLoginForm()` (GET), `login()` (POST), `logout()` (POST) | `/admin/login`, `/admin/logout` |
| `ForgotPasswordController` | `showForm()` (GET), `sendResetLink()` (POST), `showResetForm()` (GET), `resetPassword()` (POST) | `/lupa-password`, `/reset-password/{token}` |

#### Admin Master Data (5)

| Controller | Methods | Routes |
|-----------|---------|--------|
| `UnitController` | index, store, update, destroy | `/admin/master/unit` |
| `SubUnitController` | index, store, update, destroy | `/admin/master/sub-unit` |
| `DivisiController` | index, store, update, destroy | `/admin/master/divisi` |
| `UnitOrganisasiController` | index, store, update, destroy | `/admin/master/unit-organisasi` |
| `JabatanController` | index, store, update, destroy, **reorder** | `/admin/master/jabatan` |

#### Admin Peraturan Form (1)

| Controller | Methods | Routes |
|-----------|---------|--------|
| `FormFieldController` | index, builder, store, update, destroy, reorder | `/admin/peraturan-form` |

#### User (2)

| Controller | Methods | Routes |
|-----------|---------|--------|
| `TicketWizardController` | **create()** (GET — return divisiList, jabatanList, unitList), **store()** (POST — validate + create ticket + attachments + log) | `/tiket/buat`, `/tiket` |
| `TicketHistoryController` | **index()** (GET — paginate + filter by status/date), **show()** (GET — detail + formFields + logs), **download()** (GET — file download) | `/tiket/riwayat`, `/tiket/{ticket}`, `/tiket/download/{attachment}` |

---

## Routes

### Total: ±40 Routes

| Group | Middleware | Routes |
|-------|-----------|--------|
| **Guest** | `guest` | `GET/POST /login`, `GET /daftar`, `GET/POST /lupa-password`, `GET/POST /reset-password/{token}` |
| **Guest Admin** | `guest:admin` | `GET/POST /admin/login` |
| **User Auth** | `auth` | `POST /logout`, `GET /dashboard`, `GET/POST /tiket/*`, `GET /api/*` |
| **Admin Auth** | `auth:admin` | `POST /admin/logout`, `GET /admin/dashboard`, `CRUD /admin/master/*`, `/admin/peraturan-form/*` |

### API Endpoints (User Auth)
| Route | Method | Returns |
|-------|--------|---------|
| `/api/org-units/{divisiId}` | GET | OrgUnit by divisi_id |
| `/api/sub-units/{unitId}` | GET | SubUnit by unit_id (aktif=true) |
| `/api/form-fields/{subUnitId}` | GET | FormField by sub_unit_id (ordered by urutan) |

### Route Names Used in Frontend
```
login, register, password.request, password.email, password.reset, password.update,
logout, dashboard,
tiket.create, tiket.store, tiket.riwayat, tiket.show, tiket.download,
api.org-units, api.sub-units, api.form-fields,
admin.login, admin.logout, admin.dashboard,
admin.master.unit.*, admin.master.sub-unit.*, admin.master.divisi.*,
admin.master.unit-organisasi.*, admin.master.jabatan.*,
admin.peraturan-form.index, admin.peraturan-form.builder, admin.peraturan-form.store,
admin.peraturan-form.update, admin.peraturan-form.destroy, admin.peraturan-form.reorder
```

---

## Seeders

### Total: 9 Seeders

| Seeder | Order | Data Count | Details |
|--------|-------|-----------|---------|
| `OrgDivisiSeeder` | 1 | **4** | Divisi Keuangan, LAZ, Sekretariat, Wakaf |
| `OrgUnitSeeder` | 2 | **11** | Humas/GA/IT (Sekretariat), HRD/Legal (Sekretariat), Diklat Litbang (Sekretariat), Fundraising (LAZ), Program (LAZ), KPw (LAZ), Penyaluran & Treasury (Keuangan), Penerimaan (Keuangan), Akuntansi (Keuangan), Fundraising (Wakaf), Pengelolaan Wakaf (Wakaf) |
| `OrgJabatanSeeder` | 3 | **4** | Kepala Divisi (urutan=1), Manager (2), Koordinator (3), Staff (4) |
| `UnitSeeder` | 4 | **3** | GA (General Affair), IT (Information Technology), Humas |
| `SubUnitSeeder` | 5 | **17** | GA(7): Peminjaman Alat, Penggunaan Ruangan, Kendaraan, Pengadaan Jasa & Barang, Perbaikan & Perawatan, Perpanjang Pajak, Support SDM — IT(7): Request Akun Donasi, Data Sistem Penerimaan, Void Bukti Setor, Akun & Reset Sandi, Penggunaan Link Zoom, Maintenance HW/SW, Support SDM — Humas(3): Editing & Produksi Video, Pengajuan Desain Grafis, Dokumentasi Event/Kegiatan |
| `FormFieldSeeder` | 6 | **94** | Lihat tabel [Form Fields](#form-fields-peraturan-form) |
| `AdminSeeder` | 7 | **2+2** | superadmin (role:admin), operator1 (role:operator) — pass: `password` |
| `UserSeeder` | 8 | **1** | sarpras (email: sarpraslazalazhar@gmail.com) — pass: `password` |

### How to Run
```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

---

## Form Fields (Peraturan Form)

### Total: 94 field entries across 17 sub units

| Sub Unit | Fields | Types Used |
|----------|--------|------------|
| Peminjaman Alat | 7 | info_peraturan, multi_pilih, teks_pendek, teks_pendek, tanggal, tanggal, teks_panjang |
| Penggunaan Ruangan | 6 | dropdown, tanggal, waktu, waktu, angka, teks_panjang |
| Penggunaan Kendaraan | 9 | dropdown, teks_pendek, tanggal, waktu, waktu, angka, teks_panjang, dropdown, upload_file (**conditional**) |
| Pengadaan Jasa & Barang | 3 | teks_pendek, teks_panjang, teks_panjang |
| Perbaikan & Perawatan | 3 | teks_pendek, multi_pilih, teks_panjang |
| Perpanjang Pajak | 3 | multi_pilih, tanggal, teks_panjang |
| Support SDM (GA) | 3 | tanggal, tanggal, teks_panjang |
| Request Akun Donasi | 6 | teks_pendek, dropdown, nominal_rp, tanggal, teks_panjang, upload_file |
| Data Sistem Penerimaan | 5 | dropdown, teks_pendek (**conditional**), tanggal, tanggal, teks_panjang |
| Void Bukti Setor | 5 | teks_panjang, teks_pendek, tanggal, nominal_rp, teks_panjang |
| Akun & Reset Sandi | 4 | multi_pilih, teks_pendek, dropdown, teks_panjang |
| Penggunaan Link Zoom | 7 | info_peraturan, dropdown, tanggal, waktu, waktu, teks_pendek, teks_pendek |
| Maintenance HW/SW | 3 | dropdown, dropdown (**conditional**), dropdown (**conditional**) |
| Support SDM (IT) | 3 | dropdown, teks_pendek, teks_panjang |
| Editing & Produksi Video | 11 | teks_pendek, tanggal, dropdown, dropdown, tanggal, teks_pendek, dropdown, teks_panjang (**conditional**), teks_panjang, upload_file, teks_panjang |
| Pengajuan Desain Grafis | 13 | dropdown, tanggal, teks_pendek, dropdown, teks_pendek (**conditional**), teks_panjang, teks_panjang, upload_file, angka, dropdown, teks_pendek (**conditional**), tanggal, teks_panjang |
| Dokumentasi Event/Kegiatan | 3 | dropdown, teks_pendek, teks_panjang |

### Conditional Fields (parent-child)
| Parent Field | Child Field | Trigger Value |
|-------------|-------------|---------------|
| Keperluan Penggunaan Kendaraan | Upload SIM | "Tugas Non Dinas" |
| Jenis Video | Jenis Video Lainnya | "Lainnya" |
| Jenis Produksi Desain | Jika lainnya | "Lainnya" |
| Distribusi | Jika Lainnya | "Lainnya" |
| Jenis Data | Jika Lainnya | "Lainnya" |
| Jenis Bantuan | Hardware | "Hardware" |
| Jenis Bantuan | Software | "Software" |

---

## React Pages

### Total: 17 Pages

| Page | Route | Layout | Description |
|------|-------|--------|-------------|
| `Auth/UserLogin.tsx` | `/login` | GuestLayout | Login form user (username + password + checkbox) |
| `Auth/AdminLogin.tsx` | `/admin/login` | GuestLayout | Login form admin (desain berbeda) |
| `Auth/Register.tsx` | `/daftar` | GuestLayout | Info "Hubungi Admin" + kontak |
| `Auth/ForgotPassword.tsx` | `/lupa-password` | GuestLayout | Input email → kirim link reset |
| `Auth/ResetPassword.tsx` | `/reset-password/{token}` | GuestLayout | Input password baru + konfirmasi |
| `Admin/Dashboard/Index.tsx` | `/admin/dashboard` | AdminLayout | Admin dashboard (placeholder) |
| `Admin/MasterData/Unit/Index.tsx` | `/admin/master/unit` | AdminLayout | CRUD + search + pagination |
| `Admin/MasterData/SubUnit/Index.tsx` | `/admin/master/sub-unit` | AdminLayout | CRUD + filter by unit |
| `Admin/MasterData/Divisi/Index.tsx` | `/admin/master/divisi` | AdminLayout | CRUD sederhana |
| `Admin/MasterData/UnitOrganisasi/Index.tsx` | `/admin/master/unit-organisasi` | AdminLayout | CRUD + filter by divisi |
| `Admin/MasterData/Jabatan/Index.tsx` | `/admin/master/jabatan` | AdminLayout | CRUD + drag-reorder |
| `Admin/PeraturanForm/Index.tsx` | `/admin/peraturan-form` | AdminLayout | Daftar sub unit grouped by unit |
| `Admin/PeraturanForm/Builder.tsx` | `/admin/peraturan-form/{subUnit}/builder` | AdminLayout | Drag-drop form builder |
| `User/Dashboard.tsx` | `/dashboard` | UserLayout | User dashboard (stat cards) |
| `User/Tiket/Wizard.tsx` | `/tiket/buat` | UserLayout | 5-step wizard |
| `User/Tiket/Riwayat.tsx` | `/tiket/riwayat` | UserLayout | Daftar tiket + filter + pagination |
| `User/Tiket/Detail.tsx` | `/tiket/{ticket}` | UserLayout | Detail tiket + log + attachments |

---

## React Components

### Total: 30+ Components

#### shadcn/ui (18)
`alert`, `badge`, `button`, `card`, `checkbox`, `dialog`, `dropdown-menu`, `input`, `label`, `radio-group`, `select`, `separator`, `sheet`, `table`, `textarea`, `toast`, `toaster`, `tooltip`

#### Shared (8)
| Component | Props | Description |
|-----------|-------|-------------|
| `DataTable` | columns, data, meta | Generic table with sorting |
| `SearchInput` | value, onChange, placeholder | Search with debounce |
| `Pagination` | meta, onPageChange | Page navigation |
| `ConfirmDialog` | open, title, message, onConfirm, onCancel | Delete confirmation |
| `StatusBadge` | status | Colored ticket status badge |
| `Stepper` | steps (string[] or {label,description}[]), activeStep | Horizontal stepper |
| `ThemeProvider` | children | Dark/light mode context |
| `ThemeToggle` | — | Toggle button (sun/moon) |

#### FormBuilder (4)
| Component | Props | Description |
|-----------|-------|-------------|
| `FieldCard` | field, index, onEdit, onDelete, dragHandle | Sortable field card |
| `FieldConfigDialog` | open, field, subUnitId, allFields, onSave, onClose | Add/edit field dialog |
| `FieldRenderer` | field, value, onChange, errors | Render single field by type |
| `FormPreview` | fields, values | Read-only preview |

#### DynamicForm (2)
| Component | Props | Description |
|-----------|-------|-------------|
| `DynamicField` | fields, values, onChange, errors | Render visible fields + conditional logic |
| `ConditionalField` | field, value, onChange, errors | Standalone conditional field |

---

## Layouts

| Layout | Sidebar Links | Used By |
|--------|---------------|---------|
| `UserLayout` | Dashboard, Buat Tiket, Riwayat Tiket | User pages |
| `AdminLayout` | Dashboard, Unit, Sub Unit, Divisi, Unit Organisasi, Jabatan, Peraturan Form | Admin pages |
| `GuestLayout` | None (centered card layout) | Login, Register, Forgot/Reset Password |

---

## Hooks & Utilities

| File | Exports | Description |
|------|---------|-------------|
| `hooks/useDebounce.ts` | `useDebounce<T>(value, delay)` | Debounce hook for search |
| `hooks/useDependentDropdown.ts` | `useDependentDropdown(baseUrl)` | Fetch dropdown options by parent ID |
| `lib/utils.ts` | `cn()`, `formatDate()`, `formatCurrency()` | Class name merger + formatters |

---

## Config Files

| File | Key Settings |
|------|-------------|
| `config/auth.php` | guards: web (User), admin (Admin); providers: users, admins |
| `config/permission.php` | guard_name = 'admin' |
| `vite.config.ts` | laravel plugin + react + tailwindcss + @ alias |
| `tsconfig.json` | strict mode, paths: @ → resources/js |
| `.env` | DB_HOST=127.0.0.1, DB_DATABASE=HaloAPU, DB_USERNAME=root |

---

## Middleware

| File | Purpose |
|------|---------|
| `EnsureUserAuthenticated` | Redirect to `/login` if not authenticated as web guard |
| `EnsureAdminAuthenticated` | Redirect to `/admin/login` if not authenticated as admin guard |
| `HandleInertiaRequests` | Share `auth.user`, `auth.admin`, `flash.success`, `flash.error` |

---

## PLAN Checklist vs Realitas

### Config Files
| Item | Status | Notes |
|------|--------|-------|
| `config/auth.php` — multi-guard | ✅ | web + admin |
| `config/permission.php` — guard_name = 'admin' | ✅ | |
| `vite.config.ts` | ✅ | Laravel + React + Tailwind |
| `tsconfig.json` | ✅ | strict, paths |
| `.env` | ✅ | DB + app config |
| `resources/views/app.blade.php` | ✅ | + @routes |

### Migrations
| Item | Status |
|------|--------|
| `create_org_divisi_table` | ✅ |
| `create_org_unit_table` | ✅ |
| `create_org_jabatan_table` | ✅ (+ urutan column beyond PLAN) |
| `create_users_table` | ✅ |
| `create_admins_table` | ✅ (+ admin_password_reset_tokens inline) |
| `create_units_table` | ✅ |
| `create_sub_units_table` | ✅ |
| `create_form_fields_table` | ✅ |
| `create_tickets_table` | ✅ |
| `create_ticket_attachments_table` | ✅ |
| `create_ticket_logs_table` | ✅ |
| Spatie permission tables | ✅ |

### Seeders
| Item | Status |
|------|--------|
| `DatabaseSeeder` | ✅ (+ FK check disable, UserSeeder) |
| `OrgDivisiSeeder` | ✅ (data real: 4 divisi) |
| `OrgUnitSeeder` | ✅ (data real: 11 unit) |
| `OrgJabatanSeeder` | ✅ (data real: 4 jabatan + urutan) |
| `UnitSeeder` | ✅ (3 unit) |
| `SubUnitSeeder` | ✅ (data real: 17 sub unit) |
| `AdminSeeder` | ✅ (2 admin + 2 roles) |
| `FormFieldSeeder` | ✅ (94 fields) — **beyond PLAN** |
| `UserSeeder` | ✅ (1 user) — **beyond PLAN** |

### Mailables
| Item | Status | Notes |
|------|--------|-------|
| `ResetPasswordMail` | ❌ (file) ✅ (fungsi) | Menggunakan built-in Laravel Notification via `CustomResetPasswordNotification` |

### TypeScript Types
| Item | Status |
|------|--------|
| `types/index.d.ts` — Semua interface | ✅ (User, Admin, Unit, SubUnit, OrgDivisi, OrgUnit, OrgJabatan, FormField, Ticket, TicketAttachment, TicketLog, PaginatedData, PageProps) |

### Hooks
| Item | Status |
|------|--------|
| `hooks/useDependentDropdown.ts` | ✅ |
| `hooks/useDebounce.ts` | ✅ (beyond PLAN — used for search) |

### Utilities
| Item | Status |
|------|--------|
| `lib/utils.ts` — cn() | ✅ (+ formatDate, formatCurrency) |

### UI Components (shadcn)
| Item | Status |
|------|--------|
| button, card, dialog, dropdown-menu, input, label, select, table, textarea, badge, separator, sheet, toast, toaster, tooltip, checkbox, radio-group, alert | ✅ **18 components** |

### Shared Components
| Item | Status |
|------|--------|
| DataTable | ✅ |
| SearchInput | ✅ |
| Pagination | ✅ |
| ConfirmDialog | ✅ |
| StatusBadge | ✅ |
| Stepper | ✅ (+ {label,description}[] support) |
| ThemeProvider | ✅ |
| ThemeToggle | ✅ |

### FormBuilder Components
| Item | Status |
|------|--------|
| FieldCard | ✅ (with dnd-kit drag handle) |
| FieldConfigDialog | ✅ |
| FieldRenderer | ✅ (13 field types + multi_pilih + info_peraturan) |
| FormPreview | ✅ |
| **SortableField** | ❌ (tidak dibuat — FieldCard handle sorting langsung) |

### DynamicForm Components
| Item | Status |
|------|--------|
| DynamicField | ✅ (conditional field logic) |
| ConditionalField | ✅ |

### Layouts
| Item | Status |
|------|--------|
| UserLayout | ✅ (sidebar: Dashboard, Buat Tiket, Riwayat) |
| AdminLayout | ✅ (sidebar: Dashboard, 5 Master Data, Peraturan Form) |
| GuestLayout | ✅ (centered card for auth pages) |
| Sidebar (separate file) | ❌ (inline di AdminLayout/UserLayout) |
| Header (separate file) | ❌ (inline di AdminLayout/UserLayout) |

### Pages — Auth
| Item | Status |
|------|--------|
| Auth/UserLogin | ✅ |
| Auth/AdminLogin | ✅ |
| Auth/Register | ✅ |
| Auth/ForgotPassword | ✅ |
| Auth/ResetPassword | ✅ |

### Pages — Admin Master Data
| Item | Status |
|------|--------|
| Unit/Index | ✅ (CRUD + search + pagination) |
| SubUnit/Index | ✅ (CRUD + filter by unit) |
| Divisi/Index | ✅ (CRUD) |
| UnitOrganisasi/Index | ✅ (CRUD + filter by divisi) |
| Jabatan/Index | ✅ (CRUD + drag-reorder) |

### Pages — Admin Peraturan Form
| Item | Status |
|------|--------|
| Index | ✅ (list sub unit grouped by unit) |
| Builder | ✅ (drag-drop form builder + conditional fields) |

### Pages — User
| Item | Status |
|------|--------|
| Dashboard | ✅ (stat cards) |
| Wizard | ✅ (5-step: Data Pengaju → Pilih Layanan → Isi Form → Lampiran → Review & Kirim) |
| Riwayat | ✅ (list + filter by status/date + pagination) |
| Detail | ✅ (ticket info + form values + attachment list + log timeline) |

---

## Data Master

### Divisi (4)
```
1. Divisi Keuangan
2. Divisi LAZ
3. Divisi Sekretariat
4. Divisi Wakaf
```

### Unit Organisasi (11)
| Divisi | Unit Organisasi |
|--------|----------------|
| Sekretariat | Humas, GA & IT, HRD & Legal, Diklat Litbang |
| LAZ | Fundraising, Program, KPw |
| Keuangan | Penyaluran & Treasury, Penerimaan, Akuntansi |
| Wakaf | Fundraising, Pengelolaan Wakaf |

### Jabatan (4)
```
1. Kepala Divisi (urutan 1)
2. Manager (urutan 2)
3. Koordinator (urutan 3)
4. Staff (urutan 4)
```

### Unit Layanan (3)
```
1. GA (General Affair)
2. IT (Information Technology)
3. Humas
```

### Sub Unit (17)
| Unit | Sub Unit |
|------|----------|
| GA | Peminjaman Alat, Penggunaan Ruangan, Penggunaan Kendaraan, Pengadaan Jasa & Barang, Perbaikan & Perawatan, Perpanjang Pajak, Support SDM |
| IT | Request Akun Donasi, Data Sistem Penerimaan, Void Bukti Setor, Akun & Reset Sandi, Penggunaan Link Zoom, Maintenance Hardware & Software, Support SDM |
| Humas | Editing & Produksi Video, Pengajuan Desain Grafis, Dokumentasi Event/Kegiatan |

### Form Fields (94)
Tersebar di 17 sub unit — mencakup 13 tipe field dengan 7 conditional parent-child relationships.

### Akun Default
| Role | Username | Email | Password |
|------|----------|-------|----------|
| Superadmin | superadmin | admin@haloapu.test | password |
| Operator | operator1 | operator1@haloapu.test | password |
| User | sarpras | sarpraslazalazhar@gmail.com | password |

---

## Deviations from PLAN

### Intentional Changes

| Item | PLAN Asli | Realitas | Alasan |
|------|-----------|----------|--------|
| **User Dashboard Controller** | `User\DashboardController` class | Closure di `routes/web.php` | Halaman sederhana, tidak perlu controller terpisah |
| **Middleware aliases** | `auth.user`, `auth.admin` | `auth` (web), `auth:admin` (admin guard) | Tidak perlu alias — Laravel built-in sudah cukup |
| **Route name `login.submit`** | `name('login.submit')` | `name('login')` | Inertia default menggunakan `login` |
| **`SortableField.tsx`** | File terpisah | Tidak dibuat — logika ada di `FieldCard` | FieldCard handle drag handle langsung |
| **`ResetPasswordMail.php`** | File Mail terpisah | `CustomResetPasswordNotification` | Laravel Notification system lebih praktis |
| **`useTheme.ts` hook** | File terpisah | Logic di dalam `ThemeProvider.tsx` | Context + hook dalam satu file lebih rapi |
| **Sidebar/Header files** | File terpisah | Inline di `AdminLayout`/`UserLayout` | Layout spesifik, tidak perlu dipisah |
| **Seeder data** | Dummy data (Umum, SDM, etc.) | Data real LAZ | User request — sesuai database aktual |
| **`urutan` column jabatan** | Tidak ada | Ditambahkan ke migration | Untuk drag-reorder fitur |
| **User HasRoles trait** | Tidak ada | Ditambahkan | Untuk future-proof jika user也需要role |

### Missing from PLAN (New Additions)
- `FormFieldSeeder.php` (94 fields) — tidak ada di PLAN section 2.13, dibuat untuk deployment
- `UserSeeder.php` — tidak ada di PLAN, dibuat untuk user demo
- `useDebounce.ts` hook — dibuat untuk search optimization
- `alert.tsx` shadcn component — tidak ada di PLAN list tapi dibuat

### Fully Implemented (no gaps)
Semua fitur di Section 3-7 PLAN sudah 100% implemented:
- ✅ Auth (3.1-3.5)
- ✅ Master Data CRUD (4.1-4.5)
- ✅ Peraturan Form Builder (5.1-5.4)
- ✅ Wizard Tiket (6.1-6.4)
- ✅ Riwayat Tiket (7.1-7.3)

---

## Catatan Tambahan

### Wizard Tiket — 5 Steps
```
Step 1: Data Pengaju    → Dropdown Divisi, Unit Organisasi (dependent), Jabatan
Step 2: Pilih Layanan   → Dropdown Unit, Sub Unit (dependent) → fetch form fields
Step 3: Isi Form        → DynamicField render non-upload fields + conditional logic
Step 4: Lampiran        → Upload file per field (preview gambar/file + hapus)
Step 5: Review & Kirim  → 4 summary cards + error display + submit button
```

### Riwayat Tiket — Features
- Pagination (10 per page)
- Filter by status (open, on_proses, pending, solve, reject)
- Filter by date range (date_from, date_to)
- Detail page with form values mapped by FormField labels
- Attachment download with authorization check
- Log timeline (aksi + catatan + timestamp + admin name)

### Peraturan Form Builder — Features
- Drag-and-drop field reordering (dnd-kit)
- 13 field types support
- Conditional field logic (parent + trigger value)
- JSON options for dropdown/radio/multi_pilih
- Field grouping by sub unit
- Sub unit list grouped by unit on index page

---

*Report generated: 10 Juli 2026*
