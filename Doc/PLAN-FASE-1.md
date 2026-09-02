# 📋 PLAN FASE 1 — Halo APU v2
## Auth, Master Data, Peraturan Form, Wizard Pengajuan Tiket, Riwayat Tiket

| Metadata | Detail |
|---|---|
| **Proyek** | Halo APU v2 — Sistem Tiketing Internal |
| **Fase** | 1 of 5 |
| **Cakupan** | Setup, Auth, Master Data, Form Builder, Wizard Tiket, Riwayat Tiket |
| **Tech Stack** | Laravel 13, Inertia.js, React, TypeScript, shadcn/ui, Tailwind CSS, MySQL |
| **Tanggal** | 9 Juli 2026 |

---

## Daftar Isi

1. [Setup Project](#1-setup-project)
2. [Database & Migration](#2-database--migration)
3. [Modul Autentikasi](#3-modul-autentikasi)
4. [Master Data (Admin Only)](#4-master-data-admin-only)
5. [Peraturan Form (Form Builder)](#5-peraturan-form-form-builder)
6. [Wizard Pengajuan Tiket (User)](#6-wizard-pengajuan-tiket-user)
7. [Riwayat Tiket (User)](#7-riwayat-tiket-user)
8. [Layout & Navigasi](#8-layout--navigasi)
9. [File-by-File Checklist](#9-file-by-file-checklist)

---

## 1. Setup Project

### 1.1 Inisialisasi Laravel 13

```bash
# 1. Buat project Laravel 13 baru
composer create-project laravel/laravel halo-apu-v2 "^13.0"
cd halo-apu-v2

# 2. Install Inertia.js server-side
composer require inertiajs/inertia-laravel

# 3. Install spatie/laravel-permission
composer require spatie/laravel-permission

# 4. Publish config spatie
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# 5. Install Inertia.js client-side + React + TypeScript
npm install @inertiajs/react react react-dom
npm install -D @types/react @types/react-dom @vitejs/plugin-react typescript

# 6. Install Tailwind CSS
npm install -D tailwindcss @tailwindcss/vite

# 7. Install shadcn/ui
npx shadcn@latest init

# 8. Install @dnd-kit untuk Form Builder
npm install @dnd-kit/core @dnd-kit/sortable @dnd-kit/utilities

# 9. Install Recharts (untuk Fase 2, tapi install sekarang)
npm install recharts

# 10. Install Lucide React icons
npm install lucide-react

# 11. Install dependencies tambahan
npm install @hookform/resolvers zod react-hook-form
npm install -D @types/node
```

### 1.2 Konfigurasi Vite

**`vite.config.ts`**:
```typescript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});
```

### 1.3 Konfigurasi TypeScript

**`tsconfig.json`**:
```json
{
    "compilerOptions": {
        "target": "ESNext",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "jsx": "react-jsx",
        "strict": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "forceConsistentCasingInFileNames": true,
        "baseUrl": ".",
        "paths": {
            "@/*": ["resources/js/*"]
        }
    },
    "include": ["resources/js/**/*.ts", "resources/js/**/*.tsx"]
}
```

### 1.4 Entry Point React

**`resources/js/app.tsx`**:
```typescript
import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeProvider } from '@/Components/ThemeProvider';

const appName = import.meta.env.VITE_APP_NAME || 'Halo APU';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx')
        ),
    setup({ el, App, props }) {
        const root = createRoot(el!);
        root.render(
            <ThemeProvider>
                <App {...props} />
            </ThemeProvider>
        );
    },
    progress: {
        color: '#2563eb', // blue-600
    },
});
```

### 1.5 Setup Multi-Auth Guard

**`config/auth.php`** (LENGKAP):
```php
<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'admins' => [
            'provider' => 'admins',
            'table' => 'admin_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
```

### 1.6 Konfigurasi Database (.env)

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=halo_apu_v2
DB_USERNAME=root
DB_PASSWORD=

APP_NAME="Halo APU"
APP_URL=http://localhost:8000

FILESYSTEM_DISK=public
```

### 1.7 Setup Inertia Middleware

**`app/Http/Middleware/HandleInertiaRequests.php`** — sudah ada dari package, tambahkan shared data:
```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'auth' => [
            'user' => $request->user(),
            'admin' => $request->user('admin'),
        ],
        'flash' => [
            'success' => fn () => $request->session()->get('success'),
            'error' => fn () => $request->session()->get('error'),
        ],
    ];
}
```

### 1.8 Struktur Folder Project

```
halo-apu-v2/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   ├── UserLoginController.php
│   │   │   │   ├── AdminLoginController.php
│   │   │   │   └── ForgotPasswordController.php
│   │   │   ├── Admin/
│   │   │   │   ├── UnitController.php
│   │   │   │   ├── SubUnitController.php
│   │   │   │   ├── DivisiController.php
│   │   │   │   ├── UnitOrganisasiController.php
│   │   │   │   ├── JabatanController.php
│   │   │   │   ├── FormFieldController.php
│   │   │   │   ├── TicketController.php          # Fase 2
│   │   │   │   └── DashboardController.php       # Fase 2
│   │   │   └── User/
│   │   │       ├── TicketWizardController.php
│   │   │       ├── TicketHistoryController.php
│   │   │       └── DashboardController.php
│   │   └── Middleware/
│   │       ├── HandleInertiaRequests.php
│   │       ├── EnsureUserAuthenticated.php
│   │       └── EnsureAdminAuthenticated.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Admin.php
│   │   ├── Unit.php
│   │   ├── SubUnit.php
│   │   ├── OrgDivisi.php
│   │   ├── OrgUnit.php
│   │   ├── OrgJabatan.php
│   │   ├── FormField.php
│   │   ├── Ticket.php
│   │   ├── TicketAttachment.php
│   │   └── TicketLog.php
│   └── Mail/
│       └── ResetPasswordMail.php
├── database/
│   ├── migrations/
│   │   ├── 2026_07_10_000001_create_org_divisi_table.php
│   │   ├── 2026_07_10_000002_create_org_unit_table.php
│   │   ├── 2026_07_10_000003_create_org_jabatan_table.php
│   │   ├── 2026_07_10_000004_create_users_table.php
│   │   ├── 2026_07_10_000005_create_admins_table.php
│   │   ├── 2026_07_10_000006_create_units_table.php
│   │   ├── 2026_07_10_000007_create_sub_units_table.php
│   │   ├── 2026_07_10_000008_create_form_fields_table.php
│   │   ├── 2026_07_10_000009_create_tickets_table.php
│   │   ├── 2026_07_10_000010_create_ticket_attachments_table.php
│   │   ├── 2026_07_10_000011_create_ticket_logs_table.php
│   │   └── 2026_07_10_000012_create_admin_password_reset_tokens_table.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       ├── OrgDivisiSeeder.php
│       ├── OrgUnitSeeder.php
│       ├── OrgJabatanSeeder.php
│       ├── UnitSeeder.php
│       ├── SubUnitSeeder.php
│       └── AdminSeeder.php
├── resources/
│   ├── css/
│   │   └── app.css
│   ├── js/
│   │   ├── app.tsx
│   │   ├── bootstrap.ts
│   │   ├── types/
│   │   │   └── index.d.ts
│   │   ├── lib/
│   │   │   └── utils.ts
│   │   ├── hooks/
│   │   │   ├── useTheme.ts
│   │   │   └── useDependentDropdown.ts
│   │   ├── Components/
│   │   │   ├── ui/              # shadcn/ui components
│   │   │   │   ├── button.tsx
│   │   │   │   ├── card.tsx
│   │   │   │   ├── dialog.tsx
│   │   │   │   ├── dropdown-menu.tsx
│   │   │   │   ├── input.tsx
│   │   │   │   ├── label.tsx
│   │   │   │   ├── select.tsx
│   │   │   │   ├── table.tsx
│   │   │   │   ├── textarea.tsx
│   │   │   │   ├── badge.tsx
│   │   │   │   ├── separator.tsx
│   │   │   │   ├── sheet.tsx
│   │   │   │   ├── toast.tsx
│   │   │   │   ├── toaster.tsx
│   │   │   │   └── tooltip.tsx
│   │   │   ├── DataTable.tsx
│   │   │   ├── SearchInput.tsx
│   │   │   ├── Pagination.tsx
│   │   │   ├── ConfirmDialog.tsx
│   │   │   ├── StatusBadge.tsx
│   │   │   ├── Stepper.tsx
│   │   │   ├── ThemeProvider.tsx
│   │   │   ├── ThemeToggle.tsx
│   │   │   ├── FormBuilder/
│   │   │   │   ├── FieldCard.tsx
│   │   │   │   ├── FieldConfigDialog.tsx
│   │   │   │   ├── FieldRenderer.tsx
│   │   │   │   ├── FormPreview.tsx
│   │   │   │   └── SortableField.tsx
│   │   │   └── DynamicForm/
│   │   │       ├── DynamicField.tsx
│   │   │       └── ConditionalField.tsx
│   │   ├── Layouts/
│   │   │   ├── UserLayout.tsx
│   │   │   ├── AdminLayout.tsx
│   │   │   ├── GuestLayout.tsx
│   │   │   ├── Sidebar.tsx
│   │   │   └── Header.tsx
│   │   └── Pages/
│   │       ├── Auth/
│   │       │   ├── UserLogin.tsx
│   │       │   ├── AdminLogin.tsx
│   │       │   ├── ForgotPassword.tsx
│   │       │   └── Register.tsx
│   │       ├── Admin/
│   │       │   ├── MasterData/
│   │       │   │   ├── Unit/
│   │       │   │   │   └── Index.tsx
│   │       │   │   ├── SubUnit/
│   │       │   │   │   └── Index.tsx
│   │       │   │   ├── Divisi/
│   │       │   │   │   └── Index.tsx
│   │       │   │   ├── UnitOrganisasi/
│   │       │   │   │   └── Index.tsx
│   │       │   │   └── Jabatan/
│   │       │   │       └── Index.tsx
│   │       │   └── PeraturanForm/
│   │       │       ├── Index.tsx
│   │       │       └── Builder.tsx
│   │       └── User/
│   │           ├── Dashboard.tsx
│   │           └── Tiket/
│   │               ├── Wizard.tsx
│   │               ├── Riwayat.tsx
│   │               └── Detail.tsx
│   └── views/
│       └── app.blade.php
├── routes/
│   └── web.php
├── vite.config.ts
├── tsconfig.json
└── tailwind.config.js (jika diperlukan)
```

### 1.9 Blade Root Template

**`resources/views/app.blade.php`**:
```blade
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Halo APU') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
```

---

## 2. Database & Migration

### 2.1 Migration: `create_org_divisi_table`

**File**: `2026_07_10_000001_create_org_divisi_table.php`

```php
Schema::create('org_divisi', function (Blueprint $table) {
    $table->id();
    $table->string('nama_divisi', 100);
    $table->timestamps();
});
```

### 2.2 Migration: `create_org_unit_table`

**File**: `2026_07_10_000002_create_org_unit_table.php`

```php
Schema::create('org_unit', function (Blueprint $table) {
    $table->id();
    $table->string('nama_unit_organisasi', 150);
    $table->foreignId('divisi_id')->constrained('org_divisi')->cascadeOnDelete();
    $table->timestamps();
});
```

### 2.3 Migration: `create_org_jabatan_table`

**File**: `2026_07_10_000003_create_org_jabatan_table.php`

```php
Schema::create('org_jabatan', function (Blueprint $table) {
    $table->id();
    $table->string('nama_jabatan', 100);
    $table->timestamps();
});
```

### 2.4 Migration: `create_users_table`

**File**: `2026_07_10_000004_create_users_table.php`

> **Catatan**: Hapus/replace migration default Laravel.

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('username', 50)->unique();
    $table->string('email')->unique();
    $table->string('password');
    $table->string('no_wa', 20)->nullable();
    $table->foreignId('divisi_id')->nullable()->constrained('org_divisi')->nullOnDelete();
    $table->foreignId('org_unit_id')->nullable()->constrained('org_unit')->nullOnDelete();
    $table->foreignId('jabatan_id')->nullable()->constrained('org_jabatan')->nullOnDelete();
    $table->rememberToken();
    $table->timestamps();
});

Schema::create('password_reset_tokens', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->string('token');
    $table->timestamp('created_at')->nullable();
});

Schema::create('sessions', function (Blueprint $table) {
    $table->string('id')->primary();
    $table->foreignId('user_id')->nullable()->index();
    $table->string('ip_address', 45)->nullable();
    $table->text('user_agent')->nullable();
    $table->longText('payload');
    $table->integer('last_activity')->index();
});
```

### 2.5 Migration: `create_admins_table`

**File**: `2026_07_10_000005_create_admins_table.php`

```php
Schema::create('admins', function (Blueprint $table) {
    $table->id();
    $table->string('username', 50)->unique();
    $table->string('email')->unique();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();
});

Schema::create('admin_password_reset_tokens', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->string('token');
    $table->timestamp('created_at')->nullable();
});
```

### 2.6 Migration: `create_units_table`

**File**: `2026_07_10_000006_create_units_table.php`

```php
Schema::create('units', function (Blueprint $table) {
    $table->id();
    $table->string('nama_unit', 100);
    $table->text('deskripsi')->nullable();
    $table->boolean('aktif')->default(true);
    $table->timestamps();
});
```

### 2.7 Migration: `create_sub_units_table`

**File**: `2026_07_10_000007_create_sub_units_table.php`

```php
Schema::create('sub_units', function (Blueprint $table) {
    $table->id();
    $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
    $table->string('nama_layanan', 150);
    $table->text('deskripsi')->nullable();
    $table->boolean('aktif')->default(true);
    $table->timestamps();
});
```

### 2.8 Migration: `create_form_fields_table`

**File**: `2026_07_10_000008_create_form_fields_table.php`

```php
Schema::create('form_fields', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sub_unit_id')->constrained('sub_units')->cascadeOnDelete();
    $table->string('label', 255);
    $table->string('tipe_field', 30);
    // Enum values: teks_pendek, teks_panjang, angka, tanggal, waktu,
    // dropdown, radio, checkbox, multi_pilih, upload_gambar,
    // upload_file, nominal_rp, info_peraturan
    $table->boolean('wajib')->default(false);
    $table->json('opsi')->nullable();
    // Contoh: ["Opsi A", "Opsi B", "Opsi C"] untuk dropdown/radio/multi_pilih
    $table->foreignId('parent_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
    $table->string('trigger_value', 255)->nullable();
    // Jika parent_field_id != null, field ini hanya muncul ketika parent field == trigger_value
    $table->unsignedInteger('urutan')->default(0);
    $table->timestamps();

    $table->index(['sub_unit_id', 'urutan']);
});
```

### 2.9 Migration: `create_tickets_table`

**File**: `2026_07_10_000009_create_tickets_table.php`

```php
Schema::create('tickets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('divisi_id')->nullable()->constrained('org_divisi')->nullOnDelete();
    $table->foreignId('org_unit_id')->nullable()->constrained('org_unit')->nullOnDelete();
    $table->foreignId('jabatan_id')->nullable()->constrained('org_jabatan')->nullOnDelete();
    $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
    $table->foreignId('sub_unit_id')->constrained('sub_units')->cascadeOnDelete();
    $table->json('form_data');
    // Struktur: { "field_id_1": "value1", "field_id_2": ["val_a", "val_b"], ... }
    $table->string('status', 20)->default('open');
    // Values: open, on_proses, pending, solve, reject
    $table->timestamps();

    $table->index('status');
    $table->index('user_id');
    $table->index(['unit_id', 'sub_unit_id']);
    $table->index('created_at');
});
```

### 2.10 Migration: `create_ticket_attachments_table`

**File**: `2026_07_10_000010_create_ticket_attachments_table.php`

```php
Schema::create('ticket_attachments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
    $table->foreignId('field_id')->nullable()->constrained('form_fields')->nullOnDelete();
    $table->string('file_path', 500);
    $table->string('original_name', 255);
    $table->string('mime_type', 100)->nullable();
    $table->unsignedBigInteger('file_size')->nullable(); // bytes
    $table->boolean('wajib')->default(false);
    $table->timestamps();
});
```

### 2.11 Migration: `create_ticket_logs_table`

**File**: `2026_07_10_000011_create_ticket_logs_table.php`

```php
Schema::create('ticket_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
    $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
    $table->string('aksi', 50);
    // Values: dibuat, open, on_proses, pending, solve, reject
    $table->text('catatan')->nullable();
    $table->timestamp('timestamp')->useCurrent();

    $table->index('ticket_id');
});
```

### 2.12 Spatie Permission Tables

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

Spatie akan membuat tabel: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.

**Config `config/permission.php`** — ubah guard default:
```php
'guard_name' => 'admin', // karena role hanya untuk admin
```

### 2.13 Seeders

#### `OrgDivisiSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\OrgDivisi;
use Illuminate\Database\Seeder;

class OrgDivisiSeeder extends Seeder
{
    public function run(): void
    {
        $divisi = [
            ['nama_divisi' => 'Divisi Umum'],
            ['nama_divisi' => 'Divisi Keuangan'],
            ['nama_divisi' => 'Divisi SDM'],
            ['nama_divisi' => 'Divisi Operasional'],
            ['nama_divisi' => 'Divisi Pemasaran'],
        ];

        foreach ($divisi as $d) {
            OrgDivisi::create($d);
        }
    }
}
```

#### `OrgUnitSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\OrgDivisi;
use Illuminate\Database\Seeder;

class OrgUnitSeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            'Divisi Umum' => [
                'Bagian Tata Usaha',
                'Bagian Rumah Tangga',
                'Bagian Protokol',
            ],
            'Divisi Keuangan' => [
                'Bagian Anggaran',
                'Bagian Akuntansi',
                'Bagian Perbendaharaan',
            ],
            'Divisi SDM' => [
                'Bagian Rekrutmen',
                'Bagian Pengembangan',
                'Bagian Kesejahteraan',
            ],
            'Divisi Operasional' => [
                'Bagian Produksi',
                'Bagian Logistik',
                'Bagian Distribusi',
            ],
            'Divisi Pemasaran' => [
                'Bagian Digital Marketing',
                'Bagian Hubungan Pelanggan',
            ],
        ];

        foreach ($mapping as $divisiNama => $units) {
            $divisi = OrgDivisi::where('nama_divisi', $divisiNama)->first();
            if ($divisi) {
                foreach ($units as $unitNama) {
                    OrgUnit::create([
                        'nama_unit_organisasi' => $unitNama,
                        'divisi_id' => $divisi->id,
                    ]);
                }
            }
        }
    }
}
```

#### `OrgJabatanSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\OrgJabatan;
use Illuminate\Database\Seeder;

class OrgJabatanSeeder extends Seeder
{
    public function run(): void
    {
        $jabatan = [
            ['nama_jabatan' => 'Staff'],
            ['nama_jabatan' => 'Kepala Seksi'],
            ['nama_jabatan' => 'Kepala Bagian'],
            ['nama_jabatan' => 'Kepala Divisi'],
            ['nama_jabatan' => 'Direktur'],
            ['nama_jabatan' => 'Komisaris'],
        ];

        foreach ($jabatan as $j) {
            OrgJabatan::create($j);
        }
    }
}
```

#### `UnitSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            [
                'nama_unit' => 'GA (General Affair)',
                'deskripsi' => 'Menangani urusan umum seperti ruangan, kendaraan, dan aset perusahaan',
                'aktif' => true,
            ],
            [
                'nama_unit' => 'IT (Information Technology)',
                'deskripsi' => 'Menangani urusan teknologi informasi, jaringan, dan perangkat',
                'aktif' => true,
            ],
            [
                'nama_unit' => 'Humas',
                'deskripsi' => 'Menangani urusan hubungan masyarakat dan komunikasi',
                'aktif' => true,
            ],
        ];

        foreach ($units as $unit) {
            Unit::create($unit);
        }
    }
}
```

#### `SubUnitSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class SubUnitSeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            'GA (General Affair)' => [
                ['nama_layanan' => 'Peminjaman Ruang Rapat', 'deskripsi' => 'Pengajuan peminjaman ruang rapat'],
                ['nama_layanan' => 'Peminjaman Kendaraan Dinas', 'deskripsi' => 'Pengajuan peminjaman kendaraan operasional'],
                ['nama_layanan' => 'Permintaan ATK', 'deskripsi' => 'Permintaan alat tulis kantor'],
                ['nama_layanan' => 'Perbaikan Fasilitas', 'deskripsi' => 'Laporan kerusakan fasilitas kantor'],
            ],
            'IT (Information Technology)' => [
                ['nama_layanan' => 'Reset Password', 'deskripsi' => 'Permintaan reset password akun'],
                ['nama_layanan' => 'Instalasi Software', 'deskripsi' => 'Permintaan instalasi software baru'],
                ['nama_layanan' => 'Gangguan Jaringan', 'deskripsi' => 'Laporan gangguan jaringan/internet'],
                ['nama_layanan' => 'Permintaan Akses Sistem', 'deskripsi' => 'Permintaan akses ke sistem tertentu'],
                ['nama_layanan' => 'Perbaikan Hardware', 'deskripsi' => 'Laporan kerusakan perangkat keras'],
            ],
            'Humas' => [
                ['nama_layanan' => 'Permintaan Publikasi', 'deskripsi' => 'Permintaan publikasi di media internal'],
                ['nama_layanan' => 'Permintaan Dokumentasi', 'deskripsi' => 'Permintaan dokumentasi kegiatan'],
                ['nama_layanan' => 'Permintaan Desain', 'deskripsi' => 'Permintaan desain grafis'],
            ],
        ];

        foreach ($mapping as $unitNama => $subUnits) {
            $unit = Unit::where('nama_unit', $unitNama)->first();
            if ($unit) {
                foreach ($subUnits as $subUnit) {
                    SubUnit::create(array_merge($subUnit, [
                        'unit_id' => $unit->id,
                        'aktif' => true,
                    ]));
                }
            }
        }
    }
}
```

#### `AdminSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Buat role
        Role::create(['name' => 'admin', 'guard_name' => 'admin']);
        Role::create(['name' => 'operator', 'guard_name' => 'admin']);

        // Buat superadmin
        $admin = Admin::create([
            'username' => 'superadmin',
            'email' => 'admin@haloapu.test',
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('admin');

        // Buat operator contoh
        $operator = Admin::create([
            'username' => 'operator1',
            'email' => 'operator1@haloapu.test',
            'password' => Hash::make('password'),
        ]);
        $operator->assignRole('operator');
    }
}
```

#### `DatabaseSeeder.php`
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OrgDivisiSeeder::class,
            OrgUnitSeeder::class,
            OrgJabatanSeeder::class,
            UnitSeeder::class,
            SubUnitSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
```

