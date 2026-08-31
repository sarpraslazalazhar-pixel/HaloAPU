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
        $oneHourAgo = $now->copy()->subHour();
        $oneHourLater = $now->copy()->addHour();

        // 1. Ambil booking relevan (selesai dalam 1 jam terakhir atau di masa depan)
        $query = RoomVehicleBooking::whereIn('status', ['open', 'on_proses'])
            ->where('tanggal_selesai', '>=', $oneHourAgo)
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
        $result = $allAssets->map(function ($asset) use ($bookings, $now, $oneHourAgo, $oneHourLater, $formatWaktu) {
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

            // 4. Tersedia
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
