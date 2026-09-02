<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SnoozeCheckCommand extends Command
{
    protected $signature = 'reminder:snooze-check';
    protected $description = 'Re-fire notifikasi yang snooze-nya sudah expired';

    public function handle(): int
    {
        $this->info('Memulai pengecekan snooze notifikasi...');

        // Cari notifikasi yang di-snooze dan sudah expired langsung di database
        $notifications = DatabaseNotification::whereNotNull('read_at')
            ->where('data->snoozed', true)
            ->whereNotNull('data->snoozed_until')
            ->where('data->snoozed_until', '<=', now()->toISOString())
            ->whereNull('data->done_at')
            ->get();

        $refired = 0;

        foreach ($notifications as $notification) {
            try {
                $notificationToDispatch = null;

                DB::transaction(function () use ($notification, &$notificationToDispatch) {
                    $lockedNotification = DatabaseNotification::lockForUpdate()->find($notification->id);
                    if (!$lockedNotification) {
                        return;
                    }

                    $data = $lockedNotification->data;
                    if (!isset($data['snoozed']) || !$data['snoozed']) {
                        return;
                    }

                    $ticketId = $data['ticket_id'] ?? null;
                    if (!$ticketId) {
                        Log::warning("Snooze re-fire skipped: No ticket_id in notification #{$lockedNotification->id}");
                        return;
                    }

                    $ticket = Ticket::find($ticketId);
                    if (!$ticket) {
                        Log::warning("Snooze re-fire skipped: Ticket #{$ticketId} not found for notification #{$lockedNotification->id}");
                        return;
                    }

                    $notifiable = $lockedNotification->notifiable;
                    if (!$notifiable) {
                        Log::warning("Snooze re-fire skipped: Notifiable not found for notification #{$lockedNotification->id}");
                        return;
                    }

                    $type = $lockedNotification->type;
                    $newNotification = null;

                    if ($type === \App\Notifications\SlaEscalationNotification::class) {
                        $breachType = strtolower($data['jenis_breach'] ?? '') === 'respon' ? 'respon' : 'penyelesaian';
                        $priority = $data['prioritas'] ?? $ticket->priority ?? 'Sedang';
                        $newNotification = new \App\Notifications\SlaEscalationNotification($ticket, $breachType, $priority);
                    } elseif ($type === \App\Notifications\PendingTicketReminderNotification::class) {
                        $newNotification = new \App\Notifications\PendingTicketReminderNotification($ticket);
                    } else {
                        if (class_exists($type)) {
                            try {
                                $newNotification = new $type($ticket);
                            } catch (\Throwable $e) {
                                Log::warning("Snooze re-fire skipped: Cannot instantiate {$type}: " . $e->getMessage());
                            }
                        }
                    }

                    if ($newNotification) {
                        $data['snoozed'] = false;
                        unset($data['snoozed_until']);
                        $data['re_fired_at'] = now()->toISOString();

                        $lockedNotification->update([
                            'data' => $data,
                        ]);

                        $notificationToDispatch = [
                            'notifiable' => $notifiable,
                            'notification' => $newNotification,
                        ];
                    }
                });

                if ($notificationToDispatch) {
                    $notificationToDispatch['notifiable']->notify($notificationToDispatch['notification']);
                    $refired++;
                }
            } catch (\Exception $e) {
                Log::error("Gagal memproses snooze untuk notifikasi #{$notification->id}: " . $e->getMessage());
            }
        }

        $this->info("Selesai. {$refired} notifikasi di-re-fire.");
        return Command::SUCCESS;
    }
}
