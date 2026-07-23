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

        // Ambil semua booking yang relevan (hari ini dan ke depan)
        $query = RoomVehicleBooking::where('status', 'on_proses')
            ->where('tanggal_selesai', '>=', $now->copy()->startOfDay())
            ->with(['ticket.user:id,username']);

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

        // Map status per aset
        return $allAssets->map(function ($asset) use ($bookings, $now) {
            $assetBookings = $bookings->where('nama_aset', $asset->nama_aset);

            // Cek apakah sedang dipakai
            $activeBooking = $assetBookings->first(function ($b) use ($now) {
                return Carbon::parse($b->tanggal_mulai)->lte($now)
                    && Carbon::parse($b->tanggal_selesai)->gt($now);
            });

            if ($activeBooking) {
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => 'Sedang Dipakai',
                    'user' => $activeBooking->ticket?->user?->username ?? '-',
                    'waktu_mulai' => Carbon::parse($activeBooking->tanggal_mulai)->format('d M Y H:i'),
                    'waktu_selesai' => Carbon::parse($activeBooking->tanggal_selesai)->format('d M Y H:i'),
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
                    'user' => $nextBooking->ticket?->user?->username ?? '-',
                    'waktu_mulai' => Carbon::parse($nextBooking->tanggal_mulai)->format('d M Y H:i'),
                    'waktu_selesai' => Carbon::parse($nextBooking->tanggal_selesai)->format('d M Y H:i'),
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

    /**
     * Data kalender: booking dikelompokkan per tanggal (30 hari ke depan).
     */
    protected function getCalendarData()
    {
        $bookings = RoomVehicleBooking::where('status', 'on_proses')
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
