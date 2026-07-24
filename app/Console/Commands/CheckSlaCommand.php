<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Ticket;
use App\Notifications\SlaEscalationNotification;
use App\Services\SlaCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSlaCommand extends Command
{
    protected $signature = 'sla:check';
    protected $description = 'Cek status SLA semua tiket aktif dan kirim notifikasi jika breach';

    public function handle(SlaCalculator $slaCalculator): int
    {
        $this->info('Memulai pengecekan SLA...');

        $activeTickets = Ticket::whereIn('status', ['open', 'on_proses'])
            ->whereHas('slaTracking', function ($q) {
                $q->whereNull('paused_at');
            })
            ->with(['slaTracking', 'subUnit.unit', 'unit.admins', 'assignedAdmin'])
            ->get();

        $escalated = 0;

        foreach ($activeTickets as $ticket) {
            try {
                $notificationsToDispatch = [];

                DB::transaction(function () use ($ticket, $slaCalculator, &$notificationsToDispatch) {
                    $sla = $ticket->slaTracking()->lockForUpdate()->first();
                    if (!$sla) {
                        return;
                    }
                    
                    // Prevent N+1 query inside SlaCalculator by setting the already loaded ticket relation
                    $sla->setRelation('ticket', $ticket);

                    $wasResponseBreached = $sla->is_response_breached;
                    $wasResolutionBreached = $sla->is_resolution_breached;

                    // Calculates SLA status and updates the tracking model flags.
                    $slaCalculator->checkAndUpdateTier($sla);

                    $sla->refresh();

                    $isResponseNewlyBreached = !$wasResponseBreached && $sla->is_response_breached;
                    $isResolutionNewlyBreached = !$wasResolutionBreached && $sla->is_resolution_breached;

                    if ($isResponseNewlyBreached) {
                        Log::info("SLA Breach (Response): Tiket #{$ticket->id} priority {$ticket->priority}");
                        
                        $adminsToNotify = collect();
                        if ($ticket->unit && $ticket->unit->admins->isNotEmpty()) {
                            $adminsToNotify = $adminsToNotify->concat($ticket->unit->admins);
                        }
                        if ($ticket->assignedAdmin) {
                            $adminsToNotify->push($ticket->assignedAdmin);
                        }
                        if ($adminsToNotify->isEmpty()) {
                            $adminsToNotify = Admin::all();
                        }
                        
                        $adminsToNotify = $adminsToNotify->unique('id');
                        foreach ($adminsToNotify as $admin) {
                            $notificationsToDispatch[] = [
                                'admin' => $admin,
                                'type' => 'respon',
                                'priority' => $ticket->priority ?? 'Sedang',
                            ];
                        }
                    }

                    if ($isResolutionNewlyBreached) {
                        Log::info("SLA Breach (Resolution): Tiket #{$ticket->id} priority {$ticket->priority}");
                        
                        $adminsToNotify = collect();
                        if ($ticket->unit && $ticket->unit->admins->isNotEmpty()) {
                            $adminsToNotify = $adminsToNotify->concat($ticket->unit->admins);
                        }
                        if ($ticket->assignedAdmin) {
                            $adminsToNotify->push($ticket->assignedAdmin);
                        }
                        if ($adminsToNotify->isEmpty()) {
                            $adminsToNotify = Admin::all();
                        }
                        
                        $adminsToNotify = $adminsToNotify->unique('id');
                        foreach ($adminsToNotify as $admin) {
                            $notificationsToDispatch[] = [
                                'admin' => $admin,
                                'type' => 'penyelesaian',
                                'priority' => $ticket->priority ?? 'Sedang',
                            ];
                        }
                    }
                });

                foreach ($notificationsToDispatch as $item) {
                    $item['admin']->notify(new SlaEscalationNotification($ticket, $item['type'], $item['priority']));
                    $escalated++;
                }
            } catch (\Exception $e) {
                Log::error("Gagal memeriksa SLA untuk Tiket #{$ticket->id}: " . $e->getMessage());
            }
        }

        $this->info("Selesai. {$activeTickets->count()} tiket dicek, {$escalated} eskalasi.");
        return Command::SUCCESS;
    }
}
