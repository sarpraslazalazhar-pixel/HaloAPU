<?php

namespace App\Services;

use App\Models\SlaConfig;
use App\Models\SystemConfig;
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
        $config = SystemConfig::where('key', 'jam_kerja')->first();

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

        return json_decode($config->value, true);
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

    public function calculateResponseDeadline(Ticket $ticket): Carbon
    {
        $subUnitId = $ticket->sub_unit_id;
        $thresholdMinutes = SlaConfig::getThreshold($subUnitId, 3, 'respon');

        return $this->addWorkingMinutes($ticket->created_at, $thresholdMinutes);
    }

    public function calculateResolutionDeadline(Ticket $ticket): Carbon
    {
        $subUnitId = $ticket->sub_unit_id;
        $thresholdMinutes = SlaConfig::getThreshold($subUnitId, 3, 'penyelesaian');

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
        $now = Carbon::now();

        $effectiveNow = $sla->paused_at ?? $now;

        $elapsedMinutes = $this->getWorkingMinutesBetween(
            $ticket->created_at,
            $effectiveNow
        ) - $sla->total_paused_minutes;

        if (!$sla->responded_at) {
            $tier3Resp = SlaConfig::getThreshold($subUnitId, 3, 'respon');
            $tier2Resp = SlaConfig::getThreshold($subUnitId, 2, 'respon');
            $tier1Resp = SlaConfig::getThreshold($subUnitId, 1, 'respon');

            if ($elapsedMinutes >= $tier3Resp) {
                $sla->update([
                    'current_tier' => 3,
                    'is_response_breached' => true,
                ]);
                return 3;
            } elseif ($elapsedMinutes >= $tier2Resp) {
                $sla->update(['current_tier' => max($sla->current_tier, 2)]);
                return max($sla->current_tier, 2);
            } elseif ($elapsedMinutes >= $tier1Resp) {
                $sla->update(['current_tier' => max($sla->current_tier, 1)]);
                return max($sla->current_tier, 1);
            }
        }

        if (!$sla->resolved_at) {
            $tier3Res = SlaConfig::getThreshold($subUnitId, 3, 'penyelesaian');
            $tier2Res = SlaConfig::getThreshold($subUnitId, 2, 'penyelesaian');
            $tier1Res = SlaConfig::getThreshold($subUnitId, 1, 'penyelesaian');

            if ($elapsedMinutes >= $tier3Res) {
                $sla->update([
                    'current_tier' => 3,
                    'is_resolution_breached' => true,
                ]);
                return 3;
            } elseif ($elapsedMinutes >= $tier2Res) {
                $sla->update(['current_tier' => max($sla->current_tier, 2)]);
                return max($sla->current_tier, 2);
            } elseif ($elapsedMinutes >= $tier1Res) {
                $sla->update(['current_tier' => max($sla->current_tier, 1)]);
                return max($sla->current_tier, 1);
            }
        }

        return $sla->current_tier;
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

            $minutesToday = $current->diffInMinutes($dayEnd);
            $totalMinutes += $minutesToday;

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
