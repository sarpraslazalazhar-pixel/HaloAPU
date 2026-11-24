<?php

namespace App\Services;

use App\Events\ActiveAssignedTicketChanged;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\User;
use App\Notifications\TicketAssignedOperatorNotification;
use App\Notifications\TicketWorkHoursNotification;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Tukar tiket antrean menjadi tiket aktif; tiket aktif lama dijeda ke antrean terdepan.
     */
    public static function swapWithActive(Ticket $ticket, Admin $actor): Ticket
    {
        $snapshotAdminId = self::adminId($ticket);
        if ($snapshotAdminId === null) {
            throw new InvalidArgumentException('Tiket belum ditugaskan ke petugas.');
        }

        return DB::transaction(function () use ($ticket, $actor, $snapshotAdminId) {
            if (! Admin::lockForUpdate()->find($snapshotAdminId)) {
                throw new InvalidArgumentException('Petugas tidak ditemukan.');
            }

            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);

            if (self::adminId($locked) !== $snapshotAdminId) {
                throw new InvalidArgumentException('Penugasan tiket baru saja berubah. Silakan muat ulang halaman.');
            }

            if (! self::isQueued($locked)) {
                throw new InvalidArgumentException('Hanya tiket yang sedang berada di antrean yang dapat ditukar ke aktif.');
            }

            $calendar = new SlaCalculator;
            $at = Carbon::now()->microsecond(0);
            if (! $calendar->isWithinWorkingHours($at)) {
                throw new InvalidArgumentException('Pertukaran tiket antrean hanya dapat dilakukan pada jam kerja.');
            }

            $holders = Ticket::where('assigned_admin_id', $snapshotAdminId)
                ->whereKeyNot($locked->id)
                ->where(fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES)
                    ->orWhere(fn ($paused) => $paused->where('status', 'pending')
                        ->whereNotNull('work_hours_resume_status')
                        ->whereNull('assignment_queued_at')))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $earliestQueuedAt = Ticket::where('assigned_admin_id', $snapshotAdminId)
                ->whereKeyNot($locked->id)
                ->where('status', 'pending')
                ->whereNotNull('assignment_queued_at')
                ->min('assignment_queued_at');
            $displacedQueueAt = $earliestQueuedAt ? Carbon::parse($earliestQueuedAt)->subSecond() : $at;
            $displacedModels = [];

            foreach ($holders as $offset => $holder) {
                $prevStatus = in_array($holder->work_hours_resume_status, self::ACTIVE_STATUSES, true)
                    ? $holder->work_hours_resume_status
                    : (in_array($holder->status, self::ACTIVE_STATUSES, true) ? $holder->status : 'open');

                if ($holderSla = self::unfinishedTracking($holder)) {
                    if (! $holderSla->isPaused()) {
                        $calendar->pauseSla($holderSla, $at);
                    }
                }

                $holderQueueAt = $offset === 0 ? $displacedQueueAt : $displacedQueueAt->copy()->addSeconds($offset);
                $holder->update([
                    'status' => 'pending',
                    'assignment_queued_at' => $holderQueueAt,
                    'assignment_resume_status' => $prevStatus,
                    'work_hours_resume_status' => null,
                    'work_hours_sla_paused_at' => null,
                ]);

                self::writeLog(
                    $holder,
                    $actor->id,
                    'pending',
                    "Pengerjaan dijeda sementara ke antrean terdepan karena petugas memprioritaskan tiket #TKT-{$locked->formatted_id}.",
                    $at,
                );
                self::notifyStatusChange($holder);
                $displacedModels[] = $holder->fresh();
            }

            $readyStatus = in_array($locked->assignment_resume_status, self::ACTIVE_STATUSES, true)
                ? $locked->assignment_resume_status
                : 'open';

            if ($sla = self::unfinishedTracking($locked)) {
                if ($sla->isPaused() || $sla->ticket->status === 'pending') {
                    $calendar->resumeSla($sla, $at, true);
                }
                if ($readyStatus === 'on_proses' && $sla->responded_at === null) {
                    $sla->refresh();
                    $sla->update([
                        'responded_at' => $at,
                        'is_response_breached' => $sla->is_response_breached
                            || ($sla->sla_response_deadline && $at->gt($sla->sla_response_deadline)),
                    ]);
                }
            }

            $locked->update([
                'status' => $readyStatus,
                'assignment_queued_at' => null,
                'assignment_resume_status' => null,
                'work_hours_resume_status' => null,
                'work_hours_sla_paused_at' => null,
            ]);

            $actorName = $actor->name ?? $actor->username ?? 'Admin';
            $firstDisplaced = $displacedModels[0] ?? null;
            $note = $firstDisplaced
                ? "Diprioritaskan menggantikan tiket aktif #TKT-{$firstDisplaced->formatted_id} oleh {$actorName}."
                : "Diprioritaskan dari antrean oleh {$actorName}.";

            self::writeLog($locked, $actor->id, $readyStatus, $note, $at);
            self::notifyStatusChange($locked);

            $activated = $locked->fresh();
            DB::afterCommit(function () use ($snapshotAdminId, $displacedModels, $activated, $actor): void {
                try {
                    broadcast(new ActiveAssignedTicketChanged($snapshotAdminId));
                } catch (\Throwable $e) {
                    Log::warning("Gagal mengirim pembaruan tiket aktif untuk petugas #{$snapshotAdminId}: ".$e->getMessage());
                }

                foreach ($displacedModels as $displaced) {
                    if (! $displaced) {
                        continue;
                    }

                    $displaced->loadMissing(['user', 'createdByAdmin', 'assignedAdmin', 'subUnit']);
                    $displacedTitle = "Tiket dijeda sementara: Tiket #TKT-{$displaced->formatted_id}";
                    $displacedMessage = 'Pengerjaan tiket Anda dijeda sementara dan ditempatkan di antrean terdepan karena petugas memprioritaskan tiket lain yang lebih mendesak.';
                    $sentKeys = ['admin:'.$actor->id => true];

                    foreach ([
                        $displaced->user ? ['user:'.$displaced->user->id, $displaced->user] : null,
                        $displaced->createdByAdmin ? ['admin:'.$displaced->createdByAdmin->id, $displaced->createdByAdmin] : null,
                    ] as $pair) {
                        if (! $pair) {
                            continue;
                        }
                        [$key, $notifiable] = $pair;
                        if (isset($sentKeys[$key])) {
                            continue;
                        }
                        $sentKeys[$key] = true;
                        try {
                            $notifiable->notify(new TicketWorkHoursNotification($displaced, $displacedMessage, false, $displacedTitle));
                        } catch (\Throwable $e) {
                            Log::error("Gagal mengirim notifikasi jeda swap tiket #{$displaced->id}: ".$e->getMessage());
                        }
                    }
                }

                if (! $activated) {
                    return;
                }

                $activated->loadMissing(['user', 'createdByAdmin', 'assignedAdmin', 'subUnit']);
                $operator = $activated->assignedAdmin;
                $operatorName = $operator?->name ?? $operator?->username ?? 'Petugas';
                $formattedId = $activated->formatted_id;
                $activatedTitle = "Giliran pengerjaan: Tiket #TKT-{$formattedId}";
                $notifiedKeys = ['admin:'.$actor->id => true];

                $sendActivated = function (?object $notifiable, string $key, string $msg) use (&$notifiedKeys, $activated, $activatedTitle): void {
                    if (! $notifiable || isset($notifiedKeys[$key])) {
                        return;
                    }
                    $notifiedKeys[$key] = true;
                    try {
                        $notifiable->notify(new TicketWorkHoursNotification($activated, $msg, false, $activatedTitle));
                    } catch (\Throwable $e) {
                        Log::error("Gagal mengirim notifikasi aktivasi antrean tiket #{$activated->id}: ".$e->getMessage());
                    }
                };

                if ($operator && (int) $operator->id !== (int) $actor->id) {
                    $sendActivated(
                        $operator,
                        'admin:'.$operator->id,
                        "Tiket #TKT-{$formattedId} diprioritaskan dari antrean dan sekarang menjadi giliran Anda untuk dikerjakan.",
                    );
                }
                if ($activated->user) {
                    $sendActivated(
                        $activated->user,
                        'user:'.$activated->user->id,
                        "Tiket Anda diprioritaskan dari antrean dan kini sedang ditangani oleh petugas {$operatorName}.",
                    );
                }
                if ($activated->createdByAdmin) {
                    $sendActivated(
                        $activated->createdByAdmin,
                        'admin:'.$activated->createdByAdmin->id,
                        "Tiket Anda diprioritaskan dari antrean dan kini sedang ditangani oleh petugas {$operatorName}.",
                    );
                }
            });

            return $activated;
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
            $locked->work_hours_resume_status = null;
            $locked->work_hours_sla_paused_at = null;
            self::writeLog(
                $locked,
                $actor->id,
                $operator ? 'assign_operator' : 'unassign_operator',
                $operator ? 'Tiket ditugaskan ke operator: '.($operator->name ?? $operator->username) : 'Penugasan operator dilepas',
                $at,
            );

            if ($targetAdminId === null) {
                $calendar = new SlaCalculator;
                if (in_array($readyStatus, self::ACTIVE_STATUSES, true)) {
                    if (! $calendar->isWithinWorkingHours($at)) {
                        $locked->update([
                            'status' => 'pending',
                            'assignment_queued_at' => null,
                            'assignment_resume_status' => 'open',
                            'work_hours_resume_status' => null,
                            'work_hours_sla_paused_at' => null,
                        ]);
                        if ($sla = self::unfinishedTracking($locked)) {
                            if (! $sla->isPaused()) {
                                $calendar->pauseSla($sla, $at);
                            }
                        }
                    } else {
                        self::restore($locked, $readyStatus, $at);
                    }
                } else {
                    $locked->save();
                }
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
            ->where('status', 'pending')
            ->whereNotNull('assignment_queued_at')
            ->whereNotNull('assignment_resume_status')
            ->orderByRaw(self::QUEUE_ORDER_SQL)
            ->orderBy('assignment_queued_at')->orderBy('created_at')->orderBy('id')
            ->lockForUpdate()->first();

        if (! $next) {
            return false;
        }

        if ($next->work_hours_resume_status !== null || $next->work_hours_sla_paused_at !== null) {
            $next->work_hours_resume_status = null;
            $next->work_hours_sla_paused_at = null;
        }

        self::restore($next, $next->assignment_resume_status, $at, true);

        return true;
    }

    /**
     * Lanjutkan otomatis tiket yang dijeda karena jam kerja tutup setelah jam kerja buka.
     */
    public static function autoResumeAfterHours(Ticket $ticket, SlaCalculator $calendar): bool
    {
        $snapshotAdminId = self::adminId($ticket);
        $resumed = false;

        DB::transaction(function () use ($ticket, $snapshotAdminId, $calendar, &$resumed) {
            if ($snapshotAdminId !== null && ! Admin::lockForUpdate()->find($snapshotAdminId)) {
                return;
            }

            $locked = Ticket::lockForUpdate()->find($ticket->id);
            $at = Carbon::now()->microsecond(0);
            if (! $locked
                || self::adminId($locked) !== $snapshotAdminId
                || $locked->status !== 'pending'
                || $locked->work_hours_resume_status === null
                || $locked->assignment_queued_at !== null
                || ! $calendar->isWithinWorkingHours($at)) {
                return;
            }

            $resumeStatus = $locked->work_hours_resume_status;
            $adminId = self::adminId($locked);

            if ($adminId !== null && self::hasOtherActive($locked, $adminId)) {
                $locked->work_hours_resume_status = null;
                $locked->work_hours_sla_paused_at = null;
                self::place($locked, $resumeStatus, $at);

                return;
            }

            if ($sla = self::unfinishedTracking($locked)) {
                if ($sla->isPaused()) {
                    $calendar->resumeSla($sla, $at, true);
                }
            }

            $locked->update([
                'status' => $resumeStatus,
                'work_hours_resume_status' => null,
                'work_hours_sla_paused_at' => null,
                'assignment_queued_at' => null,
                'assignment_resume_status' => null,
            ]);

            self::writeLog($locked, null, 'work_hours_resumed', 'Jam kerja telah buka. Pengerjaan tiket dilanjutkan otomatis.', $at);
            self::notifyStatusChange($locked);
            $resumed = true;

            $fresh = $locked->fresh();
            DB::afterCommit(function () use ($fresh, $adminId): void {
                if ($adminId !== null) {
                    try {
                        broadcast(new ActiveAssignedTicketChanged($adminId));
                    } catch (\Throwable $e) {
                        Log::warning("Gagal mengirim pembaruan tiket aktif untuk petugas #{$adminId}: ".$e->getMessage());
                    }
                }

                if (! $fresh) {
                    return;
                }

                $fresh->loadMissing(['user', 'createdByAdmin', 'assignedAdmin', 'subUnit']);
                $formattedId = $fresh->formatted_id;
                $operatorName = $fresh->assignedAdmin?->name ?? $fresh->assignedAdmin?->username ?? 'Petugas';

                self::notifyTicketParties(
                    $fresh,
                    "Jam kerja sudah dimulai. Tiket #TKT-{$formattedId} dilanjutkan otomatis dan siap dikerjakan.",
                    "Jam kerja sudah dimulai. Tiket Anda kembali aktif dan dilanjutkan oleh petugas {$operatorName}.",
                    true,
                );
            });
        });

        return $resumed;
    }

    /**
     * Aktifkan tiket yang dibuat di luar jam kerja tanpa petugas ketika jam kerja buka.
     */
    public static function activateUnassignedAfterHours(Ticket $ticket, SlaCalculator $calendar): bool
    {
        $activated = false;

        DB::transaction(function () use ($ticket, $calendar, &$activated) {
            $locked = Ticket::lockForUpdate()->find($ticket->id);
            $at = Carbon::now()->microsecond(0);
            if (! $locked
                || $locked->assigned_admin_id !== null
                || $locked->status !== 'pending'
                || $locked->assignment_queued_at !== null
                || $locked->assignment_resume_status !== 'open'
                || ! $calendar->isWithinWorkingHours($at)) {
                return;
            }

            if ($sla = self::unfinishedTracking($locked)) {
                if ($sla->isPaused() || $sla->ticket->status === 'pending') {
                    $calendar->resumeSla($sla, $at, true);
                }
            }

            $locked->update([
                'status' => 'open',
                'assignment_resume_status' => null,
                'work_hours_resume_status' => null,
                'work_hours_sla_paused_at' => null,
            ]);

            self::writeLog($locked, null, 'work_hours_resumed', 'Jam kerja telah buka. Tiket siap ditindaklanjuti.', $at);
            self::notifyStatusChange($locked);
            $activated = true;

            $fresh = $locked->fresh();
            DB::afterCommit(function () use ($fresh): void {
                if (! $fresh) {
                    return;
                }

                self::notifyTicketParties(
                    $fresh,
                    '',
                    'Jam kerja sudah buka. Tiket Anda kini aktif dan siap ditindaklanjuti.',
                    true,
                );
            });
        });

        return $activated;
    }

    /**
     * Satu entrypoint sinkronisasi saat jam kerja buka: auto-resume jeda, promosikan antrean, aktifkan unassigned.
     */
    public static function syncOpenWorkHours(?SlaCalculator $calendar = null): int
    {
        $calendar ??= new SlaCalculator;
        if (! $calendar->isWithinWorkingHours(Carbon::now())) {
            return 0;
        }

        $failed = 0;

        Ticket::query()
            ->where('status', 'pending')
            ->whereNotNull('work_hours_resume_status')
            ->whereNull('assignment_queued_at')
            ->select(['id', 'assigned_admin_id'])
            ->chunkById(100, function ($rows) use ($calendar, &$failed) {
                foreach ($rows as $row) {
                    try {
                        self::autoResumeAfterHours($row, $calendar);
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error("syncOpenWorkHours gagal auto-resume tiket #{$row->id}: ".$e->getMessage());
                    }
                }
            });

        $adminIds = Ticket::query()
            ->whereNotNull('assigned_admin_id')
            ->where('status', 'pending')
            ->whereNotNull('assignment_queued_at')
            ->whereNotNull('assignment_resume_status')
            ->distinct()
            ->pluck('assigned_admin_id');

        foreach ($adminIds as $adminId) {
            try {
                self::promoteWhenOpen((int) $adminId, $calendar);
            } catch (\Throwable $e) {
                $failed++;
                Log::error("syncOpenWorkHours gagal mempromosikan antrean petugas #{$adminId}: ".$e->getMessage());
            }
        }

        Ticket::query()
            ->whereNull('assigned_admin_id')
            ->where('status', 'pending')
            ->whereNull('assignment_queued_at')
            ->where('assignment_resume_status', 'open')
            ->select('id')
            ->chunkById(100, function ($rows) use ($calendar, &$failed) {
                foreach ($rows as $row) {
                    try {
                        self::activateUnassignedAfterHours($row, $calendar);
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error("syncOpenWorkHours gagal mengaktifkan tiket #{$row->id}: ".$e->getMessage());
                    }
                }
            });

        return $failed;
    }

    /** Fallback sinkronisasi on-demand pada detail tiket bila jam kerja sudah buka. */
    public static function syncTicketIfWorkHoursOpen(Ticket $ticket, ?SlaCalculator $calendar = null): Ticket
    {
        if ($ticket->status !== 'pending'
            || ($ticket->work_hours_resume_status === null
                && $ticket->assignment_queued_at === null
                && $ticket->assignment_resume_status === null)) {
            return $ticket;
        }

        $calendar ??= new SlaCalculator;
        if (! $calendar->isWithinWorkingHours(Carbon::now())) {
            return $ticket;
        }

        if ($ticket->work_hours_resume_status !== null && $ticket->assignment_queued_at === null) {
            self::autoResumeAfterHours($ticket, $calendar);
        } elseif ($ticket->assigned_admin_id !== null && self::isQueued($ticket)) {
            $adminId = (int) $ticket->assigned_admin_id;
            $pausedHolders = Ticket::query()
                ->where('assigned_admin_id', $adminId)
                ->where('status', 'pending')
                ->whereNotNull('work_hours_resume_status')
                ->whereNull('assignment_queued_at')
                ->get(['id', 'assigned_admin_id']);

            foreach ($pausedHolders as $holder) {
                self::autoResumeAfterHours($holder, $calendar);
            }

            self::promoteWhenOpen($adminId, $calendar);
        } elseif ($ticket->assigned_admin_id === null
            && $ticket->assignment_queued_at === null
            && $ticket->assignment_resume_status === 'open') {
            self::activateUnassignedAfterHours($ticket, $calendar);
        }

        return $ticket->fresh() ?? $ticket;
    }

    /** Fallback sinkronisasi on-demand ber-throttle (max 1x/menit) pada daftar tiket bila cron telat/nonaktif. */
    public static function syncOpenWorkHoursThrottled(?SlaCalculator $calendar = null): void
    {
        $calendar ??= new SlaCalculator;
        if (! $calendar->isWithinWorkingHours(Carbon::now())) {
            return;
        }

        try {
            if (Cache::add('tickets:work_hours:open_sync', 1, 60)) {
                self::syncOpenWorkHours($calendar);
            }
        } catch (\Throwable $e) {
            Log::warning('syncOpenWorkHoursThrottled gagal: '.$e->getMessage());
        }
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
                if (($pausedAt && $sla->paused_at && $sla->paused_at->equalTo($pausedAt)) || $sla->isPaused()) {
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

            $fresh = $locked->fresh();
            DB::afterCommit(function () use ($fresh, $actor): void {
                if (! $fresh) {
                    return;
                }

                $fresh->loadMissing(['user', 'createdByAdmin', 'assignedAdmin', 'subUnit']);
                $operatorName = $fresh->assignedAdmin?->name ?? $fresh->assignedAdmin?->username ?? 'Petugas';
                $requesterMessage = "Jam kerja sudah dimulai. Tiket Anda kembali aktif dan dilanjutkan oleh petugas {$operatorName}.";
                $notifiedKeys = ['admin:'.$actor->id => true];

                foreach ([
                    $fresh->user ? ['user:'.$fresh->user->id, $fresh->user] : null,
                    $fresh->createdByAdmin ? ['admin:'.$fresh->createdByAdmin->id, $fresh->createdByAdmin] : null,
                ] as $pair) {
                    if (! $pair) {
                        continue;
                    }
                    [$key, $notifiable] = $pair;
                    if (isset($notifiedKeys[$key])) {
                        continue;
                    }
                    $notifiedKeys[$key] = true;
                    try {
                        $notifiable->notify(new TicketWorkHoursNotification($fresh, $requesterMessage, true));
                    } catch (\Throwable $e) {
                        Log::error("Gagal mengirim notifikasi lanjut kerja tiket #{$fresh->id}: ".$e->getMessage());
                    }
                }
            });
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

    private static function restore(Ticket $ticket, string $readyStatus, Carbon $at, bool $fromQueuePromotion = false): void
    {
        $wasQueued = $ticket->assignment_queued_at !== null;
        $wasPending = $ticket->status === 'pending' && $ticket->work_hours_resume_status === null;
        if (($wasQueued || $wasPending) && ($sla = self::unfinishedTracking($ticket))) {
            $calendar = new SlaCalculator;
            if ($sla->isPaused() || $sla->ticket->status === 'pending') {
                $calendar->resumeSla($sla, $at, true);
            }
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
            'work_hours_resume_status' => null,
            'work_hours_sla_paused_at' => null,
        ]);

        if ($wasQueued || $wasPending) {
            $note = self::adminId($ticket) === null
                ? 'Tiket keluar dari antrean karena penugasan dilepas.'
                : ($wasQueued ? 'Tiket mendapat giliran petugas.' : 'Tiket diaktifkan dari status tertunda.');
            self::writeLog($ticket, null, $readyStatus, $note, $at);
            self::notifyStatusChange($ticket);

            if ($fromQueuePromotion && self::adminId($ticket) !== null) {
                $promoted = $ticket->fresh();
                DB::afterCommit(function () use ($promoted): void {
                    if (! $promoted) {
                        return;
                    }

                    $promoted->loadMissing(['user', 'createdByAdmin', 'assignedAdmin', 'subUnit']);
                    $operatorName = $promoted->assignedAdmin?->name ?? $promoted->assignedAdmin?->username ?? 'Petugas';
                    $formattedId = $promoted->formatted_id;
                    $title = "Giliran pengerjaan: Tiket #TKT-{$formattedId}";

                    self::notifyTicketParties(
                        $promoted,
                        "Tiket #TKT-{$formattedId} keluar dari antrean dan sekarang menjadi giliran Anda untuk dikerjakan.",
                        "Tiket Anda keluar dari antrean dan kini sedang ditangani oleh petugas {$operatorName}.",
                        false,
                        $title,
                    );
                });
            }
        }
    }

    private static function notifyTicketParties(
        Ticket $ticket,
        string $operatorMessage,
        string $requesterMessage,
        bool $resumeReminder = false,
        ?string $customTitle = null,
    ): void {
        $ticket->loadMissing(['user', 'createdByAdmin', 'assignedAdmin', 'subUnit']);
        $operator = $ticket->assignedAdmin;
        $notifiedKeys = [];

        $send = function (?object $notifiable, string $key, string $message) use (&$notifiedKeys, $ticket, $resumeReminder, $customTitle): void {
            if (! $notifiable || isset($notifiedKeys[$key]) || $message === '') {
                return;
            }
            $notifiedKeys[$key] = true;
            try {
                $notifiable->notify(new TicketWorkHoursNotification($ticket, $message, $resumeReminder, $customTitle));
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi jam kerja tiket #{$ticket->id}: ".$e->getMessage());
            }
        };

        if ($operator) {
            $send($operator, 'admin:'.$operator->id, $operatorMessage);
        }
        if ($ticket->user) {
            $send($ticket->user, 'user:'.$ticket->user->id, $requesterMessage);
        }
        if ($ticket->createdByAdmin) {
            $send($ticket->createdByAdmin, 'admin:'.$ticket->createdByAdmin->id, $requesterMessage);
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
