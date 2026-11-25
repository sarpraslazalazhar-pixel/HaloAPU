<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Services\Ai\AiAccessScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ComparePeriodsTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'compare_periods';
    }

    public function description(): string
    {
        return 'Membandingkan metrik tiket (volume tiket, tiket selesai, pelanggaran SLA, dan rata-rata CSAT) antara dua periode: bulan ini vs bulan lalu atau minggu ini vs minggu lalu.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'comparison' => [
                    'type' => 'STRING',
                    'description' => 'Jenis perbandingan: this_month_vs_last_month atau this_week_vs_last_week. Default this_month_vs_last_month.',
                ],
                'unit_id' => [
                    'type' => 'INTEGER',
                    'description' => 'Filter ID unit layanan tertentu (opsional).',
                ],
            ],
        ];
    }

    public function execute(Admin $admin, array $args): AiToolResult
    {
        $comparison = strtolower(trim((string) ($args['comparison'] ?? 'this_month_vs_last_month')));
        if (! in_array($comparison, ['this_month_vs_last_month', 'this_week_vs_last_week'], true)) {
            $comparison = 'this_month_vs_last_month';
        }

        $requestedUnitId = isset($args['unit_id']) && $args['unit_id'] !== '' ? (int) $args['unit_id'] : null;
        $unitCheck = $this->scope->filterUnitId($admin, $requestedUnitId);

        $tz = 'Asia/Jakarta';
        $now = Carbon::now($tz);

        if ($comparison === 'this_week_vs_last_week') {
            $currentStart = $now->copy()->startOfWeek()->startOfDay();
            $currentEnd = $now->copy()->endOfWeek()->endOfDay();
            $prevStart = $now->copy()->subWeek()->startOfWeek()->startOfDay();
            $prevEnd = $now->copy()->subWeek()->endOfWeek()->endOfDay();
            $currentLabel = 'Minggu Ini';
            $previousLabel = 'Minggu Lalu';
        } else {
            $prevMonth = $now->copy()->subMonthNoOverflow();
            $currentStart = $now->copy()->startOfMonth()->startOfDay();
            $currentEnd = $now->copy()->endOfMonth()->endOfDay();
            $prevStart = $prevMonth->copy()->startOfMonth()->startOfDay();
            $prevEnd = $prevMonth->copy()->endOfMonth()->endOfDay();
            $currentLabel = 'Bulan Ini (' . $now->copy()->locale('id')->translatedFormat('M Y') . ')';
            $previousLabel = 'Bulan Lalu (' . $prevMonth->copy()->locale('id')->translatedFormat('M Y') . ')';
        }

        $currentStats = $this->collectMetricsForRange($admin, $currentStart, $currentEnd, $unitCheck['unit_id']);
        $previousStats = $this->collectMetricsForRange($admin, $prevStart, $prevEnd, $unitCheck['unit_id']);

        $deltas = [
            'total_tickets' => [
                'diff' => $currentStats['total_tickets'] - $previousStats['total_tickets'],
                'delta_pct' => $this->deltaPercent($currentStats['total_tickets'], $previousStats['total_tickets']),
            ],
            'solved_tickets' => [
                'diff' => $currentStats['solved_tickets'] - $previousStats['solved_tickets'],
                'delta_pct' => $this->deltaPercent($currentStats['solved_tickets'], $previousStats['solved_tickets']),
            ],
            'sla_breaches' => [
                'diff' => $currentStats['sla_breaches'] - $previousStats['sla_breaches'],
                'delta_pct' => $this->deltaPercent($currentStats['sla_breaches'], $previousStats['sla_breaches']),
            ],
            'csat_avg' => [
                'diff' => round($currentStats['csat_avg'] - $previousStats['csat_avg'], 2),
                'delta_pct' => $this->deltaPercent($currentStats['csat_avg'], $previousStats['csat_avg']),
            ],
        ];

        $chart = [
            'id' => 'period_comparison',
            'type' => 'bar',
            'title' => 'Perbandingan Periode: ' . $currentLabel . ' vs ' . $previousLabel,
            'subtitle' => $comparison === 'this_week_vs_last_week' ? 'Mingguan' : 'Bulanan',
            'labels' => ['Total Tiket', 'Tiket Selesai', 'Pelanggaran SLA'],
            'series' => [
                [
                    'name' => $previousLabel,
                    'data' => [
                        (int) $previousStats['total_tickets'],
                        (int) $previousStats['solved_tickets'],
                        (int) $previousStats['sla_breaches'],
                    ],
                    'color' => '#94a3b8',
                ],
                [
                    'name' => $currentLabel,
                    'data' => [
                        (int) $currentStats['total_tickets'],
                        (int) $currentStats['solved_tickets'],
                        (int) $currentStats['sla_breaches'],
                    ],
                    'color' => '#0284c7',
                ],
            ],
            'unit' => 'tiket',
        ];

        $data = [
            'comparison' => $comparison,
            'current_period' => array_merge(['label' => $currentLabel, 'date_from' => $currentStart->format('Y-m-d'), 'date_to' => $currentEnd->format('Y-m-d')], $currentStats),
            'previous_period' => array_merge(['label' => $previousLabel, 'date_from' => $prevStart->format('Y-m-d'), 'date_to' => $prevEnd->format('Y-m-d')], $previousStats),
            'deltas' => $deltas,
        ];

        if ($unitCheck['denied'] && $unitCheck['note'] !== null) {
            $data['scope_note'] = $unitCheck['note'];
        }

        return new AiToolResult($data, $chart);
    }

    /**
     * @return array{total_tickets: int, solved_tickets: int, sla_breaches: int, csat_avg: float, csat_count: int}
     */
    private function collectMetricsForRange(Admin $admin, Carbon $start, Carbon $end, ?int $unitId): array
    {
        $appTz = (string) config('app.timezone', 'Asia/Jakarta');
        $rangeStart = $start->copy()->timezone($appTz);
        $rangeEnd = $end->copy()->timezone($appTz);

        $ticketQuery = $this->scope->tickets($admin)
            ->whereBetween('tickets.created_at', [$rangeStart, $rangeEnd]);

        if ($unitId !== null) {
            $this->scope->applyUnitFilterToTickets($ticketQuery, $unitId);
        }

        $totalTickets = (clone $ticketQuery)->count();
        $solvedTickets = (clone $ticketQuery)
            ->whereIn(DB::raw('LOWER(tickets.status)'), ['solve', 'selesai'])
            ->count();

        $slaBreaches = $this->scope->slaTrackings($admin)
            ->whereHas('ticket', function (Builder $tq) use ($rangeStart, $rangeEnd, $unitId): void {
                $tq->whereBetween('tickets.created_at', [$rangeStart, $rangeEnd]);
                if ($unitId !== null) {
                    $tq->where(function (Builder $uq) use ($unitId): void {
                        $uq->where('tickets.unit_id', $unitId)
                            ->orWhereHas('subUnit', fn (Builder $sq) => $sq->where('sub_units.unit_id', $unitId));
                    });
                }
            })
            ->where(function (Builder $q): void {
                $q->where('is_response_breached', true)
                    ->orWhere('is_resolution_breached', true);
            })
            ->count();

        $csatQuery = $this->scope->csats($admin)
            ->whereBetween('csats.created_at', [$rangeStart, $rangeEnd]);

        if ($unitId !== null) {
            $csatQuery->whereHas('ticket', function (Builder $tq) use ($unitId): void {
                $tq->where('tickets.unit_id', $unitId)
                    ->orWhereHas('subUnit', fn (Builder $sq) => $sq->where('sub_units.unit_id', $unitId));
            });
        }

        $csatStats = $csatQuery
            ->selectRaw('COUNT(*) as total, COALESCE(AVG(rating), 0) as avg_rating')
            ->first();

        $csatCount = (int) ($csatStats->total ?? 0);
        $csatAvg = $csatCount > 0 ? round((float) $csatStats->avg_rating, 2) : 0.0;

        return [
            'total_tickets' => $totalTickets,
            'solved_tickets' => $solvedTickets,
            'sla_breaches' => $slaBreaches,
            'csat_avg' => $csatAvg,
            'csat_count' => $csatCount,
        ];
    }

    private function deltaPercent(float|int $current, float|int $previous): float
    {
        if ((float) $previous <= 0.0) {
            return (float) $current > 0.0 ? 100.0 : 0.0;
        }

        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
    }
}
