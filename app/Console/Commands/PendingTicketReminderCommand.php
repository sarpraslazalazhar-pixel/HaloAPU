<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use App\Notifications\PendingTicketReminderNotification;
use Illuminate\Console\Command;

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

        $tickets = Ticket::whereIn('status', ['pending', 'Pending'])
            ->where('updated_at', '<', $cutoff)
            ->with(['subUnit.unit', 'assignedAdmin'])
            ->get();

        $sent = 0;

        foreach ($tickets as $ticket) {
            // Anti-spam: max 1 reminder per hari per tiket
            $alreadySent = \Illuminate\Notifications\DatabaseNotification::where('type', PendingTicketReminderNotification::class)
                ->where('data->ticket_id', $ticket->id)
                ->whereDate('created_at', today())
                ->exists();

            if ($alreadySent) continue;

            // Kirim ke admin yang di-assign + admin unit terkait
            $admins = collect();
            if ($ticket->assignedAdmin) {
                $admins->push($ticket->assignedAdmin);
            }

            $unitId = $ticket->subUnit?->unit_id;
            if ($unitId) {
                $unitAdmins = Admin::whereHas('units', fn ($q) => $q->where('unit_id', $unitId))->get();
                $admins = $admins->merge($unitAdmins)->unique('id');
            }

            foreach ($admins as $admin) {
                $admin->notify(new PendingTicketReminderNotification($ticket));
                $sent++;
            }
        }

        $this->info("Selesai. {$tickets->count()} tiket pending ditemukan, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