### 2.14 Jalankan Migration & Seeder

```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

---

## 3. Modul Autentikasi

### 3.1 Login User

#### Routes
```php
// routes/web.php
Route::middleware('guest')->group(function () {
    Route::get('/login', [UserLoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [UserLoginController::class, 'login'])->name('login.submit');
    Route::get('/daftar', [UserLoginController::class, 'register'])->name('register');
});

Route::middleware('auth:web')->group(function () {
    Route::post('/logout', [UserLoginController::class, 'logout'])->name('logout');
});
```

#### Controller: `Auth\UserLoginController`

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class UserLoginController extends Controller
{
    public function showLoginForm()
    {
        return Inertia::render('Auth/UserLogin');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        if (Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'username' => 'Username atau password salah.',
        ])->onlyInput('username');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }

    public function register()
    {
        return Inertia::render('Auth/Register');
        // Halaman ini hanya menampilkan pesan "Hubungi Admin untuk didaftarkan"
    }
}
```

#### React Page: `Auth/UserLogin.tsx`

```typescript
// Inertia Props: (tidak ada props khusus, hanya errors dari flash)
// Props type:
interface UserLoginProps {
    errors: {
        username?: string;
        password?: string;
    };
}

// Komponen:
// - Form dengan field: username (Input), password (Input type="password")
// - Checkbox "Ingat Saya"
// - Tombol "Masuk"
// - Link ke /daftar ("Belum punya akun?")
// - Link ke /lupa-password ("Lupa password?")
// - Logo Halo APU di atas form
// - Gunakan GuestLayout
```

### 3.2 Login Admin

#### Routes
```php
// routes/web.php
Route::prefix('admin')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('admin.login');
        Route::post('/login', [AdminLoginController::class, 'login'])->name('admin.login.submit');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');
    });
});
```

#### Controller: `Auth\AdminLoginController`

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AdminLoginController extends Controller
{
    public function showLoginForm()
    {
        return Inertia::render('Auth/AdminLogin');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        if (Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('/admin/dashboard');
        }

        return back()->withErrors([
            'username' => 'Username atau password salah.',
        ])->onlyInput('username');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/admin/login');
    }
}
```

#### React Page: `Auth/AdminLogin.tsx`

```typescript
// Inertia Props:
interface AdminLoginProps {
    errors: {
        username?: string;
        password?: string;
    };
}

// Komponen:
// - Form dengan field: username, password
// - Checkbox "Ingat Saya"
// - Tombol "Masuk sebagai Admin"
// - Badge "Panel Admin" di atas form
// - Desain sedikit berbeda dari user login (warna header lebih gelap)
// - Gunakan GuestLayout
```

### 3.3 Lupa Password

#### Routes
```php
Route::middleware('guest')->group(function () {
    Route::get('/lupa-password', [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/lupa-password', [ForgotPasswordController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])->name('password.update');
});
```

#### Controller: `Auth\ForgotPasswordController`

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ForgotPasswordController extends Controller
{
    public function showForm()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? back()->with('success', 'Link reset password telah dikirim ke email Anda.')
            : back()->withErrors(['email' => 'Gagal mengirim link reset.']);
    }

    public function showResetForm(string $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));
                $user->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect('/login')->with('success', 'Password berhasil direset.')
            : back()->withErrors(['email' => 'Gagal mereset password.']);
    }
}
```

**Flow Lupa Password**:
1. User klik "Lupa password?" → `GET /lupa-password`
2. User masukkan email → `POST /lupa-password` → kirim email dengan token
3. User klik link di email → `GET /reset-password/{token}`
4. User masukkan password baru → `POST /reset-password` → redirect ke login

### 3.4 Halaman Daftar (Redirect)

**React Page: `Auth/Register.tsx`**:
```typescript
// Halaman sederhana menampilkan:
// - Icon info
// - Judul: "Pendaftaran Akun"
// - Pesan: "Untuk mendaftar akun Halo APU, silakan hubungi Admin."
// - Kontak: "Email: admin@haloapu.test"
// - Tombol: "Kembali ke Login" → link ke /login
```

### 3.5 Middleware

#### `EnsureUserAuthenticated.php`
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureUserAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('web')->check()) {
            return redirect()->route('login');
        }
        return $next($request);
    }
}
```

#### `EnsureAdminAuthenticated.php`
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAdminAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('admin')->check()) {
            return redirect()->route('admin.login');
        }
        return $next($request);
    }
}
```

