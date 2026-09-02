<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use App\Notifications\OpenTicketReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenTicketReminderCommand extends Command
{
    protected $signature = 'reminder:open';
    protected $description = 'Kirim reminder untuk tiket baru/open yang belum ditindaklanjuti atau ditugaskan';

    public function handle(): int
    {
        $config = ReminderConfig::getConfig('open_lama');
        
        // Default threshold 1 hari jika config belum diatur/dinonaktifkan
        $thresholdDays = $config ? (int)$config->lead_time_value : 1;
        if ($thresholdDays < 1) {
            $thresholdDays = 1;
        }

        $cutoff = now()->subDays($thresholdDays);

        $tickets = Ticket::whereRaw('LOWER(status) IN (?, ?, ?)', ['open', 'diajukan', 'baru'])
            ->where('created_at', '<', $cutoff)
            ->with(['subUnit.unit.admins', 'assignedAdmin'])
            ->get();

        $ticketIds = $tickets->pluck('id')->toArray();

        $sentTicketIds = \Illuminate\Notifications\DatabaseNotification::where('type', OpenTicketReminderNotification::class)
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
                    if (!$lockedTicket || !in_array(strtolower($lockedTicket->status), ['open', 'diajukan', 'baru'])) {
                        return;
                    }

                    // Anti-spam: max 1 reminder per hari per tiket
                    if (in_array($lockedTicket->id, $sentTicketIds)) {
                        return;
                    }

                    // Kumpulkan admin penerima: Admin yang di-assign + Admin Unit terkait + Super Admin (jika belum ada unit admin)
                    $admins = collect();
                    if ($ticket->assignedAdmin) {
                        $admins->push($ticket->assignedAdmin);
                    }

                    $unit = $ticket->subUnit?->unit;
                    if ($unit) {
                        $unitAdmins = $unit->admins;
                        $admins = $admins->merge($unitAdmins);
                    }

                    if ($admins->isEmpty()) {
                        // Fallback ke Super Admin jika tidak ada admin unit yang terhubung
                        $superAdmins = Admin::role('Super Admin')->get();
                        $admins = $admins->merge($superAdmins);
                    }

                    $admins = $admins->unique('id');

                    foreach ($admins as $admin) {
                        $notificationsToDispatch[] = [
                            'admin' => $admin,
                            'ticket' => $lockedTicket,
                        ];
                    }
                });

                foreach ($notificationsToDispatch as $item) {
                    try {
                        $item['admin']->notify(new OpenTicketReminderNotification($item['ticket']));
                        $sent++;
                    } catch (\Exception $ne) {
                        Log::warning("Gagal mengirim email/WA OpenTicketReminderNotification untuk admin #{$item['admin']->id}: " . $ne->getMessage());
                    }
                }

                // Kirim pesan pengingat langsung ke percakapan bot pengingat
                $hariOpen = (int) max(1, now()->diffInDays($ticket->created_at));
                $chatBody = "📋 [PENGINGAT TIKET OPEN/BARU]\nTiket #{$ticket->formatted_id} (\"{$ticket->judul}\") masih berstatus Open dan belum diproses selama {$hariOpen} hari. Mohon segera ditugaskan ke operator atau diproses.";
                \App\Services\ChatReminderService::sendTicketReminder($ticket, $chatBody);
            } catch (\Exception $e) {
                Log::error("Gagal mengirim reminder open untuk Tiket #{$ticket->id}: " . $e->getMessage());
            }
        }

        $this->info("Selesai. {$tickets->count()} tiket open ditemukan, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
