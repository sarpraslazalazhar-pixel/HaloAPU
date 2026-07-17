<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use App\Notifications\PendingTicketReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PendingTicketReminderCommand extends Command
{
    protected $signature = 'reminder:pending';
    protected $description = 'Kirim reminder untuk tiket yang sudah lama pending';

    public function handle(): int
    {
        $config = ReminderConfig::getConfig('pending_lama');
        if (!$config) {
            $this->info('Reminder pending tidak aktif.');
            return Command::SUCCESS;
        }

        $thresholdDays = $config->lead_time_value;
        $cutoff = now()->subDays($thresholdDays);

        $tickets = Ticket::whereRaw('LOWER(status) = ?', ['pending'])
            ->where('updated_at', '<', $cutoff)
            ->with(['subUnit.unit.admins', 'assignedAdmin'])
            ->get();

        $ticketIds = $tickets->pluck('id')->toArray();

        $sentTicketIds = \Illuminate\Notifications\DatabaseNotification::where('type', PendingTicketReminderNotification::class)
            ->whereDate('created_at', today())
            ->get()
            ->map(fn ($n) => isset($n->data['ticket_id']) ? (int)$n->data['ticket_id'] : null)
            ->filter()
            ->toArray();

        $sent = 0;

        foreach ($tickets as $ticket) {
            try {
                $notificationsToDispatch = [];

                DB::transaction(function () use ($ticket, &$notificationsToDispatch, $sentTicketIds) {
                    $lockedTicket = Ticket::lockForUpdate()->find($ticket->id);
                    if (!$lockedTicket || strtolower($lockedTicket->status) !== 'pending') {
                        return;
                    }

                    // Anti-spam: max 1 reminder per hari per tiket
                    if (in_array($lockedTicket->id, $sentTicketIds)) {
                        return;
                    }

                    // Kirim ke admin yang di-assign + admin unit terkait
                    $admins = collect();
                    if ($ticket->assignedAdmin) {
                        $admins->push($ticket->assignedAdmin);
                    }

                    $unit = $ticket->subUnit?->unit;
                    if ($unit) {
                        $unitAdmins = $unit->admins;
                        $admins = $admins->merge($unitAdmins)->unique('id');
                    }

                    foreach ($admins as $admin) {
                        $notificationsToDispatch[] = [
                            'admin' => $admin,
                            'ticket' => $lockedTicket,
                        ];
                    }
                });

                foreach ($notificationsToDispatch as $item) {
                    $item['admin']->notify(new PendingTicketReminderNotification($item['ticket']));
                    $sent++;
                }
            } catch (\Exception $e) {
                Log::error("Gagal mengirim reminder pending untuk Tiket #{$ticket->id}: " . $e->getMessage());
            }
        }

        $this->info("Selesai. {$tickets->count()} tiket pending ditemukan, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
