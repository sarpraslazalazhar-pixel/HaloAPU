<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Csat;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\SubUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanOperatorController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->input('year', date('Y'));
        $month = $request->input('month');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $unitId = $request->input('unit_id');
        $subUnitId = $request->input('sub_unit_id');
        $search = $request->input('search');

        // References for Filter Dropdowns
        $units = Unit::where('aktif', true)->orderBy('nama_unit')->get(['id', 'nama_unit']);
        $subUnits = $unitId ? SubUnit::where('unit_id', $unitId)->orderBy('nama_layanan')->get(['id', 'unit_id', 'nama_layanan']) : [];

        // 1. Get Operators (Admins with role Operator or admins that have assigned tickets)
        $adminQuery = Admin::with(['roles:id,name', 'units:id,nama_unit', 'subUnits:id,nama_layanan'])
            ->where(function ($q) {
                $q->whereHas('roles', fn ($r) => $r->whereIn('name', ['Operator', 'operator', 'Admin', 'admin']))
                  ->orWhereExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('tickets')
                          ->whereColumn('tickets.assigned_admin_id', 'admins.id');
                  });
            });

        if ($search) {
            $adminQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($unitId) {
            $adminQuery->whereHas('units', fn ($q) => $q->where('units.id', $unitId));
        }

        $operators = $adminQuery->orderBy('name')->get();

        // 2. Base Query for Tickets Assigned within period and filters
        $ticketSubQuery = Ticket::query()
            ->whereNotNull('tickets.assigned_admin_id');

        if ($dateFrom && $dateTo) {
            $ticketSubQuery->whereBetween('tickets.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } else {
            if ($year) $ticketSubQuery->whereYear('tickets.created_at', $year);
            if ($month) $ticketSubQuery->whereMonth('tickets.created_at', $month);
        }

        if ($unitId) {
            $ticketSubQuery->whereHas('subUnit', fn ($q) => $q->where('unit_id', $unitId));
        }
        if ($subUnitId) {
            $ticketSubQuery->where('tickets.sub_unit_id', $subUnitId);
        }

        // Aggregate tickets per assigned_admin_id with SLA & CSAT
        $statsByAdmin = (clone $ticketSubQuery)
            ->leftJoin('ticket_sla_tracking', 'tickets.id', '=', 'ticket_sla_tracking.ticket_id')
            ->leftJoin('csats', 'tickets.id', '=', 'csats.ticket_id')
            ->selectRaw('
                tickets.assigned_admin_id,
                COUNT(tickets.id) as total_tickets,
                SUM(CASE WHEN tickets.status IN ("on_proses", "pending", "need_revision", "waiting_approval") THEN 1 ELSE 0 END) as active_tickets,
                SUM(CASE WHEN tickets.status IN ("solve", "selesai") THEN 1 ELSE 0 END) as solved_tickets,
                SUM(CASE WHEN tickets.status IN ("reject", "dibatalkan") THEN 1 ELSE 0 END) as rejected_tickets,
                SUM(CASE WHEN tickets.status = "open" THEN 1 ELSE 0 END) as open_tickets,
                SUM(CASE WHEN ticket_sla_tracking.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as total_resolved,
                SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 1 THEN 1 ELSE 0 END) as response_breaches,
                SUM(CASE WHEN ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breaches,
                ROUND(AVG(csats.rating), 2) as avg_rating,
                COUNT(csats.id) as total_reviews
            ')
            ->groupBy('tickets.assigned_admin_id')
            ->get()
            ->keyBy('assigned_admin_id');

        // 3. Map Stats onto Operator list
        $operatorWorkload = $operators->map(function ($operator) use ($statsByAdmin) {
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
                ? round((($totalResolved - $resolutionBreaches) / $totalResolved) * 100, 1)
                : ($total > 0 ? 100 : 100);

            $avgRating = $stat && $stat->avg_rating !== null ? (float) $stat->avg_rating : null;
            $totalReviews = (int) ($stat->total_reviews ?? 0);

            return [
                'id' => $operator->id,
                'name' => $operator->name,
                'username' => $operator->username,
                'email' => $operator->email,
                'no_wa' => $operator->no_wa,
                'roles' => $operator->roles->pluck('name'),
                'units' => $operator->units->pluck('nama_unit'),
                'sub_units' => $operator->subUnits->pluck('nama_layanan'),
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
        });

        // Sort by active tickets descending by default so overwork operators appear at top
        $operatorWorkload = $operatorWorkload->sortByDesc('active_tickets')->values();

        // 4. Summary Totals
        $totalOperators = $operatorWorkload->count();
        $totalAssignedTickets = $operatorWorkload->sum('total_tickets');
        $totalActiveTickets = $operatorWorkload->sum('active_tickets');
        $totalSolvedTickets = $operatorWorkload->sum('solved_tickets');
        $avgActivePerOperator = $totalOperators > 0 ? round($totalActiveTickets / $totalOperators, 1) : 0;

        $ratedOperators = $operatorWorkload->filter(fn ($o) => $o['avg_rating'] !== null);
        $overallAvgRating = $ratedOperators->count() > 0 ? round($ratedOperators->avg('avg_rating'), 2) : 0;

        // 5. Chart Data: Workload Comparison (Active vs Solved)
        $workloadChartData = $operatorWorkload->take(10)->map(fn ($o) => [
            'name' => $o['name'],
            'active' => $o['active_tickets'],
            'solved' => $o['solved_tickets'],
        ]);

        // SLA & Rating Comparison
        $performanceChartData = $operatorWorkload->take(10)->map(fn ($o) => [
            'name' => $o['name'],
            'sla' => $o['resolution_compliance'],
            'rating' => $o['avg_rating'] ?? 0,
        ]);

        return Inertia::render('Admin/Laporan/KinerjaOperator', [
            'filters' => [
                'year' => $year,
                'month' => $month,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'unit_id' => $unitId,
                'sub_unit_id' => $subUnitId,
                'search' => $search,
            ],
            'units' => $units,
            'subUnits' => $subUnits,
            'operators' => $operatorWorkload,
            'summary' => [
                'totalOperators' => $totalOperators,
                'totalAssigned' => $totalAssignedTickets,
                'totalActive' => $totalActiveTickets,
                'totalSolved' => $totalSolvedTickets,
                'avgActivePerOperator' => $avgActivePerOperator,
                'overallAvgRating' => $overallAvgRating,
            ],
            'workloadChartData' => $workloadChartData,
            'performanceChartData' => $performanceChartData,
        ]);
    }

    public function ulasan(Admin $admin, Request $request)
    {
        $year = $request->input('year');
        $month = $request->input('month');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $unitId = $request->input('unit_id');
        $subUnitId = $request->input('sub_unit_id');

        $query = Csat::whereHas('ticket', function ($q) use ($admin, $dateFrom, $dateTo, $year, $month, $unitId, $subUnitId) {
            $q->where('assigned_admin_id', $admin->id);

            if ($dateFrom && $dateTo) {
                $q->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            } else {
                if ($year) $q->whereYear('created_at', $year);
                if ($month) $q->whereMonth('created_at', $month);
            }

            if ($unitId) {
                $q->whereHas('subUnit', fn ($sq) => $sq->where('unit_id', $unitId));
            }
            if ($subUnitId) {
                $q->where('sub_unit_id', $subUnitId);
            }
        })
        ->with([
            'ticket' => fn ($q) => $q->select('id', 'sub_unit_id', 'status', 'created_at', 'form_data')
                ->with(['subUnit:id,unit_id,nama_layanan', 'subUnit.unit:id,nama_unit']),
            'user:id,name,username,email,divisi_id',
            'user.divisi:id,nama_divisi',
        ])
        ->latest();

        $reviews = $query->limit(50)->get()->map(function ($csat) {
            return [
                'id' => $csat->id,
                'rating' => $csat->rating,
                'komentar' => $csat->komentar,
                'created_at' => $csat->created_at->format('d M Y H:i'),
                'ticket_id' => $csat->ticket?->formatted_id ?? (string) $csat->ticket_id,
                'ticket_raw_id' => $csat->ticket_id,
                'ticket_title' => $csat->ticket?->judul ?? '-',
                'unit_nama' => $csat->ticket?->subUnit?->unit?->nama_unit ?? '-',
                'layanan_nama' => $csat->ticket?->subUnit?->nama_layanan ?? '-',
                'user_name' => $csat->user?->name ?? $csat->user?->username ?? 'User',
                'divisi_nama' => $csat->user?->divisi?->nama_divisi ?? '-',
            ];
        });

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'username' => $admin->username,
            ],
            'reviews' => $reviews,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $year = $request->input('year', date('Y'));
        $month = $request->input('month');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $unitId = $request->input('unit_id');
        $subUnitId = $request->input('sub_unit_id');
        $search = $request->input('search');

        $adminQuery = Admin::with(['units:id,nama_unit', 'subUnits:id,nama_layanan'])
            ->where(function ($q) {
                $q->whereHas('roles', fn ($r) => $r->whereIn('name', ['Operator', 'operator', 'Admin', 'admin']))
                  ->orWhereExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('tickets')
                          ->whereColumn('tickets.assigned_admin_id', 'admins.id');
                  });
            });

        if ($search) {
            $adminQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($unitId) {
            $adminQuery->whereHas('units', fn ($q) => $q->where('units.id', $unitId));
        }

        $operators = $adminQuery->orderBy('name')->get();

        $ticketSubQuery = Ticket::query()->whereNotNull('tickets.assigned_admin_id');

        if ($dateFrom && $dateTo) {
            $ticketSubQuery->whereBetween('tickets.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } else {
            if ($year) $ticketSubQuery->whereYear('tickets.created_at', $year);
            if ($month) $ticketSubQuery->whereMonth('tickets.created_at', $month);
        }

        if ($unitId) {
            $ticketSubQuery->whereHas('subUnit', fn ($q) => $q->where('unit_id', $unitId));
        }
        if ($subUnitId) {
            $ticketSubQuery->where('tickets.sub_unit_id', $subUnitId);
        }

        $statsByAdmin = (clone $ticketSubQuery)
            ->leftJoin('ticket_sla_tracking', 'tickets.id', '=', 'ticket_sla_tracking.ticket_id')
            ->leftJoin('csats', 'tickets.id', '=', 'csats.ticket_id')
            ->selectRaw('
                tickets.assigned_admin_id,
                COUNT(tickets.id) as total_tickets,
                SUM(CASE WHEN tickets.status IN ("on_proses", "pending", "need_revision", "waiting_approval") THEN 1 ELSE 0 END) as active_tickets,
                SUM(CASE WHEN tickets.status IN ("solve", "selesai") THEN 1 ELSE 0 END) as solved_tickets,
                SUM(CASE WHEN tickets.status IN ("reject", "dibatalkan") THEN 1 ELSE 0 END) as rejected_tickets,
                SUM(CASE WHEN ticket_sla_tracking.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as total_resolved,
                SUM(CASE WHEN ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breaches,
                ROUND(AVG(csats.rating), 2) as avg_rating,
                COUNT(csats.id) as total_reviews
            ')
            ->groupBy('tickets.assigned_admin_id')
            ->get()
            ->keyBy('assigned_admin_id');

        $filename = 'laporan-kinerja-operator-' . date('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($operators, $statsByAdmin) {
            $handle = fopen('php://output', 'w');
            // Add UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'Nama Operator',
                'Username',
                'Email',
                'Unit Layanan',
                'Tiket Aktif (Berjalan)',
                'Tiket Selesai',
                'Tiket Ditolak/Batal',
                'Total Tiket',
                'Kepatuhan SLA Resolusi (%)',
                'Pelanggaran SLA',
                'Rata-rata Rating CSAT (1-5)',
                'Jumlah Ulasan CSAT'
            ]);

            foreach ($operators as $op) {
                $stat = $statsByAdmin->get($op->id);
                $total = (int) ($stat->total_tickets ?? 0);
                $active = (int) ($stat->active_tickets ?? 0);
                $solved = (int) ($stat->solved_tickets ?? 0);
                $rejected = (int) ($stat->rejected_tickets ?? 0);
                $totalResolved = (int) ($stat->total_resolved ?? 0);
                $breaches = (int) ($stat->resolution_breaches ?? 0);
                $compliance = $totalResolved > 0
                    ? round((($totalResolved - $breaches) / $totalResolved) * 100, 1) . '%'
                    : ($total > 0 ? '100%' : '100%');
                $rating = $stat && $stat->avg_rating !== null ? $stat->avg_rating : '-';
                $reviews = (int) ($stat->total_reviews ?? 0);
                $units = $op->units->pluck('nama_unit')->join(', ') ?: '-';

                fputcsv($handle, [
                    $op->name,
                    $op->username,
                    $op->email,
                    $units,
                    $active,
                    $solved,
                    $rejected,
                    $total,
                    $compliance,
                    $breaches,
                    $rating,
                    $reviews,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