#### Registrasi Middleware di `bootstrap/app.php`
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);

    $middleware->alias([
        'auth.user' => \App\Http\Middleware\EnsureUserAuthenticated::class,
        'auth.admin' => \App\Http\Middleware\EnsureAdminAuthenticated::class,
        'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    ]);
})
```

---

## 4. Master Data (Admin Only)

> Semua route Master Data berada di prefix `/admin/master` dengan middleware `auth.admin`.

### 4.0 Route Definitions

```php
// routes/web.php — di dalam group admin + auth.admin
Route::prefix('admin')->middleware('auth.admin')->group(function () {

    // Master Data
    Route::prefix('master')->group(function () {
        Route::resource('unit', Admin\UnitController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('admin.master.unit');

        Route::resource('sub-unit', Admin\SubUnitController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('admin.master.sub-unit');

        Route::resource('divisi', Admin\DivisiController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('admin.master.divisi');

        Route::resource('unit-organisasi', Admin\UnitOrganisasiController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('admin.master.unit-organisasi');

        Route::resource('jabatan', Admin\JabatanController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('admin.master.jabatan');
    });
});
```

### 4.1 CRUD Unit

#### Model: `Unit`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = ['nama_unit', 'deskripsi', 'aktif'];

    protected $casts = [
        'aktif' => 'boolean',
    ];

    public function subUnits(): HasMany
    {
        return $this->hasMany(SubUnit::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
```

#### Controller: `Admin\UnitController`
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $query = Unit::withCount('subUnits');

        if ($request->has('search')) {
            $query->where('nama_unit', 'like', '%' . $request->search . '%');
        }

        $units = $query->orderBy('nama_unit')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/Unit/Index', [
            'units' => $units,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_unit' => 'required|string|max:100|unique:units,nama_unit',
            'deskripsi' => 'nullable|string|max:500',
            'aktif' => 'boolean',
        ]);

        Unit::create($validated);

        return redirect()->back()->with('success', 'Unit berhasil ditambahkan.');
    }

    public function update(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'nama_unit' => 'required|string|max:100|unique:units,nama_unit,' . $unit->id,
            'deskripsi' => 'nullable|string|max:500',
            'aktif' => 'boolean',
        ]);

        $unit->update($validated);

        return redirect()->back()->with('success', 'Unit berhasil diperbarui.');
    }

    public function destroy(Unit $unit)
    {
        // Cek apakah unit punya sub unit atau tiket
        if ($unit->subUnits()->count() > 0) {
            return redirect()->back()->with('error', 'Unit tidak bisa dihapus karena masih memiliki Sub Unit.');
        }

        if ($unit->tickets()->count() > 0) {
            return redirect()->back()->with('error', 'Unit tidak bisa dihapus karena masih memiliki Tiket.');
        }

        $unit->delete();

        return redirect()->back()->with('success', 'Unit berhasil dihapus.');
    }
}
```

#### React Page: `Admin/MasterData/Unit/Index.tsx`
```typescript
// Props (Inertia):
interface UnitIndexProps {
    units: PaginatedData<Unit>;
    filters: {
        search?: string;
    };
}

interface Unit {
    id: number;
    nama_unit: string;
    deskripsi: string | null;
    aktif: boolean;
    sub_units_count: number;
    created_at: string;
}

// Komponen pada halaman:
// 1. Header: "Kelola Unit" + tombol "Tambah Unit" → buka CreateDialog
// 2. SearchInput: filter pencarian nama unit
// 3. DataTable columns:
//    - No (index)
//    - Nama Unit
//    - Deskripsi (truncated)
//    - Status (Badge: Aktif/Nonaktif)
//    - Jumlah Sub Unit
//    - Aksi (Edit, Hapus)
// 4. Pagination
// 5. CreateDialog / EditDialog (shadcn Dialog):
//    - Input: Nama Unit (required)
//    - Textarea: Deskripsi
//    - Switch: Aktif
//    - Tombol: Simpan / Batal
// 6. ConfirmDialog untuk hapus
```

### 4.2 CRUD Sub Unit

#### Model: `SubUnit`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubUnit extends Model
{
    protected $fillable = ['unit_id', 'nama_layanan', 'deskripsi', 'aktif'];

    protected $casts = [
        'aktif' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function formFields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('urutan');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
```

#### Controller: `Admin\SubUnitController`
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SubUnitController extends Controller
{
    public function index(Request $request)
    {
        $query = SubUnit::with('unit')->withCount('formFields');

        if ($request->has('search')) {
            $query->where('nama_layanan', 'like', '%' . $request->search . '%');
        }

        if ($request->has('unit_id') && $request->unit_id) {
            $query->where('unit_id', $request->unit_id);
        }

        $subUnits = $query->orderBy('nama_layanan')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/SubUnit/Index', [
            'subUnits' => $subUnits,
            'units' => Unit::where('aktif', true)->orderBy('nama_unit')->get(),
            'filters' => $request->only('search', 'unit_id'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'nama_layanan' => 'required|string|max:150',
            'deskripsi' => 'nullable|string|max:500',
            'aktif' => 'boolean',
        ]);

        SubUnit::create($validated);

        return redirect()->back()->with('success', 'Sub Unit berhasil ditambahkan.');
    }

    public function update(Request $request, SubUnit $subUnit)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'nama_layanan' => 'required|string|max:150',
            'deskripsi' => 'nullable|string|max:500',
            'aktif' => 'boolean',
        ]);

        $subUnit->update($validated);

        return redirect()->back()->with('success', 'Sub Unit berhasil diperbarui.');
    }

    public function destroy(SubUnit $subUnit)
    {
        if ($subUnit->formFields()->count() > 0) {
            return redirect()->back()->with('error', 'Sub Unit tidak bisa dihapus karena masih memiliki Form Field.');
        }

        if ($subUnit->tickets()->count() > 0) {
            return redirect()->back()->with('error', 'Sub Unit tidak bisa dihapus karena masih memiliki Tiket.');
        }

        $subUnit->delete();

        return redirect()->back()->with('success', 'Sub Unit berhasil dihapus.');
    }
}
```

#### React Page: `Admin/MasterData/SubUnit/Index.tsx`
```typescript
// Props (Inertia):
interface SubUnitIndexProps {
    subUnits: PaginatedData<SubUnit>;
    units: Unit[];
    filters: {
        search?: string;
        unit_id?: number;
    };
}

interface SubUnit {
    id: number;
    unit_id: number;
    nama_layanan: string;
    deskripsi: string | null;
    aktif: boolean;
    unit: Unit;
    form_fields_count: number;
}

