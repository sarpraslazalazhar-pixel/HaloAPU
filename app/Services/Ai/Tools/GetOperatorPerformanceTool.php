<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Services\Ai\AiAccessScope;
use App\Services\OperatorPerformanceReportService;
use App\Services\TicketWorkQueue;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetOperatorPerformanceTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
        private readonly OperatorPerformanceReportService $reportService,
    ) {}

    public function name(): string
    {
        return 'get_operator_performance';
    }

    public function description(): string
    {
        return 'Mengambil laporan beban kerja, jumlah tiket aktif/selesai, kepatuhan SLA, dan rating CSAT petugas/operator sesuai cakupan akses admin.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'period' => [
                    'type' => 'STRING',
                    'description' => 'Preset periode: today, 7d, 30d, this_month, last_month, this_year, atau all. Default 30d.',
                ],
                'date_from' => [
                    'type' => 'STRING',
                    'description' => 'Tanggal mulai format YYYY-MM-DD (opsional).',
                ],
                'date_to' => [
                    'type' => 'STRING',
                    'description' => 'Tanggal akhir format YYYY-MM-DD (opsional).',
                ],
                'unit_id' => [
                    'type' => 'INTEGER',
                    'description' => 'Filter ID unit layanan tertentu (opsional, khusus Superadmin/Koordinator unitnya).',
                ],
                'limit' => [
                    'type' => 'INTEGER',
                    'description' => 'Maksimal petugas ditampilkan (default 10, maks 20).',
                ],
            ],
        ];
    }

    public function execute(Admin $admin, array $args): AiToolResult
    {
        $periodInfo = PeriodResolver::resolve(
            isset($args['period']) ? (string) $args['period'] : null,
            isset($args['date_from']) ? (string) $args['date_from'] : null,
            isset($args['date_to']) ? (string) $args['date_to'] : null,
            '30d'
        );

        $limit = min(20, max(1, (int) ($args['limit'] ?? 10)));
        $requestedUnitId = isset($args['unit_id']) && $args['unit_id'] !== '' ? (int) $args['unit_id'] : null;
        $unitCheck = $this->scope->filterUnitId($admin, $requestedUnitId);

        // Always pass explicit date_from & date_to so OperatorPerformanceReportService uses portable whereBetween.
        $dateFrom = $periodInfo['date_from'] ?? '2000-01-01';
        $dateTo = $periodInfo['date_to'] ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');

        $isSuperAdmin = $this->scope->isSuperAdmin($admin);
        $isCoordinator = $admin->isCoordinator();
        $scopeNotes = [];

        if ($unitCheck['denied'] && $unitCheck['note'] !== null) {
            $scopeNotes[] = $unitCheck['note'];
        }

        if ($isSuperAdmin) {
            $report = $this->reportService->buildReport([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'unit_id' => $unitCheck['unit_id'],
            ]);
            $operators = $report['operators'];
        } elseif ($isCoordinator) {
            $allowedUnitIds = $this->scope->allowedUnitIds($admin) ?? [];

            if (empty($allowedUnitIds)) {
                $report = $this->reportService->buildReport([
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ]);
                $operators = $report['operators']
                    ->filter(fn (array $op) => (int) $op['id'] === (int) $admin->id)
                    ->values();
                $scopeNotes[] = 'Akun koordinator belum terikat ke unit mana pun; hanya menampilkan data milikmu sendiri.';
            } else {
                $targetUnitIds = $unitCheck['unit_id'] !== null
                    ? [$unitCheck['unit_id']]
                    : $allowedUnitIds;

                $operators = $this->collectOperatorsForUnits($targetUnitIds, $dateFrom, $dateTo);
                $scopeNotes[] = 'Data petugas dibatasi pada unit yang kamu koordinasikan.';
            }
        } else {
            $report = $this->reportService->buildReport([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);
            $operators = $report['operators']
                ->filter(fn (array $op) => (int) $op['id'] === (int) $admin->id)
                ->values();
            $scopeNotes[] = 'Sesuai kebijakan akses peran Operator, kamu hanya dapat melihat data performa milikmu sendiri.';
        }

        $sliced = $operators->take($limit)->values();

        $enriched = $sliced->map(function (array $op): array {
            $queue = TicketWorkQueue::payload((int) $op['id']);

            return [
                'id' => (int) $op['id'],
                'name' => (string) $op['name'],
                'username' => (string) $op['username'],
                'units' => $op['units'],
                'total_tickets' => (int) $op['total_tickets'],
                'active_tickets' => (int) $op['active_tickets'],
                'solved_tickets' => (int) $op['solved_tickets'],
                'rejected_tickets' => (int) $op['rejected_tickets'],
                'resolution_breaches' => (int) $op['resolution_breaches'],
                'response_breaches' => (int) $op['response_breaches'],
                'resolution_compliance' => $op['resolution_compliance'],
                'avg_rating' => $op['avg_rating'],
                'total_reviews' => (int) $op['total_reviews'],
                'queue_status' => [
                    'active_ticket' => $queue['active'],
                    'queued_count' => count($queue['queue']),
                    'paused_count' => count($queue['paused']),
                    'work_hours_open' => $queue['work_hours_open'],
                ],
            ];
        })->all();

        $chart = null;
        if (! empty($enriched)) {
            $chart = [
                'id' => 'operator_performance',
                'type' => 'bar',
                'title' => 'Performa Penyelesaian Petugas',
                'subtitle' => $periodInfo['label'],
                'labels' => array_map(static fn (array $op) => $op['name'], $enriched),
                'series' => [
                    [
                        'name' => 'Selesai',
                        'data' => array_map(static fn (array $op) => (int) $op['solved_tickets'], $enriched),
                        'color' => '#10b981',
                    ],
                    [
                        'name' => 'Aktif',
                        'data' => array_map(static fn (array $op) => (int) $op['active_tickets'], $enriched),
                        'color' => '#f59e0b',
                    ],
                ],
                'unit' => 'tiket',
            ];
        }

        $summary = [
            'total_operators' => $operators->count(),
            'total_tickets' => (int) $operators->sum('total_tickets'),
            'total_active' => (int) $operators->sum('active_tickets'),
            'total_solved' => (int) $operators->sum('solved_tickets'),
            'total_breaches' => (int) $operators->sum('resolution_breaches') + (int) $operators->sum('response_breaches'),
        ];

        $data = [
            'period' => $periodInfo['key'],
            'period_label' => $periodInfo['label'],
            'summary' => $summary,
            'operators' => $enriched,
        ];

        if (! empty($scopeNotes)) {
            $data['scope_note'] = implode(' ', array_unique($scopeNotes));
        }

        return new AiToolResult($data, $chart);
    }

    /**
     * Build scoped operator stats restricted strictly to the coordinator's allowed units.
     *
     * @param  array<int, int>  $unitIds
     * @return Collection<int, array<string, mixed>>
     */
    private function collectOperatorsForUnits(array $unitIds, string $dateFrom, string $dateTo): Collection
    {
        $allowedAdminIds = Admin::query()
            ->where(function ($q) use ($unitIds): void {
                $q->whereHas('units', fn ($u) => $u->whereIn('units.id', $unitIds))
                    ->orWhereHas('subUnits', fn ($su) => $su->whereIn('sub_units.unit_id', $unitIds));
            })
            ->pluck('admins.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $merged = [];
        foreach ($unitIds as $unitId) {
            $report = $this->reportService->buildReport([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'unit_id' => $unitId,
            ]);

            foreach ($report['operators'] as $op) {
                $opId = (int) $op['id'];
                // Exclude operators who belong only to another unit unless they actually handled a ticket in this unit.
                if (! in_array($opId, $allowedAdminIds, true) && (int) $op['total_tickets'] === 0) {
                    continue;
                }

                if (! isset($merged[$opId])) {
                    $merged[$opId] = $op;
                    $merged[$opId]['_rating_sum'] = ($op['avg_rating'] !== null && $op['total_reviews'] > 0)
                        ? ((float) $op['avg_rating'] * (int) $op['total_reviews'])
                        : 0.0;
                    continue;
                }

                $merged[$opId]['total_tickets'] += (int) $op['total_tickets'];
                $merged[$opId]['active_tickets'] += (int) $op['active_tickets'];
                $merged[$opId]['solved_tickets'] += (int) $op['solved_tickets'];
                $merged[$opId]['rejected_tickets'] += (int) $op['rejected_tickets'];
                $merged[$opId]['open_tickets'] += (int) $op['open_tickets'];
                $merged[$opId]['total_resolved'] += (int) $op['total_resolved'];
                $merged[$opId]['resolution_breaches'] += (int) $op['resolution_breaches'];
                $merged[$opId]['response_breaches'] += (int) $op['response_breaches'];
                $merged[$opId]['total_breaches'] += (int) $op['total_breaches'];
                $merged[$opId]['total_reviews'] += (int) $op['total_reviews'];
                $merged[$opId]['_rating_sum'] += ($op['avg_rating'] !== null && $op['total_reviews'] > 0)
                    ? ((float) $op['avg_rating'] * (int) $op['total_reviews'])
                    : 0.0;
            }
        }

        return collect($merged)
            ->map(function (array $op): array {
                $resolved = (int) $op['total_resolved'];
                $resBreach = (int) $op['resolution_breaches'];
                $op['resolution_compliance'] = $resolved > 0
                    ? round((max(0, $resolved - $resBreach) / $resolved) * 100, 1)
                    : null;

                $reviews = (int) $op['total_reviews'];
                $op['avg_rating'] = $reviews > 0
                    ? round(((float) $op['_rating_sum']) / $reviews, 2)
                    : null;

                unset($op['_rating_sum']);

                return $op;
            })
            ->sort(function (array $a, array $b): int {
                return ($b['active_tickets'] <=> $a['active_tickets'])
                    ?: ($b['solved_tickets'] <=> $a['solved_tickets'])
                    ?: ($b['total_tickets'] <=> $a['total_tickets'])
                    ?: strcasecmp((string) $a['name'], (string) $b['name']);
            })
            ->values();
    }
}
