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
        $query = RoomVehicleBooking::whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
            ->where('tanggal_selesai', '>=', $oneHourAgo)
            ->with(['ticket.user:id,username,name']);

        if ($tipe) {
            $query->where('tipe', $tipe);
        }

        $bookings = $query->get();

        // Ambil daftar aset dari konfigurasi SubUnit (Eager load FormField untuk eliminasi N+1)
        $monitoredSubUnits = \App\Models\SubUnit::aktif()->where('is_monitored', true)->get();
        $fieldIds = $monitoredSubUnits->pluck('monitor_asset_field_id')->filter()->unique();
        $formFields = $fieldIds->isNotEmpty()
            ? \App\Models\FormField::whereIn('id', $fieldIds)->get()->keyBy('id')
            : collect();

        $configuredAssets = collect();
        foreach ($monitoredSubUnits as $su) {
            $hasOptions = false;
            if ($su->monitor_asset_field_id && isset($formFields[$su->monitor_asset_field_id])) {
                $field = $formFields[$su->monitor_asset_field_id];
                if (is_array($field->opsi)) {
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
                $isApproved = in_array($activeBooking->status, ['on_proses', 'disetujui', 'solve']);
                $statusLabel = $isApproved ? 'Sedang Dipakai' : 'Menunggu Persetujuan';
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
        $bookings = RoomVehicleBooking::whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
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
        $alat = $this->getAlatData();

        return Inertia::render('User/Monitor/Index', [
            'assets' => $this->getAssetData(),
            'calendarData' => $this->getCalendarData(),
            'alatPinjam' => $alat['peminjaman'],
            'ketersediaanAlat' => $alat['ketersediaan'],
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }

    public function adminIndex()
    {
        $alat = $this->getAlatData();

        return Inertia::render('Admin/Monitor/Index', [
            'assets' => $this->getAssetData(),
            'calendarData' => $this->getCalendarData(),
            'alatPinjam' => $alat['peminjaman'],
            'ketersediaanAlat' => $alat['ketersediaan'],
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }

    protected function getAlatData()
    {
        $sevenDaysAgo = Carbon::now()->subDays(7);
        $now = Carbon::now();

        $tickets = \App\Models\Ticket::whereHas('subUnit', function ($q) {
            $q->where('wajib_kembali', true);
        })
        ->whereNotIn('status', ['reject', 'dibatalkan'])
        ->where(function ($q) use ($sevenDaysAgo) {
            $q->whereNull('dikembalikan_at')
              ->orWhere('dikembalikan_at', '>=', $sevenDaysAgo);
        })
        ->with(['user:id,name,username', 'subUnit:id,nama_layanan', 'subUnit.formFields'])
        ->orderByRaw('CASE WHEN dikembalikan_at IS NULL THEN 0 ELSE 1 END ASC')
        ->orderBy('created_at', 'desc')
        ->limit(100)
        ->get();

        // 1. Koleksi master daftar alat dari FormField tipe multi_pilih/dropdown
        $subUnits = \App\Models\SubUnit::where('wajib_kembali', true)->with('formFields')->get();
        $masterTools = collect();

        foreach ($subUnits as $su) {
            $fields = $su->formFields ?? collect();
            $choiceField = $fields->first(fn ($f) => in_array($f->tipe_field, ['multi_pilih', 'dropdown']));
            if (!$choiceField || !is_array($choiceField->opsi)) continue;

            foreach ($choiceField->opsi as $opsi) {
                $opsiStr = is_array($opsi) ? ($opsi['label'] ?? json_encode($opsi)) : (string) $opsi;
                $cleanOpsi = trim($opsiStr);
                if (strtolower($cleanOpsi) === 'yang lain:' || strtolower($cleanOpsi) === 'yang lain') continue;

                $masterTools->put($cleanOpsi, [
                    'nama_alat' => $cleanOpsi,
                    'status' => 'Tersedia',
                    'user' => null,
                    'waktu' => null,
                    'ticket_id' => null,
                    'formatted_id' => null,
                ]);
            }
        }

        // 2. Map data tiket dan update ketersediaan alat
        $peminjaman = $tickets->map(function ($t) use ($now, $masterTools) {
            $userStr = $t->user?->name ?: ($t->user?->username ?: '-');
            $formData = is_array($t->form_data) ? $t->form_data : [];

            $fields = $t->subUnit?->formFields ?? collect();
            $choiceField = $fields->first(fn ($f) => in_array($f->tipe_field, ['multi_pilih', 'dropdown']));
            $childFields = $choiceField ? $fields->where('parent_field_id', $choiceField->id) : collect();

            $dateFields = $fields->where('tipe_field', 'tanggal')->values();
            $tglMulaiStr = $dateFields->get(0) && isset($formData[$dateFields->get(0)->id]) ? $formData[$dateFields->get(0)->id] : null;
            $tglSelesaiStr = $dateFields->get(1) && isset($formData[$dateFields->get(1)->id]) ? $formData[$dateFields->get(1)->id] : null;

            $startDT = $tglMulaiStr ? Carbon::parse($tglMulaiStr)->startOfDay() : $t->created_at->startOfDay();
            $endDT = $tglSelesaiStr ? Carbon::parse($tglSelesaiStr)->endOfDay() : ($tglMulaiStr ? Carbon::parse($tglMulaiStr)->endOfDay() : $t->created_at->endOfDay());

            $rentangWaktu = null;
            if ($tglMulaiStr && $tglSelesaiStr) {
                $rentangWaktu = Carbon::parse($tglMulaiStr)->format('d M Y') . ' - ' . Carbon::parse($tglSelesaiStr)->format('d M Y');
            } elseif ($tglMulaiStr) {
                $rentangWaktu = Carbon::parse($tglMulaiStr)->format('d M Y');
            } else {
                $rentangWaktu = $t->created_at->format('d M Y');
            }

            // Status peminjaman tiket
            if ($t->dikembalikan_at) {
                $statusPengembalian = 'Dikembalikan';
            } elseif ($now->lt($startDT)) {
                $statusPengembalian = ($t->status === 'open') ? 'Menunggu Persetujuan' : 'Dipesan';
            } elseif ($now->between($startDT, $endDT)) {
                $statusPengembalian = ($t->status === 'open') ? 'Menunggu Persetujuan' : 'Sedang Dipinjam';
            } else {
                $statusPengembalian = 'Belum Dikembalikan';
            }

            // Ekstrak alat yang dipinjam, termasuk resolusi "Yang lain:"
            $resolvedAlat = [];
            if ($choiceField && isset($formData[$choiceField->id])) {
                $rawVal = $formData[$choiceField->id];
                $rawList = is_array($rawVal) ? $rawVal : [$rawVal];

                foreach ($rawList as $item) {
                    $itemStr = trim((string) $item);
                    $isYangLain = in_array(strtolower($itemStr), ['yang lain:', 'yang lain']);

                    if ($isYangLain) {
                        $matchingChild = $childFields->first(function ($cf) use ($itemStr) {
                            return strtolower(trim((string)$cf->trigger_value)) === strtolower($itemStr);
                        });
                        $customVal = ($matchingChild && !empty($formData[$matchingChild->id]))
                            ? trim((string)$formData[$matchingChild->id])
                            : null;

                        $label = $customVal ? "Yang lain: {$customVal}" : $itemStr;
                        $resolvedAlat[] = $label;

                        // Tambahkan ke katalog alat jika tiket sedang aktif/dipesan dan belum dikembalikan
                        if (!$t->dikembalikan_at && $customVal) {
                            $toolKey = "custom_{$t->id}_{$customVal}";
                            $masterTools->put($toolKey, [
                                'nama_alat' => "{$customVal} (Lainnya)",
                                'status' => $statusPengembalian,
                                'user' => $userStr,
                                'waktu' => $rentangWaktu,
                                'ticket_id' => $t->id,
                                'formatted_id' => $t->formatted_id,
                            ]);
                        }
                    } else {
                        $resolvedAlat[] = $itemStr;

                        // Perbarui status alat di katalog master jika sedang dipinjam / dipesan
                        if (!$t->dikembalikan_at && $masterTools->has($itemStr)) {
                            $cur = $masterTools->get($itemStr);
                            if ($cur['status'] === 'Tersedia' || in_array($statusPengembalian, ['Sedang Dipinjam', 'Belum Dikembalikan'])) {
                                $masterTools->put($itemStr, [
                                    'nama_alat' => $itemStr,
                                    'status' => $statusPengembalian,
                                    'user' => $userStr,
                                    'waktu' => $rentangWaktu,
                                    'ticket_id' => $t->id,
                                    'formatted_id' => $t->formatted_id,
                                ]);
                            }
                        }
                    }
                }
            }

            $alatName = !empty($resolvedAlat) ? implode(', ', $resolvedAlat) : '-';

            return [
                'ticket_id' => $t->id,
                'formatted_id' => $t->formatted_id,
                'peminjam' => $userStr,
                'layanan' => $t->subUnit?->nama_layanan ?? 'Peminjaman Alat',
                'alat' => $alatName,
                'waktu' => $rentangWaktu,
                'status_tiket' => $t->status,
                'status_pengembalian' => $statusPengembalian,
                'dikembalikan_at' => $t->dikembalikan_at ? $t->dikembalikan_at->format('d M Y H:i') : null,
                'kondisi_kembali' => $t->kondisi_kembali,
                'catatan_kembali' => $t->catatan_kembali,
            ];
        });

        return [
            'peminjaman' => $peminjaman,
            'ketersediaan' => $masterTools->values(),
        ];
    }
}
