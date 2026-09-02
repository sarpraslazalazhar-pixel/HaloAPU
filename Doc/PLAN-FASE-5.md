# PLAN FASE 5 — CSAT, Live Monitor, Grafik, Konfigurasi, Deployment

> **Proyek:** Halo APU v2 — Sistem Tiketing Internal  
> **Versi Dokumen:** 1.0  
> **Tanggal:** 2026-07-09  
> **Prasyarat:** Fase 1, 2, 3, & 4 sudah selesai (auth, CRUD tiket, booking, SLA, reminder, notification center)

---

## Daftar Isi

1. [Modul CSAT](#1-modul-csat)
2. [Live Monitor](#2-live-monitor)
3. [Grafik Statistik (Extend Dashboard)](#3-grafik-statistik-extend-dashboard)
4. [Modul Konfigurasi Sistem](#4-modul-konfigurasi-sistem)
5. [Manajemen Admin](#5-manajemen-admin)
6. [Manajemen User](#6-manajemen-user)
7. [Hardening Keamanan](#7-hardening-keamanan)
8. [Deployment ke cPanel](#8-deployment-ke-cpanel)
9. [File-by-File Checklist](#9-file-by-file-checklist)

---

## 1. Modul CSAT

### 1.1 Tabel `csats`

**Struktur (sudah ada dari Fase 2):**

```sql
CREATE TABLE csats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,         -- 1-5
    komentar TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_csat (ticket_id)        -- 1 rating per tiket
);
```

### 1.2 Model `Csat`

**File:** `app/Models/Csat.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Csat extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'rating',
        'komentar',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

**Tambahkan relasi di `Ticket` model:**

```php
// app/Models/Ticket.php
public function csat(): HasOne
{
    return $this->hasOne(Csat::class);
}
```

### 1.3 User Side — Submit Rating

#### 1.3.1 Routes

```php
// routes/web.php (dalam group auth user)
Route::middleware(['auth'])->group(function () {
    Route::post('/csat/{ticket}', [CsatController::class, 'store'])->name('csat.store');
    Route::get('/csat/riwayat', [CsatController::class, 'riwayat'])->name('csat.riwayat');
});
```

#### 1.3.2 Controller `CsatController`

**File:** `app/Http/Controllers/CsatController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Csat;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CsatController extends Controller
{
    /**
     * Simpan rating CSAT.
     *
     * Validasi:
     * 1. Tiket harus milik user yang login
     * 2. Status tiket harus Solve atau Selesai
     * 3. Belum ada rating sebelumnya untuk tiket ini
     */
    public function store(Request $request, Ticket $ticket)
    {
        // Validasi kepemilikan
        if ($ticket->user_id !== $request->user()->id) {
            abort(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        // Validasi status
        if (!in_array($ticket->status, ['Solve', 'Selesai'])) {
            return back()->withErrors([
                'rating' => 'Rating hanya bisa diberikan untuk tiket yang sudah diselesaikan.',
            ]);
        }

        // Validasi belum ada rating
        if ($ticket->csat()->exists()) {
            return back()->withErrors([
                'rating' => 'Anda sudah memberikan rating untuk tiket ini.',
            ]);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'komentar' => 'nullable|string|max:1000',
        ]);

        Csat::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'rating' => $validated['rating'],
            'komentar' => $validated['komentar'],
        ]);

        // Optional: ubah status tiket ke Selesai setelah rating
        if ($ticket->status === 'Solve') {
            $ticket->update(['status' => 'Selesai']);
        }

        return back()->with('success', 'Terima kasih atas rating Anda!');
    }

    /**
     * Riwayat CSAT yang pernah diberikan user.
     */
    public function riwayat(Request $request)
    {
        $csats = Csat::where('user_id', $request->user()->id)
            ->with(['ticket' => function ($q) {
                $q->select('id', 'judul', 'status', 'sub_unit_id', 'created_at')
                    ->with('subUnit:id,nama');
            }])
            ->latest()
            ->paginate(10);

        return Inertia::render('User/Csat/Riwayat', [
            'csats' => $csats,
        ]);
    }
}
```

#### 1.3.3 UI: Dialog Rating di Detail Tiket

Tambahkan di halaman detail tiket user (`User/Tiketing/Detail.tsx` atau serupa):

```tsx
import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogFooter,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';

interface CsatDialogProps {
    ticketId: number;
    disabled?: boolean;
    existingRating?: number | null;
}

export function CsatDialog({ ticketId, disabled = false, existingRating }: CsatDialogProps) {
    const [open, setOpen] = useState(false);
    const [hoverRating, setHoverRating] = useState(0);

    const { data, setData, post, processing, errors, reset } = useForm({
        rating: 0,
        komentar: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('csat.store', { ticket: ticketId }), {
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    };

    if (existingRating) {
        return (
            <div className="flex items-center gap-1">
                <span className="text-sm text-muted-foreground mr-1">Rating Anda:</span>
                {[1, 2, 3, 4, 5].map((star) => (
                    <Star
                        key={star}
                        className={`h-5 w-5 ${
                            star <= existingRating
                                ? 'fill-yellow-400 text-yellow-400'
                                : 'text-gray-300'
                        }`}
                    />
                ))}
            </div>
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" disabled={disabled}>
                    <Star className="h-4 w-4 mr-2" />
                    Berikan Rating
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Berikan Rating Layanan</DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Star Rating */}
                    <div>
                        <Label>Rating</Label>
                        <div className="flex items-center gap-1 mt-2">
                            {[1, 2, 3, 4, 5].map((star) => (
                                <button
                                    key={star}
                                    type="button"
                                    onMouseEnter={() => setHoverRating(star)}
                                    onMouseLeave={() => setHoverRating(0)}
                                    onClick={() => setData('rating', star)}
                                    className="p-1 transition-transform hover:scale-110"
                                >
                                    <Star
                                        className={`h-8 w-8 transition-colors ${
                                            star <= (hoverRating || data.rating)
                                                ? 'fill-yellow-400 text-yellow-400'
                                                : 'text-gray-300 dark:text-gray-600'
                                        }`}
                                    />
                                </button>
                            ))}
                        </div>
                        <div className="text-sm text-muted-foreground mt-1">
                            {data.rating === 1 && 'Sangat Buruk'}
                            {data.rating === 2 && 'Buruk'}
                            {data.rating === 3 && 'Cukup'}
                            {data.rating === 4 && 'Baik'}
                            {data.rating === 5 && 'Sangat Baik'}
                        </div>
                        {errors.rating && (
                            <p className="text-sm text-destructive mt-1">{errors.rating}</p>
                        )}
                    </div>

                    {/* Komentar */}
                    <div>
                        <Label htmlFor="komentar">Komentar (opsional)</Label>
                        <Textarea
                            id="komentar"
                            value={data.komentar}
                            onChange={(e) => setData('komentar', e.target.value)}
                            placeholder="Berikan komentar tentang layanan yang Anda terima..."
                            rows={4}
                            maxLength={1000}
                        />
                        <p className="text-xs text-muted-foreground mt-1">
                            {data.komentar.length}/1000 karakter
                        </p>
                        {errors.komentar && (
                            <p className="text-sm text-destructive mt-1">{errors.komentar}</p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing || data.rating === 0}>
                            Kirim Rating
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
```

#### 1.3.4 React Page: `User/Csat/Riwayat.tsx`

**File:** `resources/js/Pages/User/Csat/Riwayat.tsx`

```
┌──────────────────────────────────────────────────────────────────┐
│ Judul: Riwayat Rating Saya                                       │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│ ┌────────────┬──────────────────┬────────┬───────────┬──────────┐│
│ │ Tiket      │ Judul            │ Rating │ Komentar  │ Tanggal  ││
│ ├────────────┼──────────────────┼────────┼───────────┼──────────┤│
│ │ #456       │ Printer Rusak    │ ⭐⭐⭐⭐  │ Cepat... │ 05 Jul  ││
│ │ #123       │ Ruang Meeting    │ ⭐⭐⭐⭐⭐│ Sangat..│ 01 Jul  ││
│ └────────────┴──────────────────┴────────┴───────────┴──────────┘│
│                                                                  │
│ [← Sebelumnya]  Halaman 1 dari 2  [Selanjutnya →]              │
└──────────────────────────────────────────────────────────────────┘
```

### 1.4 Admin Side — Laporan CSAT

#### 1.4.1 Routes

```php
// routes/admin.php
Route::middleware(['auth:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('csat', [Admin\CsatController::class, 'index'])->name('csat.index');
});
```

#### 1.4.2 Controller `Admin\CsatController`

**File:** `app/Http/Controllers/Admin/CsatController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Csat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CsatController extends Controller
{
    public function index(Request $request)
    {
        // ── DataTable: semua CSAT ──
        $query = Csat::with([
            'ticket' => fn ($q) => $q->with('subUnit.unit'),
            'user:id,name,email',
        ]);

        // Filter: Unit
        if ($unitId = $request->get('unit_id')) {
            $query->whereHas('ticket.subUnit.unit', fn ($q) => $q->where('id', $unitId));
        }

        // Filter: Sub Unit
        if ($subUnitId = $request->get('sub_unit_id')) {
            $query->whereHas('ticket', fn ($q) => $q->where('sub_unit_id', $subUnitId));
        }

        // Filter: Periode
        if ($bulan = $request->get('bulan')) {
            $query->whereYear('created_at', substr($bulan, 0, 4))
                  ->whereMonth('created_at', substr($bulan, 5, 2));
        }

        // Filter: Rating range
        if ($ratingMin = $request->get('rating_min')) {
            $query->where('rating', '>=', $ratingMin);
        }
        if ($ratingMax = $request->get('rating_max')) {
            $query->where('rating', '<=', $ratingMax);
        }

        $csats = $query->latest()->paginate(15);

        // ── Statistik Agregat ──
        $statsQuery = Csat::query();
        if ($unitId) {
            $statsQuery->whereHas('ticket.subUnit.unit', fn ($q) => $q->where('id', $unitId));
        }

        $avgRating = round($statsQuery->avg('rating'), 2);
        $totalRating = $statsQuery->count();
        $ratingDistribution = $statsQuery->select('rating', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('rating')
            ->orderBy('rating')
            ->get();

        // ── Grafik: Rata-rata CSAT per Unit ──
        $csatPerUnit = Csat::join('tickets', 'csats.ticket_id', '=', 'tickets.id')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->join('units', 'sub_units.unit_id', '=', 'units.id')
            ->select(
                'units.nama as unit_nama',
                DB::raw('ROUND(AVG(csats.rating), 2) as rata_rata'),
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('units.id', 'units.nama')
            ->get();

        return Inertia::render('Admin/Csat/Index', [
            'csats' => $csats,
            'stats' => [
                'avgRating' => $avgRating,
                'totalRating' => $totalRating,
                'ratingDistribution' => $ratingDistribution,
            ],
            'csatPerUnit' => $csatPerUnit,
            'filters' => $request->only(['unit_id', 'sub_unit_id', 'bulan', 'rating_min', 'rating_max']),
            'units' => \App\Models\Unit::select('id', 'nama')->get(),
            'subUnits' => \App\Models\SubUnit::select('id', 'unit_id', 'nama')->get(),
        ]);
    }
}
```

#### 1.4.3 React Page: `Admin/Csat/Index.tsx`

**Struktur UI:**

```
┌─────────────────────────────────────────────────────────────────────┐
│ Judul: Laporan CSAT (Customer Satisfaction)                         │
├─────────────────────────────────────────────────────────────────────┤
│ Filter: [Unit ▾] [Sub Unit ▾] [Bulan ▾] [Rating Min ▾] [Max ▾]    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│ ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐   │
│ │ Rata-rata    │  │ Total Rating │  │ Distribusi Rating        │   │
│ │ ⭐ 4.2/5.0   │  │ 156          │  │ ████████████ 5: 45%      │   │
│ │              │  │              │  │ ████████ 4: 30%          │   │
│ │              │  │              │  │ ████ 3: 15%              │   │
│ │              │  │              │  │ ██ 2: 7%                 │   │
│ │              │  │              │  │ █ 1: 3%                  │   │
│ └──────────────┘  └──────────────┘  └──────────────────────────┘   │
│                                                                     │
│ ┌─────────────────────────────────────────┐                         │
│ │ Grafik: Rata-rata CSAT per Unit         │                         │
│ │ (Recharts BarChart horizontal)          │                         │
│ │                                         │                         │
│ │ IT          ████████████ 4.5            │                         │
│ │ Umum        ████████ 3.8               │                         │
│ │ Keuangan    ██████████ 4.2             │                         │
│ └─────────────────────────────────────────┘                         │
│                                                                     │
│ DataTable:                                                          │
│ ┌──────┬────────────┬────────┬────────┬────────┬──────────┬───────┐│
│ │ #    │ Tiket      │ User   │ Unit   │ Rating │ Komentar │Tanggal││
│ ├──────┼────────────┼────────┼────────┼────────┼──────────┼───────┤│
│ │ 1    │ #456       │ Budi   │ IT     │ ⭐⭐⭐⭐  │ Bagus..  │05 Jul ││
│ │ 2    │ #123       │ Siti   │ Umum   │ ⭐⭐⭐⭐⭐ │ Sangat..│01 Jul ││
│ └──────┴────────────┴────────┴────────┴────────┴──────────┴───────┘│
│                                                                     │
│ Pagination                                                          │
└─────────────────────────────────────────────────────────────────────┘
```

**Komponen yang dipakai:**
- `Card` untuk statistik dan grafik
- `BarChart` dari Recharts (horizontal) untuk rata-rata per unit
- `DataTable` dengan kolom sortable
- `Select` untuk filter
- `Input` type month untuk periode
- Star rating display component (read-only)

---

## 2. Live Monitor

### 2.1 Tujuan

Menampilkan status **real-time** aset (ruang rapat, kendaraan) berdasarkan data booking. Menggunakan `usePoll()` dari Inertia v2 untuk auto-refresh setiap 10 detik.

### 2.2 Routes

```php
// routes/web.php — user
Route::middleware(['auth'])->group(function () {
    Route::get('/monitor', [MonitorController::class, 'userIndex'])->name('monitor.user');
});

// routes/admin.php — admin
Route::middleware(['auth:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('monitor', [MonitorController::class, 'adminIndex'])->name('monitor.index');
});
```

### 2.3 Controller `MonitorController`

**File:** `app/Http/Controllers/MonitorController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\RoomVehicleBooking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MonitorController extends Controller
{
    /**
     * Ambil data aset dengan status real-time.
     *
     * Status ditentukan berdasarkan:
     * - Tersedia: tidak ada booking aktif saat ini
     * - Dipesan: ada booking disetujui yang belum mulai
     * - Sedang Dipakai: ada booking yang sedang berlangsung (tanggal_mulai <= now <= tanggal_selesai)
     */
    protected function getAssetData(?string $tipe = null)
    {
        $now = Carbon::now();

        // Ambil semua booking yang relevan (hari ini dan ke depan)
        $query = RoomVehicleBooking::where('status', 'Disetujui')
            ->where('tanggal_selesai', '>=', $now->startOfDay())
            ->with(['ticket.user:id,name']);

        if ($tipe) {
            $query->where('tipe', $tipe);
        }

        $bookings = $query->get();

        // Ambil daftar unik aset
        $allAssets = RoomVehicleBooking::select('nama_aset', 'tipe')
            ->distinct()
            ->orderBy('tipe')
            ->orderBy('nama_aset')
            ->get();

        // Map status per aset
        return $allAssets->map(function ($asset) use ($bookings, $now) {
            $assetBookings = $bookings->where('nama_aset', $asset->nama_aset);

            // Cek apakah sedang dipakai
            $activeBooking = $assetBookings->first(function ($b) use ($now) {
                return Carbon::parse($b->tanggal_mulai)->lte($now)
                    && Carbon::parse($b->tanggal_selesai)->gte($now);
            });

            if ($activeBooking) {
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => 'Sedang Dipakai',
                    'user' => $activeBooking->ticket?->user?->name ?? '-',
                    'waktu_mulai' => Carbon::parse($activeBooking->tanggal_mulai)->format('H:i'),
                    'waktu_selesai' => Carbon::parse($activeBooking->tanggal_selesai)->format('H:i'),
                    'booking_id' => $activeBooking->id,
                ];
            }

            // Cek apakah ada booking mendatang hari ini
            $nextBooking = $assetBookings
                ->filter(fn ($b) => Carbon::parse($b->tanggal_mulai)->gt($now))
                ->sortBy('tanggal_mulai')
                ->first();

            if ($nextBooking && Carbon::parse($nextBooking->tanggal_mulai)->isToday()) {
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => 'Dipesan',
                    'user' => $nextBooking->ticket?->user?->name ?? '-',
                    'waktu_mulai' => Carbon::parse($nextBooking->tanggal_mulai)->format('H:i'),
                    'waktu_selesai' => Carbon::parse($nextBooking->tanggal_selesai)->format('H:i'),
                    'booking_id' => $nextBooking->id,
                ];
            }

            return [
                'nama_aset' => $asset->nama_aset,
                'tipe' => $asset->tipe,
                'status' => 'Tersedia',
                'user' => null,
                'waktu_mulai' => null,
                'waktu_selesai' => null,
                'booking_id' => null,
            ];
        });
    }

    public function userIndex()
    {
        return Inertia::render('User/Monitor/Index', [
            'assets' => $this->getAssetData(),
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }

    public function adminIndex()
    {
        return Inertia::render('Admin/Monitor/Index', [
            'assets' => $this->getAssetData(),
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }
}
```

### 2.4 React Page: `User/Monitor/Index.tsx` & `Admin/Monitor/Index.tsx`

**Kedua halaman hampir identik.** Admin version mungkin punya tambahan tombol aksi. Buat shared component.

**Shared Component:** `resources/js/Components/MonitorGrid.tsx`

```tsx
import React from 'react';
import { usePoll } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Car, DoorOpen, User, Clock } from 'lucide-react';

interface AssetData {
    nama_aset: string;
    tipe: 'ruang' | 'kendaraan';
    status: 'Tersedia' | 'Dipesan' | 'Sedang Dipakai';
    user: string | null;
    waktu_mulai: string | null;
    waktu_selesai: string | null;
    booking_id: number | null;
}

interface MonitorGridProps {
    assets: AssetData[];
    lastUpdated: string;
}

const STATUS_COLORS = {
    'Tersedia': 'bg-green-500/10 border-green-500/30 dark:bg-green-500/20',
    'Dipesan': 'bg-yellow-500/10 border-yellow-500/30 dark:bg-yellow-500/20',
    'Sedang Dipakai': 'bg-red-500/10 border-red-500/30 dark:bg-red-500/20',
};

const STATUS_BADGE_COLORS = {
    'Tersedia': 'bg-green-500 text-white',
    'Dipesan': 'bg-yellow-500 text-black',
    'Sedang Dipakai': 'bg-red-500 text-white',
};

export default function MonitorGrid({ assets, lastUpdated }: MonitorGridProps) {
    // Auto-refresh setiap 10 detik
    usePoll(10000);

    // Pisahkan berdasarkan tipe
    const ruangAssets = assets.filter(a => a.tipe === 'ruang');
    const kendaraanAssets = assets.filter(a => a.tipe === 'kendaraan');

    const renderAssetCard = (asset: AssetData) => (
        <Card
            key={asset.nama_aset}
            className={`border-2 transition-all duration-300 ${STATUS_COLORS[asset.status]}`}
        >
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        {asset.tipe === 'ruang'
                            ? <DoorOpen className="h-5 w-5" />
                            : <Car className="h-5 w-5" />
                        }
                        <CardTitle className="text-base">{asset.nama_aset}</CardTitle>
                    </div>
                    <Badge className={STATUS_BADGE_COLORS[asset.status]}>
                        {asset.status}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                {asset.status !== 'Tersedia' ? (
                    <div className="space-y-1 text-sm">
                        <div className="flex items-center gap-2">
                            <User className="h-4 w-4 text-muted-foreground" />
                            <span>{asset.user}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <Clock className="h-4 w-4 text-muted-foreground" />
                            <span>{asset.waktu_mulai} — {asset.waktu_selesai}</span>
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Tidak ada booking saat ini
                    </p>
                )}
            </CardContent>
        </Card>
    );

    return (
        <div className="space-y-8">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold">Live Monitor</h1>
                    <p className="text-sm text-muted-foreground">
                        Status aset diperbarui otomatis setiap 10 detik
                    </p>
                </div>
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <div className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
                    Terakhir diperbarui: {lastUpdated}
                </div>
            </div>

            {/* Legenda */}
            <div className="flex gap-4">
                <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full bg-green-500" />
                    <span className="text-sm">Tersedia</span>
                </div>
                <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full bg-yellow-500" />
                    <span className="text-sm">Dipesan</span>
                </div>
                <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full bg-red-500" />
                    <span className="text-sm">Sedang Dipakai</span>
                </div>
            </div>

            {/* Section: Ruang */}
            {ruangAssets.length > 0 && (
                <section>
                    <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
                        <DoorOpen className="h-5 w-5" />
                        Ruang Rapat
                    </h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        {ruangAssets.map(renderAssetCard)}
                    </div>
                </section>
            )}

            {/* Section: Kendaraan */}
            {kendaraanAssets.length > 0 && (
                <section>
                    <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Car className="h-5 w-5" />
                        Kendaraan
                    </h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        {kendaraanAssets.map(renderAssetCard)}
                    </div>
                </section>
            )}
        </div>
    );
}
```

**Page wrappers:**

```tsx
// resources/js/Pages/User/Monitor/Index.tsx
import UserLayout from '@/Layouts/UserLayout';
import MonitorGrid from '@/Components/MonitorGrid';

export default function UserMonitor({ assets, lastUpdated }) {
    return (
        <UserLayout title="Live Monitor">
            <MonitorGrid assets={assets} lastUpdated={lastUpdated} />
        </UserLayout>
    );
}

// resources/js/Pages/Admin/Monitor/Index.tsx
import AdminLayout from '@/Layouts/AdminLayout';
import MonitorGrid from '@/Components/MonitorGrid';

export default function AdminMonitor({ assets, lastUpdated }) {
    return (
        <AdminLayout title="Live Monitor">
            <MonitorGrid assets={assets} lastUpdated={lastUpdated} />
        </AdminLayout>
    );
}
```

---

## 3. Grafik Statistik (Extend Dashboard)

### 3.1 Tambahan Grafik di Admin Dashboard

Pastikan dashboard sudah memiliki grafik dari Fase 2 dan 3. Fase 5 menambahkan:

| # | Grafik | Tipe | Data Source |
|---|--------|------|-------------|
| 1 | Tiket Bulanan | BarChart | `tickets` group by month |
| 2 | Tiket Tahunan | LineChart | `tickets` group by year |
| 3 | Tiket per Unit | BarChart | `tickets` join `sub_units` join `units` |
| 4 | Tiket per Sub Unit | BarChart | `tickets` join `sub_units` |
| 5 | Top User (most tickets) | BarChart horizontal | `tickets` group by `user_id` |
| 6 | CSAT Trend | LineChart | `csats` avg rating per month |
| 7 | Kepatuhan SLA Trend | LineChart | `ticket_sla_tracking` compliance per month |

### 3.2 Controller Additions

**Tambahkan di** `app/Http/Controllers/Admin/DashboardController.php`:

```php
// Tambahan di method index() atau method terpisah:

// ── Top 5 User ──
$topUsers = DB::table('tickets')
    ->join('users', 'tickets.user_id', '=', 'users.id')
    ->select('users.name', DB::raw('COUNT(*) as total_tiket'))
    ->groupBy('users.id', 'users.name')
    ->orderByDesc('total_tiket')
    ->limit(5)
    ->get();

// ── CSAT Trend (rata-rata per bulan, 12 bulan terakhir) ──
$csatTrend = DB::table('csats')
    ->select(
        DB::raw("DATE_FORMAT(created_at, '%Y-%m') as bulan"),
        DB::raw('ROUND(AVG(rating), 2) as rata_rata'),
        DB::raw('COUNT(*) as total'),
    )
    ->where('created_at', '>=', now()->subYear())
    ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
    ->orderBy('bulan')
    ->get();

// ── SLA Compliance Trend (12 bulan terakhir) ──
$slaTrend = DB::table('ticket_sla_tracking')
    ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
    ->select(
        DB::raw("DATE_FORMAT(tickets.created_at, '%Y-%m') as bulan"),
        DB::raw('COUNT(*) as total'),
        DB::raw('SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) as dalam_sla'),
        DB::raw('ROUND(SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as persentase_sla'),
    )
    ->where('tickets.created_at', '>=', now()->subYear())
    ->groupBy(DB::raw("DATE_FORMAT(tickets.created_at, '%Y-%m')"))
    ->orderBy('bulan')
    ->get();

// ── Tiket Bulanan (12 bulan terakhir) ──
$tiketBulanan = DB::table('tickets')
    ->select(
        DB::raw("DATE_FORMAT(created_at, '%Y-%m') as bulan"),
        DB::raw('COUNT(*) as total'),
        DB::raw("SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai"),
        DB::raw("SUM(CASE WHEN status NOT IN ('Selesai', 'Solve') THEN 1 ELSE 0 END) as aktif"),
    )
    ->where('created_at', '>=', now()->subYear())
    ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
    ->orderBy('bulan')
    ->get();

// Kirim semua ke Inertia
return Inertia::render('Admin/Dashboard/Index', [
    // ... data existing ...
    'topUsers' => $topUsers,
    'csatTrend' => $csatTrend,
    'slaTrend' => $slaTrend,
    'tiketBulanan' => $tiketBulanan,
]);
```

### 3.3 React Dashboard Extension

Tambahkan section grafik baru di `Admin/Dashboard/Index.tsx`:

```tsx
// Top User chart
<Card>
    <CardHeader><CardTitle>Top 5 User (Paling Banyak Tiket)</CardTitle></CardHeader>
    <CardContent>
        <ResponsiveContainer width="100%" height={250}>
            <BarChart data={topUsers} layout="vertical">
                <XAxis type="number" />
                <YAxis type="category" dataKey="name" width={120} />
                <RechartsTooltip />
                <Bar dataKey="total_tiket" name="Total Tiket" fill="#3b82f6" radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    </CardContent>
</Card>

// CSAT Trend chart
<Card>
    <CardHeader><CardTitle>Tren CSAT Bulanan</CardTitle></CardHeader>
    <CardContent>
        <ResponsiveContainer width="100%" height={250}>
            <LineChart data={csatTrend}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="bulan" />
                <YAxis domain={[1, 5]} />
                <RechartsTooltip />
                <Line type="monotone" dataKey="rata_rata" name="Rata-rata CSAT" stroke="#eab308" strokeWidth={2} dot={{ r: 4 }} />
            </LineChart>
        </ResponsiveContainer>
    </CardContent>
</Card>

// SLA Compliance Trend chart
<Card>
    <CardHeader><CardTitle>Tren Kepatuhan SLA</CardTitle></CardHeader>
    <CardContent>
        <ResponsiveContainer width="100%" height={250}>
            <LineChart data={slaTrend}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="bulan" />
                <YAxis domain={[0, 100]} unit="%" />
                <RechartsTooltip />
                <Line type="monotone" dataKey="persentase_sla" name="Kepatuhan SLA (%)" stroke="#22c55e" strokeWidth={2} dot={{ r: 4 }} />
            </LineChart>
        </ResponsiveContainer>
    </CardContent>
</Card>
```

---

## 4. Modul Konfigurasi Sistem

### 4.1 Tabel `system_configs`

**Struktur (sudah ada):**

```sql
CREATE TABLE system_configs (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NULL
);
```

### 4.2 Daftar Key yang Dikelola

| Key | Tipe Value | Default | Section |
|-----|-----------|---------|---------|
| `nama_sistem` | string | "Halo APU" | Branding |
| `logo_path` | string (file path) | null | Branding |
| `banner_path` | string (file path) | null | Branding |
| `email_admin` | string | null | Notifikasi |
| `wa_api_key` | string | null | Notifikasi |
| `wa_gateway_url` | string (URL) | null | Notifikasi |
| `nomor_wa_utama` | string | null | Notifikasi |
| `nomor_wa_fallback` | string | null | Notifikasi |
| `jam_kerja` | JSON | `{"senin":["08:00","16:00"],...}` | Operasional |

### 4.3 Model `SystemConfig`

**File:** `app/Models/SystemConfig.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemConfig extends Model
{
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    /**
     * Ambil value berdasarkan key.
     */
    public static function getValue(string $key, $default = null): mixed
    {
        $config = self::find($key);
        if (!$config) return $default;

        // Auto-decode JSON jika value adalah JSON valid
        $decoded = json_decode($config->value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $config->value;
    }

    /**
     * Set value berdasarkan key.
     */
    public static function setValue(string $key, mixed $value): void
    {
        $storeValue = is_array($value) ? json_encode($value) : (string) $value;

        self::updateOrCreate(
            ['key' => $key],
            ['value' => $storeValue]
        );
    }
}
```

### 4.4 Routes

```php
use App\Http\Controllers\Admin\SystemConfigController;

Route::middleware(['auth:admin', 'role:Admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('konfigurasi', [SystemConfigController::class, 'index'])->name('konfigurasi.index');
    Route::put('konfigurasi', [SystemConfigController::class, 'update'])->name('konfigurasi.update');
    Route::post('konfigurasi/upload-logo', [SystemConfigController::class, 'uploadLogo'])->name('konfigurasi.upload-logo');
    Route::post('konfigurasi/upload-banner', [SystemConfigController::class, 'uploadBanner'])->name('konfigurasi.upload-banner');
});
```

### 4.5 Controller `Admin\SystemConfigController`

**File:** `app/Http/Controllers/Admin/SystemConfigController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SystemConfigController extends Controller
{
    public function index()
    {
        $configs = [
            // Branding
            'nama_sistem' => SystemConfig::getValue('nama_sistem', 'Halo APU'),
            'logo_path' => SystemConfig::getValue('logo_path'),
            'banner_path' => SystemConfig::getValue('banner_path'),

            // Notifikasi
            'email_admin' => SystemConfig::getValue('email_admin'),
            'wa_api_key' => SystemConfig::getValue('wa_api_key'),
            'wa_gateway_url' => SystemConfig::getValue('wa_gateway_url'),
            'nomor_wa_utama' => SystemConfig::getValue('nomor_wa_utama'),
            'nomor_wa_fallback' => SystemConfig::getValue('nomor_wa_fallback'),

            // Operasional
            'jam_kerja' => SystemConfig::getValue('jam_kerja', [
                'senin' => ['08:00', '16:00'],
                'selasa' => ['08:00', '16:00'],
                'rabu' => ['08:00', '16:00'],
                'kamis' => ['08:00', '16:00'],
                'jumat' => ['08:00', '16:00'],
                'sabtu' => null,
                'minggu' => null,
            ]),
        ];

        return Inertia::render('Admin/Konfigurasi/Index', [
            'configs' => $configs,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'nama_sistem' => 'required|string|max:100',
            'email_admin' => 'nullable|email|max:100',
            'wa_api_key' => 'nullable|string|max:255',
            'wa_gateway_url' => 'nullable|url|max:255',
            'nomor_wa_utama' => 'nullable|string|max:20',
            'nomor_wa_fallback' => 'nullable|string|max:20',
            'jam_kerja' => 'required|array',
            'jam_kerja.senin' => 'nullable|array|size:2',
            'jam_kerja.selasa' => 'nullable|array|size:2',
            'jam_kerja.rabu' => 'nullable|array|size:2',
            'jam_kerja.kamis' => 'nullable|array|size:2',
            'jam_kerja.jumat' => 'nullable|array|size:2',
            'jam_kerja.sabtu' => 'nullable|array|size:2',
            'jam_kerja.minggu' => 'nullable|array|size:2',
        ]);

        // Simpan masing-masing config
        SystemConfig::setValue('nama_sistem', $validated['nama_sistem']);
        SystemConfig::setValue('email_admin', $validated['email_admin']);
        SystemConfig::setValue('wa_api_key', $validated['wa_api_key']);
        SystemConfig::setValue('wa_gateway_url', $validated['wa_gateway_url']);
        SystemConfig::setValue('nomor_wa_utama', $validated['nomor_wa_utama']);
        SystemConfig::setValue('nomor_wa_fallback', $validated['nomor_wa_fallback']);
        SystemConfig::setValue('jam_kerja', $validated['jam_kerja']);

        return back()->with('success', 'Konfigurasi berhasil disimpan.');
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,svg|max:2048', // max 2MB
        ]);

        // Hapus logo lama jika ada
        $oldPath = SystemConfig::getValue('logo_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('logo')->store('branding', 'public');
        SystemConfig::setValue('logo_path', $path);

        return back()->with('success', 'Logo berhasil diunggah.');
    }

    public function uploadBanner(Request $request)
    {
        $request->validate([
            'banner' => 'required|image|mimes:png,jpg,jpeg|max:5120', // max 5MB
        ]);

        $oldPath = SystemConfig::getValue('banner_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('banner')->store('branding', 'public');
        SystemConfig::setValue('banner_path', $path);

        return back()->with('success', 'Banner berhasil diunggah.');
    }
}
```

### 4.6 React Page: `Admin/Konfigurasi/Index.tsx`

**Struktur UI:**

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Judul: Konfigurasi Sistem                                                │
├──────────────────────────────────────────────────────────────────────────┤
│ Tabs: [Branding] [Notifikasi] [Operasional]                             │
│                                                                          │
│ ══ Tab: Branding ══                                                      │
│ ┌────────────────────────────────────────────────────────────────────┐   │
│ │ Nama Sistem: [Halo APU________________]                            │   │
│ │                                                                    │   │
│ │ Logo:                                                              │   │
│ │ [📁 Pilih File]  [Preview: 🖼️ logo.png]                            │   │
│ │ Format: PNG, JPG, SVG. Maks 2MB.                                   │   │
│ │                                                                    │   │
│ │ Banner:                                                            │   │
│ │ [📁 Pilih File]  [Preview: 🖼️ banner.png]                          │   │
│ │ Format: PNG, JPG. Maks 5MB.                                        │   │
│ └────────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│ ══ Tab: Notifikasi ══                                                    │
│ ┌────────────────────────────────────────────────────────────────────┐   │
│ │ Email Admin:        [admin@apu.ac.id________]                      │   │
│ │ API Key WA:         [••••••••••••••••________]                     │   │
│ │ URL Gateway WA:     [https://api.wa.com_____]                      │   │
│ │ Nomor WA Utama:     [628123456789___________]                      │   │
│ │ Nomor WA Fallback:  [628987654321___________]                      │   │
│ └────────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│ ══ Tab: Operasional ══                                                   │
│ ┌────────────────────────────────────────────────────────────────────┐   │
│ │ Jam Kerja:                                                         │   │
│ │ ┌──────────┬───────┬────────┬────────────┐                         │   │
│ │ │ Hari     │ Kerja │ Mulai  │ Selesai    │                         │   │
│ │ ├──────────┼───────┼────────┼────────────┤                         │   │
│ │ │ Senin    │ ✅     │ [08:00]│ [16:00]    │                         │   │
│ │ │ Selasa   │ ✅     │ [08:00]│ [16:00]    │                         │   │
│ │ │ Rabu     │ ✅     │ [08:00]│ [16:00]    │                         │   │
│ │ │ Kamis    │ ✅     │ [08:00]│ [16:00]    │                         │   │
│ │ │ Jumat    │ ✅     │ [08:00]│ [16:00]    │                         │   │
│ │ │ Sabtu    │ ☐     │ [-]    │ [-]        │                         │   │
│ │ │ Minggu   │ ☐     │ [-]    │ [-]        │                         │   │
│ │ └──────────┴───────┴────────┴────────────┘                         │   │
│ └────────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│ [Simpan Perubahan]                                                       │
└──────────────────────────────────────────────────────────────────────────┘
```

**Komponen yang dipakai:**
- `Tabs`, `TabsContent`, `TabsList`, `TabsTrigger` dari shadcn/ui
- `Input` untuk text, email, URL, nomor telepon
- `Input` type time untuk jam kerja
- `Switch` untuk toggle hari kerja
- File upload input dengan preview gambar
- `useForm` dari `@inertiajs/react`

---

## 5. Manajemen Admin

### 5.1 Routes

```php
use App\Http\Controllers\Admin\AdminManagementController;

Route::middleware(['auth:admin', 'role:Admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('manajemen-admin', AdminManagementController::class)->except(['create', 'edit', 'show']);
});
```

**Catatan:** Middleware `role:Admin` — hanya role Admin yang bisa mengakses. Operator tidak boleh.

### 5.2 Controller `Admin\AdminManagementController`

**File:** `app/Http/Controllers/Admin/AdminManagementController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = Admin::with('roles');

        // Pencarian
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter role
        if ($role = $request->get('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $admins = $query->latest()->paginate(15);

        return Inertia::render('Admin/ManajemenAdmin/Index', [
            'admins' => $admins,
            'filters' => $request->only(['search', 'role']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:admins,username',
            'email' => 'required|email|max:100|unique:admins,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:Admin,Operator',
            'name' => 'required|string|max:100',
            'no_wa' => 'nullable|string|max:20',
        ]);

        $admin = Admin::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'name' => $validated['name'],
            'no_wa' => $validated['no_wa'] ?? null,
        ]);

        // Assign role via spatie/laravel-permission
        // Pastikan guard_name = 'admin' di konfigurasi spatie
        $admin->assignRole($validated['role']);

        return back()->with('success', "Admin {$admin->username} berhasil ditambahkan.");
    }

    public function update(Request $request, Admin $manajemen_admin)
    {
        $admin = $manajemen_admin;

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', Rule::unique('admins')->ignore($admin->id)],
            'email' => ['required', 'email', 'max:100', Rule::unique('admins')->ignore($admin->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|in:Admin,Operator',
            'name' => 'required|string|max:100',
            'no_wa' => 'nullable|string|max:20',
        ]);

        $admin->update([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'name' => $validated['name'],
            'no_wa' => $validated['no_wa'] ?? null,
        ]);

        if (!empty($validated['password'])) {
            $admin->update(['password' => Hash::make($validated['password'])]);
        }

        // Sync role
        $admin->syncRoles([$validated['role']]);

        return back()->with('success', "Admin {$admin->username} berhasil diperbarui.");
    }

    public function destroy(Admin $manajemen_admin)
    {
        $admin = $manajemen_admin;

        // Jangan izinkan hapus diri sendiri
        if ($admin->id === auth('admin')->id()) {
            return back()->withErrors(['error' => 'Anda tidak bisa menghapus akun Anda sendiri.']);
        }

        $admin->delete();

        return back()->with('success', "Admin {$admin->username} berhasil dihapus.");
    }
}
```

### 5.3 React Page: `Admin/ManajemenAdmin/Index.tsx`

**Struktur UI:**

```
┌──────────────────────────────────────────────────────────────────────┐
│ Judul: Manajemen Admin                                               │
│ [+ Tambah Admin]                                                     │
├──────────────────────────────────────────────────────────────────────┤
│ Filter: [🔍 Cari...___________] [Role: Semua ▾]                     │
├──────────────────────────────────────────────────────────────────────┤
│ ┌────┬────────────┬──────────────────┬──────────┬──────────┬───────┐│
│ │ #  │ Username   │ Email            │ Role     │ Dibuat   │ Aksi  ││
│ ├────┼────────────┼──────────────────┼──────────┼──────────┼───────┤│
│ │ 1  │ superadmin │ admin@apu.ac.id  │ Admin    │ 01 Jul   │ ✏️ 🗑️ ││
│ │ 2  │ operator1  │ op1@apu.ac.id    │ Operator │ 05 Jul   │ ✏️ 🗑️ ││
│ └────┴────────────┴──────────────────┴──────────┴──────────┴───────┘│
│                                                                      │
│ Pagination                                                           │
└──────────────────────────────────────────────────────────────────────┘
```

**Dialog Add/Edit:**

```
┌─────────────────────────────────────────────┐
│ Tambah Admin Baru / Edit Admin              │
├─────────────────────────────────────────────┤
│ Nama Lengkap: [________________________]   │
│ Username:     [________________________]   │
│ Email:        [________________________]   │
│ No. WA:       [________________________]   │
│ Password:     [________________________]   │
│ Konfirmasi:   [________________________]   │
│ Role:         [Admin ▾ / Operator ▾]       │
├─────────────────────────────────────────────┤
│                       [Batal] [Simpan]      │
└─────────────────────────────────────────────┘
```

**Komponen yang dipakai:**
- `DataTable` custom atau shadcn/ui Table
- `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogFooter` dari shadcn/ui
- `Input` untuk text fields
- `Select` untuk role dropdown
- `Button` untuk aksi
- `AlertDialog` untuk konfirmasi hapus
- `useForm` dari `@inertiajs/react`
- `Badge` untuk label role (Admin=biru, Operator=hijau)

---

## 6. Manajemen User

### 6.1 Routes

```php
use App\Http\Controllers\Admin\UserManagementController;

Route::middleware(['auth:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('manajemen-user', UserManagementController::class)->except(['create', 'edit', 'show']);
});
```

### 6.2 Controller `Admin\UserManagementController`

**File:** `app/Http/Controllers/Admin/UserManagementController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        // Pencarian
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        // Filter: Divisi
        if ($divisi = $request->get('divisi')) {
            $query->where('divisi', $divisi);
        }

        // Filter: Unit Organisasi
        if ($unitOrg = $request->get('unit_organisasi')) {
            $query->where('unit_organisasi', $unitOrg);
        }

        $users = $query->latest()->paginate(15);

        // Ambil daftar unik divisi dan unit untuk filter
        $divisiList = User::select('divisi')->distinct()->whereNotNull('divisi')->pluck('divisi');
        $unitOrgList = User::select('unit_organisasi')->distinct()->whereNotNull('unit_organisasi')->pluck('unit_organisasi');

        return Inertia::render('Admin/ManajemenUser/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'divisi', 'unit_organisasi']),
            'divisiList' => $divisiList,
            'unitOrgList' => $unitOrgList,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|email|max:100|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'no_wa' => 'nullable|string|max:20',
            'divisi' => 'nullable|string|max:100',
            'unit_organisasi' => 'nullable|string|max:100',
            'jabatan' => 'nullable|string|max:100',
        ]);

        User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'no_wa' => $validated['no_wa'],
            'divisi' => $validated['divisi'],
            'unit_organisasi' => $validated['unit_organisasi'],
            'jabatan' => $validated['jabatan'],
        ]);

        return back()->with('success', 'User berhasil ditambahkan.');
    }

    public function update(Request $request, User $manajemen_user)
    {
        $user = $manajemen_user;

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'username' => ['required', 'string', 'max:50', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', 'max:100', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'no_wa' => 'nullable|string|max:20',
            'divisi' => 'nullable|string|max:100',
            'unit_organisasi' => 'nullable|string|max:100',
            'jabatan' => 'nullable|string|max:100',
        ]);

        $user->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'no_wa' => $validated['no_wa'],
            'divisi' => $validated['divisi'],
            'unit_organisasi' => $validated['unit_organisasi'],
            'jabatan' => $validated['jabatan'],
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        return back()->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(User $manajemen_user)
    {
        $user = $manajemen_user;

        // Cek apakah user punya tiket aktif
        $activeTickets = $user->tickets()->whereNotIn('status', ['Selesai'])->count();
        if ($activeTickets > 0) {
            return back()->withErrors([
                'error' => "User ini memiliki {$activeTickets} tiket aktif. Selesaikan terlebih dahulu.",
            ]);
        }

        $user->delete();

        return back()->with('success', 'User berhasil dihapus.');
    }
}
```

### 6.3 React Page: `Admin/ManajemenUser/Index.tsx`

**Struktur UI:**

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Judul: Manajemen User                                                        │
│ [+ Tambah User]                                                              │
├──────────────────────────────────────────────────────────────────────────────┤
│ Filter: [🔍 Cari...______] [Divisi ▾] [Unit Organisasi ▾]                   │
├──────────────────────────────────────────────────────────────────────────────┤
│ ┌────┬───────────┬──────────────────┬──────────────┬──────────┬───────┬────┐│
│ │ #  │ Username  │ Email            │ No. WA       │ Divisi   │Jabatan│Aksi││
│ ├────┼───────────┼──────────────────┼──────────────┼──────────┼───────┼────┤│
│ │ 1  │ budi.s    │ budi@apu.ac.id   │ 08123456789  │ Teknik   │ Staff │✏️🗑️ ││
│ │ 2  │ siti.a    │ siti@apu.ac.id   │ 08987654321  │ Keuangan │ Kabag │✏️🗑️ ││
│ └────┴───────────┴──────────────────┴──────────────┴──────────┴───────┴────┘│
│                                                                              │
│ Pagination                                                                   │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Dialog Add/Edit:**

```
┌────────────────────────────────────────────────┐
│ Tambah User Baru / Edit User                   │
├────────────────────────────────────────────────┤
│ Nama Lengkap:      [______________________]   │
│ Username:          [______________________]   │
│ Email:             [______________________]   │
│ No. WhatsApp:      [______________________]   │
│ Password:          [______________________]   │
│ Konfirmasi:        [______________________]   │
│ Divisi:            [______________________]   │
│ Unit Organisasi:   [______________________]   │
│ Jabatan:           [______________________]   │
├────────────────────────────────────────────────┤
│                          [Batal] [Simpan]      │
└────────────────────────────────────────────────┘
```

---

## 7. Hardening Keamanan

### 7.1 Rate Limiting pada Login

**File:** `routes/web.php` atau `routes/auth.php`

```php
// Login user
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:5,1')  // Max 5 percobaan per menit
    ->name('login');

// Login admin
Route::post('/admin/login', [AdminAuthController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('admin.login');
```

**Custom Rate Limiter (opsional, untuk pesan error Bahasa Indonesia):**

```php
// app/Providers/AppServiceProvider.php → boot()
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('login', function ($request) {
    return Limit::perMinute(5)
        ->by($request->input('username') . '|' . $request->ip())
        ->response(function () {
            return back()->withErrors([
                'username' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam 1 menit.',
            ]);
        });
});
```

### 7.2 CSRF Protection

- **Otomatis** via Laravel + Inertia.js
- Inertia secara default menyertakan CSRF token di setiap request
- Pastikan middleware `VerifyCsrfToken` aktif di `app/Http/Kernel.php`
- Tidak perlu konfigurasi tambahan

### 7.3 File Upload Validation

Terapkan di **setiap controller** yang menerima file upload:

```php
// Contoh validasi umum untuk lampiran tiket
$request->validate([
    'lampiran' => 'nullable|file|max:10240|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg',
    // max:10240 = 10MB (dalam KB)
]);

// Untuk logo (konfigurasi)
$request->validate([
    'logo' => 'required|image|mimes:png,jpg,jpeg,svg|max:2048', // 2MB
]);

// Untuk banner
$request->validate([
    'banner' => 'required|image|mimes:png,jpg,jpeg|max:5120', // 5MB
]);
```

**Konfigurasi PHP (php.ini) — pastikan di cPanel:**
```ini
upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 120
```

### 7.4 XSS Prevention

- **React**: Secara default melakukan escape terhadap semua output (`{}` di JSX). Hindari penggunaan `dangerouslySetInnerHTML`.
- **Server-side**: Sanitize input menggunakan `strip_tags()` atau package `htmlpurifier` jika menerima HTML.
- **Contoh sanitasi server:**

```php
// Di controller, sebelum menyimpan ke database:
$validated['komentar'] = strip_tags($validated['komentar']);
$validated['judul'] = strip_tags($validated['judul']);
```

### 7.5 SQL Injection Prevention

- **Eloquent ORM** menggunakan parameterized queries secara default
- **Jangan pernah** menggunakan `DB::raw()` dengan input user tanpa binding:

```php
// ❌ SALAH:
DB::select("SELECT * FROM users WHERE name = '{$request->name}'");

// ✅ BENAR:
DB::select("SELECT * FROM users WHERE name = ?", [$request->name]);

// ✅ BENAR (Eloquent):
User::where('name', $request->name)->get();
```

### 7.6 Password Policy

```php
// Di validasi register/update password:
$request->validate([
    'password' => [
        'required',
        'string',
        'min:8',
        'confirmed',
        // Opsional: tambahkan rules lebih ketat
        // \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers(),
    ],
]);
```

### 7.7 Middleware Security Summary

| Middleware | Tujuan | Lokasi |
|-----------|--------|--------|
| `auth` | Autentikasi user | Routes user |
| `auth:admin` | Autentikasi admin (guard terpisah) | Routes admin |
| `role:Admin` | Hanya role Admin (bukan Operator) | Route sensitif (manajemen admin, konfigurasi) |
| `throttle:5,1` | Rate limiting login | Route login |
| `verified` | Email verified (opsional) | Route yang perlu email terverifikasi |
| `VerifyCsrfToken` | CSRF protection | Global (semua POST/PUT/DELETE) |

### 7.8 Checklist Keamanan

- [ ] Rate limiting di route login (user & admin)
- [ ] CSRF token aktif (bawaan Laravel + Inertia)
- [ ] File upload validasi: max size + allowed mimes
- [ ] XSS: React escape default, strip_tags di server
- [ ] SQL Injection: Eloquent parameterized queries, no raw queries dengan user input
- [ ] Password: min 8 karakter, hashed dengan bcrypt
- [ ] Sensitive routes dilindungi middleware role
- [ ] `.env` file tidak accessible dari browser
- [ ] `APP_DEBUG=false` di production
- [ ] HTTPS enforced (SSL)
- [ ] Session timeout configured
- [ ] CORS configured (jika ada API)

---

## 8. Deployment ke cPanel

### 8.1 Pre-checklist

| # | Item | Status | Keterangan |
|---|------|--------|-----------|
| 1 | PHP 8.3+ | ☐ | Cek via cPanel → Select PHP Version |
| 2 | MySQL 8.0+ | ☐ | Cek via cPanel → MySQL Databases |
| 3 | SSH/FTP access | ☐ | Diperlukan untuk upload file & jalankan command |
| 4 | Domain configured | ☐ | Domain/subdomain sudah pointing ke server |
| 5 | Composer tersedia | ☐ | Cek: `composer --version` via SSH |
| 6 | Node.js + npm | ☐ | Untuk build frontend (bisa build lokal lalu upload) |
| 7 | Ekstensi PHP | ☐ | BCMath, Ctype, cURL, DOM, Fileinfo, JSON, Mbstring, OpenSSL, PDO, PDO_MySQL, Tokenizer, XML |

### 8.2 Step-by-Step Deployment

#### Step 1: Buat Database MySQL

```bash
# Via cPanel → MySQL Databases:
# 1. Create Database: haloapu_db
# 2. Create User: haloapu_user (password kuat)
# 3. Add User to Database dengan ALL PRIVILEGES
```

#### Step 2: Upload File Project

**Opsi A — Via SSH + Git:**
```bash
cd /home/username
git clone https://github.com/your-repo/halo-apu-v2.git haloapu
```

**Opsi B — Via FTP/File Manager:**
```
Upload ZIP file, extract di /home/username/haloapu/
```

#### Step 3: Konfigurasi `.env`

```bash
cd /home/username/haloapu
cp .env.example .env
```

**Edit `.env` production:**

```env
APP_NAME="Halo APU"
APP_ENV=production
APP_KEY=                          # Akan di-generate nanti
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta
APP_URL=https://haloapu.yourdomain.com

# Bahasa
APP_LOCALE=id
APP_FALLBACK_LOCALE=id

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=haloapu_db
DB_USERNAME=haloapu_user
DB_PASSWORD=YourSecurePasswordHere

# Session
SESSION_DRIVER=database
SESSION_LIFETIME=120

# Queue
QUEUE_CONNECTION=database

# Cache
CACHE_DRIVER=file

# Mail (sesuaikan dengan provider email)
MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_USERNAME=noreply@yourdomain.com
MAIL_PASSWORD=YourMailPassword
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Halo APU"

# Logging
LOG_CHANNEL=daily
LOG_LEVEL=warning
```

#### Step 4: Install Dependencies

```bash
# Via SSH:
cd /home/username/haloapu

# Install PHP dependencies (tanpa dev)
composer install --no-dev --optimize-autoloader --no-interaction

# Generate app key
php artisan key:generate
```

#### Step 5: Build Frontend

**Opsi A — Build di server (jika Node.js tersedia):**
```bash
npm install
npm run build
```

**Opsi B — Build lokal, upload hasil:**
```bash
# Di komputer lokal:
npm run build

# Upload folder public/build/ ke server via FTP
```

#### Step 6: Database Migration

```bash
php artisan migrate --force
```

**Catatan:** Flag `--force` diperlukan karena `APP_ENV=production`.

#### Step 7: Seeder (jika diperlukan)

```bash
php artisan db:seed --force
```

#### Step 8: Optimize Laravel

```bash
# Cache konfigurasi
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optional: cache events
php artisan event:cache
```

#### Step 9: Storage Link

```bash
php artisan storage:link
```

Ini membuat symbolic link `public/storage → storage/app/public`.

**Jika symlink tidak bisa di shared hosting**, buat manual di `.htaccess` atau gunakan workaround:

```php
// routes/web.php - workaround jika symlink tidak tersedia:
Route::get('/storage/{path}', function (string $path) {
    $fullPath = storage_path("app/public/{$path}");
    if (!file_exists($fullPath)) abort(404);
    return response()->file($fullPath);
})->where('path', '.*');
```

#### Step 10: Document Root Configuration

**Opsi A — Subdomain (recommended):**
```
Di cPanel → Subdomains:
Subdomain: haloapu
Document Root: /home/username/haloapu/public
```

**Opsi B — Addon Domain:**
```
Di cPanel → Addon Domains:
Domain: haloapu.yourdomain.com
Document Root: /home/username/haloapu/public
```

**Opsi C — Main domain redirect (jika perlu):**

Buat `.htaccess` di root domain:
```apache
RewriteEngine On
RewriteRule ^(.*)$ haloapu/public/$1 [L]
```

#### Step 11: SSL Certificate

```
Di cPanel → SSL/TLS → Let's Encrypt:
1. Pilih domain haloapu.yourdomain.com
2. Issue certificate
3. Pastikan Force HTTPS aktif
```

#### Step 12: Cron Job untuk Scheduler

```
Di cPanel → Cron Jobs:
Schedule: Every minute (*)
Command: cd /home/username/haloapu && php artisan schedule:run >> /dev/null 2>&1
```

**Format cron:**
```
* * * * * cd /home/username/haloapu && php artisan schedule:run >> /dev/null 2>&1
```

#### Step 13: Queue Worker

**Opsi A — Supervisor (jika tersedia):**
```ini
[program:haloapu-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/username/haloapu/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/home/username/haloapu/storage/logs/worker.log
```

**Opsi B — Cron Job (workaround untuk shared hosting):**
```
* * * * * cd /home/username/haloapu && php artisan queue:work database --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

**Catatan:** `--stop-when-empty` akan menghentikan worker saat tidak ada job. `--max-time=55` memastikan proses selesai sebelum cron berikutnya dimulai.

#### Step 14: Set File Permissions

```bash
# Direktori storage dan bootstrap/cache harus writable
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Pastikan ownership benar
chown -R username:username /home/username/haloapu
```

### 8.3 Post-Deployment Checklist

| # | Item | Cara Verifikasi |
|---|------|----------------|
| 1 | Homepage loadable | Buka URL di browser |
| 2 | Login berfungsi | Coba login user & admin |
| 3 | Database connected | Cek halaman yang load data |
| 4 | File upload berfungsi | Upload lampiran/logo |
| 5 | Email terkirim | Test via konfigurasi |
| 6 | Queue berjalan | Cek `php artisan queue:monitor` atau log |
| 7 | Scheduler berjalan | Cek `storage/logs/laravel.log` |
| 8 | HTTPS aktif | Pastikan redirect ke HTTPS |
| 9 | Error handling | Cek halaman 404, 500 |
| 10 | Dark/Light mode | Toggle theme di UI |

### 8.4 Troubleshooting

| Masalah | Solusi |
|---------|-------|
| 500 Internal Server Error | Cek `storage/logs/laravel.log`. Biasanya: permission, .env, APP_KEY |
| Blank page | Pastikan `npm run build` berhasil dan folder `public/build/` ada |
| Storage link error | Buat symlink manual atau gunakan route workaround |
| Queue tidak jalan | Pastikan `QUEUE_CONNECTION=database` dan cron job aktif |
| Session expired cepat | Cek `SESSION_LIFETIME` di `.env` |
| Mixed content warning | Pastikan `APP_URL` menggunakan `https://` |
| PHP version error | Pastikan PHP 8.3+ via cPanel → PHP Selector |

---

## 9. File-by-File Checklist

### 9.1 Modul CSAT

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 1 | `app/Models/Csat.php` | **Buat baru** | Model dengan relasi `belongsTo Ticket`, `belongsTo User` |
| 2 | `app/Models/Ticket.php` | **Edit** | Tambah relasi `hasOne Csat` |
| 3 | `app/Http/Controllers/CsatController.php` | **Buat baru** | `store()` dengan validasi kepemilikan/status/unique, `riwayat()` paginated |
| 4 | `app/Http/Controllers/Admin/CsatController.php` | **Buat baru** | `index()` dengan filter, statistik agregat, grafik per unit |
| 5 | `resources/js/Components/CsatDialog.tsx` | **Buat baru** | Dialog star rating (1-5) + textarea komentar, validasi client-side |
| 6 | `resources/js/Pages/User/Csat/Riwayat.tsx` | **Buat baru** | DataTable riwayat CSAT user, star display, paginated |
| 7 | `resources/js/Pages/Admin/Csat/Index.tsx` | **Buat baru** | DataTable + filter + statistik card + BarChart rata-rata per unit |
| 8 | `resources/js/Pages/User/Tiketing/Detail.tsx` | **Edit** | Tambah `<CsatDialog>` saat status Solve/Selesai |

### 9.2 Live Monitor

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 9 | `app/Http/Controllers/MonitorController.php` | **Buat baru** | `getAssetData()` helper, `userIndex()`, `adminIndex()`, status logic (Tersedia/Dipesan/Dipakai) |
| 10 | `resources/js/Components/MonitorGrid.tsx` | **Buat baru** | Grid cards per aset, warna status (hijau/kuning/merah), `usePoll(10000)`, legenda, section ruang & kendaraan |
| 11 | `resources/js/Pages/User/Monitor/Index.tsx` | **Buat baru** | Wrapper dengan UserLayout |
| 12 | `resources/js/Pages/Admin/Monitor/Index.tsx` | **Baru baru** | Wrapper dengan AdminLayout |

### 9.3 Grafik Statistik

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 13 | `app/Http/Controllers/Admin/DashboardController.php` | **Edit** | Tambahkan query: topUsers, csatTrend, slaTrend, tiketBulanan |
| 14 | `resources/js/Pages/Admin/Dashboard/Index.tsx` | **Edit** | Tambahkan section grafik: Top User BarChart, CSAT Trend LineChart, SLA Trend LineChart, Tiket Bulanan BarChart |

### 9.4 Konfigurasi Sistem

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 15 | `app/Models/SystemConfig.php` | **Buat baru** (atau edit) | Primary key string, static `getValue()` & `setValue()`, auto JSON decode |
| 16 | `app/Http/Controllers/Admin/SystemConfigController.php` | **Buat baru** | `index()`, `update()`, `uploadLogo()`, `uploadBanner()`, validasi file upload |
| 17 | `resources/js/Pages/Admin/Konfigurasi/Index.tsx` | **Buat baru** | Tabs: Branding (upload logo/banner), Notifikasi (email/WA config), Operasional (jam kerja per hari) |

### 9.5 Manajemen Admin

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 18 | `app/Http/Controllers/Admin/AdminManagementController.php` | **Buat baru** | CRUD admin, assign/sync role via spatie, proteksi hapus diri sendiri |
| 19 | `resources/js/Pages/Admin/ManajemenAdmin/Index.tsx` | **Buat baru** | DataTable admin, filter search/role, Dialog add/edit, AlertDialog hapus, Badge role |

### 9.6 Manajemen User

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 20 | `app/Http/Controllers/Admin/UserManagementController.php` | **Buat baru** | CRUD user, filter divisi/unit, proteksi hapus user dengan tiket aktif |
| 21 | `resources/js/Pages/Admin/ManajemenUser/Index.tsx` | **Buat baru** | DataTable user, filter search/divisi/unit, Dialog add/edit, AlertDialog hapus |

### 9.7 Keamanan & Routes

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 22 | `routes/web.php` | **Edit** | Tambahkan route: CSAT (store, riwayat), Monitor (user), throttle di login |
| 23 | `routes/admin.php` | **Edit** | Tambahkan route: CSAT admin, Monitor admin, Konfigurasi, Manajemen Admin, Manajemen User |
| 24 | `app/Providers/AppServiceProvider.php` | **Edit** | Register custom rate limiter untuk login |
| 25 | `resources/js/types/index.d.ts` | **Edit** | Tambahkan interface: `Csat`, `AssetMonitor`, `SystemConfig`, etc. |

### 9.8 Deployment

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 26 | `.env.production` | **Buat baru** (template) | Template .env untuk production |
| 27 | `.htaccess` (public/) | **Cek/Edit** | Pastikan RewriteEngine dan rules benar |
| 28 | `deploy.sh` (opsional) | **Buat baru** | Script deployment otomatis |

### 9.9 Tests

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 29 | `tests/Feature/CsatTest.php` | **Buat baru** | Test submit rating, validasi, riwayat |
| 30 | `tests/Feature/MonitorTest.php` | **Buat baru** | Test status aset, polling data |
| 31 | `tests/Feature/SystemConfigTest.php` | **Buat baru** | Test CRUD config, upload file |
| 32 | `tests/Feature/AdminManagementTest.php` | **Buat baru** | Test CRUD admin, role assignment |
| 33 | `tests/Feature/UserManagementTest.php` | **Buat baru** | Test CRUD user, proteksi hapus |

### 9.10 Urutan Implementasi

```
1.  CSAT model + CsatController (user) + CsatDialog component
2.  Admin CSAT controller + page (laporan)
3.  MonitorController + MonitorGrid component + pages
4.  Dashboard extension (grafik tambahan)
5.  SystemConfig model + controller + React page
6.  AdminManagementController + React page
7.  UserManagementController + React page
8.  Security hardening (rate limiting, validasi, etc.)
9.  Routes cleanup & middleware assignment
10. Types/interfaces TypeScript
11. Feature tests
12. Deployment (database, .env, build, migrate, optimize)
13. Post-deployment verification
```

---

> **Catatan Akhir:**
> - Semua teks UI harus dalam **Bahasa Indonesia**
> - Semua komponen harus mendukung **Dark + Light mode** (tema Tailwind)
> - Warna utama: **biru** (sesuai branding Halo APU)
> - Pastikan `spatie/laravel-permission` dikonfigurasi dengan guard `admin` terpisah
> - Queue worker harus berjalan di production untuk notifikasi async
> - Backup database secara berkala setelah deployment
