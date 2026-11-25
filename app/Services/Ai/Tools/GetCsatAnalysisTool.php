<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Models\Csat;
use App\Services\Ai\AiAccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class GetCsatAnalysisTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'get_csat_analysis';
    }

    public function description(): string
    {
        return 'Menganalisis kepuasan pengguna (CSAT): rata-rata rating, distribusi bintang 1-5, rata-rata per unit, dan daftar ulasan/komentar pemohon terbaru.';
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
                'max_rating' => [
                    'type' => 'INTEGER',
                    'description' => 'Filter ulasan dengan rating <= nilai ini (1-5) untuk meninjau keluhan/rating rendah (opsional).',
                ],
                'limit' => [
                    'type' => 'INTEGER',
                    'description' => 'Jumlah ulasan terbaru ditampilkan (default 8, maks 20).',
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

        $limit = min(20, max(1, (int) ($args['limit'] ?? 8)));
        $maxRating = isset($args['max_rating']) && $args['max_rating'] !== ''
            ? min(5, max(1, (int) $args['max_rating']))
            : null;

        $requestedUnitId = isset($args['unit_id']) && $args['unit_id'] !== '' ? (int) $args['unit_id'] : null;
        $unitCheck = $this->scope->filterUnitId($admin, $requestedUnitId);

        $baseQuery = $this->scope->csats($admin);
        PeriodResolver::applyToQuery($baseQuery, 'csats.created_at', $periodInfo);

        if ($unitCheck['unit_id'] !== null) {
            $targetUnitId = $unitCheck['unit_id'];
            $baseQuery->whereHas('ticket', function (Builder $tq) use ($targetUnitId): void {
                $tq->where('tickets.unit_id', $targetUnitId)
                    ->orWhereHas('subUnit', fn (Builder $sq) => $sq->where('sub_units.unit_id', $targetUnitId));
            });
        }

        $distRows = (clone $baseQuery)
            ->select('csats.rating', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('csats.rating')
            ->get();

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $totalReviews = 0;
        $ratingSum = 0;

        foreach ($distRows as $row) {
            $star = (int) $row->rating;
            $count = (int) $row->jumlah;
            if ($star >= 1 && $star <= 5) {
                $distribution[$star] = $count;
                $totalReviews += $count;
                $ratingSum += ($star * $count);
            }
        }

        $avgRating = $totalReviews > 0 ? round($ratingSum / $totalReviews, 2) : 0.0;

        $csatIdsSub = (clone $baseQuery)->select('csats.id');
        $byUnit = DB::table('csats')
            ->join('tickets', 'csats.ticket_id', '=', 'tickets.id')
            ->join('units', 'tickets.unit_id', '=', 'units.id')
            ->whereIn('csats.id', $csatIdsSub)
            ->select(
                'units.id as unit_id',
                'units.nama_unit',
                DB::raw('COUNT(*) as total_reviews'),
                DB::raw('AVG(csats.rating) as avg_rating')
            )
            ->groupBy('units.id', 'units.nama_unit')
            ->orderByDesc('total_reviews')
            ->get()
            ->map(fn ($row) => [
                'unit_id' => (int) $row->unit_id,
                'nama_unit' => (string) $row->nama_unit,
                'total_reviews' => (int) $row->total_reviews,
                'avg_rating' => round((float) $row->avg_rating, 2),
            ])
            ->values()
            ->all();

        $reviewsQuery = clone $baseQuery;
        if ($maxRating !== null) {
            $reviewsQuery->where('csats.rating', '<=', $maxRating);
        }

        $recentReviews = $reviewsQuery
            ->with([
                'ticket:id,unit_id,sub_unit_id,status',
                'ticket.unit:id,nama_unit',
                'ticket.subUnit:id,unit_id,nama_layanan',
                'ticket.subUnit.unit:id,nama_unit',
                'user:id,name,username',
                'admin:id,name,username',
            ])
            ->orderByDesc('csats.created_at')
            ->orderByDesc('csats.id')
            ->limit($limit)
            ->get()
            ->map(function (Csat $csat): array {
                $ticket = $csat->ticket;

                return [
                    'ticket_id' => (int) $csat->ticket_id,
                    'formatted_id' => $ticket?->formatted_id ?? (string) $csat->ticket_id,
                    'url' => '/admin/tiket/' . $csat->ticket_id,
                    'rating' => (int) $csat->rating,
                    'komentar' => $csat->komentar ?: '(tanpa ulasan teks)',
                    'pemohon' => (string) ($csat->user?->name ?? $csat->user?->username ?? $csat->admin?->name ?? $csat->admin?->username ?? 'Anonim'),
                    'layanan' => (string) ($ticket?->subUnit?->nama_layanan ?? '-'),
                    'unit' => (string) ($ticket?->unit?->nama_unit ?? $ticket?->subUnit?->unit?->nama_unit ?? '-'),
                    'tanggal' => $csat->created_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                ];
            })
            ->all();

        $chart = null;
        if ($totalReviews > 0) {
            $chart = [
                'id' => 'csat_rating_distribution',
                'type' => 'pie',
                'title' => 'Distribusi Rating CSAT',
                'subtitle' => $periodInfo['label'],
                'labels' => ['1 Bintang', '2 Bintang', '3 Bintang', '4 Bintang', '5 Bintang'],
                'series' => [
                    [
                        'name' => 'Jumlah Ulasan',
                        'data' => [
                            (int) $distribution[1],
                            (int) $distribution[2],
                            (int) $distribution[3],
                            (int) $distribution[4],
                            (int) $distribution[5],
                        ],
                        'color' => '#f59e0b',
                    ],
                ],
                'unit' => 'ulasan',
            ];
        }

        $data = [
            'period' => $periodInfo['key'],
            'period_label' => $periodInfo['label'],
            'avg_rating' => $avgRating,
            'total_reviews' => $totalReviews,
            'distribution' => $distribution,
            'by_unit' => $byUnit,
            'max_rating_filter' => $maxRating,
            'recent_reviews' => $recentReviews,
        ];

        if ($unitCheck['denied'] && $unitCheck['note'] !== null) {
            $data['scope_note'] = $unitCheck['note'];
        }

        return new AiToolResult($data, $chart);
    }
}
