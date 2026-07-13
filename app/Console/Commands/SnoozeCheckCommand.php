<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class SnoozeCheckCommand extends Command
{
    protected $signature = 'reminder:snooze-check';
    protected $description = 'Re-fire notifikasi yang snooze-nya sudah expired';

    public function handle(): int
    {
        // Cari notifikasi yang di-snooze dan sudah expired
        $notifications = DatabaseNotification::whereNotNull('read_at')
            ->get()
            ->filter(function ($notification) {
                $data = $notification->data;
                if (!isset($data['snoozed']) || !$data['snoozed']) return false;
                if (!isset($data['snoozed_until'])) return false;
                if (isset($data['done_at'])) return false; // Sudah done

                return now()->gte($data['snoozed_until']);
            });

        $refired = 0;

        foreach ($notifications as $notification) {
            // Re-fire: set read_at = null dan clear snooze flags
            $data = $notification->data;
            unset($data['snoozed']);
            unset($data['snoozed_until']);
            $data['re_fired_at'] = now()->toISOString();

            $notification->update([
                'data' => $data,
                'read_at' => null,
            ]);

            $refired++;
        }

        $this->info("Selesai. {$refired} notifikasi di-re-fire.");
        return Command::SUCCESS;
    }
}
