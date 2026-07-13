<?php

namespace App\Console\Commands;

use App\Models\ReminderConfig;
use App\Models\Ticket;
use App\Notifications\CsatReminderNotification;
use Illuminate\Console\Command;

class CsatReminderCommand extends Command
{
    protected $signature = 'reminder:csat';
    protected $description = 'Kirim reminder CSAT untuk tiket yang sudah di-solve tapi belum dirating';

    public function handle(): int
    {
        $config = ReminderConfig::getConfig('csat');
        if (!$config) {
            $this->info('Reminder CSAT tidak aktif.');
            return Command::SUCCESS;
        }

        $thresholdDays = $config->lead_time_value;
        $cutoff = now()->subDays($thresholdDays);

        $tickets = Ticket::whereIn('status', ['solve', 'Solve', 'SOLVE', 'selesai', 'Selesai', 'SELESAI'])
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('csat')  // Belum ada rating
            ->with(['user'])
            ->get();

        $sent = 0;

        foreach ($tickets as $ticket) {
            $user = $ticket->user;
            if (!$user) continue;

            // Anti-spam: max 3 reminder per tiket
            $reminderCount = \Illuminate\Notifications\DatabaseNotification::where('type', CsatReminderNotification::class)
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->id)
                ->where('data->ticket_id', $ticket->id)
                ->count();

            if ($reminderCount >= 3) continue;

            // Cek interval: tidak lebih dari 1 reminder per 2 hari per tiket
            $lastSent = \Illuminate\Notifications\DatabaseNotification::where('type', CsatReminderNotification::class)
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->id)
                ->where('data->ticket_id', $ticket->id)
                ->latest()
                ->first();

            if ($lastSent && $lastSent->created_at->diffInDays(now()) < 2) {
                continue;
            }

            $user->notify(new CsatReminderNotification($ticket));
            $sent++;
        }

        $this->info("Selesai. {$tickets->count()} tiket tanpa CSAT, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
