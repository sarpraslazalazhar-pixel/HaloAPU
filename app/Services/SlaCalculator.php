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
    private const PHASES = [
        'response' => ['respon', 'responded_at'],
        'resolution' => ['penyelesaian', 'resolved_at'],
    ];

    private const ACTIVE_STATUSES = ['open', 'diajukan', 'baru', 'on_proses', 'in_progress', 'diproses', 'pending', 'need_revision'];

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
            return $this->normalizeWorkingHours($this->defaultWorkingHours());
        }

        $decoded = json_decode($config->value, true);

        // Fallback jika JSON rusak/invalid
        if (!is_array($decoded)) {
            Log::warning('Konfigurasi jam_kerja memiliki JSON yang tidak valid, menggunakan default.');
            return $this->normalizeWorkingHours($this->defaultWorkingHours());
        }

        return $this->normalizeWorkingHours($decoded);
    }

    protected function defaultWorkingHours(): array
    {
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

    /** Pasangan jam tidak valid atau start >= end diperlakukan sebagai hari tutup. */
    protected function normalizeWorkingHours(array $hours): array
    {
        $normalized = [];
        foreach ($this->dayMap as $dayName) {
            $normalized[$dayName] = $this->validPair($hours[$dayName] ?? null);
        }

        return $normalized;
    }

    private function validPair(mixed $pair): ?array
    {
        if (!is_array($pair) || count($pair) < 2) {
            return null;
        }

        [$start, $end] = array_values($pair);
        if (!is_string($start) || !is_string($end)
            || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $start)
            || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $end)) {
            return null;
        }

        try {
            $startAt = Carbon::createFromTimeString($start);
            $endAt = Carbon::createFromTimeString($end);
        } catch (\Throwable) {
            return null;
        }

        return $startAt->lt($endAt) ? [$start, $end] : null;
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

        return $date->gte($start) && $date->lt($end);
    }

    /**
     * @return array{is_open: bool, closed_since: ?Carbon, next_open_at: ?Carbon}
     */
    public function workingHoursState(Carbon $at): array
    {
        $at = $at->copy()->setTimezone(config('app.timezone'));

        if ($this->isWithinWorkingHours($at)) {
            return ['is_open' => true, 'closed_since' => null, 'next_open_at' => null];
        }

        $closedSince = null;
        for ($i = 0; $i <= 7; $i++) {
            $end = $this->getWorkEnd($at->copy()->subDays($i)->startOfDay());
            if ($end && $end->lte($at)) {
                $closedSince = $end;
                break;
            }
        }

        $nextOpen = null;
        for ($i = 0; $i <= 7; $i++) {
            $start = $this->getWorkStart($at->copy()->addDays($i)->startOfDay());
            if ($start && $start->gt($at)) {
                $nextOpen = $start;
                break;
            }
        }

        return ['is_open' => false, 'closed_since' => $closedSince, 'next_open_at' => $nextOpen];
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

    /** @param array{start: Carbon, end: Carbon}|null $window */
    public function createTracking(Ticket $ticket, ?array $window): TicketSlaTracking
    {
        $pending = $ticket->status === 'pending';

        return $ticket->slaTracking()->create([
            'sla_response_deadline' => $pending ? null : $this->calculateResponseDeadline($ticket),
            'sla_resolution_starts_at' => $window['start'] ?? null,
            'sla_resolution_base_deadline' => $window['end'] ?? null,
            'sla_resolution_deadline' => $window['end'] ?? ($pending ? null : $this->calculateResolutionDeadline($ticket)),
            'paused_at' => $pending ? now() : null,
            'current_tier' => 0,
        ]);
    }

    public function updatePriorityDeadlines(TicketSlaTracking $sla): void
    {
        $ticket = $sla->ticket;
        if (! in_array($ticket->status, self::ACTIVE_STATUSES, true)) {
            return;
        }

        $updates = [];
        foreach (self::PHASES as $phase => [$kind, $finishedAt]) {
            $key = "sla_{$phase}_deadline";
            if ($sla->$finishedAt !== null || $sla->$key === null
                || ($phase === 'resolution' && $sla->sla_resolution_base_deadline !== null)) {
                continue;
            }

            $updates[$key] = $this->deadlineWithPauseCredit($sla, $kind);
        }

        if ($updates !== []) {
            $sla->update($updates);
        }
    }

    /** @param array{start: Carbon, end: Carbon}|null $window */
    public function updateScheduledResolution(TicketSlaTracking $sla, ?array $window): void
    {
        $baseEnd = $sla->sla_resolution_base_deadline;
        if ($sla->resolved_at !== null || ($window === null && $baseEnd === null)) {
            return;
        }

        if ($window !== null) {
            $oldDeadline = $sla->sla_resolution_deadline;
            $seconds = $baseEnd && $oldDeadline ? max(0, $oldDeadline->timestamp - $baseEnd->timestamp) : 0;
            $deadline = $window['end']->copy()->setTimestamp($window['end']->timestamp + $seconds);
        } else {
            $initialPending = $sla->ticket->status === 'pending'
                && $sla->sla_response_deadline === null && $sla->responded_at === null;
            $deadline = $initialPending ? null : $this->deadlineWithPauseCredit($sla, 'penyelesaian');
        }

        $sla->update([
            'sla_resolution_starts_at' => $window['start'] ?? null,
            'sla_resolution_base_deadline' => $window['end'] ?? null,
            'sla_resolution_deadline' => $deadline,
        ]);
    }

    private function deadlineWithPauseCredit(TicketSlaTracking $sla, string $kind): Carbon
    {
        $ticket = $sla->ticket;
        $threshold = SlaConfig::getThreshold($ticket->sub_unit_id, $ticket->priority ?? 'Sedang', $kind);

        return $this->addWorkingMinutes($ticket->created_at, $threshold + $sla->total_paused_minutes);
    }

    public function pauseSla(TicketSlaTracking $sla, ?Carbon $at = null): void
    {
        if ($sla->paused_at !== null) {
            Log::warning("SLA #{$sla->id} sudah dalam status paused.");
            return;
        }

        $sla->update([
            'paused_at' => ($at ?? Carbon::now())->copy()->microsecond(0),
        ]);

        Log::info("SLA #{$sla->id} di-pause pada {$sla->paused_at}");
    }

    public function resumeSla(TicketSlaTracking $sla, ?Carbon $at = null, bool $preserveScheduledResolution = false): void
    {
        $resumeAt = ($at ?? Carbon::now())->copy()->microsecond(0);
        $pausedAt = $sla->paused_at;
        if ($pausedAt === null) {
            if ($sla->ticket->status !== 'pending') {
                Log::warning("SLA #{$sla->id} tidak dalam status paused.");
                return;
            }
            $pausedAt = $resumeAt;
        }

        $pausedMinutes = $this->getWorkingMinutesBetween($pausedAt, $resumeAt);
        $updates = [
            'paused_at' => null,
            'total_paused_minutes' => $sla->total_paused_minutes + $pausedMinutes,
        ];

        foreach (self::PHASES as $phase => [$kind, $finishedAt]) {
            if ($sla->$finishedAt !== null) {
                continue;
            }

            $key = "sla_{$phase}_deadline";
            $deadline = $sla->$key;
            if ($phase === 'resolution' && $sla->sla_resolution_starts_at && $sla->sla_resolution_base_deadline) {
                if ($preserveScheduledResolution && $deadline !== null) {
                    continue;
                }

                $deadline ??= $sla->sla_resolution_base_deadline;
                $seconds = max(0, $resumeAt->timestamp - max($pausedAt->timestamp, $sla->sla_resolution_starts_at->timestamp));
                $updates[$key] = $deadline->copy()->setTimestamp($deadline->timestamp + $seconds);
            } elseif ($deadline !== null) {
                $updates[$key] = $this->addWorkingMinutes($deadline, $pausedMinutes);
            } else {
                $ticket = $sla->ticket;
                $threshold = SlaConfig::getThreshold($ticket->sub_unit_id, $ticket->priority ?? 'Sedang', $kind);
                $updates[$key] = $this->addWorkingMinutes($resumeAt, $threshold);
            }
        }

        $sla->update($updates);
        Log::info("SLA #{$sla->id} di-resume. Paused {$pausedMinutes} menit kerja. Total paused: {$sla->total_paused_minutes} menit.");
    }

    public function checkAndUpdateTier(TicketSlaTracking $sla): int
    {
        $tier = $sla->current_tier ?? 0;
        if ($sla->paused_at !== null || $sla->ticket->status === 'pending') {
            return $tier;
        }

        $ticket = $sla->ticket;
        $now = Carbon::now()->microsecond(0);
        $updates = [];

        foreach (self::PHASES as $phase => [$kind, $finishedAt]) {
            $deadline = $sla->{"sla_{$phase}_deadline"};
            if ($deadline === null || $sla->$finishedAt !== null) {
                continue;
            }

            $start = $phase === 'resolution' ? $sla->sla_resolution_starts_at : null;
            $baseEnd = $start ? $sla->sla_resolution_base_deadline : null;
            if ($baseEnd && $now->timestamp < $start->timestamp) {
                continue;
            }

            $breach = "is_{$phase}_breached";
            if (! $sla->$breach && $now->timestamp > $deadline->timestamp) {
                $updates[$breach] = true;
            }

            $halfWarning = "is_{$phase}_half_warned";
            if (! $sla->$halfWarning) {
                if ($baseEnd) {
                    $halfReached = $now->timestamp >= $deadline->timestamp - intdiv($baseEnd->timestamp - $start->timestamp, 2);
                } else {
                    $threshold = SlaConfig::getThreshold($ticket->sub_unit_id, $ticket->priority ?? 'Sedang', $kind);
                    $halfReached = $this->getWorkingMinutesBetween($now, $deadline) <= intdiv($threshold, 2);
                }
                if ($halfReached) {
                    $updates[$halfWarning] = true;
                }
            }
        }

        if ($updates !== []) {
            $sla->update($updates);
        }

        return $tier;
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
