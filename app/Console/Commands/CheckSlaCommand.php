<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Ticket;
use App\Notifications\SlaEscalationNotification;
use App\Notifications\SlaHalfWarningNotification;
use App\Services\SlaCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSlaCommand extends Command
{
    protected $signature = 'sla:check';
    protected $description = 'Cek status SLA semua tiket aktif, kirim pengingat 50% waktu SLA, dan notifikasi jika breach';

    public function handle(SlaCalculator $slaCalculator): int
    {
        $this->info('Memulai pengecekan SLA...');

        $activeTickets = Ticket::whereIn('status', ['open', 'diajukan', 'baru', 'on_proses', 'in_progress', 'diproses'])
            ->whereHas('slaTracking', function ($q) {
                $q->whereNull('paused_at');
            })
            ->with(['slaTracking', 'subUnit.unit', 'unit.admins', 'assignedAdmin'])
            ->get();

        $escalated = 0;
        $halfWarned = 0;

        foreach ($activeTickets as $ticket) {
            try {
                $breachNotifications = [];
                $halfWarningNotifications = [];

                DB::transaction(function () use ($ticket, $slaCalculator, &$breachNotifications, &$halfWarningNotifications) {
                    $sla = $ticket->slaTracking()->lockForUpdate()->first();
                    if (!$sla) {
                        return;
                    }
                    
                    // Prevent N+1 query inside SlaCalculator by setting the already loaded ticket relation
                    $sla->setRelation('ticket', $ticket);

                    $wasResponseBreached = (bool) $sla->is_response_breached;
                    $wasResolutionBreached = (bool) $sla->is_resolution_breached;
                    $wasResponseHalfWarned = (bool) $sla->is_response_half_warned;
                    $wasResolutionHalfWarned = (bool) $sla->is_resolution_half_warned;

                    // Calculates SLA status and updates tracking model flags
                    $slaCalculator->checkAndUpdateTier($sla);

                    $sla->refresh();

                    $isResponseNewlyBreached = !$wasResponseBreached && $sla->is_response_breached;
                    $isResolutionNewlyBreached = !$wasResolutionBreached && $sla->is_resolution_breached;
                    $isResponseNewlyHalfWarned = !$wasResponseHalfWarned && $sla->is_response_half_warned && !$sla->is_response_breached;
                    $isResolutionNewlyHalfWarned = !$wasResolutionHalfWarned && $sla->is_resolution_half_warned && !$sla->is_resolution_breached;

                    // Kumpulkan admin penerima
                    $adminsToNotify = collect();
                    if ($ticket->unit && $ticket->unit->admins->isNotEmpty()) {
                        $adminsToNotify = $adminsToNotify->concat($ticket->unit->admins);
                    }
                    if ($ticket->assignedAdmin) {
                        $adminsToNotify->push($ticket->assignedAdmin);
                    }
                    try {
                        $superAdmins = Admin::role('Super Admin')->get();
                        $adminsToNotify = $adminsToNotify->concat($superAdmins);
                    } catch (\Exception $se) {
                        // ignore
                    }
                    if ($adminsToNotify->isEmpty()) {
                        $adminsToNotify = Admin::all();
                    }
                    $adminsToNotify = $adminsToNotify->unique('id');

                    // 1. Half Warnings (50% SLA)
                    if ($isResponseNewlyHalfWarned) {
                        Log::info("SLA 50% Warning (Response): Tiket #{$ticket->id} priority {$ticket->priority}");
                        foreach ($adminsToNotify as $admin) {
                            $halfWarningNotifications[] = [
                                'admin' => $admin,
                                'type' => 'respon',
                                'priority' => $ticket->priority ?? 'Sedang',
                            ];
                        }
                    }

                    if ($isResolutionNewlyHalfWarned) {
                        Log::info("SLA 50% Warning (Resolution): Tiket #{$ticket->id} priority {$ticket->priority}");
                        foreach ($adminsToNotify as $admin) {
                            $halfWarningNotifications[] = [
                                'admin' => $admin,
                                'type' => 'penyelesaian',
                                'priority' => $ticket->priority ?? 'Sedang',
                            ];
                        }
                    }

                    // 2. SLA Breaches (100% SLA)
                    if ($isResponseNewlyBreached) {
                        Log::info("SLA Breach (Response): Tiket #{$ticket->id} priority {$ticket->priority}");
                        foreach ($adminsToNotify as $admin) {
                            $breachNotifications[] = [
                                'admin' => $admin,
                                'type' => 'respon',
                                'priority' => $ticket->priority ?? 'Sedang',
                            ];
                        }
                    }

                    if ($isResolutionNewlyBreached) {
                        Log::info("SLA Breach (Resolution): Tiket #{$ticket->id} priority {$ticket->priority}");
                        foreach ($adminsToNotify as $admin) {
                            $breachNotifications[] = [
                                'admin' => $admin,
                                'type' => 'penyelesaian',
                                'priority' => $ticket->priority ?? 'Sedang',
                            ];
                        }
                    }
                });

                // Dispatch Half Warning Notifications & Chat Bot
                foreach ($halfWarningNotifications as $item) {
                    try {
                        $item['admin']->notify(new SlaHalfWarningNotification($ticket, $item['type'], $item['priority']));
                        $halfWarned++;
                    } catch (\Exception $ne) {
                        Log::warning("Gagal mengirim SlaHalfWarningNotification untuk admin #{$item['admin']->id}: " . $ne->getMessage());
                    }
                }

                if (!empty($halfWarningNotifications)) {
                    $type = $halfWarningNotifications[0]['type'];
                    if ($type === 'respon') {
                        $chatBody = "⏳ [PENGINGAT 50% SLA RESPON]\nTiket #{$ticket->formatted_id} (\"{$ticket->judul}\") masih berstatus Open dan telah mencapai 50% dari batas waktu respon SLA. Mohon segera ditugaskan atau diberikan tanggapan awal.";
                    } else {
                        $chatBody = "⏳ [PENGINGAT 50% SLA PENYELESAIAN]\nTiket #{$ticket->formatted_id} (\"{$ticket->judul}\") berstatus Sedang Diproses dan telah mencapai 50% dari batas waktu penyelesaian SLA. Mohon segera diproses hingga selesai.";
                    }
                    \App\Services\ChatReminderService::sendTicketReminder($ticket, $chatBody);
                }

                // Dispatch Breach Notifications & Chat Bot
                foreach ($breachNotifications as $item) {
                    try {
                        $item['admin']->notify(new SlaEscalationNotification($ticket, $item['type'], $item['priority']));
                        $escalated++;
                    } catch (\Exception $ne) {
                        Log::warning("Gagal mengirim SlaEscalationNotification untuk admin #{$item['admin']->id}: " . $ne->getMessage());
                    }
                }

                if (!empty($breachNotifications)) {
                    $typeLabel = $breachNotifications[0]['type'] === 'respon' ? 'Batas Waktu Respon' : 'Batas Waktu Penyelesaian';
                    $chatBody = "🚨 [PERINGATAN SLA BREACH]\nTiket #{$ticket->formatted_id} ({$typeLabel}) telah melewati batas waktu SLA. Mohon segera ditindaklanjuti.";
                    \App\Services\ChatReminderService::sendTicketReminder($ticket, $chatBody);
                }
            } catch (\Exception $e) {
                Log::error("Gagal memeriksa SLA untuk Tiket #{$ticket->id}: " . $e->getMessage());
            }
        }

        $this->info("Selesai. {$activeTickets->count()} tiket dicek, {$halfWarned} pengingat 50% SLA, {$escalated} eskalasi breach.");
        return Command::SUCCESS;
    }
}