// Komponen:
// 1. Header: "Kelola Sub Unit / Layanan" + tombol "Tambah Sub Unit"
// 2. Filter: SearchInput + Dropdown filter by Unit
// 3. DataTable columns: No, Nama Layanan, Unit (dari relasi), Status, Jml Form Field, Aksi
// 4. Dialog create/edit: Dropdown Unit, Input Nama Layanan, Textarea Deskripsi, Switch Aktif
```

### 4.3 CRUD Divisi

#### Model: `OrgDivisi`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgDivisi extends Model
{
    protected $table = 'org_divisi';

    protected $fillable = ['nama_divisi'];

    public function orgUnits(): HasMany
    {
        return $this->hasMany(OrgUnit::class, 'divisi_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'divisi_id');
    }
}
```

#### Controller: `Admin\DivisiController`
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgDivisi;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DivisiController extends Controller
{
    public function index(Request $request)
    {
        $query = OrgDivisi::withCount('orgUnits');

        if ($request->has('search')) {
            $query->where('nama_divisi', 'like', '%' . $request->search . '%');
        }

        $divisi = $query->orderBy('nama_divisi')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/Divisi/Index', [
            'divisi' => $divisi,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_divisi' => 'required|string|max:100|unique:org_divisi,nama_divisi',
        ]);

        OrgDivisi::create($validated);

        return redirect()->back()->with('success', 'Divisi berhasil ditambahkan.');
    }

    public function update(Request $request, OrgDivisi $divisi)
    {
        $validated = $request->validate([
            'nama_divisi' => 'required|string|max:100|unique:org_divisi,nama_divisi,' . $divisi->id,
        ]);

        $divisi->update($validated);

        return redirect()->back()->with('success', 'Divisi berhasil diperbarui.');
    }

    public function destroy(OrgDivisi $divisi)
    {
        if ($divisi->orgUnits()->count() > 0) {
            return redirect()->back()->with('error', 'Divisi tidak bisa dihapus karena masih memiliki Unit Organisasi.');
        }

        $divisi->delete();

        return redirect()->back()->with('success', 'Divisi berhasil dihapus.');
    }
}
```

#### React Page: `Admin/MasterData/Divisi/Index.tsx`
```typescript
// Props (Inertia):
interface DivisiIndexProps {
    divisi: PaginatedData<OrgDivisi>;
    filters: { search?: string };
}

// DataTable columns: No, Nama Divisi, Jml Unit Organisasi, Aksi
// Dialog: Input Nama Divisi
```

### 4.4 CRUD Unit Organisasi

#### Model: `OrgUnit`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrgUnit extends Model
{
    protected $table = 'org_unit';

    protected $fillable = ['nama_unit_organisasi', 'divisi_id'];

    public function divisi(): BelongsTo
    {
        return $this->belongsTo(OrgDivisi::class, 'divisi_id');
    }
}
```

#### Controller: `Admin\UnitOrganisasiController`
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\OrgDivisi;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnitOrganisasiController extends Controller
{
    public function index(Request $request)
    {
        $query = OrgUnit::with('divisi');

        if ($request->has('search')) {
            $query->where('nama_unit_organisasi', 'like', '%' . $request->search . '%');
        }

        if ($request->has('divisi_id') && $request->divisi_id) {
            $query->where('divisi_id', $request->divisi_id);
        }

        $unitOrganisasi = $query->orderBy('nama_unit_organisasi')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/UnitOrganisasi/Index', [
            'unitOrganisasi' => $unitOrganisasi,
            'divisiList' => OrgDivisi::orderBy('nama_divisi')->get(),
            'filters' => $request->only('search', 'divisi_id'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_unit_organisasi' => 'required|string|max:150',
            'divisi_id' => 'required|exists:org_divisi,id',
        ]);

        OrgUnit::create($validated);

        return redirect()->back()->with('success', 'Unit Organisasi berhasil ditambahkan.');
    }

    public function update(Request $request, OrgUnit $unitOrganisasi)
    {
        $validated = $request->validate([
            'nama_unit_organisasi' => 'required|string|max:150',
            'divisi_id' => 'required|exists:org_divisi,id',
        ]);

        $unitOrganisasi->update($validated);

        return redirect()->back()->with('success', 'Unit Organisasi berhasil diperbarui.');
    }

    public function destroy(OrgUnit $unitOrganisasi)
    {
        $unitOrganisasi->delete();

        return redirect()->back()->with('success', 'Unit Organisasi berhasil dihapus.');
    }
}
```

#### React Page: `Admin/MasterData/UnitOrganisasi/Index.tsx`
```typescript
// Props (Inertia):
interface UnitOrganisasiIndexProps {
    unitOrganisasi: PaginatedData<OrgUnit>;
    divisiList: OrgDivisi[];
    filters: { search?: string; divisi_id?: number };
}

// DataTable columns: No, Nama Unit Organisasi, Divisi (dari relasi), Aksi
// Dialog: Dropdown Divisi, Input Nama Unit Organisasi
```

### 4.5 CRUD Jabatan

#### Model: `OrgJabatan`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgJabatan extends Model
{
    protected $table = 'org_jabatan';

    protected $fillable = ['nama_jabatan'];
}
```

#### Controller: `Admin\JabatanController`
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgJabatan;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JabatanController extends Controller
{
    public function index(Request $request)
    {
        $query = OrgJabatan::query();

        if ($request->has('search')) {
            $query->where('nama_jabatan', 'like', '%' . $request->search . '%');
        }

        $jabatan = $query->orderBy('nama_jabatan')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/Jabatan/Index', [
            'jabatan' => $jabatan,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_jabatan' => 'required|string|max:100|unique:org_jabatan,nama_jabatan',
        ]);

        OrgJabatan::create($validated);

        return redirect()->back()->with('success', 'Jabatan berhasil ditambahkan.');
    }

    public function update(Request $request, OrgJabatan $jabatan)
    {
        $validated = $request->validate([
            'nama_jabatan' => 'required|string|max:100|unique:org_jabatan,nama_jabatan,' . $jabatan->id,
        ]);

        $jabatan->update($validated);

        return redirect()->back()->with('success', 'Jabatan berhasil diperbarui.');
    }

    public function destroy(OrgJabatan $jabatan)
    {
        $jabatan->delete();

        return redirect()->back()->with('success', 'Jabatan berhasil dihapus.');
    }
}
```

### 4.6 Shared Components

#### `DataTable.tsx`
```typescript
interface DataTableProps<T> {
    columns: ColumnDef<T>[];
    data: T[];
    pagination?: PaginationData;
    searchable?: boolean;
    searchPlaceholder?: string;
    onSearch?: (value: string) => void;
    searchValue?: string;
}

// Menggunakan shadcn Table component
// Support sorting per kolom (opsional)
// Render kolom sesuai ColumnDef
// Empty state: "Tidak ada data"
```

#### `SearchInput.tsx`
```typescript
interface SearchInputProps {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string; // default: "Cari..."
    debounceMs?: number;  // default: 300
}
```

#### `Pagination.tsx`
```typescript
interface PaginationProps {
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
    onPageChange: (page: number) => void;
}

// Tampilkan: "Menampilkan {from}-{to} dari {total} data"
// Tombol: Previous, Next, page numbers
```

#### `ConfirmDialog.tsx`
```typescript
interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;         // default: "Konfirmasi"
    description: string;   // default: "Apakah Anda yakin?"
    confirmLabel?: string; // default: "Hapus"
    cancelLabel?: string;  // default: "Batal"
    variant?: 'danger' | 'warning'; // default: 'danger'
    onConfirm: () => void;
    loading?: boolean;
}
```

#### `StatusBadge.tsx`
```typescript
interface StatusBadgeProps {
    status: 'open' | 'on_proses' | 'pending' | 'solve' | 'reject';
}

// Warna:
// open     → bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300    → "Open"
// on_proses → bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300 → "On Proses"
// pending  → bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300       → "Pending"
// solve    → bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300 → "Solve"
// reject   → bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300        → "Reject"
```

---

## 5. Peraturan Form (Form Builder)

### 5.1 Model: `FormField`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormField extends Model
{
    protected $fillable = [
        'sub_unit_id',
        'label',
        'tipe_field',
        'wajib',
        'opsi',
        'parent_field_id',
        'trigger_value',
        'urutan',
    ];

    protected $casts = [
        'opsi' => 'array',
        'wajib' => 'boolean',
        'urutan' => 'integer',
    ];

    // === Enum Tipe Field ===
    const TIPE_TEKS_PENDEK = 'teks_pendek';
    const TIPE_TEKS_PANJANG = 'teks_panjang';
    const TIPE_ANGKA = 'angka';
    const TIPE_TANGGAL = 'tanggal';
    const TIPE_WAKTU = 'waktu';
    const TIPE_DROPDOWN = 'dropdown';
    const TIPE_RADIO = 'radio';
    const TIPE_CHECKBOX = 'checkbox';
    const TIPE_MULTI_PILIH = 'multi_pilih';
    const TIPE_UPLOAD_GAMBAR = 'upload_gambar';
    const TIPE_UPLOAD_FILE = 'upload_file';
    const TIPE_NOMINAL_RP = 'nominal_rp';
    const TIPE_INFO_PERATURAN = 'info_peraturan';

    const TIPE_FIELDS = [
        self::TIPE_TEKS_PENDEK,
        self::TIPE_TEKS_PANJANG,
        self::TIPE_ANGKA,
        self::TIPE_TANGGAL,
        self::TIPE_WAKTU,
        self::TIPE_DROPDOWN,
        self::TIPE_RADIO,
        self::TIPE_CHECKBOX,
        self::TIPE_MULTI_PILIH,
        self::TIPE_UPLOAD_GAMBAR,
        self::TIPE_UPLOAD_FILE,
        self::TIPE_NOMINAL_RP,
        self::TIPE_INFO_PERATURAN,
    ];

    // Tipe yang membutuhkan opsi (array of string)
    const TIPE_DENGAN_OPSI = [
        self::TIPE_DROPDOWN,
        self::TIPE_RADIO,
        self::TIPE_MULTI_PILIH,
    ];

    // Tipe upload
    const TIPE_UPLOAD = [
        self::TIPE_UPLOAD_GAMBAR,
        self::TIPE_UPLOAD_FILE,
    ];

    // === Relationships ===

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function parentField(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'parent_field_id');
    }

    public function childFields(): HasMany
    {
        return $this->hasMany(FormField::class, 'parent_field_id');
    }

    // === Helpers ===

    public function isConditional(): bool
    {
        return !is_null($this->parent_field_id);
    }

    public function needsOpsi(): bool
    {
        return in_array($this->tipe_field, self::TIPE_DENGAN_OPSI);
    }

    public function isUpload(): bool
    {
        return in_array($this->tipe_field, self::TIPE_UPLOAD);
    }
}
```

