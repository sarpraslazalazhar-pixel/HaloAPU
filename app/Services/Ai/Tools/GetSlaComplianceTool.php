<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Services\Ai\AiAccessScope;
use Illuminate\Support\Facades\DB;

class GetSlaComplianceTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'get_sla_compliance';
    }

    public function description(): string
    {
        return 'Menghitung tingkat kepatuhan SLA respon dan SLA resolusi secara keseluruhan dan per unit layanan.';
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
                    'description' => 'Filter ID unit layanan tertentu (opsional).',
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

        $requestedUnitId = isset($args['unit_id']) && $args['unit_id'] !== '' ? (int) $args['unit_id'] : null;
        $unitCheck = $this->scope->filterUnitId($admin, $requestedUnitId);

        $scopedTicketQuery = $this->scope->tickets($admin);
        PeriodResolver::applyToQuery($scopedTicketQuery, 'tickets.created_at', $periodInfo);

        if ($unitCheck['unit_id'] !== null) {
            $this->scope->applyUnitFilterToTickets($scopedTicketQuery, $unitCheck['unit_id']);
        }

        $ticketIdsSub = (clone $scopedTicketQuery)->select('tickets.id');

        $overallRow = DB::table('ticket_sla_tracking')
            ->whereIn('ticket_sla_tracking.ticket_id', $ticketIdsSub)
            ->selectRaw('
                COUNT(*) as total_sla,
                SUM(CASE WHEN responded_at IS NOT NULL OR is_response_breached = 1 THEN 1 ELSE 0 END) as response_evaluated,
                SUM(CASE WHEN is_response_breached = 1 THEN 1 ELSE 0 END) as response_breached,
                SUM(CASE WHEN resolved_at IS NOT NULL OR is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_evaluated,
                SUM(CASE WHEN is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breached
            ')
            ->first();

        $totalSla = (int) ($overallRow->total_sla ?? 0);
        $respEval = (int) ($overallRow->response_evaluated ?? 0);
        $respBreach = (int) ($overallRow->response_breached ?? 0);
        $resEval = (int) ($overallRow->resolution_evaluated ?? 0);
        $resBreach = (int) ($overallRow->resolution_breached ?? 0);

        $responseCompliance = $respEval > 0
            ? round((max(0, $respEval - $respBreach) / $respEval) * 100, 1)
            : 100.0;

        $resolutionCompliance = $resEval > 0
            ? round((max(0, $resEval - $resBreach) / $resEval) * 100, 1)
            : 100.0;

        $perUnitRows = DB::table('ticket_sla_tracking')
            ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
            ->join('units', 'tickets.unit_id', '=', 'units.id')
            ->whereIn('tickets.id', $ticketIdsSub)
            ->select(
                'units.id as unit_id',
                'units.nama_unit',
                DB::raw('COUNT(*) as total_sla'),
                DB::raw('SUM(CASE WHEN ticket_sla_tracking.responded_at IS NOT NULL OR ticket_sla_tracking.is_response_breached = 1 THEN 1 ELSE 0 END) as response_evaluated'),
                DB::raw('SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 1 THEN 1 ELSE 0 END) as response_breached'),
                DB::raw('SUM(CASE WHEN ticket_sla_tracking.resolved_at IS NOT NULL OR ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_evaluated'),
                DB::raw('SUM(CASE WHEN ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breached')
            )
            ->groupBy('units.id', 'units.nama_unit')
            ->orderByDesc('total_sla')
            ->get();

        $byUnit = [];
        $chartLabels = [];
        $chartResponse = [];
        $chartResolution = [];

        foreach ($perUnitRows as $row) {
            $uRespEval = (int) $row->response_evaluated;
            $uRespBreach = (int) $row->response_breached;
            $uResEval = (int) $row->resolution_evaluated;
            $uResBreach = (int) $row->resolution_breached;

            $uRespRate = $uRespEval > 0
                ? round((max(0, $uRespEval - $uRespBreach) / $uRespEval) * 100, 1)
                : 100.0;
            $uResRate = $uResEval > 0
                ? round((max(0, $uResEval - $uResBreach) / $uResEval) * 100, 1)
                : 100.0;

            $byUnit[] = [
                'unit_id' => (int) $row->unit_id,
                'nama_unit' => (string) $row->nama_unit,
                'total_sla' => (int) $row->total_sla,
                'response_evaluated' => $uRespEval,
                'response_breached' => $uRespBreach,
                'response_compliance_pct' => $uRespRate,
                'resolution_evaluated' => $uResEval,
                'resolution_breached' => $uResBreach,
                'resolution_compliance_pct' => $uResRate,
            ];

            $chartLabels[] = (string) $row->nama_unit;
            $chartResponse[] = (float) $uRespRate;
            $chartResolution[] = (float) $uResRate;
        }

        $chart = null;
        if (! empty($byUnit)) {
            $chart = [
                'id' => 'sla_compliance_by_unit',
                'type' => 'bar',
                'title' => 'Kepatuhan SLA per Unit (%)',
                'subtitle' => $periodInfo['label'],
                'labels' => $chartLabels,
                'series' => [
                    [
                        'name' => 'Respon %',
                        'data' => $chartResponse,
                        'color' => '#0284c7',
                    ],
                    [
                        'name' => 'Resolusi %',
                        'data' => $chartResolution,
                        'color' => '#10b981',
                    ],
                ],
                'unit' => '%',
            ];
        }

        $data = [
            'period' => $periodInfo['key'],
            'period_label' => $periodInfo['label'],
            'overall' => [
                'total_sla' => $totalSla,
                'response_evaluated' => $respEval,
                'response_breached' => $respBreach,
                'response_compliance_pct' => $responseCompliance,
                'resolution_evaluated' => $resEval,
                'resolution_breached' => $resBreach,
                'resolution_compliance_pct' => $resolutionCompliance,
            ],
            'by_unit' => $byUnit,
        ];

        if ($unitCheck['denied'] && $unitCheck['note'] !== null) {
            $data['scope_note'] = $unitCheck['note'];
        }

        return new AiToolResult($data, $chart);
    }
}
