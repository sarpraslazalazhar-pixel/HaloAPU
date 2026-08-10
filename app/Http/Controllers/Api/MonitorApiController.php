<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\FormField;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MonitorApiController extends Controller
{
    /**
     * Mengambil daftar aset beserta status ketersediaannya.
     * Dapat difilter berdasarkan tipe (misal: "Ruangan" atau "Kendaraan").
     */
    public function assets(Request $request)
    {
        $tipe = $request->query('tipe');
        $now = Carbon::now();

        // 1. Ambil booking relevan (hari ini dan ke depan) yang disetujui/sedang berjalan
        $query = RoomVehicleBooking::whereIn('status', ['open', 'on_proses'])
            ->where('tanggal_selesai', '>=', $now->copy()->startOfDay())
            ->with(['ticket.user:id,username,name']);

        if ($tipe) {
            $query->where('tipe', $tipe);
        }

        $bookings = $query->get();

        // 2. Kumpulkan aset terkonfigurasi dari SubUnit
        $monitoredSubUnits = SubUnit::where('is_monitored', true)->get();
        $configuredAssets = collect();

        foreach ($monitoredSubUnits as $su) {
            $hasOptions = false;
            if ($su->monitor_asset_field_id) {
                $field = FormField::find($su->monitor_asset_field_id);
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

        // 3. Ambil aset dari historis booking (yang mungkin belum terkonfigurasi)
        $historicalAssetsQuery = RoomVehicleBooking::select('nama_aset', 'tipe')
            ->distinct()
            ->orderBy('tipe')
            ->orderBy('nama_aset');

        if ($tipe) {
            $historicalAssetsQuery->where('tipe', $tipe);
        }

        $historicalAssets = $historicalAssetsQuery->get();

        // 4. Gabungkan dan hilangkan duplikat
        $allAssets = $configuredAssets->merge($historicalAssets)->unique(function ($item) {
            return $item->tipe . '-' . $item->nama_aset;
        })->values();

        if ($tipe) {
            $allAssets = $allAssets->where('tipe', $tipe)->values();
        }

        // 5. Fungsi format waktu
        $formatWaktu = function ($start, $end) {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
            if ($s->isSameDay($e)) {
                return $s->format('d M Y, H:i') . ' - ' . $e->format('H:i');
            }
            return $s->format('d M, H:i') . ' - ' . $e->format('d M, H:i');
        };

        // 6. Mapping final: tentukan status per aset
        $result = $allAssets->map(function ($asset) use ($bookings, $now, $formatWaktu) {
            $assetBookings = $bookings->where('nama_aset', $asset->nama_aset);

            // Cek Sedang Dipakai
            $activeBooking = $assetBookings->first(function ($b) use ($now) {
                return $b->status === 'on_proses'
                    && Carbon::parse($b->tanggal_mulai)->lte($now)
                    && Carbon::parse($b->tanggal_selesai)->gt($now);
            });

            if ($activeBooking) {
                $userStr = $activeBooking->ticket?->user?->name ?? $activeBooking->ticket?->user?->username ?? '-';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => 'Sedang Dipakai',
                    'user' => $userStr,
                    'waktu' => $formatWaktu($activeBooking->tanggal_mulai, $activeBooking->tanggal_selesai),
                    'booking_id' => $activeBooking->id,
                ];
            }

            // Cek Dipesan (Mendatang)
            $nextBooking = $assetBookings
                ->where('tanggal_mulai', '>', $now->toDateTimeString())
                ->sortBy('tanggal_mulai')
                ->first();

            if ($nextBooking) {
                $displayStatus = $nextBooking->status === 'open' ? 'Menunggu Persetujuan' : 'Dipesan';
                $userStr = $nextBooking->ticket?->user?->name ?? $nextBooking->ticket?->user?->username ?? '-';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => $displayStatus,
                    'user' => $userStr,
                    'waktu' => $formatWaktu($nextBooking->tanggal_mulai, $nextBooking->tanggal_selesai),
                    'booking_id' => $nextBooking->id,
                ];
            }

            // Tersedia
            return [
                'nama_aset' => $asset->nama_aset,
                'tipe' => $asset->tipe,
                'status' => 'Tersedia',
                'user' => null,
                'waktu' => null,
                'booking_id' => null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * Mengambil daftar booking mendatang (Kalender), dikelompokkan per tanggal.
     */
    public function calendar(Request $request)
    {
        $now = Carbon::now();
        $days = (int) $request->query('days', 30); // Default lihat 30 hari ke depan
        $endDate = $now->copy()->addDays($days)->endOfDay();

        $bookings = RoomVehicleBooking::whereIn('status', ['open', 'on_proses'])
            ->where('tanggal_selesai', '>=', $now->copy()->startOfDay())
            ->where('tanggal_mulai', '<=', $endDate)
            ->with(['ticket.user:id,username,name'])
            ->orderBy('tanggal_mulai', 'asc')
            ->get();

        $formatWaktu = function ($start, $end) {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
            if ($s->isSameDay($e)) {
                return $s->format('H:i') . ' - ' . $e->format('H:i');
            }
            return $s->format('d M H:i') . ' - ' . $e->format('d M H:i');
        };

        // Kelompokkan per tanggal
        $grouped = $bookings->groupBy(function ($b) {
            return Carbon::parse($b->tanggal_mulai)->format('Y-m-d');
        });

        // Ubah format agar mudah dibaca mobile
        $result = $grouped->map(function ($items, $date) use ($formatWaktu) {
            return [
                'date' => $date,
                'bookings' => $items->map(function ($b) use ($formatWaktu) {
                    $userStr = $b->ticket?->user?->name ?? $b->ticket?->user?->username ?? '-';
                    return [
                        'id' => $b->id,
                        'nama_aset' => $b->nama_aset,
                        'tipe' => $b->tipe,
                        'status' => $b->status,
                        'user' => $userStr,
                        'waktu' => $formatWaktu($b->tanggal_mulai, $b->tanggal_selesai),
                        'waktu_raw' => [
                            'start' => $b->tanggal_mulai,
                            'end' => $b->tanggal_selesai,
                        ]
                    ];
                })->values()
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }
}
