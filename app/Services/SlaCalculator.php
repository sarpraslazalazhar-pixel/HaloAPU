<?php

namespace App\Services;

use App\Models\SlaConfig;
// use App\Models\SystemConfig; // Available in Phase 5
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SlaCalculator
{
    protected array $workingHours;

    protected array $dayMap = [
        0 => 'minggu',
        1 => 'senin',
        2 => 'selasa',
        3 => 'rabu',
        4 => 'kamis',
        5 => 'jumat',
        6 => 'sabtu',
    ];

    public function __construct()
    {
        $this->workingHours = $this->loadWorkingHours();
    }

    protected function loadWorkingHours(): array
    {
        $config = null;
        if (class_exists('App\Models\SystemConfig')) {
            $config = \App\Models\SystemConfig::where('key', 'jam_kerja')->first();
        }

        if (!$config) {
            return [
                'senin'  => ['08:00', '16:00'],
                'selasa' => ['08:00', '16:00'],
                'rabu'   => ['08:00', '16:00'],
                'kamis'  => ['08:00', '16:00'],
                'jumat'  => ['08:00', '16:00'],
                'sabtu'  => null,
                'minggu' => null,
            ];
        }

        $decoded = json_decode($config->value, true);

        // Fallback jika JSON rusak/invalid
        if (!is_array($decoded)) {
            Log::warning('Konfigurasi jam_kerja memiliki JSON yang tidak valid, menggunakan default.');
            return [
                'senin'  => ['08:00', '16:00'],
                'selasa' => ['08:00', '16:00'],
                'rabu'   => ['08:00', '16:00'],
                'kamis'  => ['08:00', '16:00'],
                'jumat'  => ['08:00', '16:00'],
                'sabtu'  => null,
                'minggu' => null,
            ];
        }

        return $decoded;
    }

    protected function isWorkingDay(Carbon $date): bool
    {
        $dayName = $this->dayMap[$date->dayOfWeek];
        return !empty($this->workingHours[$dayName]);
    }

    protected function getWorkStart(Carbon $date): ?Carbon
    {
        $dayName = $this->dayMap[$date->dayOfWeek];
        $hours = $this->workingHours[$dayName] ?? null;

        if (!$hours) return null;

        return $date->copy()->setTimeFromTimeString($hours[0]);
    }

    protected function getWorkEnd(Carbon $date): ?Carbon
    {
        $dayName = $this->dayMap[$date->dayOfWeek];
        $hours = $this->workingHours[$dayName] ?? null;

        if (!$hours) return null;

        return $date->copy()->setTimeFromTimeString($hours[1]);
    }

    public function isWithinWorkingHours(Carbon $date): bool
    {
        if (!$this->isWorkingDay($date)) {
            return false;
        }

        $start = $this->getWorkStart($date);
        $end = $this->getWorkEnd($date);

        if (!$start || !$end) {
            return false;
        }

        return $date->between($start, $end);
    }

    public function calculateResponseDeadline(Ticket $ticket): Carbon
    {
        $subUnitId = $ticket->sub_unit_id;
        $priority = $ticket->priority ?? 'Sedang'; // Default to Sedang if not set
        $thresholdMinutes = SlaConfig::getThreshold($subUnitId, $priority, 'respon');

        return $this->addWorkingMinutes($ticket->created_at, $thresholdMinutes);
    }

    public function calculateResolutionDeadline(Ticket $ticket): Carbon
    {
        $subUnitId = $ticket->sub_unit_id;
        $priority = $ticket->priority ?? 'Sedang';
        $thresholdMinutes = SlaConfig::getThreshold($subUnitId, $priority, 'penyelesaian');

        return $this->addWorkingMinutes($ticket->created_at, $thresholdMinutes);
    }

    public function pauseSla(TicketSlaTracking $sla): void
    {
        if ($sla->paused_at !== null) {
            Log::warning("SLA #{$sla->id} sudah dalam status paused.");
            return;
        }

        $sla->update([
            'paused_at' => Carbon::now(),
        ]);

        Log::info("SLA #{$sla->id} di-pause pada {$sla->paused_at}");
    }

    public function resumeSla(TicketSlaTracking $sla): void
    {
        if ($sla->paused_at === null) {
            Log::warning("SLA #{$sla->id} tidak dalam status paused.");
            return;
        }

        $pausedMinutes = $this->getWorkingMinutesBetween($sla->paused_at, Carbon::now());
        $newTotalPaused = $sla->total_paused_minutes + $pausedMinutes;

        $newResponseDeadline = $this->addWorkingMinutes(
            $sla->sla_response_deadline,
            $pausedMinutes
        );
        $newResolutionDeadline = $this->addWorkingMinutes(
            $sla->sla_resolution_deadline,
            $pausedMinutes
        );

        $sla->update([
            'paused_at' => null,
            'total_paused_minutes' => $newTotalPaused,
            'sla_response_deadline' => $newResponseDeadline,
            'sla_resolution_deadline' => $newResolutionDeadline,
        ]);

        Log::info("SLA #{$sla->id} di-resume. Paused {$pausedMinutes} menit kerja. Total paused: {$newTotalPaused} menit.");
    }

    public function checkAndUpdateTier(TicketSlaTracking $sla): int
    {
        $ticket = $sla->ticket;
        $subUnitId = $ticket->sub_unit_id;
        $priority = $ticket->priority ?? 'Sedang';
        $now = Carbon::now();

        $effectiveNow = $sla->paused_at ?? $now;

        $elapsedMinutes = $this->getWorkingMinutesBetween(
            $ticket->created_at,
            $effectiveNow
        ) - $sla->total_paused_minutes;

        if (!$sla->responded_at) {
            $respThreshold = SlaConfig::getThreshold($subUnitId, $priority, 'respon');

            if ($elapsedMinutes >= $respThreshold) {
                $sla->update([
                    'is_response_breached' => true,
                ]);
            }
        }

        if (!$sla->resolved_at) {
            $resThreshold = SlaConfig::getThreshold($subUnitId, $priority, 'penyelesaian');

            if ($elapsedMinutes >= $resThreshold) {
                $sla->update([
                    'is_resolution_breached' => true,
                ]);
            }
        }

        // Return current tier for backward compatibility in calling functions, though it's no longer used for escalation.
        return $sla->current_tier ?? 0;
    }

    public function getWorkingMinutesBetween(Carbon $start, Carbon $end): int
    {
        if ($start->gte($end)) {
            return 0;
        }

        $totalMinutes = 0;
        $current = $start->copy();

        while ($current->lt($end)) {
            if (!$this->isWorkingDay($current)) {
                $current->addDay()->startOfDay();
                continue;
            }

            $workStart = $this->getWorkStart($current);
            $workEnd = $this->getWorkEnd($current);

            if ($current->lt($workStart)) {
                $current = $workStart->copy();
            }

            if ($current->gte($workEnd)) {
                $current->addDay()->startOfDay();
                continue;
            }

            $dayEnd = $workEnd->copy();
            if ($end->lt($dayEnd)) {
                $dayEnd = $end->copy();
            }

            if ($current->lt($dayEnd)) {
                $minutesToday = $current->diffInMinutes($dayEnd);
                $totalMinutes += $minutesToday;
            }

            $current = $workEnd->copy()->addDay()->startOfDay();
        }

        return $totalMinutes;
    }

    public function addWorkingMinutes(Carbon $start, int $minutes): Carbon
    {
        $remaining = $minutes;
        $current = $start->copy();

        while ($remaining > 0) {
            if (!$this->isWorkingDay($current)) {
                $current->addDay()->startOfDay();
                continue;
            }

            $workStart = $this->getWorkStart($current);
            $workEnd = $this->getWorkEnd($current);

            if ($current->lt($workStart)) {
                $current = $workStart->copy();
            }

            if ($current->gte($workEnd)) {
                $current->addDay()->startOfDay();
                continue;
            }

            $availableMinutes = $current->diffInMinutes($workEnd);

            if ($remaining <= $availableMinutes) {
                $current->addMinutes($remaining);
                $remaining = 0;
            } else {
                $remaining -= $availableMinutes;
                $current = $workEnd->copy()->addDay()->startOfDay();
            }
        }

        return $current;
    }
}