### 5.2 Controller: `Admin\FormFieldController`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FormFieldController extends Controller
{
    /**
     * Halaman utama Peraturan Form — daftar sub unit dengan link ke builder
     */
    public function index(Request $request)
    {
        $units = Unit::with(['subUnits' => function ($q) {
            $q->where('aktif', true)->withCount('formFields');
        }])->where('aktif', true)->orderBy('nama_unit')->get();

        return Inertia::render('Admin/PeraturanForm/Index', [
            'units' => $units,
            'selectedUnitId' => $request->get('unit_id'),
        ]);
    }

    /**
     * Form Builder untuk sub unit tertentu
     */
    public function builder(SubUnit $subUnit)
    {
        $fields = $subUnit->formFields()
            ->with('childFields')
            ->whereNull('parent_field_id') // hanya root fields
            ->orderBy('urutan')
            ->get();

        // Ambil semua fields (termasuk children) untuk referensi parent
        $allFields = $subUnit->formFields()->orderBy('urutan')->get();

        return Inertia::render('Admin/PeraturanForm/Builder', [
            'subUnit' => $subUnit->load('unit'),
            'fields' => $fields,
            'allFields' => $allFields,
            'tipeFields' => FormField::TIPE_FIELDS,
            'tipeDenganOpsi' => FormField::TIPE_DENGAN_OPSI,
        ]);
    }

    /**
     * Tambah field baru
     */
    public function store(Request $request, SubUnit $subUnit)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'tipe_field' => 'required|in:' . implode(',', FormField::TIPE_FIELDS),
            'wajib' => 'boolean',
            'opsi' => 'nullable|array',
            'opsi.*' => 'string|max:255',
            'parent_field_id' => 'nullable|exists:form_fields,id',
            'trigger_value' => 'nullable|string|max:255',
        ]);

        // Set urutan otomatis (terakhir)
        $maxUrutan = $subUnit->formFields()->max('urutan') ?? 0;
        $validated['urutan'] = $maxUrutan + 1;
        $validated['sub_unit_id'] = $subUnit->id;

        FormField::create($validated);

        return redirect()->back()->with('success', 'Field berhasil ditambahkan.');
    }

    /**
     * Update field
     */
    public function update(Request $request, FormField $formField)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'tipe_field' => 'required|in:' . implode(',', FormField::TIPE_FIELDS),
            'wajib' => 'boolean',
            'opsi' => 'nullable|array',
            'opsi.*' => 'string|max:255',
            'parent_field_id' => 'nullable|exists:form_fields,id',
            'trigger_value' => 'nullable|string|max:255',
        ]);

        $formField->update($validated);

        return redirect()->back()->with('success', 'Field berhasil diperbarui.');
    }

    /**
     * Hapus field (cascade: children juga terhapus via FK)
     */
    public function destroy(FormField $formField)
    {
        $formField->delete();

        return redirect()->back()->with('success', 'Field berhasil dihapus.');
    }

    /**
     * Reorder fields via drag-and-drop
     */
    public function reorder(Request $request, SubUnit $subUnit)
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*.id' => 'required|exists:form_fields,id',
            'order.*.urutan' => 'required|integer|min:0',
        ]);

        foreach ($validated['order'] as $item) {
            FormField::where('id', $item['id'])
                ->where('sub_unit_id', $subUnit->id)
                ->update(['urutan' => $item['urutan']]);
        }

        return redirect()->back()->with('success', 'Urutan field berhasil diperbarui.');
    }
}
```

#### Routes untuk Form Builder
```php
// Di dalam group admin + auth.admin
Route::prefix('peraturan-form')->group(function () {
    Route::get('/', [Admin\FormFieldController::class, 'index'])->name('admin.peraturan-form.index');
    Route::get('/{subUnit}/builder', [Admin\FormFieldController::class, 'builder'])->name('admin.peraturan-form.builder');
    Route::post('/{subUnit}/fields', [Admin\FormFieldController::class, 'store'])->name('admin.peraturan-form.store');
    Route::put('/fields/{formField}', [Admin\FormFieldController::class, 'update'])->name('admin.peraturan-form.update');
    Route::delete('/fields/{formField}', [Admin\FormFieldController::class, 'destroy'])->name('admin.peraturan-form.destroy');
    Route::post('/{subUnit}/reorder', [Admin\FormFieldController::class, 'reorder'])->name('admin.peraturan-form.reorder');
});
```

### 5.3 React Pages

#### `Admin/PeraturanForm/Index.tsx`
```typescript
// Props:
interface PeraturanFormIndexProps {
    units: (Unit & {
        sub_units: (SubUnit & { form_fields_count: number })[];
    })[];
    selectedUnitId?: number;
}

// UI:
// 1. Header: "Peraturan Form"
// 2. Dropdown filter: pilih Unit
// 3. Tabel sub unit: Nama Layanan, Jumlah Field, Aksi → tombol "Kelola Form" → navigate ke Builder
// 4. Empty state jika belum ada sub unit
```

#### `Admin/PeraturanForm/Builder.tsx`
```typescript
// Props:
interface FormBuilderProps {
    subUnit: SubUnit & { unit: Unit };
    fields: FormField[];           // root fields (parent_field_id = null)
    allFields: FormField[];        // semua fields untuk referensi parent
    tipeFields: string[];
    tipeDenganOpsi: string[];
}

interface FormField {
    id: number;
    sub_unit_id: number;
    label: string;
    tipe_field: string;
    wajib: boolean;
    opsi: string[] | null;
    parent_field_id: number | null;
    trigger_value: string | null;
    urutan: number;
    child_fields?: FormField[];
}

