<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class EquipmentLoanService
{
    public const STATUS_MENUNGGU_PERSETUJUAN = 'Menunggu Persetujuan';
    public const STATUS_MENUNGGU_DISERAHKAN = 'Menunggu Diserahkan';
    public const STATUS_SEDANG_DIPINJAM = 'Sedang Dipinjam';
    public const STATUS_BELUM_DIKEMBALIKAN = 'Belum Dikembalikan';
    public const STATUS_DIKEMBALIKAN = 'Dikembalikan';

    public static function isLoanTicket(Ticket $ticket): bool
    {
        $ticket->loadMissing('subUnit');
        $subUnit = $ticket->subUnit;

        if ((bool) $subUnit?->wajib_kembali) {
            return true;
        }

        $name = strtolower(trim((string) ($subUnit?->nama_layanan ?? '')));

        return $name !== '' && (str_contains($name, 'peminjaman alat') || str_contains($name, 'alat'));
    }

    public static function scopeLoanTickets(Builder $query): Builder
    {
        return $query->whereHas('subUnit', function (Builder $subQuery): void {
            self::applyLoanSubUnitConstraint($subQuery);
        });
    }

    public static function applyLoanSubUnitConstraint(Builder $subUnitQuery): void
    {
        $subUnitQuery->where(function (Builder $q): void {
            $q->where('wajib_kembali', true)
                ->orWhereRaw('LOWER(nama_layanan) LIKE ?', ['%peminjaman alat%'])
                ->orWhereRaw('LOWER(nama_layanan) LIKE ?', ['%alat%']);
        });
    }

    public static function isSuperAdmin(Admin $admin): bool
    {
        return $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');
    }

    public static function canViewLoanTab(Admin $admin): bool
    {
        if (self::isSuperAdmin($admin)) {
            return true;
        }

        $unitIds = $admin->units()->pluck('units.id')->filter()->map(fn ($id) => (int) $id)->all();
        $subUnitIds = $admin->subUnits()->pluck('sub_units.id')->filter()->map(fn ($id) => (int) $id)->all();

        if (! empty($unitIds) || ! empty($subUnitIds)) {
            $hasLoanService = SubUnit::query()
                ->where(function (Builder $q): void {
                    self::applyLoanSubUnitConstraint($q);
                })
                ->where(function (Builder $q) use ($unitIds, $subUnitIds): void {
                    if (! empty($unitIds) && ! empty($subUnitIds)) {
                        $q->whereIn('unit_id', $unitIds)->orWhereIn('id', $subUnitIds);
                    } elseif (! empty($unitIds)) {
                        $q->whereIn('unit_id', $unitIds);
                    } else {
                        $q->whereIn('id', $subUnitIds);
                    }
                })
                ->exists();

            if ($hasLoanService) {
                return true;
            }
        }

        return self::scopeLoanTickets(Ticket::query())
            ->where('tickets.assigned_admin_id', $admin->id)
            ->exists();
    }

    public static function canManageLoanTicket(Ticket $ticket, Admin $admin): bool
    {
        if (self::isSuperAdmin($admin)) {
            return true;
        }

        if ($ticket->assigned_admin_id !== null && (int) $ticket->assigned_admin_id === (int) $admin->id) {
            return true;
        }

        $ticket->loadMissing('subUnit');

        $unitIds = $admin->units()->pluck('units.id')->filter()->map(fn ($id) => (int) $id)->all();
        if (! empty($unitIds)) {
            if ($ticket->unit_id !== null && in_array((int) $ticket->unit_id, $unitIds, true)) {
                return true;
            }
            if ($ticket->subUnit && in_array((int) $ticket->subUnit->unit_id, $unitIds, true)) {
                return true;
            }
        }

        $subUnitIds = $admin->subUnits()->pluck('sub_units.id')->filter()->map(fn ($id) => (int) $id)->all();
        if (! empty($subUnitIds) && $ticket->sub_unit_id !== null && in_array((int) $ticket->sub_unit_id, $subUnitIds, true)) {
            return true;
        }

        return false;
    }

    public static function scopeManageableLoanTickets(Builder $query, Admin $admin): Builder
    {
        self::scopeLoanTickets($query);

        if (self::isSuperAdmin($admin)) {
            return $query;
        }

        $unitIds = $admin->units()->pluck('units.id')->filter()->map(fn ($id) => (int) $id)->all();
        $subUnitIds = $admin->subUnits()->pluck('sub_units.id')->filter()->map(fn ($id) => (int) $id)->all();

        return $query->where(function (Builder $q) use ($admin, $unitIds, $subUnitIds): void {
            $q->where('tickets.assigned_admin_id', $admin->id);

            if (! empty($unitIds)) {
                $q->orWhereIn('tickets.unit_id', $unitIds)
                    ->orWhereHas('subUnit', fn (Builder $sq) => $sq->whereIn('unit_id', $unitIds));
            }

            if (! empty($subUnitIds)) {
                $q->orWhereIn('tickets.sub_unit_id', $subUnitIds);
            }
        });
    }

    public static function isFinishedStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), Ticket::WORKLOAD_FINISHED_STATUSES, true);
    }

    /**
     * Ringkasan status fisik dan jadwal peminjaman alat dari sebuah tiket.
     *
     * @return array{
     *     status_peminjaman: string,
     *     is_overdue_schedule: bool,
     *     is_due_today: bool,
     *     alat_list: array<int, string>,
     *     alat_text: string,
     *     custom_alat: array<int, string>,
     *     rentang_waktu: string,
     *     tanggal_mulai_iso: string,
     *     tanggal_selesai_iso: string,
     *     sort_date: int
     * }
     */
    public static function extractLoanSummary(Ticket $ticket, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $ticket->loadMissing('subUnit.formFields');

        $formData = is_array($ticket->form_data) ? $ticket->form_data : [];
        $fields = $ticket->subUnit?->formFields ?? collect();
        $choiceField = $fields->first(fn ($f) => in_array($f->tipe_field, ['multi_pilih', 'dropdown'], true));
        $childFields = $choiceField ? $fields->where('parent_field_id', $choiceField->id) : collect();

        $dateFields = $fields->where('tipe_field', 'tanggal')->values();
        $rawStart = $dateFields->get(0) ? ($formData[$dateFields->get(0)->id] ?? null) : null;
        $rawEnd = $dateFields->get(1) ? ($formData[$dateFields->get(1)->id] ?? null) : null;

        $tglMulaiStr = is_string($rawStart) && trim($rawStart) !== '' ? trim($rawStart) : null;
        $tglSelesaiStr = is_string($rawEnd) && trim($rawEnd) !== '' ? trim($rawEnd) : null;

        $fallbackDate = $ticket->created_at ?? $now;
        $startDT = self::parseBoundaryDate($tglMulaiStr, $fallbackDate, true);
        $endDT = self::parseBoundaryDate($tglSelesaiStr ?? $tglMulaiStr, $fallbackDate, false);

        if ($tglMulaiStr && $tglSelesaiStr) {
            $rentangWaktu = self::formatDateLabel($tglMulaiStr, $startDT).' - '.self::formatDateLabel($tglSelesaiStr, $endDT);
        } elseif ($tglMulaiStr) {
            $rentangWaktu = self::formatDateLabel($tglMulaiStr, $startDT);
        } else {
            $rentangWaktu = $fallbackDate->format('d M Y');
        }

        if ($ticket->dikembalikan_at !== null) {
            $statusPeminjaman = self::STATUS_DIKEMBALIKAN;
        } elseif (! self::isFinishedStatus($ticket->status)) {
            $statusPeminjaman = self::STATUS_MENUNGGU_PERSETUJUAN;
        } elseif ($ticket->diserahkan_at !== null) {
            $statusPeminjaman = $now->gt($endDT)
                ? self::STATUS_BELUM_DIKEMBALIKAN
                : self::STATUS_SEDANG_DIPINJAM;
        } else {
            $statusPeminjaman = self::STATUS_MENUNGGU_DISERAHKAN;
        }

        $resolvedAlat = [];
        $customAlat = [];

        if ($choiceField && isset($formData[$choiceField->id])) {
            $rawVal = $formData[$choiceField->id];
            $rawList = is_array($rawVal) ? $rawVal : [$rawVal];

            foreach ($rawList as $item) {
                $itemStr = trim((string) $item);
                if ($itemStr === '') {
                    continue;
                }

                $isYangLain = in_array(strtolower($itemStr), ['yang lain:', 'yang lain'], true);

                if ($isYangLain) {
                    $matchingChild = $childFields->first(function ($cf) use ($itemStr): bool {
                        return strtolower(trim((string) $cf->trigger_value)) === strtolower($itemStr);
                    });
                    $customVal = ($matchingChild && ! empty($formData[$matchingChild->id]))
                        ? trim((string) $formData[$matchingChild->id])
                        : null;

                    $resolvedAlat[] = $customVal ? "Yang lain: {$customVal}" : $itemStr;
                    if ($customVal) {
                        $customAlat[] = $customVal;
                    }
                } else {
                    $resolvedAlat[] = $itemStr;
                }
            }
        }

        $alatText = $resolvedAlat !== [] ? implode(', ', $resolvedAlat) : '-';
        $isAwaitingHandover = $statusPeminjaman === self::STATUS_MENUNGGU_DISERAHKAN;

        return [
            'status_peminjaman' => $statusPeminjaman,
            'is_overdue_schedule' => $isAwaitingHandover && $now->gt($endDT),
            'is_due_today' => $isAwaitingHandover && $now->between($startDT, $endDT),
            'alat_list' => $resolvedAlat,
            'alat_text' => $alatText,
            'custom_alat' => $customAlat,
            'rentang_waktu' => $rentangWaktu,
            'tanggal_mulai_iso' => $startDT->toIso8601String(),
            'tanggal_selesai_iso' => $endDT->toIso8601String(),
            'sort_date' => $startDT->timestamp,
        ];
    }

    public static function conditionLabel(?string $kondisi): string
    {
        return match ($kondisi) {
            'rusak' => 'Rusak',
            'tidak_lengkap' => 'Kurang Lengkap',
            default => 'Bagus (Baik)',
        };
    }

    /**
     * @return array{
     *     default_recipient: array{type: string, id: int, name: string, username: string},
     *     options: array<int, array{type: string, id: int, name: string, username: string, divisi: ?string, is_requester: bool}>
     * }
     */
    public static function searchRecipientOptions(Admin $admin, ?string $search = null, ?int $ticketId = null): array
    {
        $defaultRecipient = [
            'type' => 'admin',
            'id' => (int) $admin->id,
            'name' => $admin->name ?: $admin->username,
            'username' => $admin->username,
        ];

        $requesterUserId = null;
        if ($ticketId !== null) {
            $rawUserId = Ticket::query()->whereKey($ticketId)->value('user_id');
            if ($rawUserId !== null) {
                $requesterUserId = (int) $rawUserId;
            }
        }

        $trimmedSearch = $search !== null ? trim($search) : '';
        $applySearch = function (Builder $q) use ($trimmedSearch): void {
            $q->where(function (Builder $sub) use ($trimmedSearch): void {
                $sub->where('name', 'like', "%{$trimmedSearch}%")
                    ->orWhere('username', 'like', "%{$trimmedSearch}%")
                    ->orWhere('email', 'like', "%{$trimmedSearch}%");
            });
        };

        $requesterUser = null;
        if ($requesterUserId !== null) {
            $requesterQuery = User::query()
                ->with('divisi:id,nama_divisi')
                ->whereKey($requesterUserId);
            if ($trimmedSearch !== '') {
                $applySearch($requesterQuery);
            }
            $requesterUser = $requesterQuery->first();
        }

        $users = User::query()
            ->with('divisi:id,nama_divisi')
            ->when($requesterUser !== null, fn (Builder $q) => $q->where('id', '!=', $requesterUser->id))
            ->when($trimmedSearch !== '', $applySearch)
            ->orderBy('name')
            ->orderBy('username')
            ->limit($requesterUser !== null ? 49 : 50)
            ->get();

        if ($requesterUser !== null) {
            $users->prepend($requesterUser);
        }

        $options = $users->map(function (User $user) use ($requesterUserId): array {
            return [
                'type' => 'user',
                'id' => (int) $user->id,
                'name' => $user->name ?: $user->username,
                'username' => $user->username,
                'divisi' => $user->divisi?->nama_divisi,
                'is_requester' => $requesterUserId !== null && (int) $user->id === $requesterUserId,
            ];
        })->values()->all();

        return [
            'default_recipient' => $defaultRecipient,
            'options' => $options,
        ];
    }

    /**
     * @return array{serah: array<int, array<string, mixed>>, kembali: array<int, array<string, mixed>>}
     */
    public static function extractLoanAttachments(Ticket $ticket): array
    {
        $ticket->loadMissing('logs.attachments');

        $formatAttachments = function (string $aksi) use ($ticket): array {
            return $ticket->logs
                ->where('aksi', $aksi)
                ->flatMap(fn (TicketLog $log) => $log->attachments ?? collect())
                ->map(function (TicketAttachment $att): array {
                    $name = $att->original_name ?: 'attachment';
                    if (! str_contains($name, '.')) {
                        $ext = match (strtolower((string) ($att->mime_type ?? ''))) {
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                            'application/pdf' => 'pdf',
                            default => 'jpg',
                        };
                        $name = $name.'.'.$ext;
                    }

                    return [
                        'id' => (int) $att->id,
                        'ticket_id' => (int) $att->ticket_id,
                        'ticket_log_id' => $att->ticket_log_id ? (int) $att->ticket_log_id : null,
                        'original_name' => $att->original_name ?: $name,
                        'file_name' => $name,
                        'file_path' => $att->file_path,
                        'mime_type' => $att->mime_type,
                        'file_size' => $att->file_size !== null ? (int) $att->file_size : null,
                        'path' => 'storage/'.$att->file_path,
                        'url' => url('storage/'.$att->file_path),
                    ];
                })
                ->values()
                ->all();
        };

        return [
            'serah' => $formatAttachments('alat_diserahkan'),
            'kembali' => $formatAttachments('alat_dikembalikan'),
        ];
    }

    /**
     * @param  array<int, UploadedFile|mixed>  $attachments
     */
    public static function markAsHandedOver(
        Ticket $ticket,
        Admin $admin,
        ?int $penerimaUserId = null,
        string $kondisi = 'baik',
        ?string $catatanSerah = null,
        array $attachments = []
    ): Ticket {
        if (! self::isLoanTicket($ticket)) {
            throw new InvalidArgumentException('Jenis layanan ini tidak memerlukan serah-terima alat.');
        }

        if (! self::isFinishedStatus($ticket->status)) {
            throw new InvalidArgumentException('Tiket harus disetujui/selesai terlebih dahulu sebelum alat dapat diserahkan.');
        }

        if ($ticket->dikembalikan_at !== null) {
            throw new InvalidArgumentException('Alat pada tiket ini sudah ditandai dikembalikan.');
        }

        if ($ticket->diserahkan_at !== null) {
            throw new InvalidArgumentException('Alat pada tiket ini sudah ditandai diserahkan sebelumnya.');
        }

        $recipientUser = null;
        if ($penerimaUserId !== null) {
            $recipientUser = User::find($penerimaUserId);
            if (! $recipientUser) {
                throw new InvalidArgumentException('User penerima alat tidak ditemukan.');
            }
        }

        $storedPaths = [];

        try {
            $updated = DB::transaction(function () use ($ticket, $admin, $recipientUser, $kondisi, $catatanSerah, $attachments, &$storedPaths): Ticket {
                $locked = Ticket::query()->lockForUpdate()->with('subUnit')->findOrFail($ticket->id);

                if (! self::isLoanTicket($locked)) {
                    throw new InvalidArgumentException('Jenis layanan ini tidak memerlukan serah-terima alat.');
                }
                if (! self::isFinishedStatus($locked->status)) {
                    throw new InvalidArgumentException('Tiket harus disetujui/selesai terlebih dahulu sebelum alat dapat diserahkan.');
                }
                if ($locked->dikembalikan_at !== null) {
                    throw new InvalidArgumentException('Alat pada tiket ini sudah ditandai dikembalikan.');
                }
                if ($locked->diserahkan_at !== null) {
                    throw new InvalidArgumentException('Alat pada tiket ini sudah ditandai diserahkan sebelumnya.');
                }

                $now = Carbon::now();
                $penerima = $recipientUser
                    ? ($recipientUser->name ?: $recipientUser->username)
                    : ($admin->name ?: $admin->username);
                $kondisiNormalized = in_array($kondisi, ['baik', 'rusak', 'tidak_lengkap'], true) ? $kondisi : 'baik';
                $catatan = filled($catatanSerah) ? trim((string) $catatanSerah) : null;

                $locked->update([
                    'diserahkan_at' => $now,
                    'diserahkan_oleh_admin_id' => $admin->id,
                    'penerima_alat_user_id' => $recipientUser?->id,
                    'penerima_alat' => $penerima,
                    'kondisi_serah' => $kondisiNormalized,
                    'catatan_serah' => $catatan,
                ]);

                $kondisiLabel = self::conditionLabel($kondisiNormalized);
                $adminName = $admin->name ?: $admin->username;
                $logCatatan = "Alat diserahkan [Kondisi: {$kondisiLabel}] oleh Admin {$adminName} kepada {$penerima}";
                if ($catatan !== null) {
                    $logCatatan .= " — Catatan: {$catatan}";
                }

                $log = TicketLog::create([
                    'ticket_id' => $locked->id,
                    'admin_id' => $admin->id,
                    'aksi' => 'alat_diserahkan',
                    'catatan' => $logCatatan,
                    'timestamp' => $now,
                ]);

                self::storeLoanAttachments($locked, $log, $attachments, $storedPaths);

                return $locked->fresh([
                    'subUnit.formFields',
                    'diserahkanOlehAdmin:id,name,username',
                    'penerimaAlatUser:id,name,username',
                    'diterimaKembaliOlehAdmin:id,name,username',
                    'logs.attachments',
                    'logs.admin:id,name,username',
                    'booking',
                ]);
            });
        } catch (\Throwable $e) {
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }

            throw $e;
        }

        $ticket->setRawAttributes($updated->getAttributes(), true);
        $ticket->setRelations($updated->getRelations());

        return $updated;
    }

    /**
     * @param  array<int, UploadedFile|mixed>  $attachments
     */
    public static function markAsReturned(
        Ticket $ticket,
        Admin $admin,
        string $kondisi = 'baik',
        ?string $catatanKembali = null,
        array $attachments = []
    ): Ticket {
        if (! self::isLoanTicket($ticket)) {
            throw new InvalidArgumentException('Jenis layanan ini tidak memerlukan pengembalian alat.');
        }

        if (! self::isFinishedStatus($ticket->status)) {
            throw new InvalidArgumentException('Tiket harus berstatus selesai terlebih dahulu sebelum alat dapat dikembalikan.');
        }

        if ($ticket->dikembalikan_at !== null) {
            throw new InvalidArgumentException('Alat pada tiket ini sudah ditandai dikembalikan sebelumnya.');
        }

        $storedPaths = [];

        try {
            $updated = DB::transaction(function () use ($ticket, $admin, $kondisi, $catatanKembali, $attachments, &$storedPaths): Ticket {
                $locked = Ticket::query()->lockForUpdate()->with(['subUnit', 'booking'])->findOrFail($ticket->id);

                if (! self::isLoanTicket($locked)) {
                    throw new InvalidArgumentException('Jenis layanan ini tidak memerlukan pengembalian alat.');
                }
                if (! self::isFinishedStatus($locked->status)) {
                    throw new InvalidArgumentException('Tiket harus berstatus selesai terlebih dahulu sebelum alat dapat dikembalikan.');
                }
                if ($locked->dikembalikan_at !== null) {
                    throw new InvalidArgumentException('Alat pada tiket ini sudah ditandai dikembalikan sebelumnya.');
                }

                $now = Carbon::now();
                $kondisiNormalized = in_array($kondisi, ['baik', 'rusak', 'tidak_lengkap'], true) ? $kondisi : 'baik';
                $catatan = filled($catatanKembali) ? trim((string) $catatanKembali) : null;

                $locked->update([
                    'dikembalikan_at' => $now,
                    'diterima_kembali_oleh_admin_id' => $admin->id,
                    'kondisi_kembali' => $kondisiNormalized,
                    'catatan_kembali' => $catatan,
                ]);

                if ($locked->booking) {
                    $locked->booking->update(['status' => 'selesai']);
                }

                $kondisiLabel = self::conditionLabel($kondisiNormalized);
                $adminName = $admin->name ?: $admin->username;
                $logCatatan = "Alat dikembalikan [Kondisi: {$kondisiLabel}], ditandai oleh Admin {$adminName}";
                if ($catatan !== null) {
                    $logCatatan .= " — Catatan: {$catatan}";
                }

                $log = TicketLog::create([
                    'ticket_id' => $locked->id,
                    'admin_id' => $admin->id,
                    'aksi' => 'alat_dikembalikan',
                    'catatan' => $logCatatan,
                    'timestamp' => $now,
                ]);

                self::storeLoanAttachments($locked, $log, $attachments, $storedPaths);

                return $locked->fresh([
                    'subUnit.formFields',
                    'diserahkanOlehAdmin:id,name,username',
                    'penerimaAlatUser:id,name,username',
                    'diterimaKembaliOlehAdmin:id,name,username',
                    'logs.attachments',
                    'logs.admin:id,name,username',
                    'booking',
                ]);
            });
        } catch (\Throwable $e) {
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }

            throw $e;
        }

        $ticket->setRawAttributes($updated->getAttributes(), true);
        $ticket->setRelations($updated->getRelations());

        return $updated;
    }

    /**
     * @param  array<int, UploadedFile|mixed>  $attachments
     * @param  array<int, string>  $storedPaths
     */
    private static function storeLoanAttachments(Ticket $ticket, TicketLog $log, array $attachments, array &$storedPaths): void
    {
        foreach ($attachments as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $path = Storage::disk('public')->putFileAs(
                "ticket-attachments/{$ticket->id}",
                $file->getPathname(),
                $file->hashName()
            );

            if ($path === false) {
                continue;
            }

            $storedPaths[] = $path;

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'field_id' => null,
                'ticket_log_id' => $log->id,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'wajib' => false,
            ]);
        }
    }

    private static function parseBoundaryDate(?string $raw, Carbon $fallback, bool $startOfDay): Carbon
    {
        if ($raw === null) {
            return $startOfDay ? $fallback->copy()->startOfDay() : $fallback->copy()->endOfDay();
        }

        try {
            $parsed = Carbon::parse($raw);

            return $startOfDay ? $parsed->startOfDay() : $parsed->endOfDay();
        } catch (\Throwable) {
            return $startOfDay ? $fallback->copy()->startOfDay() : $fallback->copy()->endOfDay();
        }
    }

    private static function formatDateLabel(string $raw, Carbon $parsedFallback): string
    {
        try {
            return Carbon::parse($raw)->format('d M Y');
        } catch (\Throwable) {
            return $parsedFallback->format('d M Y');
        }
    }
}
