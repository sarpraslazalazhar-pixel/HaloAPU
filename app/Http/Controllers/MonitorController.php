<?php

namespace App\Http\Controllers;

use App\Models\RoomVehicleBooking;
use Carbon\Carbon;
use Inertia\Inertia;

class MonitorController extends Controller
{
    /**
     * Ambil data aset dengan status real-time.
     *
     * Status ditentukan berdasarkan:
     * - Tersedia: tidak ada booking aktif saat ini
     * - Dipesan: ada booking disetujui yang belum mulai tapi hari ini
     * - Sedang Dipakai: ada booking yang sedang berlangsung (tanggal_mulai <= now <= tanggal_selesai)
     */
    protected function getAssetData(?string $tipe = null)
    {
        $now = Carbon::now();
        $oneHourAgo = $now->copy()->subHour();
        $oneHourLater = $now->copy()->addHour();

        // Ambil semua booking yang relevan (selesai dalam 1 jam terakhir atau di masa depan)
        $query = RoomVehicleBooking::whereIn('status', ['open', 'on_proses'])
            ->where('tanggal_selesai', '>=', $oneHourAgo)
            ->with(['ticket.user:id,username,name']);

        if ($tipe) {
            $query->where('tipe', $tipe);
        }

        $bookings = $query->get();

        // Ambil daftar aset dari konfigurasi SubUnit
        $monitoredSubUnits = \App\Models\SubUnit::where('is_monitored', true)->get();
        $configuredAssets = collect();
        foreach ($monitoredSubUnits as $su) {
            $hasOptions = false;
            if ($su->monitor_asset_field_id) {
                $field = \App\Models\FormField::find($su->monitor_asset_field_id);
                if ($field && is_array($field->opsi)) {
                    foreach ($field->opsi as $opsiItem) {
                        $assetName = is_array($opsiItem) ? ($opsiItem['label'] ?? json_encode($opsiItem)) : $opsiItem;
                        $configuredAssets->push((object)[
                            'nama_aset' => $assetName,
                            'tipe' => $su->monitor_kategori ?? 'Lainnya'
                        ]);
                    }
                    $hasOptions = true;
                }
            }
            if (!$hasOptions) {
                $configuredAssets->push((object)[
                    'nama_aset' => $su->nama_layanan,
                    'tipe' => $su->monitor_kategori ?? 'Lainnya'
                ]);
            }
        }

        // Ambil daftar unik aset historis dari booking
        $historicalAssetsQuery = RoomVehicleBooking::select('nama_aset', 'tipe')
            ->distinct()
            ->orderBy('tipe')
            ->orderBy('nama_aset');

        if ($tipe) {
            $historicalAssetsQuery->where('tipe', $tipe);
        }

        $historicalAssets = $historicalAssetsQuery->get();

        // Gabungkan aset dari konfigurasi dan historis, lalu hapus duplikat
        $allAssets = $configuredAssets->merge($historicalAssets)->unique(function ($item) {
            return $item->tipe . '-' . $item->nama_aset;
        })->values();

        if ($tipe) {
            $allAssets = $allAssets->where('tipe', $tipe)->values();
        }

        $formatWaktu = function ($start, $end) {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
            if ($s->isSameDay($e)) {
                return $s->format('d M Y, H:i') . ' - ' . $e->format('H:i');
            }
            return $s->format('d M, H:i') . ' - ' . $e->format('d M, H:i');
        };

        // Map status per aset
        return $allAssets->map(function ($asset) use ($bookings, $now, $oneHourAgo, $oneHourLater, $formatWaktu) {
            $assetBookings = $bookings->where('nama_aset', $asset->nama_aset);

            // 1. Cek apakah sedang aktif berlangsung saat ini (mulai <= now < selesai)
            $activeBooking = $assetBookings->filter(function ($b) use ($now) {
                return Carbon::parse($b->tanggal_mulai)->lte($now)
                    && Carbon::parse($b->tanggal_selesai)->gt($now);
            })->sortByDesc('tanggal_mulai')->first();

            if ($activeBooking) {
                $userStr = $activeBooking->ticket?->user?->name ?? $activeBooking->ticket?->user?->username ?? '-';
                $statusLabel = $activeBooking->status === 'on_proses' ? 'Sedang Dipakai' : 'Menunggu Persetujuan';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => $statusLabel,
                    'user' => $userStr,
                    'waktu' => $formatWaktu($activeBooking->tanggal_mulai, $activeBooking->tanggal_selesai),
                    'booking_id' => $activeBooking->id,
                ];
            }

            // 2. Cek apakah ada booking terdekat dalam 1 jam ke depan (now < mulai <= now + 1 jam)
            $imminentBooking = $assetBookings->filter(function ($b) use ($now, $oneHourLater) {
                $start = Carbon::parse($b->tanggal_mulai);
                return $start->gt($now) && $start->lte($oneHourLater);
            })->sortBy('tanggal_mulai')->first();

            if ($imminentBooking) {
                $displayStatus = $imminentBooking->status === 'open' ? 'Menunggu Persetujuan' : 'Dipesan';
                $userStr = $imminentBooking->ticket?->user?->name ?? $imminentBooking->ticket?->user?->username ?? '-';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => $displayStatus,
                    'user' => $userStr,
                    'waktu' => $formatWaktu($imminentBooking->tanggal_mulai, $imminentBooking->tanggal_selesai),
                    'booking_id' => $imminentBooking->id,
                ];
            }

            // 3. Cek apakah ada booking yang baru saja selesai dalam 1 jam terakhir (now - 1 jam <= selesai < now)
            $recentlyFinishedBooking = $assetBookings->filter(function ($b) use ($now, $oneHourAgo) {
                $end = Carbon::parse($b->tanggal_selesai);
                return $end->gte($oneHourAgo) && $end->lte($now);
            })->sortByDesc('tanggal_selesai')->first();

            if ($recentlyFinishedBooking) {
                $userStr = $recentlyFinishedBooking->ticket?->user?->name ?? $recentlyFinishedBooking->ticket?->user?->username ?? '-';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => 'Selesai Digunakan',
                    'user' => $userStr,
                    'waktu' => $formatWaktu($recentlyFinishedBooking->tanggal_mulai, $recentlyFinishedBooking->tanggal_selesai),
                    'booking_id' => $recentlyFinishedBooking->id,
                ];
            }