// UI:
// 1. Header: "Form Builder — {subUnit.nama_layanan}" + breadcrumb
// 2. Toolbar: Tombol "Tambah Field" + Tombol "Preview Form"
// 3. Drag-and-drop area (DndContext + SortableContext):
//    - Setiap field ditampilkan sebagai FieldCard (sortable)
//    - FieldCard menampilkan: icon tipe, label, badge wajib, badge conditional
//    - Tombol edit (buka FieldConfigDialog) dan hapus
//    - Children fields indent ke kanan
// 4. FieldConfigDialog:
//    - Input Label
//    - Dropdown Tipe Field (13 pilihan)
//    - Switch Wajib
//    - Jika tipe dropdown/radio/multi_pilih → editor opsi (add/remove opsi)
//    - Jika tipe info_peraturan → textarea konten info
//    - Section "Form Cabang" (opsional):
//      - Dropdown "Parent Field" (dari allFields yang bertipe dropdown/radio)
//      - Input "Trigger Value" (nilai parent yang memicu field ini muncul)
// 5. Preview button → buka FormPreview modal
```

### 5.4 Tipe Field — Deskripsi Lengkap

| No | Enum Value | Label UI | Input Component | Opsi? | Upload? |
|---|---|---|---|---|---|
| 1 | `teks_pendek` | Teks Pendek | `<Input type="text">` | ❌ | ❌ |
| 2 | `teks_panjang` | Teks Panjang | `<Textarea>` | ❌ | ❌ |
| 3 | `angka` | Angka | `<Input type="number">` | ❌ | ❌ |
| 4 | `tanggal` | Tanggal | Date Picker | ❌ | ❌ |
| 5 | `waktu` | Waktu | `<Input type="time">` | ❌ | ❌ |
| 6 | `dropdown` | Dropdown | `<Select>` with options | ✅ | ❌ |
| 7 | `radio` | Radio | Radio Group | ✅ | ❌ |
| 8 | `checkbox` | Checkbox | Single Checkbox | ❌ | ❌ |
| 9 | `multi_pilih` | Multi-Pilih | Checkbox Group / Multi-Select | ✅ | ❌ |
| 10 | `upload_gambar` | Upload Gambar | File Input (accept: `image/*`) | ❌ | ✅ |
| 11 | `upload_file` | Upload File | File Input (accept: `.pdf,.doc,.docx,.xls,.xlsx`) | ❌ | ✅ |
| 12 | `nominal_rp` | Nominal Rp | Input with "Rp" prefix + formatting (ribuan) | ❌ | ❌ |
| 13 | `info_peraturan` | Info/Peraturan | Read-only text block (no user input) | ❌ | ❌ |

### 5.5 @dnd-kit Integration

```typescript
// Builder.tsx — contoh implementasi drag-and-drop

import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
    arrayMove,
} from '@dnd-kit/sortable';

// Di dalam component:
const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
);

function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;

    if (over && active.id !== over.id) {
        const oldIndex = fields.findIndex((f) => f.id === active.id);
        const newIndex = fields.findIndex((f) => f.id === over.id);
        const newFields = arrayMove(fields, oldIndex, newIndex);

        // Kirim reorder ke server
        router.post(route('admin.peraturan-form.reorder', subUnit.id), {
            order: newFields.map((f, i) => ({ id: f.id, urutan: i })),
        });
    }
}

// Render:
<DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
    <SortableContext items={fields.map(f => f.id)} strategy={verticalListSortingStrategy}>
        {fields.map((field) => (
            <SortableField key={field.id} field={field} />
        ))}
    </SortableContext>
</DndContext>
```

#### `SortableField.tsx`
```typescript
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

interface SortableFieldProps {
    field: FormField;
    onEdit: (field: FormField) => void;
    onDelete: (id: number) => void;
}

// Menggunakan useSortable hook
// Render FieldCard dengan drag handle (GripVertical icon)
```

### 5.6 Form Cabang (Conditional Fields)

**Logika**:
- Field dengan `parent_field_id` != null adalah conditional field
- Field ini hanya ditampilkan ketika parent field memiliki nilai == `trigger_value`
- Parent field harus bertipe `dropdown` atau `radio` (yang memiliki value jelas)

**UI di Builder**:
- Conditional field ditampilkan indent ke kanan, dengan garis penghubung ke parent
- Badge "Cabang dari: {parent_label} = {trigger_value}"

**UI di Form User (Wizard Step 3)**:
- Watch value parent field
- Tampilkan/sembunyikan child field berdasarkan match trigger_value

---

## 6. Wizard Pengajuan Tiket (User)

### 6.1 Routes

```php
// routes/web.php — di dalam group user + auth:web
Route::middleware('auth.user')->group(function () {
    Route::get('/tiket/buat', [User\TicketWizardController::class, 'create'])->name('tiket.create');
    Route::post('/tiket', [User\TicketWizardController::class, 'store'])->name('tiket.store');
});

// API routes (untuk dependent dropdown & dynamic form)
Route::middleware('auth.user')->prefix('api')->group(function () {
    Route::get('/org-units/{divisiId}', function ($divisiId) {
        return \App\Models\OrgUnit::where('divisi_id', $divisiId)->orderBy('nama_unit_organisasi')->get();
    })->name('api.org-units');

    Route::get('/sub-units/{unitId}', function ($unitId) {
        return \App\Models\SubUnit::where('unit_id', $unitId)->where('aktif', true)->orderBy('nama_layanan')->get();
    })->name('api.sub-units');

    Route::get('/form-fields/{subUnitId}', function ($subUnitId) {
        return \App\Models\FormField::where('sub_unit_id', $subUnitId)->orderBy('urutan')->get();
    })->name('api.form-fields');
});
```

### 6.2 Controller: `User\TicketWizardController`

```php
<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\OrgDivisi;
use App\Models\OrgJabatan;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TicketWizardController extends Controller
{
    public function create()
    {
        return Inertia::render('User/Tiket/Wizard', [
            'divisiList' => OrgDivisi::orderBy('nama_divisi')->get(),
            'jabatanList' => OrgJabatan::orderBy('nama_jabatan')->get(),
            'unitList' => Unit::where('aktif', true)->orderBy('nama_unit')->get(),
        ]);
    }

    public function store(Request $request)
    {
        // Validasi dasar
        $request->validate([
            'divisi_id' => 'required|exists:org_divisi,id',
            'org_unit_id' => 'required|exists:org_unit,id',
            'jabatan_id' => 'required|exists:org_jabatan,id',
            'unit_id' => 'required|exists:units,id',
            'sub_unit_id' => 'required|exists:sub_units,id',
            'form_data' => 'required|array',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240', // max 10MB per file
        ]);

        // Validasi form_data berdasarkan form_fields yang wajib
        $formFields = FormField::where('sub_unit_id', $request->sub_unit_id)->get();
        foreach ($formFields as $field) {
            if ($field->wajib && !$field->isUpload() && $field->tipe_field !== 'info_peraturan') {
                $fieldKey = 'field_' . $field->id;
                if (!isset($request->form_data[$fieldKey]) || empty($request->form_data[$fieldKey])) {
                    return back()->withErrors([
                        'form_data.' . $fieldKey => "Field \"{$field->label}\" wajib diisi.",
                    ]);
                }
            }
        }

        DB::transaction(function () use ($request, $formFields) {
            // 1. Buat tiket
            $ticket = Ticket::create([
                'user_id' => auth()->id(),
                'divisi_id' => $request->divisi_id,
                'org_unit_id' => $request->org_unit_id,
                'jabatan_id' => $request->jabatan_id,
                'unit_id' => $request->unit_id,
                'sub_unit_id' => $request->sub_unit_id,
                'form_data' => $request->form_data,
                'status' => 'open',
            ]);

            // 2. Simpan attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $fieldId => $file) {
                    $path = $file->store("ticket-attachments/{$ticket->id}", 'public');

                    $field = $formFields->firstWhere('id', $fieldId);

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'field_id' => $fieldId,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'wajib' => $field ? $field->wajib : false,
                    ]);
                }
            }

            // 3. Log awal
            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => null,
                'aksi' => 'dibuat',
                'catatan' => 'Tiket dibuat oleh ' . auth()->user()->username,
            ]);
        });

        return redirect()->route('tiket.riwayat')->with('success', 'Tiket berhasil diajukan!');
    }
}
```

### 6.3 React Page: `User/Tiket/Wizard.tsx`

```typescript
// Props:
interface WizardProps {
    divisiList: OrgDivisi[];
    jabatanList: OrgJabatan[];
    unitList: Unit[];
}

// State:
// - activeStep: number (0-4)
// - formData: {
//     divisi_id: number | null;
//     org_unit_id: number | null;
//     jabatan_id: number | null;
//     unit_id: number | null;
//     sub_unit_id: number | null;
//     form_data: Record<string, any>;
//     attachments: Record<string, File>;
//   }
// - orgUnitList: OrgUnit[] (fetched dynamically)
// - subUnitList: SubUnit[] (fetched dynamically)
// - formFields: FormField[] (fetched dynamically)
// - errors: Record<string, string>

// 5 Steps:
const steps = [
    { label: 'Data Pengaju', description: 'Divisi, unit organisasi, jabatan' },
    { label: 'Pilih Layanan', description: 'Unit tujuan dan sub unit' },
    { label: 'Isi Form', description: 'Formulir layanan' },
    { label: 'Lampiran', description: 'Upload dokumen pendukung' },
    { label: 'Review & Kirim', description: 'Periksa dan kirim tiket' },
];
```

#### Step 1: Data Pengaju
```typescript
// Dropdown Divisi → onChange: fetch org units for selected divisi
// Dropdown Unit Organisasi (dependent, empty until divisi selected)
// Dropdown Jabatan (independent)
// Validasi: semua field required
```

#### Step 2: Pilih Unit & Layanan
```typescript
// Dropdown Unit (from unitList prop, aktif=true only)
// Dropdown Sub Unit (dependent, fetch by unit_id)
// onChange sub_unit: fetch form_fields for this sub unit
// Validasi: semua field required
```

#### Step 3: Form Dinamis
```typescript
// Render form_fields berdasarkan tipe_field
// Menggunakan DynamicField component yang switch berdasarkan tipe:
// - teks_pendek → <Input>
// - teks_panjang → <Textarea>
// - angka → <Input type="number">
// - tanggal → <DatePicker>
// - waktu → <Input type="time">
// - dropdown → <Select> with opsi
// - radio → <RadioGroup> with opsi
// - checkbox → <Checkbox>
// - multi_pilih → <CheckboxGroup> with opsi
// - upload_gambar / upload_file → SKIP (handled in Step 4)
// - nominal_rp → <Input> with "Rp" prefix, number formatting
// - info_peraturan → <Alert> readonly info block

// Conditional fields: watch parent field value, show/hide child
```

#### Step 4: Lampiran
```typescript
// Render hanya form_fields yang bertipe upload_gambar atau upload_file
// Untuk setiap upload field:
// - Label field
// - Badge Wajib / Opsional
// - File input with drag-and-drop area
// - Preview (gambar: thumbnail, file: nama file)
// - Tombol hapus file yang sudah dipilih
// Jika tidak ada field upload → tampilkan "Tidak ada lampiran yang diperlukan"
```

#### Step 5: Review & Submit
```typescript
// Tampilkan ringkasan:
// Card "Data Pengaju": Divisi, Unit Organisasi, Jabatan
// Card "Layanan Tujuan": Unit, Sub Unit
// Card "Isian Form": setiap field label + value
// Card "Lampiran": list file yang akan diupload
// Tombol "Kirim Tiket" → submit form via Inertia POST
// Tombol "Kembali" → go to previous step
```

### 6.4 Stepper Component

**File**: `Components/Stepper.tsx`

```typescript
interface StepperProps {
    steps: { label: string; description?: string }[];
    activeStep: number;
    completedSteps: number[];
    onStepClick?: (step: number) => void;
}

// UI:
// Horizontal stepper (desktop) / Vertical stepper (mobile)
// Step indicator: circle with number/checkmark
// Active step: blue, Completed: green checkmark, Upcoming: gray
// Connector line between steps
// Label dan description di bawah indicator
```

---

## 7. Riwayat Tiket (User)

### 7.1 Routes

```php
Route::middleware('auth.user')->group(function () {
    Route::get('/tiket/riwayat', [User\TicketHistoryController::class, 'index'])->name('tiket.riwayat');
    Route::get('/tiket/{ticket}', [User\TicketHistoryController::class, 'show'])->name('tiket.show');
});
```

### 7.2 Controller: `User\TicketHistoryController`

```php
<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Ticket::where('user_id', auth()->id())
            ->with(['unit', 'subUnit']);

        // Filter status
        if ($request->has('status') && $request->status) {
            if (is_array($request->status)) {
                $query->whereIn('status', $request->status);
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter rentang tanggal
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $tickets = $query->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('User/Tiket/Riwayat', [
            'tickets' => $tickets,
            'filters' => $request->only('status', 'date_from', 'date_to'),
            'statuses' => ['open', 'on_proses', 'pending', 'solve', 'reject'],
        ]);
    }

    public function show(Ticket $ticket)
    {
        // Pastikan tiket milik user yang login
        if ($ticket->user_id !== auth()->id()) {
            abort(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        $ticket->load([
            'unit',
            'subUnit',
            'orgDivisi',
            'orgUnit',
            'jabatan',
            'attachments',
            'logs' => function ($q) {
                $q->orderBy('timestamp', 'desc');
            },
            'logs.admin',
        ]);

        // Ambil form fields untuk mapping label
        $formFields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get();

        return Inertia::render('User/Tiket/Detail', [
            'ticket' => $ticket,
            'formFields' => $formFields,
        ]);
    }
}
```

### 7.3 React Pages

#### `User/Tiket/Riwayat.tsx`
```typescript
// Props:
interface RiwayatProps {
    tickets: PaginatedData<Ticket>;
    filters: {
        status?: string | string[];
        date_from?: string;
        date_to?: string;
    };
    statuses: string[];
}

// UI:
// 1. Header: "Riwayat Tiket"
// 2. Filter panel:
//    - Status dropdown (multi-select)
//    - Tanggal Mulai (date input)
//    - Tanggal Selesai (date input)
//    - Tombol "Terapkan" + "Reset"
// 3. DataTable columns:
//    - No. Tiket → #TKT-{id}
//    - Unit (unit.nama_unit)
//    - Layanan (subUnit.nama_layanan)
//    - Status (StatusBadge)
//    - Tanggal (formatted created_at: "9 Jul 2026, 14:30")
//    - Aksi → Link "Lihat Detail"
// 4. Pagination
// 5. Empty state: "Belum ada tiket"
```

#### `User/Tiket/Detail.tsx`
```typescript
// Props:
interface DetailProps {
    ticket: Ticket & {
        unit: Unit;
        sub_unit: SubUnit;
        org_divisi: OrgDivisi;
        org_unit: OrgUnit;
        jabatan: OrgJabatan;
        attachments: TicketAttachment[];
        logs: (TicketLog & { admin?: Admin })[];
    };
    formFields: FormField[];
}

// UI Sections:
// 1. Header: "#TKT-{id}" + StatusBadge + tanggal dibuat
// 2. Card "Data Pengaju":
//    - Divisi: {org_divisi.nama_divisi}
//    - Unit Organisasi: {org_unit.nama_unit_organisasi}
//    - Jabatan: {jabatan.nama_jabatan}
// 3. Card "Layanan Tujuan":
//    - Unit: {unit.nama_unit}
//    - Sub Unit: {sub_unit.nama_layanan}
// 4. Card "Isian Form":
//    - Loop formFields, tampilkan label + value dari ticket.form_data
//    - Format value sesuai tipe (tanggal → format tanggal, nominal_rp → format Rp)
// 5. Card "Lampiran":
//    - List attachments dengan icon file type
//    - Tombol download (href ke storage path)
//    - Preview gambar (jika tipe gambar)
// 6. Card "Timeline Respon":
//    - Vertical timeline dari ticket.logs
//    - Setiap entry: icon aksi, label aksi, catatan, admin username, timestamp
//    - Aksi: dibuat (biru), on_proses (kuning), pending (abu), solve (hijau), reject (merah)
```

### 7.4 Model Ticket — Tambahan Relasi

```php
// app/Models/Ticket.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'user_id', 'divisi_id', 'org_unit_id', 'jabatan_id',
        'unit_id', 'sub_unit_id', 'form_data', 'status',
    ];

    protected $casts = [
        'form_data' => 'array',
    ];

    const STATUS_OPEN = 'open';
    const STATUS_ON_PROSES = 'on_proses';
    const STATUS_PENDING = 'pending';
    const STATUS_SOLVE = 'solve';
    const STATUS_REJECT = 'reject';

    const ALL_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ON_PROSES,
        self::STATUS_PENDING,
        self::STATUS_SOLVE,
        self::STATUS_REJECT,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function orgDivisi(): BelongsTo
    {
        return $this->belongsTo(OrgDivisi::class, 'divisi_id');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(OrgJabatan::class, 'jabatan_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TicketLog::class);
    }
}
```

### 7.5 Model TicketAttachment

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    protected $fillable = [
        'ticket_id', 'field_id', 'file_path', 'original_name',
        'mime_type', 'file_size', 'wajib',
    ];

    protected $casts = [
        'wajib' => 'boolean',
        'file_size' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function formField(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'field_id');
    }
}
```

### 7.6 Model TicketLog

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'admin_id', 'aksi', 'catatan', 'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    protected $attributes = [
        'timestamp' => null, // will use DB default
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
```

---

## 8. Layout & Navigasi

### 8.1 UserLayout

**File**: `resources/js/Layouts/UserLayout.tsx`

```typescript
interface UserLayoutProps {
    children: React.ReactNode;
    title?: string;
}

// Sidebar Navigation Items:
const userNavItems = [
    {
        label: 'Dashboard',
        icon: LayoutDashboard, // dari lucide-react
        route: '/dashboard',
        routeName: 'dashboard',
    },
    {
        label: 'Ajukan Tiket',
        icon: PlusCircle,
        route: '/tiket/buat',
        routeName: 'tiket.create',
    },
    {
        label: 'Riwayat Tiket',
        icon: History,
        route: '/tiket/riwayat',
        routeName: 'tiket.riwayat',
    },
    {
        label: 'CSAT',
        icon: Star,
        route: '/csat',
        routeName: 'csat',
        disabled: true,  // Fase 5
        badge: 'Segera',
    },
    {
        label: 'Live Monitor',
        icon: Monitor,
        route: '/live-monitor',
        routeName: 'live-monitor',
        disabled: true,  // Fase 5
        badge: 'Segera',
    },
];

// Header:
// - Logo "Halo APU" (kiri)
// - ThemeToggle (kanan)
// - User dropdown: username, email, tombol Logout (kanan)

// Responsive:
// - Desktop: sidebar tetap di kiri (width: 260px)
// - Mobile: sidebar tersembunyi, hamburger menu di header
// - Gunakan Sheet component dari shadcn untuk mobile sidebar
```

### 8.2 AdminLayout

**File**: `resources/js/Layouts/AdminLayout.tsx`

```typescript
interface AdminLayoutProps {
    children: React.ReactNode;
    title?: string;
}

// Sidebar Navigation Items:
const adminNavItems = [
    {
        label: 'Dashboard',
        icon: LayoutDashboard,
        route: '/admin/dashboard',
        routeName: 'admin.dashboard',
    },
    {
        label: 'Tiketing',
        icon: Ticket,
        route: '/admin/tiket',
        routeName: 'admin.tiket.index',
    },
    {
        label: 'Master Data',
        icon: Database,
        children: [
            { label: 'Unit', route: '/admin/master/unit', routeName: 'admin.master.unit.index' },
            { label: 'Sub Unit', route: '/admin/master/sub-unit', routeName: 'admin.master.sub-unit.index' },
            { label: 'Divisi', route: '/admin/master/divisi', routeName: 'admin.master.divisi.index' },
            { label: 'Unit Organisasi', route: '/admin/master/unit-organisasi', routeName: 'admin.master.unit-organisasi.index' },
            { label: 'Jabatan', route: '/admin/master/jabatan', routeName: 'admin.master.jabatan.index' },
        ],
    },
    {
        label: 'Peraturan Form',
        icon: FileEdit,
        route: '/admin/peraturan-form',
        routeName: 'admin.peraturan-form.index',
    },
    {
        label: 'SLA',
        icon: Clock,
        route: '/admin/sla',
        disabled: true,  // Fase 3
        badge: 'Fase 3',
    },
    {
        label: 'Reminder',
        icon: Bell,
        route: '/admin/reminder',
        disabled: true,  // Fase 4
        badge: 'Fase 4',
    },
    {
        label: 'CSAT',
        icon: Star,
        route: '/admin/csat',
        disabled: true,  // Fase 5
        badge: 'Fase 5',
    },
    {
        label: 'Konfigurasi',
        icon: Settings,
        route: '/admin/konfigurasi',
        disabled: true,  // Fase 5
        badge: 'Fase 5',
    },
    {
        label: 'User Management',
        icon: Users,
        route: '/admin/users',
        disabled: true,  // Fase 5
        badge: 'Fase 5',
    },
    {
        label: 'Admin Management',
        icon: Shield,
        route: '/admin/admins',
        disabled: true,  // Fase 5
        badge: 'Fase 5',
    },
];

// Header:
// - Logo "Halo APU — Panel Admin" (kiri)
// - ThemeToggle (kanan)
// - Admin dropdown: username, role badge (Admin/Operator), tombol Logout
```

### 8.3 Theme Toggle (Dark/Light Mode)

**File**: `resources/js/Components/ThemeProvider.tsx`
```typescript
// Context provider untuk theme
// Simpan preference di localStorage key: 'halo-apu-theme'
// Values: 'light' | 'dark' | 'system'
// Tambahkan/hapus class 'dark' pada <html> element
```

**File**: `resources/js/Components/ThemeToggle.tsx`
```typescript
// Tombol toggle dengan icon:
// - Light mode: Sun icon
// - Dark mode: Moon icon
// Dropdown: Light, Dark, System
// Menggunakan shadcn DropdownMenu
```

**Tailwind Config** — dark mode strategy:
```css
/* resources/css/app.css */
@import "tailwindcss";

