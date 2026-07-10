<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\SlaCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSlaCommand extends Command
{
    protected $signature = 'sla:check';
    protected $description = 'Cek status SLA semua tiket aktif dan update tier';

    public function handle(SlaCalculator $slaCalculator): int
    {
        $this->info('Memulai pengecekan SLA...');

        $activeTickets = Ticket::whereIn('status', ['open', 'on_proses'])
            ->whereHas('slaTracking', function ($q) {
                $q->whereNull('paused_at');
            })
            ->with(['slaTracking', 'subUnit.unit'])
            ->get();

        $escalated = 0;

        foreach ($activeTickets as $ticket) {
            $sla = $ticket->slaTracking;
            if (!$sla) continue;

            $previousTier = $sla->current_tier;
            $currentTier = $slaCalculator->checkAndUpdateTier($sla);

            if ($currentTier > $previousTier) {
                Log::info("SLA escalation: Tiket #{$ticket->id} naik ke Tier {$currentTier}");
                $escalated++;
            }
        }

        $this->info("Selesai. {$activeTickets->count()} tiket dicek, {$escalated} eskalasi.");
        return Command::SUCCESS;
    }
}
