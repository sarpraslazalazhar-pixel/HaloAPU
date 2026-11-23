<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Csat;
use App\Models\SubUnit;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperatorPerformanceReportService
{
    /**
     * @var array<int, string>
     */
    public const ACTIVE_STATUSES = [
        'open',
        'on_proses',
        'pending',
        'need_revision',
        'waiting_approval',
    ];

    /**
     * @var array<int, string>
     */
    public const SOLVED_STATUSES = [
        'solve',
        'selesai',
    ];

    /**
     * @var array<int, string>
     */
    public const REJECTED_STATUSES = [
        'reject',
        'dibatalkan',
    ];

    /**
     * @var array<int, string>
     */
    public const DEFAULT_OPERATOR_ROLES = [
        'Operator',
        'operator',
        'Admin',
        'admin',
    ];

    /**
     * Normalize report filters so index, ulasan, PDF, and Excel behave identically.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $filters): array
    {
        $dateFrom = $this->normalizeDateFilter($filters['date_from'] ?? null);
        $dateTo = $this->normalizeDateFilter($filters['date_to'] ?? null);

        $year = isset($filters['year']) && trim((string) $filters['year']) !== ''
            ? trim((string) $filters['year'])
            : null;
        $month = isset($filters['month']) && trim((string) $filters['month']) !== ''
            ? trim((string) $filters['month'])
            : null;

        if ($year === null && ! ($dateFrom && $dateTo) && ! ($dateFrom || $dateTo)) {
            $year = date('Y');
        }

        $unitId = isset($filters['unit_id']) && trim((string) $filters['unit_id']) !== ''
            ? $filters['unit_id']
            : null;
        $subUnitId = isset($filters['sub_unit_id']) && trim((string) $filters['sub_unit_id']) !== ''
            ? $filters['sub_unit_id']
            : null;
        $search = isset($filters['search']) && trim((string) $filters['search']) !== ''
            ? trim((string) $filters['search'])
            : null;

        return [
            'year' => $year,
            'month' => $month,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'unit_id' => $unitId,
            'sub_unit_id' => $subUnitId,
            'search' => $search,
        ];
    }

    private function normalizeDateFilter(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::parse($trimmed)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build the single-source-of-truth operator performance report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     filters: array<string, mixed>,
     *     operators: Collection<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     workloadChartData: array<int, array<string, mixed>>,
     *     performanceChartData: array<int, array<string, mixed>>,
     * }
     */
    public function buildReport(array $filters): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $statsByAdmin = $this->aggregateStatsByAdmin($normalizedFilters);

        $activeAdminIds = $statsByAdmin
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $adminQuery = Admin::with(['roles:id,name', 'units:id,nama_unit', 'subUnits:id,nama_layanan'])
            ->where(function ($q) use ($activeAdminIds) {
                $q->whereHas('roles', fn ($r) => $r->whereIn('name', self::DEFAULT_OPERATOR_ROLES));
                if (! empty($activeAdminIds)) {
                    $q->orWhereIn('admins.id', $activeAdminIds);
                }
            });

        $unitId = $normalizedFilters['unit_id'];
        if ($unitId !== null) {
            $adminQuery->where(function ($q) use ($unitId, $activeAdminIds) {
                $q->whereHas('units', fn ($u) => $u->where('units.id', $unitId))
                    ->orWhereHas('subUnits', fn ($su) => $su->where('sub_units.unit_id', $unitId));

                if (! empty($activeAdminIds)) {
                    $q->orWhereIn('admins.id', $activeAdminIds);
                }
            });
        }

        $search = $normalizedFilters['search'];
        if ($search !== null) {
            $adminQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $operators = $adminQuery->orderBy('name')->get();

        $operatorWorkload = $operators
            ->map(function (Admin $operator) use ($statsByAdmin): array {
                $stat = $statsByAdmin->get($operator->id);

                $total = (int) ($stat->total_tickets ?? 0);
                $active = (int) ($stat->active_tickets ?? 0);
                $solved = (int) ($stat->solved_tickets ?? 0);
                $rejected = (int) ($stat->rejected_tickets ?? 0);
                $open = (int) ($stat->open_tickets ?? 0);

                $totalResolved = (int) ($stat->total_resolved ?? 0);
                $resolutionBreaches = (int) ($stat->resolution_breaches ?? 0);
                $responseBreaches = (int) ($stat->response_breaches ?? 0);
                $totalBreaches = $resolutionBreaches + $responseBreaches;

                $resolutionCompliance = $totalResolved > 0
                    ? round((max(0, $totalResolved - $resolutionBreaches) / $totalResolved) * 100, 1)
                    : null;

                $totalReviews = (int) ($stat->total_reviews ?? 0);
                $ratingSum = (float) ($stat->rating_sum ?? 0);
                $avgRating = $totalReviews > 0 ? round($ratingSum / $totalReviews, 2) : null;

                return [
                    'id' => $operator->id,
                    'name' => $operator->name,
                    'username' => $operator->username,
                    'email' => $operator->email,
                    'no_wa' => $operator->no_wa,
                    'roles' => $operator->roles->pluck('name')->values()->all(),
                    'units' => $operator->units->pluck('nama_unit')->values()->all(),
                    'sub_units' => $operator->subUnits->pluck('nama_layanan')->values()->all(),
                    'total_tickets' => $total,
                    'active_tickets' => $active,
                    'solved_tickets' => $solved,
                    'rejected_tickets' => $rejected,
                    'open_tickets' => $open,
                    'total_resolved' => $totalResolved,
                    'resolution_breaches' => $resolutionBreaches,
                    'response_breaches' => $responseBreaches,
                    'total_breaches' => $totalBreaches,
                    'resolution_compliance' => $resolutionCompliance,
                    'avg_rating' => $avgRating,
                    'total_reviews' => $totalReviews,
                ];
            })
            ->sort(function (array $a, array $b): int {
                return ($b['active_tickets'] <=> $a['active_tickets'])
                    ?: ($b['solved_tickets'] <=> $a['solved_tickets'])
                    ?: ($b['total_tickets'] <=> $a['total_tickets'])
                    ?: strcasecmp((string) $a['name'], (string) $b['name'])
                    ?: strcmp((string) $a['name'], (string) $b['name'])
                    ?: ($a['id'] <=> $b['id']);
            })
            ->values();

        $totalOperators = $operatorWorkload->count();
        $activeOperators = $operatorWorkload->filter(fn (array $o) => $o['total_tickets'] > 0)->count();
        $totalAssignedTickets = (int) $operatorWorkload->sum('total_tickets');
        $totalActiveTickets = (int) $operatorWorkload->sum('active_tickets');
        $totalSolvedTickets = (int) $operatorWorkload->sum('solved_tickets');
        $totalRejectedTickets = (int) $operatorWorkload->sum('rejected_tickets');
        $totalResolutionBreaches = (int) $operatorWorkload->sum('resolution_breaches');
        $totalReviews = (int) $operatorWorkload->sum('total_reviews');
        $avgActivePerOperator = $totalOperators > 0 ? round($totalActiveTickets / $totalOperators, 1) : 0;

        $totalRatingSum = (float) $operatorWorkload->sum(function (array $o) use ($statsByAdmin): float {
            $stat = $statsByAdmin->get($o['id']);

            return (float) ($stat->rating_sum ?? 0);
        });
        $overallAvgRating = $totalReviews > 0 ? round($totalRatingSum / $totalReviews, 2) : 0;

        $complianceOperators = $operatorWorkload->filter(fn (array $o) => $o['resolution_compliance'] !== null);
        $avgCompliance = $complianceOperators->isNotEmpty()
            ? round((float) $complianceOperators->avg('resolution_compliance'), 1)
            : null;

        $workloadChartData = $operatorWorkload
            ->filter(fn (array $o) => $o['active_tickets'] > 0 || $o['solved_tickets'] > 0)
            ->take(10)
            ->values()
            ->map(fn (array $o) => [
                'name' => $o['name'],
                'active' => $o['active_tickets'],
                'solved' => $o['solved_tickets'],
            ])
            ->all();

        $performanceChartData = $operatorWorkload
            ->filter(fn (array $o) => $o['resolution_compliance'] !== null || $o['avg_rating'] !== null)
            ->take(10)
            ->values()
            ->map(fn (array $o) => [
                'name' => $o['name'],
                'sla' => $o['resolution_compliance'],
                'rating' => $o['avg_rating'],
            ])
            ->all();

        return [
            'filters' => $normalizedFilters,
            'operators' => $operatorWorkload,
            'summary' => [
                'totalOperators' => $totalOperators,
                'activeOperators' => $activeOperators,
                'totalAssigned' => $totalAssignedTickets,
                'totalActive' => $totalActiveTickets,
                'totalSolved' => $totalSolvedTickets,
                'totalRejected' => $totalRejectedTickets,
                'totalReviews' => $totalReviews,
                'totalResolutionBreaches' => $totalResolutionBreaches,
                'avgActivePerOperator' => $avgActivePerOperator,
                'overallAvgRating' => $overallAvgRating,
                'avgCompliance' => $avgCompliance,
            ],
            'workloadChartData' => $workloadChartData,
            'performanceChartData' => $performanceChartData,
        ];
    }

    /**
     * Fetch CSAT reviews attributed to the given admin using the same attribution and filter rules as the report.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function reviewsForAdmin(Admin $admin, array $filters, int $limit = 50): Collection
    {
        $normalizedFilters = $this->normalizeFilters($filters);

        $ticketIdsQuery = DB::query()
            ->fromSub($this->attributedTicketsQuery($normalizedFilters), 'attributed_tickets')
            ->where('attributed_tickets.responsible_admin_id', $admin->id)
            ->select('attributed_tickets.ticket_id');

        return Csat::query()
            ->whereIn('ticket_id', $ticketIdsQuery)
            ->with([
                'ticket' => fn ($q) => $q->select('id', 'sub_unit_id', 'status', 'created_at', 'form_data')
                    ->with(['subUnit:id,unit_id,nama_layanan', 'subUnit.unit:id,nama_unit']),
                'user:id,name,username,email,divisi_id',
                'user.divisi:id,nama_divisi',
                'admin:id,name,username',
            ])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (Csat $csat): array {
                return [
                    'id' => $csat->id,
                    'rating' => $csat->rating,
                    'komentar' => $csat->komentar,
                    'created_at' => $csat->created_at?->format('d M Y H:i') ?? '-',
                    'ticket_id' => $csat->ticket?->formatted_id ?? (string) $csat->ticket_id,
                    'ticket_raw_id' => $csat->ticket_id,
                    'ticket_title' => $csat->ticket?->judul ?? '-',
                    'unit_nama' => $csat->ticket?->subUnit?->unit?->nama_unit ?? '-',
                    'layanan_nama' => $csat->ticket?->subUnit?->nama_layanan ?? '-',
                    'user_name' => $csat->user?->name
                        ?? $csat->user?->username
                        ?? $csat->admin?->name
                        ?? $csat->admin?->username
                        ?? 'User',
                    'divisi_nama' => $csat->user?->divisi?->nama_divisi ?? '-',
                ];
            });
    }

    /**
     * Build a human-readable period label for PDF and Excel exports.
     *
     * @param  array<string, mixed>  $normalizedFilters
     */
    public function buildPeriodeLabel(array $normalizedFilters): ?string
    {
        $dateFrom = $normalizedFilters['date_from'] ?? null;
        $dateTo = $normalizedFilters['date_to'] ?? null;
        $year = $normalizedFilters['year'] ?? null;
        $month = $normalizedFilters['month'] ?? null;

        try {
            if ($dateFrom && $dateTo) {
                return Carbon::parse($dateFrom)->format('d M Y') . ' - ' . Carbon::parse($dateTo)->format('d M Y');
            }
            if ($dateFrom) {
                return 'Mulai ' . Carbon::parse($dateFrom)->format('d M Y');
            }
            if ($dateTo) {
                return 'Sampai ' . Carbon::parse($dateTo)->format('d M Y');
            }
            if ($year && $month && (int) $month >= 1 && (int) $month <= 12) {
                return Carbon::createFromDate((int) $year, (int) $month, 1)->translatedFormat('F Y');
            }
            if ($year) {
                return "Tahun {$year}";
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Build a human-readable filter label for PDF and Excel exports.
     *
     * @param  array<string, mixed>  $normalizedFilters
     */
    public function buildFilterLabel(array $normalizedFilters, string $unitPrefix = 'Unit'): ?string
    {
        $parts = [];

        if (! empty($normalizedFilters['unit_id'])) {
            $unit = Unit::find($normalizedFilters['unit_id']);
            if ($unit) {
                $parts[] = "{$unitPrefix} = {$unit->nama_unit}";
            }
        }
        if (! empty($normalizedFilters['sub_unit_id'])) {
            $sub = SubUnit::find($normalizedFilters['sub_unit_id']);
            if ($sub) {
                $parts[] = "Layanan = {$sub->nama_layanan}";
            }
        }
        if (! empty($normalizedFilters['search'])) {
            $parts[] = "Kata kunci = {$normalizedFilters['search']}";
        }

        return $parts ? implode(' | ', $parts) : null;
    }

    /**
     * Aggregate ticket, SLA, and CSAT metrics per attributed responsible admin.
     *
     * @param  array<string, mixed>  $normalizedFilters
     * @return Collection<int, object>
     */
    private function aggregateStatsByAdmin(array $normalizedFilters): Collection
    {
        $latestSlaIds = DB::table('ticket_sla_tracking')
            ->selectRaw('ticket_id, MAX(id) as max_id')
            ->groupBy('ticket_id');

        $latestSla = DB::table('ticket_sla_tracking as sla')
            ->joinSub($latestSlaIds, 'latest_sla', function ($join) {
                $join->on('sla.ticket_id', '=', 'latest_sla.ticket_id')
                    ->on('sla.id', '=', 'latest_sla.max_id');
            })
            ->select(
                'sla.ticket_id',
                'sla.resolved_at',
                'sla.is_response_breached',
                'sla.is_resolution_breached'
            );

        return DB::query()
            ->fromSub($this->attributedTicketsQuery($normalizedFilters), 'attributed_tickets')
            ->leftJoinSub($latestSla, 'ticket_sla_tracking', function ($join) {
                $join->on('attributed_tickets.ticket_id', '=', 'ticket_sla_tracking.ticket_id');
            })
            ->leftJoin('csats', 'attributed_tickets.ticket_id', '=', 'csats.ticket_id')
            ->whereNotNull('attributed_tickets.responsible_admin_id')
            ->selectRaw("
                attributed_tickets.responsible_admin_id as responsible_admin_id,
                COUNT(attributed_tickets.ticket_id) as total_tickets,
                SUM(CASE WHEN attributed_tickets.normalized_status IN ('open', 'on_proses', 'pending', 'need_revision', 'waiting_approval') THEN 1 ELSE 0 END) as active_tickets,
                SUM(CASE WHEN attributed_tickets.normalized_status IN ('solve', 'selesai') THEN 1 ELSE 0 END) as solved_tickets,
                SUM(CASE WHEN attributed_tickets.normalized_status IN ('reject', 'dibatalkan') THEN 1 ELSE 0 END) as rejected_tickets,
                SUM(CASE WHEN attributed_tickets.normalized_status = 'open' THEN 1 ELSE 0 END) as open_tickets,
                SUM(CASE WHEN ticket_sla_tracking.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as total_resolved,
                SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 1 THEN 1 ELSE 0 END) as response_breaches,
                SUM(CASE WHEN ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breaches,
                COALESCE(SUM(csats.rating), 0) as rating_sum,
                COUNT(csats.id) as total_reviews
            ")
            ->groupBy('attributed_tickets.responsible_admin_id')
            ->get()
            ->keyBy('responsible_admin_id');
    }

    /**
     * Resolve each filtered ticket to a single responsible_admin_id:
     * - Solved ('solve', 'selesai'): latest admin solve/selesai log (highest ticket_logs.id), fallback assigned_admin_id.
     * - Rejected/cancelled ('reject', 'dibatalkan'): latest admin reject/dibatalkan log, fallback assigned_admin_id.
     * - Active ('open', 'on_proses', 'pending', 'need_revision', 'waiting_approval'): strictly assigned_admin_id.
     *
     * @param  array<string, mixed>  $normalizedFilters
     */
    public function attributedTicketsQuery(array $normalizedFilters): QueryBuilder
    {
        $latestSolveLogIds = DB::table('ticket_logs')
            ->selectRaw('ticket_id, MAX(id) as max_id')
            ->whereRaw("LOWER(aksi) IN ('solve', 'selesai')")
            ->whereNotNull('admin_id')
            ->groupBy('ticket_id');

        $latestSolveLogs = DB::table('ticket_logs as solve_log')
            ->joinSub($latestSolveLogIds, 'latest_solve', function ($join) {
                $join->on('solve_log.ticket_id', '=', 'latest_solve.ticket_id')
                    ->on('solve_log.id', '=', 'latest_solve.max_id');
            })
            ->select('solve_log.ticket_id', 'solve_log.admin_id as resolver_admin_id');

        $latestRejectLogIds = DB::table('ticket_logs')
            ->selectRaw('ticket_id, MAX(id) as max_id')
            ->whereRaw("LOWER(aksi) IN ('reject', 'dibatalkan')")
            ->whereNotNull('admin_id')
            ->groupBy('ticket_id');

        $latestRejectLogs = DB::table('ticket_logs as reject_log')
            ->joinSub($latestRejectLogIds, 'latest_reject', function ($join) {
                $join->on('reject_log.ticket_id', '=', 'latest_reject.ticket_id')
                    ->on('reject_log.id', '=', 'latest_reject.max_id');
            })
            ->select('reject_log.ticket_id', 'reject_log.admin_id as rejector_admin_id');

        $query = DB::table('tickets')
            ->leftJoinSub($latestSolveLogs, 'latest_solve_logs', function ($join) {
                $join->on('tickets.id', '=', 'latest_solve_logs.ticket_id');
            })
            ->leftJoinSub($latestRejectLogs, 'latest_reject_logs', function ($join) {
                $join->on('tickets.id', '=', 'latest_reject_logs.ticket_id');
            });

        $dateFrom = $normalizedFilters['date_from'] ?? null;
        $dateTo = $normalizedFilters['date_to'] ?? null;
        $year = $normalizedFilters['year'] ?? null;
        $month = $normalizedFilters['month'] ?? null;

        if ($dateFrom && $dateTo) {
            $query->whereBetween('tickets.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } elseif ($dateFrom || $dateTo) {
            if ($dateFrom) {
                $query->where('tickets.created_at', '>=', $dateFrom . ' 00:00:00');
            }
            if ($dateTo) {
                $query->where('tickets.created_at', '<=', $dateTo . ' 23:59:59');
            }
        } else {
            if ($year !== null) {
                $query->whereYear('tickets.created_at', $year);
            }
            if ($month !== null) {
                $query->whereMonth('tickets.created_at', $month);
            }
        }

        if (! empty($normalizedFilters['unit_id'])) {
            $query->where('tickets.unit_id', $normalizedFilters['unit_id']);
        }
        if (! empty($normalizedFilters['sub_unit_id'])) {
            $query->where('tickets.sub_unit_id', $normalizedFilters['sub_unit_id']);
        }

        return $query->selectRaw("
            tickets.id as ticket_id,
            LOWER(tickets.status) as normalized_status,
            CASE
                WHEN LOWER(tickets.status) IN ('solve', 'selesai') THEN COALESCE(latest_solve_logs.resolver_admin_id, tickets.assigned_admin_id)
                WHEN LOWER(tickets.status) IN ('reject', 'dibatalkan') THEN COALESCE(latest_reject_logs.rejector_admin_id, tickets.assigned_admin_id)
                WHEN LOWER(tickets.status) IN ('open', 'on_proses', 'pending', 'need_revision', 'waiting_approval') THEN tickets.assigned_admin_id
                ELSE NULL
            END as responsible_admin_id
        ");
    }
}