/* shadcn/ui CSS variables — warna utama biru */
@layer base {
    :root {
        --background: 0 0% 100%;
        --foreground: 222.2 84% 4.9%;
        --card: 0 0% 100%;
        --card-foreground: 222.2 84% 4.9%;
        --popover: 0 0% 100%;
        --popover-foreground: 222.2 84% 4.9%;
        --primary: 221.2 83.2% 53.3%;        /* blue-600 */
        --primary-foreground: 210 40% 98%;
        --secondary: 210 40% 96.1%;
        --secondary-foreground: 222.2 47.4% 11.2%;
        --muted: 210 40% 96.1%;
        --muted-foreground: 215.4 16.3% 46.9%;
        --accent: 210 40% 96.1%;
        --accent-foreground: 222.2 47.4% 11.2%;
        --destructive: 0 84.2% 60.2%;
        --destructive-foreground: 210 40% 98%;
        --border: 214.3 31.8% 91.4%;
        --input: 214.3 31.8% 91.4%;
        --ring: 221.2 83.2% 53.3%;
        --radius: 0.5rem;
    }

    .dark {
        --background: 222.2 84% 4.9%;
        --foreground: 210 40% 98%;
        --card: 222.2 84% 4.9%;
        --card-foreground: 210 40% 98%;
        --popover: 222.2 84% 4.9%;
        --popover-foreground: 210 40% 98%;
        --primary: 217.2 91.2% 59.8%;        /* blue-500 (lighter for dark) */
        --primary-foreground: 222.2 47.4% 11.2%;
        --secondary: 217.2 32.6% 17.5%;
        --secondary-foreground: 210 40% 98%;
        --muted: 217.2 32.6% 17.5%;
        --muted-foreground: 215 20.2% 65.1%;
        --accent: 217.2 32.6% 17.5%;
        --accent-foreground: 210 40% 98%;
        --destructive: 0 62.8% 30.6%;
        --destructive-foreground: 210 40% 98%;
        --border: 217.2 32.6% 17.5%;
        --input: 217.2 32.6% 17.5%;
        --ring: 224.3 76.3% 48%;
    }
}
```

### 8.4 GuestLayout

**File**: `resources/js/Layouts/GuestLayout.tsx`
```typescript
// Layout untuk halaman login dan daftar
// Centered card layout
// Background gradient biru
// Logo Halo APU di atas card
// Responsive: full width di mobile, max-w-md di desktop
```

---

## 9. File-by-File Checklist

### Migrations

- [ ] `2026_07_10_000001_create_org_divisi_table.php`
- [ ] `2026_07_10_000002_create_org_unit_table.php`
- [ ] `2026_07_10_000003_create_org_jabatan_table.php`
- [ ] `2026_07_10_000004_create_users_table.php` (replace default)
- [ ] `2026_07_10_000005_create_admins_table.php`
- [ ] `2026_07_10_000006_create_units_table.php`
- [ ] `2026_07_10_000007_create_sub_units_table.php`
- [ ] `2026_07_10_000008_create_form_fields_table.php`
- [ ] `2026_07_10_000009_create_tickets_table.php`
- [ ] `2026_07_10_000010_create_ticket_attachments_table.php`
- [ ] `2026_07_10_000011_create_ticket_logs_table.php`
- [ ] `2026_07_10_000012_create_admin_password_reset_tokens_table.php`
- [ ] Spatie permission tables (published via vendor:publish)

### Models

- [ ] `App\Models\User` (modify existing)
- [ ] `App\Models\Admin`
- [ ] `App\Models\Unit`
- [ ] `App\Models\SubUnit`
- [ ] `App\Models\OrgDivisi`
- [ ] `App\Models\OrgUnit`
- [ ] `App\Models\OrgJabatan`
- [ ] `App\Models\FormField`
- [ ] `App\Models\Ticket`
- [ ] `App\Models\TicketAttachment`
- [ ] `App\Models\TicketLog`

### Controllers

- [ ] `App\Http\Controllers\Auth\UserLoginController`
- [ ] `App\Http\Controllers\Auth\AdminLoginController`
- [ ] `App\Http\Controllers\Auth\ForgotPasswordController`
- [ ] `App\Http\Controllers\Admin\UnitController`
- [ ] `App\Http\Controllers\Admin\SubUnitController`
- [ ] `App\Http\Controllers\Admin\DivisiController`
- [ ] `App\Http\Controllers\Admin\UnitOrganisasiController`
- [ ] `App\Http\Controllers\Admin\JabatanController`
- [ ] `App\Http\Controllers\Admin\FormFieldController`
- [ ] `App\Http\Controllers\User\TicketWizardController`
- [ ] `App\Http\Controllers\User\TicketHistoryController`
- [ ] `App\Http\Controllers\User\DashboardController`

### Middleware

- [ ] `App\Http\Middleware\HandleInertiaRequests` (modify existing)
- [ ] `App\Http\Middleware\EnsureUserAuthenticated`
- [ ] `App\Http\Middleware\EnsureAdminAuthenticated`

### React Pages

- [ ] `resources/js/Pages/Auth/UserLogin.tsx`
- [ ] `resources/js/Pages/Auth/AdminLogin.tsx`
- [ ] `resources/js/Pages/Auth/ForgotPassword.tsx`
- [ ] `resources/js/Pages/Auth/ResetPassword.tsx`
- [ ] `resources/js/Pages/Auth/Register.tsx`
- [ ] `resources/js/Pages/Admin/MasterData/Unit/Index.tsx`
- [ ] `resources/js/Pages/Admin/MasterData/SubUnit/Index.tsx`
- [ ] `resources/js/Pages/Admin/MasterData/Divisi/Index.tsx`
- [ ] `resources/js/Pages/Admin/MasterData/UnitOrganisasi/Index.tsx`
- [ ] `resources/js/Pages/Admin/MasterData/Jabatan/Index.tsx`
- [ ] `resources/js/Pages/Admin/PeraturanForm/Index.tsx`
- [ ] `resources/js/Pages/Admin/PeraturanForm/Builder.tsx`
- [ ] `resources/js/Pages/User/Dashboard.tsx`
- [ ] `resources/js/Pages/User/Tiket/Wizard.tsx`
- [ ] `resources/js/Pages/User/Tiket/Riwayat.tsx`
- [ ] `resources/js/Pages/User/Tiket/Detail.tsx`

### React Components (Shared)

- [ ] `resources/js/Components/DataTable.tsx`
- [ ] `resources/js/Components/SearchInput.tsx`
- [ ] `resources/js/Components/Pagination.tsx`
- [ ] `resources/js/Components/ConfirmDialog.tsx`
- [ ] `resources/js/Components/StatusBadge.tsx`
- [ ] `resources/js/Components/Stepper.tsx`
- [ ] `resources/js/Components/ThemeProvider.tsx`
- [ ] `resources/js/Components/ThemeToggle.tsx`
- [ ] `resources/js/Components/FormBuilder/FieldCard.tsx`
- [ ] `resources/js/Components/FormBuilder/FieldConfigDialog.tsx`
- [ ] `resources/js/Components/FormBuilder/FieldRenderer.tsx`
- [ ] `resources/js/Components/FormBuilder/FormPreview.tsx`
- [ ] `resources/js/Components/FormBuilder/SortableField.tsx`
- [ ] `resources/js/Components/DynamicForm/DynamicField.tsx`
- [ ] `resources/js/Components/DynamicForm/ConditionalField.tsx`

### React Layouts

- [ ] `resources/js/Layouts/UserLayout.tsx`
- [ ] `resources/js/Layouts/AdminLayout.tsx`
- [ ] `resources/js/Layouts/GuestLayout.tsx`
- [ ] `resources/js/Layouts/Sidebar.tsx`
- [ ] `resources/js/Layouts/Header.tsx`

### shadcn/ui Components

- [ ] `resources/js/Components/ui/button.tsx`
- [ ] `resources/js/Components/ui/card.tsx`
- [ ] `resources/js/Components/ui/dialog.tsx`
- [ ] `resources/js/Components/ui/dropdown-menu.tsx`
- [ ] `resources/js/Components/ui/input.tsx`
- [ ] `resources/js/Components/ui/label.tsx`
- [ ] `resources/js/Components/ui/select.tsx`
- [ ] `resources/js/Components/ui/table.tsx`
- [ ] `resources/js/Components/ui/textarea.tsx`
- [ ] `resources/js/Components/ui/badge.tsx`
- [ ] `resources/js/Components/ui/separator.tsx`
- [ ] `resources/js/Components/ui/sheet.tsx`
- [ ] `resources/js/Components/ui/toast.tsx`
- [ ] `resources/js/Components/ui/toaster.tsx`
- [ ] `resources/js/Components/ui/tooltip.tsx`
- [ ] `resources/js/Components/ui/checkbox.tsx`
- [ ] `resources/js/Components/ui/radio-group.tsx`
- [ ] `resources/js/Components/ui/switch.tsx`
- [ ] `resources/js/Components/ui/alert.tsx`

### Routes (`routes/web.php`)

```php
// === GUEST ROUTES ===
Route::middleware('guest')->group(function () {
    // User Login
    Route::get('/login', [UserLoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [UserLoginController::class, 'login'])->name('login.submit');
    Route::get('/daftar', [UserLoginController::class, 'register'])->name('register');

    // Lupa Password
    Route::get('/lupa-password', [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/lupa-password', [ForgotPasswordController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])->name('password.update');
});

// === ADMIN GUEST ROUTES ===
Route::prefix('admin')->middleware('guest:admin')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminLoginController::class, 'login'])->name('admin.login.submit');
});

