<?php

namespace App\Services;

use App\Events\ActiveAssignedTicketChanged;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
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
     * (urgent first, then FIFO by `assignment_queued_at`).
     *
     * @return array{active: array{id: int, formatted_id: string, service: string, status: string, urgent: bool, position: null}|null, queue: list<array{id: int, formatted_id: string, service: string, status: string, urgent: bool, position: int}>}
     */
    public static function payload(int $adminId): array
    {
        $tickets = Ticket::where('assigned_admin_id', $adminId)
            ->where(fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES)
                ->orWhere(fn ($queued) => $queued->where('status', 'pending')
                    ->whereNotNull('assignment_queued_at')
                    ->whereNotNull('assignment_resume_status')))
            ->with('subUnit:id,nama_layanan')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 1 ELSE 0 END")
            ->orderByRaw(self::QUEUE_ORDER_SQL)
            ->orderBy('assignment_queued_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'status', 'priority', 'sub_unit_id']);

        $active = $tickets->first(fn (Ticket $ticket) => $ticket->status !== 'pending');
        $queued = $tickets->filter(fn (Ticket $ticket) => $ticket->status === 'pending')->values();

        $format = fn (Ticket $ticket, ?int $position) => [
            'id' => (int) $ticket->id,
            'formatted_id' => $ticket->formatted_id,
            'service' => $ticket->subUnit?->nama_layanan ?? 'Layanan tidak tersedia',
            'status' => $ticket->status,
            'urgent' => $ticket->priority === 'Urgen',
            'position' => $position,
        ];

        return [
            'active' => $active ? $format($active, null) : null,
            'queue' => $queued->map(fn (Ticket $ticket, int $i) => $format($ticket, $i + 1))->all(),
        ];
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
            $heldSlot = $oldAdminId !== null && in_array($oldStatus, self::ACTIVE_STATUSES, true);
            $at = Carbon::now()->microsecond(0);
            $mutation($locked, $at);

            if ($locked->status !== $oldStatus && $locked->status !== 'pending'
                && ($locked->assignment_queued_at !== null || $locked->assignment_resume_status !== null)) {
                $locked->update(['assignment_queued_at' => null, 'assignment_resume_status' => null]);
            }

            $newAdminId = self::adminId($locked);
            $holdsSlot = $newAdminId !== null && in_array($locked->status, self::ACTIVE_STATUSES, true);
            $releasedSlot = $heldSlot && (! $holdsSlot || $newAdminId !== $oldAdminId);
            if ($releasedSlot) {
                $next = Ticket::where('assigned_admin_id', $oldAdminId)
                    ->where('status', 'pending')
                    ->whereNotNull('assignment_queued_at')
                    ->whereNotNull('assignment_resume_status')
                    ->orderByRaw(self::QUEUE_ORDER_SQL)
                    ->orderBy('assignment_queued_at')->orderBy('created_at')->orderBy('id')
                    ->lockForUpdate()->first();
                if ($next) {
                    self::restore($next, $next->assignment_resume_status, $at);
                }
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
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status !== 'pending' || $locked->assignment_queued_at === null || $locked->assigned_admin_id === null) {
                throw new InvalidArgumentException('Tiket ini tidak sedang dalam antrean.');
            }

            $adminId = (int) $locked->assigned_admin_id;
            Admin::lockForUpdate()->find($adminId);

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
        return Ticket::where('assigned_admin_id', $adminId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereKeyNot($ticket->id)
            ->lockForUpdate()->first(['id']) !== null;
    }

    public static function assign(Ticket $ticket, ?Admin $operator, Admin $actor): Ticket
    {
        $targetAdminId = $operator ? (int) $operator->id : null;

        return self::mutate($ticket, $targetAdminId, function (Ticket $locked, Carbon $at) use ($operator, $actor, $targetAdminId) {
            if (self::adminId($locked) === $targetAdminId) {
                return;
            }

            $readyStatus = $locked->assignment_queued_at !== null ? $locked->assignment_resume_status : $locked->status;
            $locked->assigned_admin_id = $targetAdminId;
            self::writeLog(
                $locked,
                $actor->id,
                $operator ? 'assign_operator' : 'unassign_operator',
                $operator ? 'Tiket ditugaskan ke operator: '.($operator->name ?? $operator->username) : 'Penugasan operator dilepas',
                $at,
            );

            if (in_array($readyStatus, self::ACTIVE_STATUSES, true)) {
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
        if ($adminId === null || ! self::hasOtherActive($locked, $adminId)) {
            self::restore($locked, $readyStatus, $at);

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
                (new SlaCalculator)->pauseSla($sla, $at);
            }
        }

        self::writeLog($locked, null, 'pending', 'Tiket menunggu giliran petugas.', $at);
        if ($statusChanged) {
            self::notifyStatusChange($locked);
        }
    }

    private static function restore(Ticket $ticket, string $readyStatus, Carbon $at): void
    {
        $wasQueued = $ticket->assignment_queued_at !== null;
        if ($wasQueued && ($sla = self::unfinishedTracking($ticket))) {
            (new SlaCalculator)->resumeSla($sla, $at);
        }

        $ticket->update([
            'status' => $readyStatus,
            'assignment_queued_at' => null,
            'assignment_resume_status' => null,
        ]);

        if ($wasQueued) {
            $note = self::adminId($ticket) === null
                ? 'Tiket keluar dari antrean karena penugasan dilepas.'
                : 'Tiket mendapat giliran petugas.';
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
