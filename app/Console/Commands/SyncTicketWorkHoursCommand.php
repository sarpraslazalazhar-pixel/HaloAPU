<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketLog;
use App\Notifications\TicketWorkHoursNotification;
use App\Services\SlaCalculator;
use App\Services\TicketWorkQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTicketWorkHoursCommand extends Command
{
    protected $signature = 'tickets:work-hours';

    protected $description = 'Tunda tiket aktif di luar jam kerja dan promosikan antrean saat jam kerja buka.';

    public function handle(SlaCalculator $calendar): int
    {
        $state = $calendar->workingHoursState(now());

        if (! $state['is_open'] && $state['closed_since'] === null && $state['next_open_at'] === null) {
            $this->warn('Konfigurasi jam kerja tidak memiliki hari kerja valid. Tidak ada tiket yang diubah.');

            return self::SUCCESS;
        }

        return $state['is_open'] ? $this->promoteQueues($calendar) : $this->pauseTickets($calendar, $state['closed_since']);
    }

    private function pauseTickets(SlaCalculator $calendar, ?\Carbon\Carbon $closedSince): int
    {
        $failed = 0;

        Ticket::query()
            ->whereNotNull('assigned_admin_id')
            ->where(fn ($q) => $q->whereIn('status', TicketWorkQueue::ACTIVE_STATUSES)
                ->orWhere(fn ($queued) => $queued->where('status', 'pending')
                    ->whereNotNull('assignment_queued_at')
                    ->whereNotNull('assignment_resume_status')))
            ->select('id')
            ->chunkById(100, function ($rows) use ($calendar, $closedSince, &$failed) {
                foreach ($rows as $row) {
                    try {
                        $ticket = Ticket::with('subUnit')->find($row->id);
                        if (! $ticket) {
                            continue;
                        }

                        $outcome = TicketWorkQueue::pauseOrNoteAfterHours($ticket, $calendar);
                        if ($outcome !== null) {
                            $this->notifyRecipients($ticket, $outcome);
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        Log::error("tickets:work-hours gagal memproses tiket #{$row->id}: ".$e->getMessage());
                    }
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function promoteQueues(SlaCalculator $calendar): int
    {
        $failed = 0;

        $adminIds = Ticket::query()
            ->whereNotNull('assigned_admin_id')
            ->where('status', 'pending')
            ->whereNotNull('assignment_queued_at')
            ->whereNotNull('assignment_resume_status')
            ->distinct()
            ->pluck('assigned_admin_id');

        foreach ($adminIds as $adminId) {
            try {
                TicketWorkQueue::promoteWhenOpen((int) $adminId, $calendar);
            } catch (Throwable $e) {
                $failed++;
                Log::error("tickets:work-hours gagal mempromosikan antrean petugas #{$adminId}: ".$e->getMessage());
            }
        }

        $unassignedIds = Ticket::query()
            ->whereNull('assigned_admin_id')
            ->where('status', 'pending')
            ->whereNull('assignment_queued_at')
            ->where('assignment_resume_status', 'open')
            ->pluck('id');

        foreach ($unassignedIds as $ticketId) {
            try {
                DB::transaction(function () use ($ticketId, $calendar): void {
                    $locked = Ticket::lockForUpdate()->find($ticketId);
                    if (! $locked
                        || $locked->assigned_admin_id !== null
                        || $locked->status !== 'pending'
                        || $locked->assignment_queued_at !== null
                        || $locked->assignment_resume_status !== 'open') {
                        return;
                    }

                    $at = now()->microsecond(0);
                    $sla = $locked->slaTracking()->lockForUpdate()->first();
                    if ($sla && ($sla->responded_at === null || $sla->resolved_at === null)) {
                        $sla->setRelation('ticket', $locked);
                        $calendar->resumeSla($sla, $at);
                    }

                    $locked->update([
                        'status' => 'open',
                        'assignment_resume_status' => null,
                    ]);

                    TicketLog::withoutEvents(fn () => TicketLog::create([
                        'ticket_id' => $locked->id,
                        'admin_id' => null,
                        'aksi' => 'open',
                        'catatan' => 'Jam kerja telah buka. Tiket siap ditindaklanjuti.',
                        'timestamp' => $at,
                    ]));
                });
            } catch (Throwable $e) {
                $failed++;
                Log::error("tickets:work-hours gagal mengaktifkan tiket luar jam kerja #{$ticketId}: ".$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function notifyRecipients(Ticket $ticket, string $outcome): void
    {
        $ticket->loadMissing(['user', 'createdByAdmin', 'assignedAdmin', 'subUnit']);
        $pauseNote = $outcome === 'paused'
            ? 'Jam kerja sudah berakhir. Tiket ditunda sampai petugas melanjutkannya pada jam kerja.'
            : 'Jam kerja sudah berakhir. Tiket tetap dalam antrean petugas.';

        $requesterNote = $ticket->assignedAdmin
            ? "{$pauseNote} Perlu ditangani hari ini? Hubungi petugas lewat chat tiket untuk meminta lembur."
            : "{$pauseNote} Petugas belum ditugaskan. Sampaikan kebutuhan hari ini di chat tiket.";

        $operatorNote = "{$pauseNote} Jika harus ditangani hari ini, koordinasikan lewat chat tiket dan ajukan lembur sesuai prosedur kantor.";

        $recipients = [];

        if ($ticket->user) {
            $recipients['user:'.$ticket->user->id] = [$ticket->user, $requesterNote];
        }

        if ($ticket->createdByAdmin) {
            $recipients['admin:'.$ticket->createdByAdmin->id] = [$ticket->createdByAdmin, $requesterNote];
        }

        if ($ticket->assignedAdmin) {
            $recipients['admin:'.$ticket->assignedAdmin->id] = [$ticket->assignedAdmin, $operatorNote];
        }

        foreach ($recipients as [$notifiable, $message]) {
            try {
                $notifiable->notify(new TicketWorkHoursNotification($ticket, $message));
            } catch (Throwable $e) {
                Log::error("Gagal mengirim notifikasi jam kerja tiket #{$ticket->id}: ".$e->getMessage());
            }
        }
    }
}