            return [
                'nama_aset' => $asset->nama_aset,
                'tipe' => $asset->tipe,
                'status' => 'Tersedia',
                'user' => null,
                'waktu' => null,
                'booking_id' => null,
            ];
        });
    }

    /**
     * Data kalender: booking dikelompokkan per tanggal (30 hari ke depan).
     */
    protected function getCalendarData()
    {
        $bookings = RoomVehicleBooking::whereIn('status', ['open', 'on_proses'])
            ->whereBetween('tanggal_mulai', [Carbon::now()->startOfDay(), Carbon::now()->addDays(30)->endOfDay()])
            ->with(['ticket.user:id,username'])
            ->orderBy('tanggal_mulai')
            ->get()
            ->groupBy(fn ($b) => Carbon::parse($b->tanggal_mulai)->format('Y-m-d'));

        return $bookings->map(fn ($items, $date) => [
            'date' => $date,
            'tanggal' => Carbon::parse($date)->format('d M Y'),
            'bookings' => $items->map(fn ($b) => [
                'nama_aset' => $b->nama_aset,
                'tipe' => $b->tipe,
                'jam_mulai' => Carbon::parse($b->tanggal_mulai)->format('H:i'),
                'jam_selesai' => Carbon::parse($b->tanggal_selesai)->format('H:i'),
                'user' => $b->ticket?->user?->username ?? '-',
                'status' => $b->status,
            ]),
        ])->values();
    }

    public function userIndex()
    {
        return Inertia::render('User/Monitor/Index', [
            'assets' => $this->getAssetData(),
            'calendarData' => $this->getCalendarData(),
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }

    public function adminIndex()
    {
        return Inertia::render('Admin/Monitor/Index', [
            'assets' => $this->getAssetData(),
            'calendarData' => $this->getCalendarData(),
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }
}
