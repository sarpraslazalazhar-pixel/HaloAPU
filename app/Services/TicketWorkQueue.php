<?php

namespace App\Services;

use App\Events\ActiveAssignedTicketChanged;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\User;
use App\Notifications\TicketAssignedOperatorNotification;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class TicketWorkQueue
{
    public const ACTIVE_STATUSES = ['open', 'on_proses', 'need_revision'];

    /** Urgent tickets jump the queue; the rest stay FIFO. */
    public const QUEUE_ORDER_SQL = "CASE WHEN priority = 'Urgen' THEN 0 ELSE 1 END";

    /**
     * Slot holder plus the operator's pending queue, ordered exactly like promotion
     * (urgent first, then FIFO by `assignment_queued_at`), along with manual pending tickets.
     *
     * @return array{work_hours_open: bool, next_open_at: ?string, active: array{id: int, formatted_id: string, service: string, status: string, urgent: bool, position: null, is_work_hours_paused: bool}|null, queue: list<array{id: int, formatted_id: string, service: string, status: string, urgent: bool, position: int, is_work_hours_paused: bool}>, paused: list<array{id: int, formatted_id: string, service: string, status: string, urgent: bool, position: null, is_work_hours_paused: bool}>}
     */
    public static function payload(int $adminId): array
    {
        $tickets = Ticket::where('assigned_admin_id', $adminId)
            ->whereIn('status', ['open', 'on_proses', 'pending', 'need_revision'])
            ->with('subUnit:id,nama_layanan')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 1 ELSE 0 END")
            ->orderByRaw(self::QUEUE_ORDER_SQL)
            ->orderBy('assignment_queued_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'assigned_admin_id', 'status', 'priority', 'sub_unit_id', 'assignment_queued_at', 'assignment_resume_status', 'work_hours_resume_status']);

        $active = $tickets->first(fn (Ticket $ticket) => self::holdsSlot($ticket));
        $queued = $tickets->filter(fn (Ticket $ticket) => self::isQueued($ticket))->values();
        $paused = $tickets->filter(fn (Ticket $ticket) => $ticket->status === 'pending' && ! self::holdsSlot($ticket) && ! self::isQueued($ticket))->values();
        $workHoursState = (new SlaCalculator)->workingHoursState(Carbon::now());

        $format = fn (Ticket $ticket, ?int $position) => [
            'id' => (int) $ticket->id,
            'formatted_id' => $ticket->formatted_id,
            'service' => $ticket->subUnit?->nama_layanan ?? 'Layanan tidak tersedia',
            'status' => $ticket->status,
            'urgent' => $ticket->priority === 'Urgen',
            'position' => $position,
            'is_work_hours_paused' => ! $position && $ticket->work_hours_resume_status !== null,
        ];

        return [
            'work_hours_open' => $workHoursState['is_open'],
            'next_open_at' => $workHoursState['next_open_at']?->toIso8601String(),
            'active' => $active ? $format($active, null) : null,
            'queue' => $queued->map(fn (Ticket $ticket, int $i) => $format($ticket, $i + 1))->all(),
            'paused' => $paused->map(fn (Ticket $ticket) => $format($ticket, null))->all(),
        ];
    }

    /** Tiket pending ber-marker jam kerja yang tidak dalam barisan menahan slot petugas. */
    public static function holdsSlot(Ticket $ticket): bool
    {
        if ($ticket->assigned_admin_id === null) {
            return false;
        }

        if (in_array($ticket->status, self::ACTIVE_STATUSES, true)) {
            return true;
        }

        return $ticket->status === 'pending'
            && $ticket->work_hours_resume_status !== null
            && $ticket->assignment_queued_at === null;
    }

    private static function isQueued(Ticket $ticket): bool
    {
        return $ticket->status === 'pending'
            && $ticket->assignment_queued_at !== null
            && $ticket->assignment_resume_status !== null;
    }

    /** @param Closure(Ticket, Carbon): void $mutation */
    public static function mutate(Ticket $ticket, ?int $targetAdminId, Closure $mutation): Ticket
    {
        $oldAdminId = self::adminId($ticket);

        return DB::transaction(function () use ($ticket, $oldAdminId, $targetAdminId, $mutation) {
            $adminIds = array_filter(array_unique([$oldAdminId, $targetAdminId]), fn ($id) => $id !== null);
            sort($adminIds, SORT_NUMERIC);
            $targetExists = $targetAdminId === null;
            foreach ($adminIds as $id) {
                $admin = Admin::lockForUpdate()->find($id);
                if ($id === $targetAdminId) {
                    $targetExists = $admin !== null;
                }
            }

            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if (self::adminId($locked) !== $oldAdminId) {
                throw new InvalidArgumentException('Penugasan sudah berubah. Muat ulang tiket.');
            }
            if (! $targetExists) {
                throw new InvalidArgumentException('Petugas tidak ditemukan.');
            }

            $oldStatus = $locked->status;
            $heldSlot = self::holdsSlot($locked);
            $at = Carbon::now()->microsecond(0);
            $mutation($locked, $at);

            if ($locked->status !== 'pending') {
                if ($locked->assignment_queued_at !== null || $locked->assignment_resume_status !== null
                    || $locked->work_hours_resume_status !== null || $locked->work_hours_sla_paused_at !== null) {
                    $locked->update([
                        'assignment_queued_at' => null,
                        'assignment_resume_status' => null,
                        'work_hours_resume_status' => null,
                        'work_hours_sla_paused_at' => null,
                    ]);
                }
            }

            $newAdminId = self::adminId($locked);
            $holdsSlot = self::holdsSlot($locked);
            $releasedSlot = $heldSlot && (! $holdsSlot || $newAdminId !== $oldAdminId);
            if ($releasedSlot) {
                self::promoteNext($oldAdminId, $at, new SlaCalculator);
            }

            $affectedAdmins = [];
            if ($releasedSlot) {
                $affectedAdmins[] = $oldAdminId;
            }
            if ($holdsSlot && (! $heldSlot || $newAdminId !== $oldAdminId || $locked->status !== $oldStatus)) {
                $affectedAdmins[] = $newAdminId;
            }
            foreach ($affectedAdmins as $adminId) {
                DB::afterCommit(function () use ($adminId) {
                    try {
                        broadcast(new ActiveAssignedTicketChanged($adminId));
                    } catch (\Throwable $e) {
                        Log::warning("Gagal mengirim pembaruan tiket aktif untuk petugas #{$adminId}: ".$e->getMessage());
                    }
                });
            }

            return $locked;
        });
    }

    /**
     * Geser tiket antrean satu langkah. Hanya bisa bertukar dengan tiket
     * sekelas urgensi; kalau beda kelas, urutan ditentukan prioritas.
     */
    public static function moveInQueue(Ticket $ticket, int $direction): Ticket
    {
        return DB::transaction(function () use ($ticket, $direction) {
            $snapshot = Ticket::findOrFail($ticket->id);
            if ($snapshot->assigned_admin_id !== null) {
                Admin::lockForUpdate()->find($snapshot->assigned_admin_id);
            }

            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);

            if (! self::isQueued($locked) || $locked->assigned_admin_id === null) {
                throw new InvalidArgumentException('Tiket ini tidak sedang dalam antrean.');
            }

            $adminId = (int) $locked->assigned_admin_id;

            $siblings = Ticket::where('assigned_admin_id', $adminId)
                ->where('status', 'pending')
                ->whereNotNull('assignment_queued_at')
                ->whereNotNull('assignment_resume_status')
                ->orderByRaw(self::QUEUE_ORDER_SQL)
                ->orderBy('assignment_queued_at')->orderBy('created_at')->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'priority', 'assignment_queued_at']);

            $index = $siblings->search(fn ($t) => (int) $t->id === (int) $locked->id);
            $target = $index === false ? null : $siblings->get($index + $direction);
            if (! $target) {
                return $locked;
            }

            $isUrgent = fn ($t) => $t->priority === 'Urgen';
            if ($isUrgent($locked) !== $isUrgent($target)) {
                throw new InvalidArgumentException('Tiket urgen selalu didahulukan. Ubah prioritas untuk menggeser posisi.');
            }

            // Tukar posisi lalu nomori ulang agar urutan tidak bergantung jam yang sama.
            $order = $siblings->pluck('id')->all();
            [$order[$index], $order[$index + $direction]] = [$order[$index + $direction], $order[$index]];

            $base = $siblings->min('assignment_queued_at') ?? Carbon::now();
            $base = Carbon::parse($base)->microsecond(0);

            foreach ($order as $position => $id) {
                Ticket::whereKey($id)->update(['assignment_queued_at' => $base->copy()->addSeconds($position)]);
            }

            self::writeLog($locked, null, 'pending', 'Urutan antrean diubah oleh admin.', Carbon::now()->microsecond(0));

            DB::afterCommit(function () use ($adminId) {
                try {
                    broadcast(new ActiveAssignedTicketChanged($adminId));
                } catch (\Throwable $e) {
                    Log::warning("Gagal mengirim pembaruan antrean untuk petugas #{$adminId}: ".$e->getMessage());
                }
            });

            return $locked->fresh();
        });
    }

    public static function hasOtherActive(Ticket $ticket, int $adminId): bool
    {
        return self::adminHasHolder($adminId, (int) $ticket->id);
    }

    public static function enqueuePending(Ticket $ticket, Admin $actor): Ticket
    {
        return self::mutate($ticket, null, function (Ticket $locked, Carbon $at) {
            if ($locked->assigned_admin_id === null) {
                throw new InvalidArgumentException('Pilih petugas terlebih dahulu sebelum memasukkan tiket ke antrean.');
            }

            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException('Hanya tiket berstatus tertunda yang dapat dimasukkan ke antrean.');
            }

            if (self::isQueued($locked)) {
                throw new InvalidArgumentException('Tiket sudah berada dalam antrean petugas.');
            }

            if ($locked->work_hours_resume_status !== null) {
                throw new InvalidArgumentException('Tiket ini ditunda karena jam kerja. Gunakan Lanjutkan Tiket setelah jam kerja buka.');
            }

            $sla = $locked->slaTracking()->first();
            $wasInProgress = ($sla && $sla->responded_at !== null)
                || $locked->logs()->where('aksi', 'on_proses')->exists();

            $readyStatus = in_array($locked->assignment_resume_status, self::ACTIVE_STATUSES, true)
                ? $locked->assignment_resume_status
                : ($wasInProgress ? 'on_proses' : 'open');

            self::place($locked, $readyStatus, $at);
        });
    }

    public static function assign(Ticket $ticket, ?Admin $operator, Admin $actor): Ticket
    {
        $targetAdminId = $operator ? (int) $operator->id : null;

        return self::mutate($ticket, $targetAdminId, function (Ticket $locked, Carbon $at) use ($operator, $actor, $targetAdminId) {
            if (self::adminId($locked) === $targetAdminId) {
                return;
            }

            $readyStatus = $locked->assignment_resume_status
                ?? $locked->work_hours_resume_status
                ?? $locked->status;
            $locked->assigned_admin_id = $targetAdminId;
            self::writeLog(
                $locked,
                $actor->id,
                $operator ? 'assign_operator' : 'unassign_operator',
                $operator ? 'Tiket ditugaskan ke operator: '.($operator->name ?? $operator->username) : 'Penugasan operator dilepas',
                $at,
            );

            if ($targetAdminId === null && $locked->work_hours_resume_status !== null) {
                // Tetap tertunda tanpa petugas: buang kolom antrean, pertahankan marker jeda.
                $locked->update(['assignment_queued_at' => null, 'assignment_resume_status' => null]);
            } elseif (in_array($readyStatus, self::ACTIVE_STATUSES, true)) {
                self::place($locked, $readyStatus, $at);
            } else {
                $locked->save();
            }

            if ($operator) {
                DB::afterCommit(function () use ($operator, $locked) {
                    try {
                        $operator->notify(new TicketAssignedOperatorNotification($locked));
                    } catch (\Throwable $e) {
                        Log::error("Gagal mengirim notifikasi penugasan operator untuk tiket #{$locked->id}: ".$e->getMessage());
                    }
                });
            }
        });
    }

    public static function place(Ticket $locked, string $readyStatus, Carbon $at): void
    {
        $adminId = self::adminId($locked);
        if ($adminId === null) {
            self::restore($locked, $readyStatus, $at);

            return;
        }

        $calendar = new SlaCalculator;
        $pausedByHours = $locked->work_hours_resume_status !== null;
        $hasQueue = Ticket::where('assigned_admin_id', $adminId)
            ->whereKeyNot($locked->id)
            ->where('status', 'pending')
            ->whereNotNull('assignment_queued_at')
            ->whereNotNull('assignment_resume_status')
            ->exists();
        $slotFree = $calendar->isWithinWorkingHours($at) && ! $hasQueue && ! self::hasOtherActive($locked, $adminId);

        if ($slotFree) {
            if (! $pausedByHours) {
                self::restore($locked, $readyStatus, $at);

                return;
            }

            // Tetap tertunda sampai petugas memilih Lanjutkan Tiket; marker dan jeda SLA dipertahankan.
            $locked->update(['assignment_queued_at' => null, 'assignment_resume_status' => null]);

            return;
        }

        $statusChanged = $locked->status !== 'pending';
        $locked->update([
            'status' => 'pending',
            'assignment_queued_at' => $at,
            'assignment_resume_status' => $readyStatus,
        ]);

        if ($sla = self::unfinishedTracking($locked)) {
            if (! $sla->isPaused()) {
                $calendar->pauseSla($sla, $at);
                if ($pausedByHours) {
                    $locked->update(['work_hours_sla_paused_at' => $at]);
                }
            }
        }

        self::writeLog($locked, null, 'pending', 'Tiket menunggu giliran petugas.', $at);
        if ($statusChanged) {
            self::notifyStatusChange($locked);
        }
    }

    /**
     * Scheduler entry: isi satu slot kosong per petugas, hanya saat jam kerja.
     * Kunci petugas lebih dulu agar urutan lock sejalan dengan mutate().
     */
    public static function promoteWhenOpen(int $adminId, SlaCalculator $calendar): void
    {
        DB::transaction(function () use ($adminId, $calendar) {
            if (! Admin::lockForUpdate()->find($adminId)) {
                return;
            }

            $at = Carbon::now()->microsecond(0);
            if (self::promoteNext($adminId, $at, $calendar)) {
                DB::afterCommit(function () use ($adminId) {
                    try {
                        broadcast(new ActiveAssignedTicketChanged($adminId));
                    } catch (\Throwable $e) {
                        Log::warning("Gagal mengirim pembaruan tiket aktif untuk petugas #{$adminId}: ".$e->getMessage());
                    }
                });
            }
        });
    }

    /** @return bool ada tiket antrean yang benar-benar dipromosikan */
    private static function promoteNext(int $adminId, Carbon $at, SlaCalculator $calendar): bool
    {
        if (! $calendar->isWithinWorkingHours($at)) {
            return false;
        }

        if (self::adminHasHolder($adminId, null)) {
            return false;
        }

        $next = Ticket::where('assigned_admin_id', $adminId)
            ->where(fn ($q) => $q->where(fn ($queued) => $queued->where('status', 'pending')
                    ->whereNotNull('assignment_queued_at')
                    ->whereNotNull('assignment_resume_status'))
                ->orWhere(fn ($paused) => $paused->where('status', 'pending')
                    ->whereNotNull('work_hours_resume_status')
                    ->whereNull('assignment_queued_at')))
            ->orderByRaw(self::QUEUE_ORDER_SQL)
            ->orderBy('assignment_queued_at')->orderBy('created_at')->orderBy('id')
            ->lockForUpdate()->first();

        if (! $next) {
            // Hanya tiket antrean yang boleh dipromosikan; penahan slot bukan kandidat.
            return false;
        }

        if ($next->assignment_queued_at === null) {
            return false;
        }

        if ($next->work_hours_resume_status !== null) {
            $next->update(['assignment_queued_at' => null, 'assignment_resume_status' => null]);
            self::writeLog($next, null, 'pending', 'Tiket mendapat giliran. Petugas perlu memilih Lanjutkan Tiket.', $at);

            return true;
        }

        self::restore($next, $next->assignment_resume_status, $at);

        return true;
    }

    /**
     * Tiket aktif yang masih berjalan saat jam tutup ditunda. Tiket antrean hanya
     * dicatat sekali per periode tutup. Mengembalikan jenis catatan yang dibuat.
     */
    public static function pauseOrNoteAfterHours(Ticket $ticket, SlaCalculator $calendar): ?string
    {
        $snapshotAdminId = self::adminId($ticket);
        $outcome = null;

        self::mutate($ticket, $snapshotAdminId, function (Ticket $locked, Carbon $at) use ($calendar, &$outcome) {
            $state = $calendar->workingHoursState($at);
            if ($state['is_open'] || $calendar->isWithinWorkingHours($at) || $locked->assigned_admin_id === null) {
                return;
            }

            if (self::isQueued($locked)) {
                if (self::hasWorkHoursLog($locked, $state['closed_since'])) {
                    return;
                }

                self::writeLog($locked, null, 'work_hours_queued', 'Jam kerja sudah berakhir. Tiket tetap dalam antrean petugas.', $at);
                $outcome = 'queued';

                return;
            }

            if (! in_array($locked->status, self::ACTIVE_STATUSES, true)) {
                return;
            }

            if (self::hasWorkHoursLog($locked, $state['closed_since'])) {
                return;
            }

            $previousStatus = $locked->status;
            $locked->update([
                'status' => 'pending',
                'work_hours_resume_status' => $previousStatus,
                'assignment_queued_at' => null,
                'assignment_resume_status' => null,
            ]);

            if ($sla = self::unfinishedTracking($locked)) {
                if (! $sla->isPaused()) {
                    $calendar->pauseSla($sla, $at);
                    $locked->update(['work_hours_sla_paused_at' => $at]);
                }
            }

            self::writeLog($locked, null, 'work_hours_paused', 'Jam kerja sudah berakhir. Tiket ditunda sampai petugas melanjutkannya pada jam kerja.', $at);
            $outcome = 'paused';
        });

        return $outcome;
    }

    /** Lanjut manual oleh petugas setelah jam kerja buka. */
    public static function resumeAfterHours(Ticket $ticket, Admin $actor, SlaCalculator $calendar): Ticket
    {
        return self::mutate($ticket, null, function (Ticket $locked, Carbon $at) use ($actor, $calendar) {
            if (self::adminId($locked) === null || self::adminId($locked) !== (int) $actor->id) {
                throw new InvalidArgumentException('Hanya petugas yang ditugaskan yang dapat melanjutkan tiket ini.');
            }

            if ($locked->status !== 'pending' || $locked->work_hours_resume_status === null) {
                throw new InvalidArgumentException('Tiket ini tidak ditunda karena jam kerja.');
            }

            if (self::isQueued($locked)) {
                throw new InvalidArgumentException('Tiket masih menunggu giliran petugas.');
            }

            if (! $calendar->isWithinWorkingHours($at)) {
                throw new InvalidArgumentException('Jam kerja belum buka. Tiket belum bisa dilanjutkan.');
            }

            if (self::hasOtherActive($locked, (int) $actor->id)) {
                throw new InvalidArgumentException('Petugas masih menangani tiket lain.');
            }

            if ($sla = self::unfinishedTracking($locked)) {
                $pausedAt = $locked->work_hours_sla_paused_at;
                if ($pausedAt && $sla->paused_at && $sla->paused_at->equalTo($pausedAt)) {
                    $calendar->resumeSla($sla, $at, true);
                }
            }

            $resumeStatus = $locked->work_hours_resume_status;
            $locked->update([
                'status' => $resumeStatus,
                'work_hours_resume_status' => null,
                'work_hours_sla_paused_at' => null,
            ]);

            self::writeLog($locked, $actor->id, 'work_hours_resumed', 'Petugas melanjutkan tiket setelah jam kerja buka.', $at);
            self::notifyStatusChange($locked);
        });
    }

    /**
     * Ringkasan untuk UI detail: hanya tiket antrean atau yang dijeda jam kerja.
     *
     * @return array{reason: string, resume_status: ?string, is_open: bool, next_open_label: ?string, operator_name: ?string, is_assignee: bool, can_resume: bool}|null
     */
    public static function workHoursFor(Ticket $ticket, User|Admin $viewer): ?array
    {
        $queued = self::isQueued($ticket);
        $paused = $ticket->status === 'pending' && ! $queued && $ticket->work_hours_resume_status !== null;

        if (! $queued && ! $paused) {
            return null;
        }

        $state = (new SlaCalculator)->workingHoursState(Carbon::now());
        $ticket->loadMissing('assignedAdmin');
        $operator = $ticket->assignedAdmin;
        $isAssignee = $viewer instanceof Admin
            && $ticket->assigned_admin_id !== null
            && (int) $ticket->assigned_admin_id === (int) $viewer->id;

        return [
            'reason' => $queued ? 'queued' : 'paused',
            'resume_status' => $queued ? $ticket->assignment_resume_status : $ticket->work_hours_resume_status,
            'is_open' => $state['is_open'],
            'next_open_label' => $state['next_open_at']?->locale('id')->translatedFormat('l, d M Y H:i'),
            'operator_name' => $operator?->name ?? $operator?->username,
            'is_assignee' => $isAssignee,
            'can_resume' => $paused && $isAssignee && $state['is_open'],
        ];
    }

    private static function adminHasHolder(int $adminId, ?int $exceptTicketId): bool
    {
        return Ticket::where('assigned_admin_id', $adminId)
            ->when($exceptTicketId !== null, fn ($q) => $q->whereKeyNot($exceptTicketId))
            ->where(fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES)
                ->orWhere(fn ($paused) => $paused->where('status', 'pending')
                    ->whereNotNull('work_hours_resume_status')
                    ->whereNull('assignment_queued_at')))
            ->lockForUpdate()->first(['id']) !== null;
    }

    private static function hasWorkHoursLog(Ticket $ticket, ?Carbon $closedSince): bool
    {
        return TicketLog::where('ticket_id', $ticket->id)
            ->whereIn('aksi', ['work_hours_paused', 'work_hours_queued'])
            ->when($closedSince !== null, fn ($q) => $q->where('timestamp', '>=', $closedSince))
            ->exists();
    }

    private static function restore(Ticket $ticket, string $readyStatus, Carbon $at): void
    {
        $wasQueued = $ticket->assignment_queued_at !== null;
        $wasPending = $ticket->status === 'pending' && $ticket->work_hours_resume_status === null;
        if (($wasQueued || $wasPending) && ($sla = self::unfinishedTracking($ticket))) {
            (new SlaCalculator)->resumeSla($sla, $at);
            if ($readyStatus === 'on_proses' && $sla->responded_at === null) {
                $sla->refresh();
                $sla->update([
                    'responded_at' => $at,
                    'is_response_breached' => $sla->is_response_breached
                        || ($sla->sla_response_deadline && $at->gt($sla->sla_response_deadline)),
                ]);
            }
        }

        $ticket->update([
            'status' => $readyStatus,
            'assignment_queued_at' => null,
            'assignment_resume_status' => null,
        ]);

        if ($wasQueued || $wasPending) {
            $note = self::adminId($ticket) === null
                ? 'Tiket keluar dari antrean karena penugasan dilepas.'
                : ($wasQueued ? 'Tiket mendapat giliran petugas.' : 'Tiket diaktifkan dari status tertunda.');
            self::writeLog($ticket, null, $readyStatus, $note, $at);
            self::notifyStatusChange($ticket);
        }
    }

    private static function unfinishedTracking(Ticket $ticket): ?TicketSlaTracking
    {
        $sla = $ticket->slaTracking()->lockForUpdate()->first();
        if (! $sla || ($sla->responded_at !== null && $sla->resolved_at !== null)) {
            return null;
        }

        return $sla->setRelation('ticket', $ticket);
    }

    private static function writeLog(Ticket $ticket, ?int $adminId, string $action, string $note, Carbon $at): void
    {
        $log = TicketLog::withoutEvents(fn () => TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $adminId,
            'aksi' => $action,
            'catatan' => $note,
            'timestamp' => $at,
        ]));
        DB::afterCommit(fn () => $log->broadcastTimeline());
    }

    private static function notifyStatusChange(Ticket $ticket): void
    {
        DB::afterCommit(fn () => app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.status_updated'));
    }

    private static function adminId(Ticket $ticket): ?int
    {
        return $ticket->assigned_admin_id === null ? null : (int) $ticket->assigned_admin_id;
    }
}