// === USER AUTHENTICATED ROUTES ===
Route::middleware('auth.user')->group(function () {
    Route::post('/logout', [UserLoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [User\DashboardController::class, 'index'])->name('dashboard');

    // Tiket
    Route::get('/tiket/buat', [User\TicketWizardController::class, 'create'])->name('tiket.create');
    Route::post('/tiket', [User\TicketWizardController::class, 'store'])->name('tiket.store');
    Route::get('/tiket/riwayat', [User\TicketHistoryController::class, 'index'])->name('tiket.riwayat');
    Route::get('/tiket/{ticket}', [User\TicketHistoryController::class, 'show'])->name('tiket.show');
});

// === USER API ROUTES (dependent dropdowns) ===
Route::middleware('auth.user')->prefix('api')->group(function () {
    Route::get('/org-units/{divisiId}', fn($id) => OrgUnit::where('divisi_id', $id)->get())->name('api.org-units');
    Route::get('/sub-units/{unitId}', fn($id) => SubUnit::where('unit_id', $id)->where('aktif', true)->get())->name('api.sub-units');
    Route::get('/form-fields/{subUnitId}', fn($id) => FormField::where('sub_unit_id', $id)->orderBy('urutan')->get())->name('api.form-fields');
});

// === ADMIN AUTHENTICATED ROUTES ===
Route::prefix('admin')->middleware('auth.admin')->group(function () {
    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');

    // Dashboard (Fase 2)
    // Route::get('/dashboard', [Admin\DashboardController::class, 'index'])->name('admin.dashboard');

    // Master Data
    Route::prefix('master')->group(function () {
        Route::resource('unit', Admin\UnitController::class)->only(['index', 'store', 'update', 'destroy'])->names('admin.master.unit');
        Route::resource('sub-unit', Admin\SubUnitController::class)->only(['index', 'store', 'update', 'destroy'])->names('admin.master.sub-unit');
        Route::resource('divisi', Admin\DivisiController::class)->only(['index', 'store', 'update', 'destroy'])->names('admin.master.divisi');
        Route::resource('unit-organisasi', Admin\UnitOrganisasiController::class)->only(['index', 'store', 'update', 'destroy'])->names('admin.master.unit-organisasi');
        Route::resource('jabatan', Admin\JabatanController::class)->only(['index', 'store', 'update', 'destroy'])->names('admin.master.jabatan');
    });

    // Peraturan Form
    Route::prefix('peraturan-form')->group(function () {
        Route::get('/', [Admin\FormFieldController::class, 'index'])->name('admin.peraturan-form.index');
        Route::get('/{subUnit}/builder', [Admin\FormFieldController::class, 'builder'])->name('admin.peraturan-form.builder');
        Route::post('/{subUnit}/fields', [Admin\FormFieldController::class, 'store'])->name('admin.peraturan-form.store');
        Route::put('/fields/{formField}', [Admin\FormFieldController::class, 'update'])->name('admin.peraturan-form.update');
        Route::delete('/fields/{formField}', [Admin\FormFieldController::class, 'destroy'])->name('admin.peraturan-form.destroy');
        Route::post('/{subUnit}/reorder', [Admin\FormFieldController::class, 'reorder'])->name('admin.peraturan-form.reorder');
    });

    // Tiketing Admin (Fase 2)
    // Route::get('/tiket', [Admin\TicketController::class, 'index'])->name('admin.tiket.index');
    // Route::get('/tiket/{ticket}', [Admin\TicketController::class, 'show'])->name('admin.tiket.show');
    // Route::patch('/tiket/{ticket}/status', [Admin\TicketController::class, 'updateStatus'])->name('admin.tiket.updateStatus');
});
```

### Config Files

- [ ] `config/auth.php` — multi-guard setup
- [ ] `config/permission.php` — spatie guard_name = 'admin'
- [ ] `vite.config.ts` — Laravel + React + Tailwind plugin
- [ ] `tsconfig.json` — TypeScript config
- [ ] `.env` — database + app config
- [ ] `resources/views/app.blade.php` — Inertia root template

### Seeders

- [ ] `database/seeders/DatabaseSeeder.php`
- [ ] `database/seeders/OrgDivisiSeeder.php`
- [ ] `database/seeders/OrgUnitSeeder.php`
- [ ] `database/seeders/OrgJabatanSeeder.php`
- [ ] `database/seeders/UnitSeeder.php`
- [ ] `database/seeders/SubUnitSeeder.php`
- [ ] `database/seeders/AdminSeeder.php`

### Mailables

- [ ] `App\Mail\ResetPasswordMail` (atau gunakan built-in Laravel notification)

### TypeScript Types

**File**: `resources/js/types/index.d.ts`
```typescript
export interface User {
    id: number;
    username: string;
    email: string;
    no_wa: string | null;
    divisi_id: number | null;
    org_unit_id: number | null;
    jabatan_id: number | null;
    created_at: string;
}

export interface Admin {
    id: number;
    username: string;
    email: string;
    roles: { name: string }[];
    created_at: string;
}

export interface Unit {
    id: number;
    nama_unit: string;
    deskripsi: string | null;
    aktif: boolean;
}

export interface SubUnit {
    id: number;
    unit_id: number;
    nama_layanan: string;
    deskripsi: string | null;
    aktif: boolean;
    unit?: Unit;
}

export interface OrgDivisi {
    id: number;
    nama_divisi: string;
}

export interface OrgUnit {
    id: number;
    nama_unit_organisasi: string;
    divisi_id: number;
    divisi?: OrgDivisi;
}

export interface OrgJabatan {
    id: number;
    nama_jabatan: string;
}

export interface FormField {
    id: number;
    sub_unit_id: number;
    label: string;
    tipe_field: string;
    wajib: boolean;
    opsi: string[] | null;
    parent_field_id: number | null;
    trigger_value: string | null;
    urutan: number;
    child_fields?: FormField[];
}

export interface Ticket {
    id: number;
    user_id: number;
    divisi_id: number | null;
    org_unit_id: number | null;
    jabatan_id: number | null;
    unit_id: number;
    sub_unit_id: number;
    form_data: Record<string, any>;
    status: 'open' | 'on_proses' | 'pending' | 'solve' | 'reject';
    created_at: string;
    updated_at: string;
    user?: User;
    unit?: Unit;
    sub_unit?: SubUnit;
    org_divisi?: OrgDivisi;
    org_unit?: OrgUnit;
    jabatan?: OrgJabatan;
    attachments?: TicketAttachment[];
    logs?: TicketLog[];
}

export interface TicketAttachment {
    id: number;
    ticket_id: number;
    field_id: number | null;
    file_path: string;
    original_name: string;
    mime_type: string | null;
    file_size: number | null;
    wajib: boolean;
}

export interface TicketLog {
    id: number;
    ticket_id: number;
    admin_id: number | null;
    aksi: string;
    catatan: string | null;
    timestamp: string;
    admin?: Admin;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    links: {
        url: string | null;
        label: string;
        active: boolean;
    }[];
}

export interface PageProps {
    auth: {
        user: User | null;
        admin: Admin | null;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
}
```

### Hooks

- [ ] `resources/js/hooks/useTheme.ts` — hook untuk akses ThemeContext
- [ ] `resources/js/hooks/useDependentDropdown.ts` — hook untuk fetch dependent dropdown data

### Utilities

- [ ] `resources/js/lib/utils.ts` — cn() helper (shadcn), format tanggal, format currency

---

## Catatan Tambahan

### Model Admin — Spatie HasRoles

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use Notifiable, HasRoles;

    protected $guard_name = 'admin';

    protected $fillable = ['username', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
```

### Model User — Modifikasi

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'username', 'email', 'password', 'no_wa',
        'divisi_id', 'org_unit_id', 'jabatan_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function divisi()
    {
        return $this->belongsTo(OrgDivisi::class, 'divisi_id');
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function jabatan()
    {
        return $this->belongsTo(OrgJabatan::class, 'jabatan_id');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
```

---

> **Dokumen ini adalah referensi utama untuk development Fase 1.** Seluruh nama file, class, route, dan komponen di atas sudah final dan harus diikuti secara konsisten. Fase 2 akan dibangun di atas fondasi Fase 1 ini.
