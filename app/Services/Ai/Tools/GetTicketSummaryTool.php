<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Services\Ai\AiAccessScope;
use Illuminate\Support\Facades\DB;

class GetTicketSummaryTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'get_ticket_summary';
    }

    public function description(): string
    {
        return 'Mengambil ringkasan jumlah tiket berdasarkan semua status, prioritas, unit layanan teratas, dan sub-unit/layanan teratas dalam cakupan akses admin.';
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

        $baseQuery = $this->scope->tickets($admin);
        PeriodResolver::applyToQuery($baseQuery, 'tickets.created_at', $periodInfo);

        if ($unitCheck['unit_id'] !== null) {
            $this->scope->applyUnitFilterToTickets($baseQuery, $unitCheck['unit_id']);
        }

        $statusRows = (clone $baseQuery)
            ->select('tickets.status', DB::raw('COUNT(*) as total'))
            ->groupBy('tickets.status')
            ->get();

        $byStatus = [
            'open' => 0,
            'on_proses' => 0,
            'pending' => 0,
            'need_revision' => 0,
            'waiting_approval' => 0,
            'solve' => 0,
            'reject' => 0,
            'dibatalkan' => 0,
        ];

        $total = 0;
        foreach ($statusRows as $row) {
            $count = (int) $row->total;
            $total += $count;
            $statusKey = strtolower(trim((string) $row->status));

            if (in_array($statusKey, ['solve', 'selesai'], true)) {
                $byStatus['solve'] += $count;
            } elseif (array_key_exists($statusKey, $byStatus)) {
                $byStatus[$statusKey] += $count;
            } else {
                $byStatus[$statusKey] = ($byStatus[$statusKey] ?? 0) + $count;
            }
        }

        $priorityRows = (clone $baseQuery)
            ->select('tickets.priority', DB::raw('COUNT(*) as total'))
            ->groupBy('tickets.priority')
            ->get();

        $byPriority = [];
        foreach ($priorityRows as $row) {
            $label = trim((string) ($row->priority ?? '')) ?: 'Tidak Ditentukan';
            $byPriority[$label] = (int) $row->total;
        }

        $topUnits = (clone $baseQuery)
            ->join('units', 'tickets.unit_id', '=', 'units.id')
            ->select('units.id', 'units.nama_unit', DB::raw('COUNT(tickets.id) as total'))
            ->groupBy('units.id', 'units.nama_unit')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'unit_id' => (int) $row->id,
                'nama_unit' => (string) $row->nama_unit,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $topSubUnits = (clone $baseQuery)
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->leftJoin('units', 'sub_units.unit_id', '=', 'units.id')
            ->select('sub_units.id', 'sub_units.nama_layanan', 'units.nama_unit', DB::raw('COUNT(tickets.id) as total'))
            ->groupBy('sub_units.id', 'sub_units.nama_layanan', 'units.nama_unit')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'sub_unit_id' => (int) $row->id,
                'nama_layanan' => (string) $row->nama_layanan,
                'nama_unit' => (string) ($row->nama_unit ?? '-'),
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $chart = null;
        if ($total > 0) {
            $chart = [
                'id' => 'ticket_status_distribution',
                'type' => 'bar',
                'title' => 'Distribusi Status Tiket',
                'subtitle' => $periodInfo['label'],
                'labels' => ['Open', 'Diproses', 'Pending', 'Revisi', 'Menunggu Approval', 'Selesai', 'Ditolak', 'Dibatalkan'],
                'series' => [
                    [
                        'name' => 'Jumlah Tiket',
                        'data' => [
                            (int) $byStatus['open'],
                            (int) $byStatus['on_proses'],
                            (int) $byStatus['pending'],
                            (int) $byStatus['need_revision'],
                            (int) $byStatus['waiting_approval'],
                            (int) $byStatus['solve'],
                            (int) $byStatus['reject'],
                            (int) $byStatus['dibatalkan'],
                        ],
                        'color' => '#0284c7',
                    ],
                ],
                'unit' => 'tiket',
            ];
        }

        $activeTotal = $byStatus['open'] + $byStatus['on_proses'] + $byStatus['pending'] + $byStatus['need_revision'] + $byStatus['waiting_approval'];

        $data = [
            'period' => $periodInfo['key'],
            'period_label' => $periodInfo['label'],
            'date_from' => $periodInfo['date_from'],
            'date_to' => $periodInfo['date_to'],
            'scope' => $this->scope->describeScope($admin),
            'total_tickets' => $total,
            'active_tickets' => $activeTotal,
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'top_units' => $topUnits,
            'top_sub_units' => $topSubUnits,
        ];

        if ($unitCheck['denied'] && $unitCheck['note'] !== null) {
            $data['scope_note'] = $unitCheck['note'];
        }

        return new AiToolResult($data, $chart);
    }
}
