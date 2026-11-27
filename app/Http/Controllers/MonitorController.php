<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\RoomVehicleBooking;
use App\Models\Ticket;
use App\Services\EquipmentLoanService;
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
    protected function getAssetData(\Closure $ticketUrl, ?string $tipe = null)
    {
        $now = Carbon::now();
        $oneHourAgo = $now->copy()->subHour();

        // Ambil semua booking yang relevan (selesai dalam 1 jam terakhir atau di masa depan)
        $query = RoomVehicleBooking::whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
            ->whereHas('ticket', function ($tq) {
                $tq->whereNotIn('status', ['reject', 'dibatalkan']);
            })
            ->where('tanggal_selesai', '>=', $oneHourAgo)
            ->with([
                'ticket:id,user_id,created_by_admin_id,status',
                'ticket.user:id,username,name',
                'ticket.createdByAdmin:id,username,name',
            ]);
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
        return $allAssets->map(function ($asset) use ($bookings, $now, $oneHourAgo, $formatWaktu, $ticketUrl) {
            $assetBookings = $bookings->where('nama_aset', $asset->nama_aset);

            // 1. Cek apakah sedang aktif berlangsung saat ini (mulai <= now < selesai)
            $activeBooking = $assetBookings->filter(function ($b) use ($now) {
                return Carbon::parse($b->tanggal_mulai)->lte($now)
                    && Carbon::parse($b->tanggal_selesai)->gt($now);
            })->sortByDesc('tanggal_mulai')->first();

            if ($activeBooking) {
                $userStr = $activeBooking->ticket?->user?->name ?? $activeBooking->ticket?->createdByAdmin?->name ?? $activeBooking->ticket?->user?->username ?? $activeBooking->ticket?->createdByAdmin?->username ?? '-';
                $isApproved = in_array($activeBooking->status, ['on_proses', 'disetujui', 'solve'])
                    || in_array($activeBooking->ticket?->status, ['on_proses', 'solve']);
                $statusLabel = $isApproved ? 'Sedang Dipakai' : 'Menunggu Persetujuan';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => $statusLabel,
                    'user' => $userStr,
                    'waktu' => $formatWaktu($activeBooking->tanggal_mulai, $activeBooking->tanggal_selesai),
                    'booking_id' => $activeBooking->id,
                    'ticket_url' => $ticketUrl($activeBooking->ticket),
                ];
            }

            // 2. Cek apakah ada booking berikutnya di masa depan (now < mulai) — selaras dengan Mobile
            $upcomingBooking = $assetBookings->filter(function ($b) use ($now) {
                return Carbon::parse($b->tanggal_mulai)->gt($now);
            })->sortBy('tanggal_mulai')->first();

            if ($upcomingBooking) {
                $isApprovedUpcoming = in_array($upcomingBooking->status, ['on_proses', 'disetujui', 'solve'])
                    || in_array($upcomingBooking->ticket?->status, ['on_proses', 'solve']);
                $displayStatus = $isApprovedUpcoming ? 'Dipesan' : 'Menunggu Persetujuan';
                $userStr = $upcomingBooking->ticket?->user?->name ?? $upcomingBooking->ticket?->createdByAdmin?->name ?? $upcomingBooking->ticket?->user?->username ?? $upcomingBooking->ticket?->createdByAdmin?->username ?? '-';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => $displayStatus,
                    'user' => $userStr,
                    'waktu' => $formatWaktu($upcomingBooking->tanggal_mulai, $upcomingBooking->tanggal_selesai),
                    'booking_id' => $upcomingBooking->id,
                    'ticket_url' => $ticketUrl($upcomingBooking->ticket),
                ];
            }

            // 3. Cek apakah ada booking yang baru saja selesai dalam 1 jam terakhir (now - 1 jam <= selesai < now)
            $recentlyFinishedBooking = $assetBookings->filter(function ($b) use ($now, $oneHourAgo) {
                $end = Carbon::parse($b->tanggal_selesai);
                return $end->gte($oneHourAgo) && $end->lte($now);
            })->sortByDesc('tanggal_selesai')->first();

            if ($recentlyFinishedBooking) {
                $userStr = $recentlyFinishedBooking->ticket?->user?->name ?? $recentlyFinishedBooking->ticket?->createdByAdmin?->name ?? $recentlyFinishedBooking->ticket?->user?->username ?? $recentlyFinishedBooking->ticket?->createdByAdmin?->username ?? '-';
                return [
                    'nama_aset' => $asset->nama_aset,
                    'tipe' => $asset->tipe,
                    'status' => 'Selesai Digunakan',
                    'user' => $userStr,
                    'waktu' => $formatWaktu($recentlyFinishedBooking->tanggal_mulai, $recentlyFinishedBooking->tanggal_selesai),
                    'booking_id' => $recentlyFinishedBooking->id,
                    'ticket_url' => $ticketUrl($recentlyFinishedBooking->ticket),
                ];
            }

            return [
                'nama_aset' => $asset->nama_aset,
                'tipe' => $asset->tipe,
                'status' => 'Tersedia',
                'user' => null,
                'waktu' => null,
                'booking_id' => null,
                'ticket_url' => null,
            ];
        });
    }

    /**
     * Booking jadi tautan hanya bila penonton berhak membuka tiketnya.
     * User hanya tiketnya sendiri; admin mengikuti akses detail tiket.
     */
    protected function ticketUrlResolver(bool $isAdmin): \Closure
    {
        if ($isAdmin) {
            $admin = auth('admin')->user();
            $canViewAll = $admin->hasRole(['Admin', 'admin', 'superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
                || str_contains(strtolower($admin->username ?? ''), 'superadmin');
            $accessibleIds = $canViewAll ? null : Ticket::accessibleBy($admin)->pluck('id')->flip();

            return fn (?Ticket $ticket): ?string => $ticket && ($canViewAll || $accessibleIds->has($ticket->id))
                ? route('admin.tiket.show', $ticket->id)
                : null;
        }

        $userId = auth('web')->id();

        return fn (?Ticket $ticket): ?string => $ticket && $userId !== null && (int) $ticket->user_id === (int) $userId
            ? route('tiket.show', $ticket->id)
            : null;
    }

    /**
     * Data kalender: booking dikelompokkan per tanggal (30 hari ke depan).
     */
    protected function getCalendarData(\Closure $ticketUrl)
    {
        $now = Carbon::now();

        $bookings = RoomVehicleBooking::whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
            ->whereHas('ticket', function ($tq) {
                $tq->whereNotIn('status', ['reject', 'dibatalkan']);
            })
            ->where('tanggal_selesai', '>=', $now->copy()->startOfDay())
            ->where('tanggal_mulai', '<=', $now->copy()->addDays(30)->endOfDay())
            ->with([
                'ticket:id,user_id,created_by_admin_id,status',
                'ticket.user:id,username,name',
                'ticket.createdByAdmin:id,username,name',
            ])
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
                'user' => $b->ticket?->user?->name ?? $b->ticket?->createdByAdmin?->name ?? $b->ticket?->user?->username ?? $b->ticket?->createdByAdmin?->username ?? '-',
                'status' => $b->status,
                'booking_id' => $b->id,
                'ticket_url' => $ticketUrl($b->ticket),
            ]),
        ])->values();
    }

    public function userIndex()
    {
        $ticketUrl = $this->ticketUrlResolver(false);
        $alat = $this->getAlatData($ticketUrl, null);

        return Inertia::render('User/Monitor/Index', [
            'assets' => $this->getAssetData($ticketUrl),
            'calendarData' => $this->getCalendarData($ticketUrl),
            'alatPinjam' => $alat['peminjaman'],
            'ketersediaanAlat' => $alat['ketersediaan'],
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }

    public function adminIndex()
    {
        $ticketUrl = $this->ticketUrlResolver(true);
        $alat = $this->getAlatData($ticketUrl, auth('admin')->user());

        return Inertia::render('Admin/Monitor/Index', [
            'assets' => $this->getAssetData($ticketUrl),
            'calendarData' => $this->getCalendarData($ticketUrl),
            'alatPinjam' => $alat['peminjaman'],
            'ketersediaanAlat' => $alat['ketersediaan'],
            'lastUpdated' => now()->format('H:i:s'),
        ]);
    }

    protected function getAlatData(\Closure $ticketUrl, ?Admin $admin = null)
    {
        $sevenDaysAgo = Carbon::now()->subDays(7);
        $now = Carbon::now();

        $tickets = EquipmentLoanService::scopeLoanTickets(Ticket::query())
            ->whereNotIn('status', ['reject', 'dibatalkan'])
            ->where(function ($q) use ($sevenDaysAgo) {
                $q->whereNull('dikembalikan_at')
                    ->orWhere('dikembalikan_at', '>=', $sevenDaysAgo);
            })
            ->with([
                'user:id,name,username',
                'user.divisi:id,nama_divisi',
                'createdByAdmin:id,name,username',
                'subUnit:id,unit_id,nama_layanan,wajib_kembali',
                'subUnit.formFields',
                'diserahkanOlehAdmin:id,name,username',
                'penerimaAlatUser:id,name,username',
                'diterimaKembaliOlehAdmin:id,name,username',
                'logs' => fn ($q) => $q->whereIn('aksi', ['alat_diserahkan', 'pengajuan_kembali_alat', 'alat_dikembalikan'])->with('attachments'),
            ])
            ->orderByRaw('CASE WHEN dikembalikan_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderByRaw('CASE WHEN dikembalikan_at IS NULL AND pengembalian_diajukan_at IS NOT NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        // 1. Koleksi master daftar alat dari FormField tipe multi_pilih/dropdown
        $subUnits = \App\Models\SubUnit::query()
            ->where(function ($q): void {
                EquipmentLoanService::applyLoanSubUnitConstraint($q);
            })
            ->with('formFields')
            ->get();
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
                    'ticket_url' => null,
                ]);
            }
        }

        $statusPriority = [
            EquipmentLoanService::STATUS_MENUNGGU_KONFIRMASI_KEMBALI => 5,
            EquipmentLoanService::STATUS_BELUM_DIKEMBALIKAN => 4,
            EquipmentLoanService::STATUS_SEDANG_DIPINJAM => 3,
            EquipmentLoanService::STATUS_MENUNGGU_DISERAHKAN => 2,
            EquipmentLoanService::STATUS_MENUNGGU_PERSETUJUAN => 1,
            'Tersedia' => 0,
        ];

        // 2. Map data tiket dan update ketersediaan alat
        $peminjaman = $tickets->map(function (Ticket $t) use ($now, $masterTools, $ticketUrl, $admin, $statusPriority) {
            $userStr = $t->user?->name ?? $t->createdByAdmin?->name ?? $t->user?->username ?? $t->createdByAdmin?->username ?? '-';
            $summary = EquipmentLoanService::extractLoanSummary($t, $now);
            $statusPengembalian = $summary['status_peminjaman'];
            $rentangWaktu = $summary['rentang_waktu'];
            $resolvedTicketUrl = $ticketUrl($t);

            if (! $t->dikembalikan_at) {
                foreach ($summary['custom_alat'] as $customVal) {
                    $toolKey = "custom_{$t->id}_{$customVal}";
                    $masterTools->put($toolKey, [
                        'nama_alat' => "{$customVal} (Lainnya)",
                        'status' => $statusPengembalian,
                        'user' => $userStr,
                        'waktu' => $rentangWaktu,
                        'ticket_id' => $t->id,
                        'formatted_id' => $t->formatted_id,
                        'ticket_url' => $resolvedTicketUrl,
                    ]);
                }

                foreach ($summary['alat_list'] as $itemStr) {
                    if (str_starts_with(strtolower($itemStr), 'yang lain') || ! $masterTools->has($itemStr)) {
                        continue;
                    }

                    $cur = $masterTools->get($itemStr);
                    $curPriority = $statusPriority[$cur['status']] ?? 0;
                    $nextPriority = $statusPriority[$statusPengembalian] ?? 0;

                    if ($nextPriority > $curPriority) {
                        $masterTools->put($itemStr, [
                            'nama_alat' => $itemStr,
                            'status' => $statusPengembalian,
                            'user' => $userStr,
                            'waktu' => $rentangWaktu,
                            'ticket_id' => $t->id,
                            'formatted_id' => $t->formatted_id,
                            'ticket_url' => $resolvedTicketUrl,
                        ]);
                    }
                }
            }

            $canManage = $admin !== null && EquipmentLoanService::canManageLoanTicket($t, $admin);
            $isFinished = EquipmentLoanService::isFinishedStatus($t->status);
            $loanAttachments = EquipmentLoanService::extractLoanAttachments($t);

            return [
                'ticket_id' => $t->id,
                'formatted_id' => $t->formatted_id,
                'ticket_url' => $resolvedTicketUrl,
                'peminjam' => $userStr,
                'layanan' => $t->subUnit?->nama_layanan ?? 'Peminjaman Alat',
                'alat' => $summary['alat_text'],
                'waktu' => $rentangWaktu,
                'status_tiket' => $t->status,
                'status_pengembalian' => $statusPengembalian,
                'diserahkan_at' => $t->diserahkan_at ? $t->diserahkan_at->format('d M Y H:i') : null,
                'diserahkan_oleh' => $t->diserahkanOlehAdmin ? ($t->diserahkanOlehAdmin->name ?: $t->diserahkanOlehAdmin->username) : null,
                'penerima_alat_user_id' => $t->penerima_alat_user_id ? (int) $t->penerima_alat_user_id : null,
                'penerima_alat' => $t->penerima_alat,
                'kondisi_serah' => $t->kondisi_serah,
                'catatan_serah' => $t->catatan_serah,
                'pengembalian_diajukan_at' => $t->pengembalian_diajukan_at ? $t->pengembalian_diajukan_at->format('d M Y H:i') : null,
                'catatan_pengajuan_kembali' => $t->catatan_pengajuan_kembali,
                'dikembalikan_at' => $t->dikembalikan_at ? $t->dikembalikan_at->format('d M Y H:i') : null,
                'diterima_kembali_oleh' => $t->diterimaKembaliOlehAdmin ? ($t->diterimaKembaliOlehAdmin->name ?: $t->diterimaKembaliOlehAdmin->username) : null,
                'kondisi_kembali' => $t->kondisi_kembali,
                'catatan_kembali' => $t->catatan_kembali,
                'lampiran_serah_count' => count($loanAttachments['serah']),
                'lampiran_pengajuan_kembali_count' => count($loanAttachments['pengajuan_kembali']),
                'lampiran_kembali_count' => count($loanAttachments['kembali']),
                'is_overdue_schedule' => $summary['is_overdue_schedule'],
                'can_serahkan' => $canManage && $isFinished && $t->diserahkan_at === null && $t->dikembalikan_at === null,
                'can_kembalikan' => $canManage && $isFinished && $t->dikembalikan_at === null,
            ];
        });

        return [
            'peminjaman' => $peminjaman,
            'ketersediaan' => $masterTools->values(),
        ];
    }
}
