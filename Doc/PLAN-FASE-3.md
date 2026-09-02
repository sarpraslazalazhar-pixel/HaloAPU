# PLAN FASE 3 — Modul SLA Lengkap

> **Proyek:** Halo APU v2 — Sistem Tiketing Internal  
> **Versi Dokumen:** 1.0  
> **Tanggal:** 2026-07-09  
> **Prasyarat:** Fase 1 & Fase 2 sudah selesai (auth, CRUD tiket, booking, dashboard dasar)

---

## Daftar Isi

1. [Konfigurasi SLA per Layanan](#1-konfigurasi-sla-per-layanan)
2. [Service Class: SlaCalculator](#2-service-class-slacalculator)
3. [Integrasi ke Alur Tiket](#3-integrasi-ke-alur-tiket)
4. [Scheduler: CheckSlaCommand](#4-scheduler-checkslcommand)
5. [Indikator Visual](#5-indikator-visual)
6. [Dashboard Kepatuhan SLA](#6-dashboard-kepatuhan-sla)
7. [File-by-File Checklist](#7-file-by-file-checklist)

---

## 1. Konfigurasi SLA per Layanan

### 1.1 Tujuan

Menyediakan halaman admin untuk mengatur threshold SLA (respon & penyelesaian) dalam satuan **menit** per Sub Unit layanan, dengan sistem **default global** yang bisa di-override per layanan.

### 1.2 Struktur Tabel `sla_configs`

```sql
CREATE TABLE sla_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sub_unit_id BIGINT UNSIGNED NULL,          -- FK ke sub_units, NULL = default global
    tier TINYINT UNSIGNED NOT NULL,             -- 1, 2, atau 3
    jenis ENUM('respon', 'penyelesaian') NOT NULL,
    threshold_minutes INT UNSIGNED NOT NULL,    -- menit kerja
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (sub_unit_id) REFERENCES sub_units(id) ON DELETE CASCADE,
    UNIQUE KEY unique_sla (sub_unit_id, tier, jenis)
);
```

**Seeder default global (sub_unit_id = NULL):**

| Tier | Jenis        | Threshold (menit) |
|------|--------------|--------------------|
| 1    | respon       | 30                 |
| 2    | respon       | 60                 |
| 3    | respon       | 120                |
| 1    | penyelesaian | 240                |
| 2    | penyelesaian | 480                |
| 3    | penyelesaian | 1440               |

### 1.3 Migration

**File:** `database/migrations/xxxx_xx_xx_create_sla_configs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_unit_id')
                  ->nullable()
                  ->constrained('sub_units')
                  ->cascadeOnDelete();
            $table->unsignedTinyInteger('tier');           // 1, 2, 3
            $table->enum('jenis', ['respon', 'penyelesaian']);
            $table->unsignedInteger('threshold_minutes');
            $table->timestamps();

            $table->unique(['sub_unit_id', 'tier', 'jenis'], 'unique_sla');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_configs');
    }
};
```

### 1.4 Model `SlaConfig`

**File:** `app/Models/SlaConfig.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaConfig extends Model
{
    protected $fillable = [
        'sub_unit_id',
        'tier',
        'jenis',
        'threshold_minutes',
    ];

    protected $casts = [
        'tier' => 'integer',
        'threshold_minutes' => 'integer',
    ];

    // ──────────────────────────────────
    // Relasi
    // ──────────────────────────────────

    /**
     * Sub Unit terkait. NULL berarti konfigurasi default global.
     */
    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }

    // ──────────────────────────────────
    // Scopes
    // ──────────────────────────────────

    /**
     * Scope untuk ambil konfigurasi global (sub_unit_id = NULL).
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('sub_unit_id');
    }

    /**
     * Scope untuk ambil konfigurasi per Sub Unit.
     */
    public function scopeForSubUnit($query, int $subUnitId)
    {
        return $query->where('sub_unit_id', $subUnitId);
    }

    // ──────────────────────────────────
    // Static Helper
    // ──────────────────────────────────

    /**
     * Ambil threshold untuk sub unit tertentu.
     * Jika tidak ada config khusus, fallback ke global.
     *
     * @param int|null $subUnitId
     * @param int $tier (1, 2, 3)
     * @param string $jenis ('respon' | 'penyelesaian')
     * @return int threshold dalam menit
     */
    public static function getThreshold(?int $subUnitId, int $tier, string $jenis): int
    {
        // Coba cari config spesifik sub unit
        if ($subUnitId) {
            $config = self::where('sub_unit_id', $subUnitId)
                ->where('tier', $tier)
                ->where('jenis', $jenis)
                ->first();

            if ($config) {
                return $config->threshold_minutes;
            }
        }

        // Fallback ke global
        $global = self::whereNull('sub_unit_id')
            ->where('tier', $tier)
            ->where('jenis', $jenis)
            ->first();

        return $global?->threshold_minutes ?? 60; // safety fallback
    }
}
```

### 1.5 Routes

**File:** `routes/admin.php` (atau di dalam group admin yang sudah ada)

```php
use App\Http\Controllers\Admin\SlaConfigController;

Route::middleware(['auth:admin'])->prefix('admin')->name('admin.')->group(function () {
    // ... route admin lainnya ...

    Route::get('sla-config', [SlaConfigController::class, 'index'])->name('sla-config.index');
    Route::put('sla-config', [SlaConfigController::class, 'update'])->name('sla-config.update');
});
```

### 1.6 Controller `Admin\SlaConfigController`

**File:** `app/Http/Controllers/Admin/SlaConfigController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlaConfig;
use App\Models\SubUnit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SlaConfigController extends Controller
{
    /**
     * Tampilkan halaman konfigurasi SLA.
     * Data dikelompokkan: global default + per Sub Unit.
     */
    public function index()
    {
        $globalConfigs = SlaConfig::whereNull('sub_unit_id')
            ->orderBy('jenis')
            ->orderBy('tier')
            ->get();

        $subUnits = SubUnit::with(['unit', 'slaConfigs' => function ($q) {
            $q->orderBy('jenis')->orderBy('tier');
        }])->get();

        return Inertia::render('Admin/SlaConfig/Index', [
            'globalConfigs' => $globalConfigs,
            'subUnits' => $subUnits,
        ]);
    }

    /**
     * Batch update konfigurasi SLA.
     * Request body: { configs: [{ sub_unit_id, tier, jenis, threshold_minutes }, ...] }
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'configs' => 'required|array|min:1',
            'configs.*.sub_unit_id' => 'nullable|exists:sub_units,id',
            'configs.*.tier' => 'required|integer|in:1,2,3',
            'configs.*.jenis' => ['required', Rule::in(['respon', 'penyelesaian'])],
            'configs.*.threshold_minutes' => 'required|integer|min:1',
        ]);

        // Validasi bisnis: Tier 1 < Tier 2 < Tier 3 per sub_unit per jenis
        $grouped = collect($validated['configs'])
            ->groupBy(fn ($c) => ($c['sub_unit_id'] ?? 'global') . '_' . $c['jenis']);

        foreach ($grouped as $key => $items) {
            $sorted = $items->sortBy('tier')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                if ($sorted[$i]['threshold_minutes'] <= $sorted[$i - 1]['threshold_minutes']) {
                    return back()->withErrors([
                        'configs' => "Threshold Tier {$sorted[$i]['tier']} harus lebih besar dari Tier {$sorted[$i-1]['tier']} untuk grup {$key}.",
                    ]);
                }
            }
        }

        // Upsert semua config
        foreach ($validated['configs'] as $config) {
            SlaConfig::updateOrCreate(
                [
                    'sub_unit_id' => $config['sub_unit_id'],
                    'tier' => $config['tier'],
                    'jenis' => $config['jenis'],
                ],
                [
                    'threshold_minutes' => $config['threshold_minutes'],
                ]
            );
        }

        return back()->with('success', 'Konfigurasi SLA berhasil disimpan.');
    }
}
```

### 1.7 React Page: `Admin/SlaConfig/Index.tsx`

**File:** `resources/js/Pages/Admin/SlaConfig/Index.tsx`

**Struktur UI:**

```
┌───────────────────────────────────────────────────────────┐
│ Judul: Konfigurasi SLA                                    │
│ Deskripsi: Atur threshold SLA per layanan                 │
├───────────────────────────────────────────────────────────┤
│ Tabs/Accordion:                                           │
│                                                           │
│ ▼ Default Global                                          │
│ ┌──────────────┬────────┬────────┬────────┐               │
│ │ Jenis        │ Tier 1 │ Tier 2 │ Tier 3 │               │
│ ├──────────────┼────────┼────────┼────────┤               │
│ │ Respon       │ [30]   │ [60]   │ [120]  │  (menit)      │
│ │ Penyelesaian │ [240]  │ [480]  │ [1440] │  (menit)      │
│ └──────────────┴────────┴────────┴────────┘               │
│                                                           │
│ ▼ Sub Unit: Layanan IT                                    │
│ ┌──────────────┬────────┬────────┬────────┬───────────┐   │
│ │ Jenis        │ Tier 1 │ Tier 2 │ Tier 3 │ Override? │   │
│ ├──────────────┼────────┼────────┼────────┼───────────┤   │
│ │ Respon       │ [20]   │ [45]   │ [90]   │ ✅        │   │
│ │ Penyelesaian │ [-]    │ [-]    │ [-]    │ ☐         │   │
│ └──────────────┴────────┴────────┴────────┴───────────┘   │
│  ([-] berarti pakai default global)                       │
│                                                           │
│ ▼ Sub Unit: Layanan Umum                                  │
│ ... (serupa)                                              │
│                                                           │
│ [Simpan Perubahan]                                        │
└───────────────────────────────────────────────────────────┘
```

**Komponen yang dipakai:**
- `Card`, `CardHeader`, `CardContent` dari shadcn/ui
- `Accordion`, `AccordionItem` dari shadcn/ui
- `Input` type number untuk threshold
- `Switch` / `Checkbox` untuk toggle override
- `Button` untuk submit
- `useForm` dari `@inertiajs/react`
- Toast notification untuk feedback

**Logika React:**
```tsx
// Pseudocode struktur komponen
import { useForm } from '@inertiajs/react';

interface SlaConfigFormData {
    configs: Array<{
        sub_unit_id: number | null;
        tier: number;
        jenis: 'respon' | 'penyelesaian';
        threshold_minutes: number;
    }>;
}

export default function SlaConfigIndex({ globalConfigs, subUnits }) {
    const { data, setData, put, processing, errors } = useForm<SlaConfigFormData>({
        configs: buildInitialConfigs(globalConfigs, subUnits),
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.sla-config.update'));
    };

    // Validasi client-side: Tier 1 < Tier 2 < Tier 3
    const validateTierOrder = (subUnitId: number | null, jenis: string): boolean => {
        const items = data.configs.filter(
            c => c.sub_unit_id === subUnitId && c.jenis === jenis
        );
        const sorted = items.sort((a, b) => a.tier - b.tier);
        for (let i = 1; i < sorted.length; i++) {
            if (sorted[i].threshold_minutes <= sorted[i - 1].threshold_minutes) {
                return false;
            }
        }
        return true;
    };

    return (
        <AdminLayout title="Konfigurasi SLA">
            <form onSubmit={handleSubmit}>
                {/* Global Default Section */}
                {/* Per Sub Unit Sections */}
                {/* Submit Button */}
            </form>
        </AdminLayout>
    );
}
```

### 1.8 Validasi Bisnis

| Rule | Keterangan |
|------|-----------|
| `threshold_minutes` harus integer positif | Minimal 1 menit |
| Tier 1 < Tier 2 < Tier 3 | Per kombinasi `sub_unit_id` + `jenis`. Validasi di server & client |
| Sub Unit override opsional | Jika checkbox override tidak dicentang, hapus record SLA untuk sub unit tersebut (pakai global) |
| Minimal 1 config global harus ada | Seeder memastikan ini. Jika admin menghapus, fallback ke hardcoded default di `SlaConfig::getThreshold()` |

---

## 2. Service Class: SlaCalculator

### 2.1 Tujuan

Service class yang menangani semua perhitungan SLA: deadline, pause/resume, cek tier, dan kalkulasi menit kerja berdasarkan jam operasional.

### 2.2 File: `app/Services/SlaCalculator.php`

```php
<?php

namespace App\Services;

use App\Models\SlaConfig;
use App\Models\SystemConfig;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SlaCalculator
{
    /**
     * Format jam kerja dari system_configs:
     * key: 'jam_kerja'
     * value (JSON):
     * {
     *   "senin":    ["08:00", "16:00"],
     *   "selasa":   ["08:00", "16:00"],
     *   "rabu":     ["08:00", "16:00"],
     *   "kamis":    ["08:00", "16:00"],
     *   "jumat":    ["08:00", "16:00"],
     *   "sabtu":    null,
     *   "minggu":   null
     * }
     * null = hari libur / non-kerja
     */

    protected array $workingHours;

    // Map Carbon dayOfWeek (0=Sunday..6=Saturday) ke nama hari Indonesia
    protected array $dayMap = [
        0 => 'minggu',
        1 => 'senin',
        2 => 'selasa',
        3 => 'rabu',
        4 => 'kamis',
        5 => 'jumat',
        6 => 'sabtu',
    ];

    public function __construct()
    {
        $this->workingHours = $this->loadWorkingHours();
    }

    /**
     * Load jam kerja dari system_configs.
     */
    protected function loadWorkingHours(): array
    {
        $config = SystemConfig::where('key', 'jam_kerja')->first();

        if (!$config) {
            // Default: Senin-Jumat 08:00-16:00
            return [
                'senin'  => ['08:00', '16:00'],
                'selasa' => ['08:00', '16:00'],
                'rabu'   => ['08:00', '16:00'],
                'kamis'  => ['08:00', '16:00'],
                'jumat'  => ['08:00', '16:00'],
                'sabtu'  => null,
                'minggu' => null,
            ];
        }

        return json_decode($config->value, true);
    }

    /**
     * Cek apakah hari tertentu adalah hari kerja.
     */
    protected function isWorkingDay(Carbon $date): bool
    {
        $dayName = $this->dayMap[$date->dayOfWeek];
        return !empty($this->workingHours[$dayName]);
    }

    /**
     * Ambil jam mulai kerja untuk hari tertentu.
     */
    protected function getWorkStart(Carbon $date): ?Carbon
    {
        $dayName = $this->dayMap[$date->dayOfWeek];
        $hours = $this->workingHours[$dayName] ?? null;

        if (!$hours) return null;

        return $date->copy()->setTimeFromTimeString($hours[0]);
    }

    /**
     * Ambil jam selesai kerja untuk hari tertentu.
     */
    protected function getWorkEnd(Carbon $date): ?Carbon
    {
        $dayName = $this->dayMap[$date->dayOfWeek];
        $hours = $this->workingHours[$dayName] ?? null;

        if (!$hours) return null;

        return $date->copy()->setTimeFromTimeString($hours[1]);
    }

    // ──────────────────────────────────────────────
    // PUBLIC METHODS
    // ──────────────────────────────────────────────

    /**
     * Hitung deadline respon SLA berdasarkan jam kerja.
     * Ambil threshold Tier 3 (max) sebagai deadline absolut.
     *
     * @param Ticket $ticket
     * @return Carbon deadline respon
     */
    public function calculateResponseDeadline(Ticket $ticket): Carbon
    {
        $subUnitId = $ticket->sub_unit_id;
        $thresholdMinutes = SlaConfig::getThreshold($subUnitId, 3, 'respon');

        return $this->addWorkingMinutes($ticket->created_at, $thresholdMinutes);
    }

    /**
     * Hitung deadline penyelesaian SLA berdasarkan jam kerja.
     * Ambil threshold Tier 3 (max) sebagai deadline absolut.
     *
     * @param Ticket $ticket
     * @return Carbon deadline penyelesaian
     */
    public function calculateResolutionDeadline(Ticket $ticket): Carbon
    {
        $subUnitId = $ticket->sub_unit_id;
        $thresholdMinutes = SlaConfig::getThreshold($subUnitId, 3, 'penyelesaian');

        return $this->addWorkingMinutes($ticket->created_at, $thresholdMinutes);
    }

    /**
     * Pause SLA — dipanggil saat status tiket berubah ke Pending.
     * Catat waktu pause.
     *
     * @param TicketSlaTracking $sla
     */
    public function pauseSla(TicketSlaTracking $sla): void
    {
        if ($sla->paused_at !== null) {
            Log::warning("SLA #{$sla->id} sudah dalam status paused.");
            return;
        }

        $sla->update([
            'paused_at' => Carbon::now(),
        ]);

        Log::info("SLA #{$sla->id} di-pause pada {$sla->paused_at}");
    }

    /**
     * Resume SLA — dipanggil saat status tiket berubah dari Pending ke On Proses.
     * Hitung durasi paused, tambahkan ke total_paused_minutes, recalculate deadline, clear paused_at.
     *
     * @param TicketSlaTracking $sla
     */
    public function resumeSla(TicketSlaTracking $sla): void
    {
        if ($sla->paused_at === null) {
            Log::warning("SLA #{$sla->id} tidak dalam status paused.");
            return;
        }

        $pausedMinutes = $this->getWorkingMinutesBetween($sla->paused_at, Carbon::now());
        $newTotalPaused = $sla->total_paused_minutes + $pausedMinutes;

        // Recalculate deadline: tambah paused minutes ke deadline saat ini
        $newResponseDeadline = $this->addWorkingMinutes(
            $sla->sla_response_deadline,
            $pausedMinutes
        );
        $newResolutionDeadline = $this->addWorkingMinutes(
            $sla->sla_resolution_deadline,
            $pausedMinutes
        );

        $sla->update([
            'paused_at' => null,
            'total_paused_minutes' => $newTotalPaused,
            'sla_response_deadline' => $newResponseDeadline,
            'sla_resolution_deadline' => $newResolutionDeadline,
        ]);

        Log::info("SLA #{$sla->id} di-resume. Paused {$pausedMinutes} menit kerja. Total paused: {$newTotalPaused} menit.");
    }

    /**
     * Cek dan update tier berdasarkan elapsed working time.
     * Return tier saat ini (1, 2, atau 3).
     *
     * @param TicketSlaTracking $sla
     * @return int current tier (1, 2, atau 3)
     */
    public function checkAndUpdateTier(TicketSlaTracking $sla): int
    {
        $ticket = $sla->ticket;
        $subUnitId = $ticket->sub_unit_id;
        $now = Carbon::now();

        // Jika sedang paused, gunakan paused_at sebagai "now"
        $effectiveNow = $sla->paused_at ?? $now;

        // Hitung menit kerja yang sudah berlalu sejak tiket dibuat
        $elapsedMinutes = $this->getWorkingMinutesBetween(
            $ticket->created_at,
            $effectiveNow
        ) - $sla->total_paused_minutes;

        // Cek SLA Respon (jika belum direspon)
        if (!$sla->responded_at) {
            $tier3Resp = SlaConfig::getThreshold($subUnitId, 3, 'respon');
            $tier2Resp = SlaConfig::getThreshold($subUnitId, 2, 'respon');
            $tier1Resp = SlaConfig::getThreshold($subUnitId, 1, 'respon');

            if ($elapsedMinutes >= $tier3Resp) {
                $sla->update([
                    'current_tier' => 3,
                    'is_response_breached' => true,
                ]);
                return 3;
            } elseif ($elapsedMinutes >= $tier2Resp) {
                $sla->update(['current_tier' => max($sla->current_tier, 2)]);
                return max($sla->current_tier, 2);
            } elseif ($elapsedMinutes >= $tier1Resp) {
                $sla->update(['current_tier' => max($sla->current_tier, 1)]);
                return max($sla->current_tier, 1);
            }
        }

        // Cek SLA Penyelesaian (jika belum diselesaikan)
        if (!$sla->resolved_at) {
            $tier3Res = SlaConfig::getThreshold($subUnitId, 3, 'penyelesaian');
            $tier2Res = SlaConfig::getThreshold($subUnitId, 2, 'penyelesaian');
            $tier1Res = SlaConfig::getThreshold($subUnitId, 1, 'penyelesaian');

            if ($elapsedMinutes >= $tier3Res) {
                $sla->update([
                    'current_tier' => 3,
                    'is_resolution_breached' => true,
                ]);
                return 3;
            } elseif ($elapsedMinutes >= $tier2Res) {
                $sla->update(['current_tier' => max($sla->current_tier, 2)]);
                return max($sla->current_tier, 2);
            } elseif ($elapsedMinutes >= $tier1Res) {
                $sla->update(['current_tier' => max($sla->current_tier, 1)]);
                return max($sla->current_tier, 1);
            }
        }

        return $sla->current_tier;
    }

    /**
     * Hitung menit kerja antara dua waktu.
     * Skip hari non-kerja dan jam di luar jam kerja.
     *
     * Contoh: Jika jam kerja Senin-Jumat 08:00-16:00 (480 menit/hari),
     * maka dari Jumat 15:00 ke Senin 09:00 = 60 + 60 = 120 menit kerja.
     *
     * @param Carbon $start
     * @param Carbon $end
     * @return int total menit kerja
     */
    public function getWorkingMinutesBetween(Carbon $start, Carbon $end): int
    {
        if ($start->gte($end)) {
            return 0;
        }

        $totalMinutes = 0;
        $current = $start->copy();

        while ($current->lt($end)) {
            if (!$this->isWorkingDay($current)) {
                $current->addDay()->startOfDay();
                continue;
            }

            $workStart = $this->getWorkStart($current);
            $workEnd = $this->getWorkEnd($current);

            // Jika current sebelum jam kerja, snap ke jam mulai
            if ($current->lt($workStart)) {
                $current = $workStart->copy();
            }

            // Jika current sudah melewati jam selesai, lanjut ke hari berikutnya
            if ($current->gte($workEnd)) {
                $current->addDay()->startOfDay();
                continue;
            }

            // Hitung menit kerja di hari ini
            $dayEnd = $workEnd->copy();
            if ($end->lt($dayEnd)) {
                $dayEnd = $end->copy();
            }

            $minutesToday = $current->diffInMinutes($dayEnd);
            $totalMinutes += $minutesToday;

            // Lanjut ke hari berikutnya
            $current = $workEnd->copy()->addDay()->startOfDay();
        }

        return $totalMinutes;
    }

    /**
     * Tambah menit kerja dari titik waktu tertentu.
     * Menghasilkan Carbon deadline yang sudah memperhitungkan jam kerja.
     *
     * @param Carbon $start titik mulai
     * @param int $minutes menit kerja yang ditambahkan
     * @return Carbon waktu deadline
     */
    public function addWorkingMinutes(Carbon $start, int $minutes): Carbon
    {
        $remaining = $minutes;
        $current = $start->copy();

        while ($remaining > 0) {
            if (!$this->isWorkingDay($current)) {
                $current->addDay()->startOfDay();
                continue;
            }

            $workStart = $this->getWorkStart($current);
            $workEnd = $this->getWorkEnd($current);

            // Snap ke jam mulai jika sebelum jam kerja
            if ($current->lt($workStart)) {
                $current = $workStart->copy();
            }

            // Jika sudah melewati jam selesai, lanjut ke hari berikutnya
            if ($current->gte($workEnd)) {
                $current->addDay()->startOfDay();
                continue;
            }

            // Menit tersedia hari ini
            $availableMinutes = $current->diffInMinutes($workEnd);

            if ($remaining <= $availableMinutes) {
                $current->addMinutes($remaining);
                $remaining = 0;
            } else {
                $remaining -= $availableMinutes;
                $current = $workEnd->copy()->addDay()->startOfDay();
            }
        }

        return $current;
    }
}
```

### 2.3 Service Provider Registration

Daftarkan di `app/Providers/AppServiceProvider.php` (opsional, bisa juga di-resolve otomatis via DI):

```php
// Di method register():
$this->app->singleton(\App\Services\SlaCalculator::class);
```

### 2.4 Unit Test Plan

**File:** `tests/Unit/SlaCalculatorTest.php`

| Test Case | Input | Expected |
|-----------|-------|----------|
| `test_working_minutes_same_day` | Senin 09:00 → Senin 11:00 | 120 menit |
| `test_working_minutes_cross_day` | Jumat 15:00 → Senin 09:00 | 120 menit (60+60) |
| `test_working_minutes_skip_weekend` | Jumat 08:00 → Senin 16:00 | 480+480 = 960 menit |
| `test_add_working_minutes_simple` | Senin 08:00 + 120 | Senin 10:00 |
| `test_add_working_minutes_cross_day` | Senin 15:00 + 120 | Selasa 09:00 |
| `test_add_working_minutes_skip_weekend` | Jumat 15:00 + 120 | Senin 09:00 |
| `test_pause_sla` | Sla aktif, call pauseSla | paused_at = now |
| `test_resume_sla` | Sla paused 30 menit, call resumeSla | total_paused_minutes += 30, deadline geser |
| `test_check_tier_escalation` | Elapsed 65 menit, tier1=30, tier2=60 | current_tier = 2 |

---

## 3. Integrasi ke Alur Tiket

### 3.1 Tabel `ticket_sla_tracking`

**Migration:**

```php
Schema::create('ticket_sla_tracking', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
    $table->timestamp('sla_response_deadline')->nullable();
    $table->timestamp('sla_resolution_deadline')->nullable();
    $table->timestamp('responded_at')->nullable();
    $table->timestamp('resolved_at')->nullable();
    $table->timestamp('paused_at')->nullable();
    $table->unsignedInteger('total_paused_minutes')->default(0);
    $table->unsignedTinyInteger('current_tier')->default(0);
    $table->boolean('is_response_breached')->default(false);
    $table->boolean('is_resolution_breached')->default(false);
    $table->timestamps();
});
```

### 3.2 Model `TicketSlaTracking`

**File:** `app/Models/TicketSlaTracking.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSlaTracking extends Model
{
    protected $table = 'ticket_sla_tracking';

    protected $fillable = [
        'ticket_id',
        'sla_response_deadline',
        'sla_resolution_deadline',
        'responded_at',
        'resolved_at',
        'paused_at',
        'total_paused_minutes',
        'current_tier',
        'is_response_breached',
        'is_resolution_breached',
    ];

    protected $casts = [
        'sla_response_deadline' => 'datetime',
        'sla_resolution_deadline' => 'datetime',
        'responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'paused_at' => 'datetime',
        'total_paused_minutes' => 'integer',
        'current_tier' => 'integer',
        'is_response_breached' => 'boolean',
        'is_resolution_breached' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Cek apakah SLA sedang dalam status paused.
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /**
     * Cek apakah SLA sudah breach (respon atau penyelesaian).
     */
    public function isBreached(): bool
    {
        return $this->is_response_breached || $this->is_resolution_breached;
    }
}
```

### 3.3 Tambahkan Relasi ke Model `Ticket`

```php
// Di app/Models/Ticket.php, tambahkan:
public function slaTracking(): HasOne
{
    return $this->hasOne(TicketSlaTracking::class);
}
```

### 3.4 Integrasi di `TicketWizardController@store`

**Modifikasi di** `app/Http/Controllers/TicketWizardController.php`:

```php
use App\Services\SlaCalculator;

public function store(Request $request, SlaCalculator $slaCalculator)
{
    // ... validasi & simpan tiket (kode existing) ...

    $ticket = Ticket::create([
        // ... data tiket ...
    ]);

    // ── Buat record SLA tracking ──
    $responseDeadline = $slaCalculator->calculateResponseDeadline($ticket);
    $resolutionDeadline = $slaCalculator->calculateResolutionDeadline($ticket);

    TicketSlaTracking::create([
        'ticket_id' => $ticket->id,
        'sla_response_deadline' => $responseDeadline,
        'sla_resolution_deadline' => $resolutionDeadline,
        'current_tier' => 0,
    ]);

    // ... lanjut kirim notifikasi, redirect, dll ...
}
```

### 3.5 Integrasi di `Admin\TicketController@updateStatus`

**Modifikasi di** `app/Http/Controllers/Admin/TicketController.php`:

```php
use App\Services\SlaCalculator;

public function updateStatus(Request $request, Ticket $ticket, SlaCalculator $slaCalculator)
{
    $validated = $request->validate([
        'status' => 'required|in:Open,On Proses,Pending,Solve,Selesai',
    ]);

    $oldStatus = $ticket->status;
    $newStatus = $validated['status'];
    $sla = $ticket->slaTracking;

    // ── Logika SLA berdasarkan transisi status ──

    // Open → On Proses: catat responded_at
    if ($oldStatus === 'Open' && $newStatus === 'On Proses') {
        if ($sla && !$sla->responded_at) {
            $sla->update(['responded_at' => now()]);
        }
    }

    // Any → Pending: pause SLA
    if ($newStatus === 'Pending' && $oldStatus !== 'Pending') {
        if ($sla) {
            $slaCalculator->pauseSla($sla);
        }
    }

    // Pending → On Proses: resume SLA
    if ($oldStatus === 'Pending' && $newStatus === 'On Proses') {
        if ($sla) {
            $slaCalculator->resumeSla($sla);
        }
        // Catat responded_at jika belum
        if ($sla && !$sla->responded_at) {
            $sla->update(['responded_at' => now()]);
        }
    }

    // Any → Solve: catat resolved_at
    if ($newStatus === 'Solve') {
        if ($sla && !$sla->resolved_at) {
            $sla->update(['resolved_at' => now()]);
        }
    }

    // Update status tiket
    $ticket->update(['status' => $newStatus]);

    return back()->with('success', "Status tiket diubah ke {$newStatus}.");
}
```

### 3.6 Diagram Transisi Status & SLA

```
┌──────┐    assign     ┌──────────┐    pause    ┌─────────┐
│ Open │───────────────▶│ On Proses│────────────▶│ Pending │
│      │                │          │◀────────────│         │
└──────┘                │          │   resume    └─────────┘
                        │          │
                        │          │────────────▶┌───────┐
                        │          │   solve     │ Solve │
                        └──────────┘             └───────┘
                                                      │
                                                      │ close
                                                      ▼
                                                 ┌─────────┐
                                                 │ Selesai  │
                                                 └─────────┘

SLA Events:
  Open → On Proses : responded_at = now()
  Any  → Pending   : pauseSla()
  Pending → On Proses : resumeSla()
  Any  → Solve     : resolved_at = now()
```

---

## 4. Scheduler: CheckSlaCommand

### 4.1 File: `app/Console/Commands/CheckSlaCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Notifications\SlaEscalationNotification;
use App\Services\SlaCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSlaCommand extends Command
{
    protected $signature = 'sla:check';
    protected $description = 'Cek status SLA semua tiket aktif dan eskalasi jika diperlukan';

    public function handle(SlaCalculator $slaCalculator): int
    {
        $this->info('Memulai pengecekan SLA...');

        // Ambil semua tiket aktif (Open atau On Proses) yang punya SLA tracking
        $activeTickets = Ticket::whereIn('status', ['Open', 'On Proses'])
            ->whereHas('slaTracking', function ($q) {
                $q->whereNull('paused_at'); // Skip yang sedang paused
            })
            ->with(['slaTracking', 'subUnit.unit', 'assignedAdmin'])
            ->get();

        $escalated = 0;

        foreach ($activeTickets as $ticket) {
            $sla = $ticket->slaTracking;
            $previousTier = $sla->current_tier;

            // Cek dan update tier
            $currentTier = $slaCalculator->checkAndUpdateTier($sla);

            // Jika tier naik, kirim notifikasi
            if ($currentTier > $previousTier) {
                $this->escalate($ticket, $sla, $currentTier);
                $escalated++;
            }
        }

        $this->info("Selesai. {$activeTickets->count()} tiket dicek, {$escalated} eskalasi dikirim.");

        return Command::SUCCESS;
    }

    /**
     * Kirim notifikasi eskalasi sesuai tier.
     */
    protected function escalate(Ticket $ticket, TicketSlaTracking $sla, int $tier): void
    {
        Log::info("Eskalasi SLA Tier {$tier} untuk tiket #{$ticket->id}");

        switch ($tier) {
            case 1:
                // Tier 1: Notifikasi in-app + email ke admin/operator unit terkait
                $this->notifyUnitAdmins($ticket, $sla, $tier, ['database', 'mail']);
                break;

            case 2:
                // Tier 2: Update badge, set priority flag, notifikasi ulang
                $ticket->update(['prioritas' => 'Tinggi']);
                $this->notifyUnitAdmins($ticket, $sla, $tier, ['database', 'mail']);
                break;

            case 3:
                // Tier 3: Breach! Eskalasi WA ke nomor fallback
                $this->notifyUnitAdmins($ticket, $sla, $tier, ['database', 'mail']);
                $this->notifyFallbackWhatsApp($ticket, $sla);
                break;
        }
    }

    /**
     * Kirim notifikasi ke semua admin/operator unit terkait.
     */
    protected function notifyUnitAdmins(Ticket $ticket, TicketSlaTracking $sla, int $tier, array $channels): void
    {
        // Ambil admin yang di-assign ke tiket
        $admins = collect();

        if ($ticket->assignedAdmin) {
            $admins->push($ticket->assignedAdmin);
        }

        // Ambil semua admin unit terkait
        // (implementasi tergantung struktur relasi admin-unit)
        $unitAdmins = \App\Models\Admin::whereHas('units', function ($q) use ($ticket) {
            $q->where('unit_id', $ticket->subUnit?->unit_id);
        })->get();

        $admins = $admins->merge($unitAdmins)->unique('id');

        foreach ($admins as $admin) {
            $admin->notify(new SlaEscalationNotification($ticket, $sla, $tier, $channels));
        }
    }

    /**
     * Kirim WA ke nomor fallback (Tier 3 breach).
     */
    protected function notifyFallbackWhatsApp(Ticket $ticket, TicketSlaTracking $sla): void
    {
        $fallbackNumber = \App\Models\SystemConfig::getValue('nomor_wa_fallback');

        if (!$fallbackNumber) {
            Log::warning('Nomor WA fallback tidak dikonfigurasi.');
            return;
        }

        // Kirim via WhatsApp channel
        // Implementasi detail ada di Fase 4 (WhatsAppChannel)
        try {
            $waGateway = \App\Models\SystemConfig::getValue('wa_gateway_url');
            $waApiKey = \App\Models\SystemConfig::getValue('wa_api_key');

            \Illuminate\Support\Facades\Http::post($waGateway, [
                'api_key' => $waApiKey,
                'receiver' => $fallbackNumber,
                'data' => [
                    'message' => "⚠️ *SLA BREACH - Tier 3*\n\n"
                        . "Tiket: #{$ticket->id}\n"
                        . "Judul: {$ticket->judul}\n"
                        . "Unit: {$ticket->subUnit?->unit?->nama}\n"
                        . "Sub Unit: {$ticket->subUnit?->nama}\n"
                        . "Status: {$ticket->status}\n"
                        . "SLA Respon Breach: " . ($sla->is_response_breached ? 'Ya' : 'Tidak') . "\n"
                        . "SLA Penyelesaian Breach: " . ($sla->is_resolution_breached ? 'Ya' : 'Tidak'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("Gagal kirim WA fallback: " . $e->getMessage());
        }
    }
}
```

### 4.2 Register Schedule

**File:** `routes/console.php` (Laravel 11+)

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sla:check')->everyMinute();
```

### 4.3 Cron Job di cPanel

```bash
* * * * * cd /home/username/haloapu && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5. Indikator Visual

### 5.1 Komponen `SlaBadge.tsx`

**File:** `resources/js/Components/SlaBadge.tsx`

```tsx
import React from 'react';
import { Badge } from '@/Components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/Components/ui/tooltip';

interface SlaBadgeProps {
    currentTier: number;            // 0, 1, 2, 3
    isBreached: boolean;            // is_response_breached || is_resolution_breached
    deadline: string | null;        // ISO datetime string (sla_resolution_deadline)
    respondedAt: string | null;
    resolvedAt: string | null;
    pausedAt: string | null;
    totalPausedMinutes: number;
}

export default function SlaBadge({
    currentTier,
    isBreached,
    deadline,
    respondedAt,
    resolvedAt,
    pausedAt,
    totalPausedMinutes,
}: SlaBadgeProps) {
    const getStatus = () => {
        if (resolvedAt) return { label: 'Selesai', color: 'bg-gray-500', textColor: 'text-white' };
        if (isBreached) return { label: 'Breach', color: 'bg-red-600', textColor: 'text-white' };
        if (currentTier >= 3) return { label: 'Tier 3', color: 'bg-red-500', textColor: 'text-white' };
        if (currentTier === 2) return { label: 'Tier 2', color: 'bg-orange-500', textColor: 'text-white' };
        if (currentTier === 1) return { label: 'Tier 1', color: 'bg-yellow-500', textColor: 'text-black' };

        // Tier 0 — hitung persentase terhadap deadline
        if (deadline) {
            const now = new Date();
            const dl = new Date(deadline);
            const remaining = dl.getTime() - now.getTime();
            const total = dl.getTime() - now.getTime(); // approx

            if (remaining <= 0) {
                return { label: 'Breach', color: 'bg-red-600', textColor: 'text-white' };
            }
        }

        return { label: 'Aman', color: 'bg-green-500', textColor: 'text-white' };
    };

    const status = getStatus();

    const formatSisaWaktu = () => {
        if (!deadline || resolvedAt) return '-';
        const now = new Date();
        const dl = new Date(deadline);
        const diffMs = dl.getTime() - now.getTime();

        if (diffMs <= 0) return 'Melewati deadline';

        const diffMinutes = Math.floor(diffMs / 60000);
        const hours = Math.floor(diffMinutes / 60);
        const minutes = diffMinutes % 60;

        if (hours > 0) return `${hours} jam ${minutes} menit`;
        return `${minutes} menit`;
    };

    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger>
                    <Badge className={`${status.color} ${status.textColor} cursor-default`}>
                        {status.label}
                    </Badge>
                </TooltipTrigger>
                <TooltipContent className="text-sm space-y-1">
                    <p><strong>Status SLA:</strong> {status.label}</p>
                    <p><strong>Sisa Waktu:</strong> {formatSisaWaktu()}</p>
                    <p><strong>Tier Saat Ini:</strong> {currentTier}</p>
                    {pausedAt && <p><strong>Sedang Paused</strong></p>}
                    {totalPausedMinutes > 0 && (
                        <p><strong>Total Paused:</strong> {totalPausedMinutes} menit</p>
                    )}
                    {respondedAt && (
                        <p><strong>Direspon:</strong> {new Date(respondedAt).toLocaleString('id-ID')}</p>
                    )}
                    {resolvedAt && (
                        <p><strong>Diselesaikan:</strong> {new Date(resolvedAt).toLocaleString('id-ID')}</p>
                    )}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
```

### 5.2 Integrasi ke DataTable Tiket Admin

**File:** `resources/js/Pages/Admin/Tiketing/Index.tsx`

Tambahkan kolom baru di definisi kolom DataTable:

```tsx
// Di array columns, tambahkan:
{
    accessorKey: 'sla_tracking',
    header: 'SLA',
    cell: ({ row }) => {
        const sla = row.original.sla_tracking;
        if (!sla) return <span className="text-muted-foreground">-</span>;

        return (
            <SlaBadge
                currentTier={sla.current_tier}
                isBreached={sla.is_response_breached || sla.is_resolution_breached}
                deadline={sla.sla_resolution_deadline}
                respondedAt={sla.responded_at}
                resolvedAt={sla.resolved_at}
                pausedAt={sla.paused_at}
                totalPausedMinutes={sla.total_paused_minutes}
            />
        );
    },
},
```

**Di controller yang merender halaman ini**, pastikan eager load `slaTracking`:

```php
$tickets = Ticket::with(['slaTracking', 'subUnit.unit', 'user', 'assignedAdmin'])
    ->latest()
    ->paginate(15);
```

### 5.3 Warna Badge Summary

| Kondisi | Warna | Label |
|---------|-------|-------|
| `currentTier == 0` dan sisa waktu > 50% | 🟢 Hijau | Aman |
| `currentTier == 1` atau sisa waktu ≤ 50% | 🟡 Kuning | Tier 1 |
| `currentTier == 2` | 🟠 Oranye | Tier 2 |
| `currentTier == 3` | 🔴 Merah | Tier 3 |
| `isBreached == true` | 🔴 Merah gelap | Breach |
| `resolvedAt != null` | ⚪ Abu-abu | Selesai |

---

## 6. Dashboard Kepatuhan SLA

### 6.1 Tambahan di Controller Dashboard

**File:** `app/Http/Controllers/Admin/DashboardController.php`

Tambahkan method atau modifikasi `index()`:

```php
use App\Models\TicketSlaTracking;
use Illuminate\Support\Facades\DB;

public function index(Request $request)
{
    // ... data dashboard existing ...

    // ── SLA Compliance Data ──
    $period = $request->get('sla_period', now()->format('Y-m'));
    $unitId = $request->get('sla_unit_id');

    $slaQuery = TicketSlaTracking::query()
        ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
        ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
        ->join('units', 'sub_units.unit_id', '=', 'units.id');

    if ($unitId) {
        $slaQuery->where('units.id', $unitId);
    }

    // Total tiket yang sudah direspon/diselesaikan
    $totalResolved = (clone $slaQuery)->whereNotNull('resolved_at')->count();
    $totalResponded = (clone $slaQuery)->whereNotNull('responded_at')->count();
    $totalAll = (clone $slaQuery)->count();

    // Breach counts
    $responseBreach = (clone $slaQuery)->where('is_response_breached', true)->count();
    $resolutionBreach = (clone $slaQuery)->where('is_resolution_breached', true)->count();

    // Persentase kepatuhan
    $responseCompliance = $totalResponded > 0
        ? round((($totalResponded - $responseBreach) / $totalResponded) * 100, 1)
        : 100;

    $resolutionCompliance = $totalResolved > 0
        ? round((($totalResolved - $resolutionBreach) / $totalResolved) * 100, 1)
        : 100;

    // Data untuk PieChart: Dalam SLA vs Breach
    $pieChartData = [
        ['name' => 'Dalam SLA', 'value' => $totalAll - $responseBreach - $resolutionBreach],
        ['name' => 'Breach Respon', 'value' => $responseBreach],
        ['name' => 'Breach Penyelesaian', 'value' => $resolutionBreach],
    ];

    // Data untuk BarChart: Kepatuhan per Unit per bulan
    $barChartData = DB::table('ticket_sla_tracking')
        ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
        ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
        ->join('units', 'sub_units.unit_id', '=', 'units.id')
        ->select(
            'units.nama as unit_nama',
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) as dalam_sla'),
            DB::raw('SUM(CASE WHEN is_response_breached = 1 OR is_resolution_breached = 1 THEN 1 ELSE 0 END) as breach'),
        )
        ->whereYear('tickets.created_at', substr($period, 0, 4))
        ->whereMonth('tickets.created_at', substr($period, 5, 2))
        ->groupBy('units.id', 'units.nama')
        ->get();

    return Inertia::render('Admin/Dashboard/Index', [
        // ... data existing ...
        'slaStats' => [
            'responseCompliance' => $responseCompliance,
            'resolutionCompliance' => $resolutionCompliance,
            'totalBreach' => $responseBreach + $resolutionBreach,
            'totalAll' => $totalAll,
        ],
        'slaPieChart' => $pieChartData,
        'slaBarChart' => $barChartData,
        'slaFilters' => [
            'period' => $period,
            'unitId' => $unitId,
        ],
        'units' => \App\Models\Unit::select('id', 'nama')->get(),
    ]);
}
```

### 6.2 React Dashboard Extension

**File:** `resources/js/Pages/Admin/Dashboard/Index.tsx`

Tambahkan section SLA di bawah section dashboard existing:

```tsx
// Import Recharts
import {
    PieChart, Pie, Cell,
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip as RechartsTooltip, Legend,
    ResponsiveContainer,
} from 'recharts';

// Warna untuk PieChart
const PIE_COLORS = ['#22c55e', '#ef4444', '#f97316']; // hijau, merah, oranye

// Di dalam komponen, tambahkan section:
<section className="mt-8 space-y-6">
    <h2 className="text-xl font-semibold">Kepatuhan SLA</h2>

    {/* Filter */}
    <div className="flex gap-4">
        <Select value={slaFilters.unitId} onValueChange={handleUnitFilter}>
            <SelectTrigger className="w-48">
                <SelectValue placeholder="Semua Unit" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="">Semua Unit</SelectItem>
                {units.map(u => (
                    <SelectItem key={u.id} value={u.id.toString()}>
                        {u.nama}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>

        <Input
            type="month"
            value={slaFilters.period}
            onChange={handlePeriodFilter}
            className="w-48"
        />
    </div>

    {/* Kartu Statistik */}
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm text-muted-foreground">
                    Kepatuhan SLA Respon
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold text-green-500">
                    {slaStats.responseCompliance}%
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm text-muted-foreground">
                    Kepatuhan SLA Penyelesaian
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold text-blue-500">
                    {slaStats.resolutionCompliance}%
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm text-muted-foreground">
                    Total Breach
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold text-red-500">
                    {slaStats.totalBreach}
                </p>
                <p className="text-sm text-muted-foreground">
                    dari {slaStats.totalAll} tiket
                </p>
            </CardContent>
        </Card>
    </div>

    {/* Grafik */}
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* PieChart: Dalam SLA vs Breach */}
        <Card>
            <CardHeader>
                <CardTitle>Distribusi Kepatuhan SLA</CardTitle>
            </CardHeader>
            <CardContent>
                <ResponsiveContainer width="100%" height={300}>
                    <PieChart>
                        <Pie
                            data={slaPieChart}
                            dataKey="value"
                            nameKey="name"
                            cx="50%"
                            cy="50%"
                            outerRadius={100}
                            label={({ name, percent }) =>
                                `${name}: ${(percent * 100).toFixed(0)}%`
                            }
                        >
                            {slaPieChart.map((_, index) => (
                                <Cell key={index} fill={PIE_COLORS[index % PIE_COLORS.length]} />
                            ))}
                        </Pie>
                        <RechartsTooltip />
                        <Legend />
                    </PieChart>
                </ResponsiveContainer>
            </CardContent>
        </Card>

        {/* BarChart: Kepatuhan per Unit */}
        <Card>
            <CardHeader>
                <CardTitle>Kepatuhan per Unit</CardTitle>
            </CardHeader>
            <CardContent>
                <ResponsiveContainer width="100%" height={300}>
                    <BarChart data={slaBarChart}>
                        <CartesianGrid strokeDasharray="3 3" />
                        <XAxis dataKey="unit_nama" />
                        <YAxis />
                        <RechartsTooltip />
                        <Legend />
                        <Bar dataKey="dalam_sla" name="Dalam SLA" fill="#22c55e" />
                        <Bar dataKey="breach" name="Breach" fill="#ef4444" />
                    </BarChart>
                </ResponsiveContainer>
            </CardContent>
        </Card>
    </div>
</section>
```

---

## 7. File-by-File Checklist

### 7.1 Backend — PHP/Laravel

| # | File | Aksi | Keterangan | Status |
|---|------|------|-----------|--------|
| 1 | `database/migrations/xxxx_create_sla_configs_table.php` | **Buat baru** | Migration tabel `sla_configs` | ✅ |
| 2 | `database/migrations/xxxx_create_ticket_sla_tracking_table.php` | **Buat baru** | Migration tabel `ticket_sla_tracking` | ✅ |
| 3 | `database/seeders/SlaConfigSeeder.php` | **Buat baru** | Seeder default global SLA (6 record) | ✅ |
| 4 | `database/seeders/DatabaseSeeder.php` | **Edit** | Tambahkan `$this->call(SlaConfigSeeder::class)` | ✅ |
| 5 | `app/Models/SlaConfig.php` | **Buat baru** | Model + scope + getThreshold() | ✅ |
| 6 | `app/Models/TicketSlaTracking.php` | **Buat baru** | Model + isPaused() + isBreached() | ✅ |
| 7 | `app/Models/Ticket.php` | **Edit** | Tambah relasi `hasOne TicketSlaTracking` | ✅ |
| 8 | `app/Models/SubUnit.php` | **Edit** | Tambah relasi `hasMany SlaConfig` | ✅ |
| 9 | `app/Services/SlaCalculator.php` | **Buat baru** | Semua method perhitungan SLA | ✅ |
| 10 | `app/Http/Controllers/Admin/SlaConfigController.php` | **Buat baru** | index() + update() | ✅ |
| 11 | `app/Http/Controllers/TicketWizardController.php` | **Edit** | Inject SlaCalculator + buat SLA tracking | ✅ |
| 12 | `app/Http/Controllers/Admin/TicketController.php` | **Edit** | Logika SLA di updateStatus() | ✅ |
| 13 | `app/Http/Controllers/Admin/DashboardController.php` | **Edit** | Query SLA compliance + charts | ✅ |
| 14 | `app/Console/Commands/CheckSlaCommand.php` | **Buat baru** | Command `sla:check` | ✅ |
| 15 | `app/Notifications/SlaEscalationNotification.php` | **Buat baru** | Notification class | ⏭️ (Fase 4) |
| 16 | `routes/web.php` | **Edit** | Route GET/PUT /admin/sla-config | ✅ |
| 17 | `routes/console.php` | **Edit** | Register scheduler `sla:check` | ✅ |
| 18 | `app/Providers/AppServiceProvider.php` | **Edit** (opsional) | Register singleton | ⏭️ (opsional) |

### 7.2 Frontend — React/TypeScript

| # | File | Aksi | Keterangan | Status |
|---|------|------|-----------|--------|
| 19 | `resources/js/Pages/Admin/SlaConfig/Index.tsx` | **Buat baru** | Halaman konfigurasi SLA — Accordion + form | ✅ |
| 20 | `resources/js/Components/SlaBadge.tsx` | **Buat baru** | Badge SLA + Tooltip detail | ✅ |
| 21 | `resources/js/Pages/Admin/Tiketing/Index.tsx` | **Edit** | Tambah kolom SLA di DataTable | ✅ |
| 22 | `resources/js/Pages/Admin/Dashboard/Index.tsx` | **Edit** | Section Kepatuhan SLA (3 kartu + Pie + Bar + Line) | ✅ |
