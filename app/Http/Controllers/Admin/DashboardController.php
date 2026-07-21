<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->input('month', date('n'));
        $year = $request->input('year', date('Y'));

        $baseQuery = Ticket::query();
        if ($year) $baseQuery->whereYear('created_at', $year);
        if ($month) $baseQuery->whereMonth('created_at', $month);

        $totalTickets = (clone $baseQuery)->count();
        $statusCounts = [
            'open' => (clone $baseQuery)->where('status', 'open')->count(),
            'on_proses' => (clone $baseQuery)->where('status', 'on_proses')->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'solve' => (clone $baseQuery)->where('status', 'solve')->count(),
            'reject' => (clone $baseQuery)->where('status', 'reject')->count(),
        ];

        $topUsers = User::select('users.id', 'users.username', 'org_divisi.nama_divisi')
            ->selectRaw('COUNT(tickets.id) as total_tiket')
            ->leftJoin('tickets', 'users.id', '=', 'tickets.user_id')
            ->leftJoin('org_divisi', 'users.divisi_id', '=', 'org_divisi.id');
        if ($year) $topUsers->whereYear('tickets.created_at', $year);
        if ($month) $topUsers->whereMonth('tickets.created_at', $month);
        $topUsers = $topUsers->groupBy('users.id', 'users.username', 'org_divisi.nama_divisi')
            ->orderByDesc('total_tiket')
            ->limit(5)
            ->get();

        $followUpTickets = Ticket::with(['user.divisi', 'unit', 'subUnit'])
            ->whereIn('status', ['open', 'pending'])
            ->latest()
            ->limit(10)
            ->get();

        $units = Unit::where('aktif', true)->orderBy('nama_unit')->get();
        $unitNames = $units->pluck('nama_unit', 'id');

        // Monthly chart — only if year is selected
        $monthlyChartData = [];
        if ($year) {
            $monthlyRaw = Ticket::selectRaw('MONTH(created_at) as bulan, unit_id, COUNT(*) as total')
                ->whereYear('created_at', $year)
                ->groupBy('bulan', 'unit_id')
                ->with('unit')
                ->get();

            $monthlyChartData = collect(range(1, 12))->map(function ($b) use ($monthlyRaw, $unitNames) {
                $row = ['bulan' => date('M', mktime(0, 0, 0, $b, 1))];
                foreach ($unitNames as $id => $name) {
                    $row[$name] = $monthlyRaw->firstWhere(fn($r) => $r->bulan == $b && $r->unit_id == $id)?->total ?? 0;
                }
                return $row;
            });
        }

        // Yearly chart — all years regardless of filter
        $yearlyRaw = Ticket::selectRaw('YEAR(created_at) as tahun, unit_id, COUNT(*) as total')
            ->groupBy('tahun', 'unit_id')
            ->with('unit')
            ->get();

        $yearlyChartData = $yearlyRaw
            ->groupBy('tahun')
            ->sortKeys()
            ->map(function ($items, $tahun) use ($unitNames) {
                $row = ['tahun' => (string) $tahun];
                foreach ($unitNames as $id => $name) {
                    $row[$name] = $items->firstWhere('unit_id', $id)?->total ?? 0;
                }
                return $row;
            })->values();

        // Sub unit chart — per unit + aggregate across all
        $subUnitQuery = Ticket::selectRaw('unit_id, sub_unit_id, COUNT(*) as total')
            ->whereNotNull('sub_unit_id');
        if ($year) $subUnitQuery->whereYear('created_at', $year);
        if ($month) $subUnitQuery->whereMonth('created_at', $month);
        $subUnitRaw = $subUnitQuery->groupBy('unit_id', 'sub_unit_id')
            ->with(['unit', 'subUnit'])
            ->get();

        $subUnitChartData = $subUnitRaw
            ->groupBy('unit_id')
            ->map(fn($items) => $items->map(fn($i) => [
                'name' => $i->subUnit?->nama_layanan ?? 'Unknown',
                'value' => $i->total,
            ])->values());

        // Add aggregate across all units
        $subUnitChartData['_all'] = $subUnitRaw
            ->groupBy('sub_unit_id')
            ->map(fn($items) => [
                'name' => $items->first()->subUnit?->nama_layanan ?? 'Unknown',
                'value' => $items->sum('total'),
            ])->values();

        // ── Top 5 User (all-time, using tickets table) ──
        $topUsersAll = DB::table('tickets')
            ->join('users', 'tickets.user_id', '=', 'users.id')
            ->select('users.username', DB::raw('COUNT(*) as total_tiket'))
            ->groupBy('users.id', 'users.username')
            ->orderByDesc('total_tiket')
            ->limit(5)
            ->get();

        // ── CSAT Trend (rata-rata per bulan, 12 bulan terakhir) ──
        $csatTrend = DB::table('csats')
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as bulan"),
                DB::raw('ROUND(AVG(rating), 2) as rata_rata'),
                DB::raw('COUNT(*) as total'),
            )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->orderBy('bulan')
            ->get();

        // ── Tiket Bulanan (12 bulan terakhir) ──
        $tiketBulanan = DB::table('tickets')
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as bulan"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai"),
                DB::raw("SUM(CASE WHEN status NOT IN ('Selesai', 'Solve') THEN 1 ELSE 0 END) as aktif"),
            )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->orderBy('bulan')
            ->get();

        // ── SLA Compliance Data ──
        $slaPeriod = $request->get('sla_period', now()->format('Y-m'));
        $slaUnitId = $request->get('sla_unit_id');

        $slaQuery = TicketSlaTracking::query()
            ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->join('units', 'sub_units.unit_id', '=', 'units.id');

        if ($slaUnitId) {
            $slaQuery->where('units.id', $slaUnitId);
        }

        $totalResolved = (clone $slaQuery)->whereNotNull('resolved_at')->count();
        $totalResponded = (clone $slaQuery)->whereNotNull('responded_at')->count();
        $totalAll = (clone $slaQuery)->count();

        $responseBreach = (clone $slaQuery)->where('is_response_breached', true)->count();
        $resolutionBreach = (clone $slaQuery)->where('is_resolution_breached', true)->count();
        
        $totalWarning = (clone $slaQuery)
            ->whereNull('resolved_at')
            ->where('is_resolution_breached', false)
            ->where('sla_resolution_deadline', '<=', now()->addDay())
            ->count();

        $responseCompliance = $totalResponded > 0
            ? round((($totalResponded - $responseBreach) / $totalResponded) * 100, 1)
            : 100;

        $resolutionCompliance = $totalResolved > 0
            ? round((($totalResolved - $resolutionBreach) / $totalResolved) * 100, 1)
            : 100;

        $slaPieChartData = [
            ['name' => 'Dalam SLA', 'value' => max(0, $totalAll - $responseBreach - $resolutionBreach)],
            ['name' => 'Pelanggaran Respon', 'value' => $responseBreach],
            ['name' => 'Pelanggaran Penyelesaian', 'value' => $resolutionBreach],
        ];

        $slaBarChartData = DB::table('ticket_sla_tracking')
            ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->join('units', 'sub_units.unit_id', '=', 'units.id')
            ->select(
                'units.nama_unit as unit_nama',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) as dalam_sla'),
                DB::raw('SUM(CASE WHEN is_response_breached = 1 OR is_resolution_breached = 1 THEN 1 ELSE 0 END) as breach'),
            )
            ->whereYear('tickets.created_at', substr($slaPeriod, 0, 4))
            ->whereMonth('tickets.created_at', substr($slaPeriod, 5, 2))
            ->groupBy('units.id', 'units.nama_unit')
            ->get();

        // ── SLA Trend (12 bulan) ──
        $slaTrendData = DB::table('ticket_sla_tracking')
            ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
            ->select(
                DB::raw("DATE_FORMAT(tickets.created_at, '%Y-%m') as bulan"),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) as dalam_sla'),
                DB::raw('ROUND(SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as persentase_sla'),
            )
            ->where('tickets.created_at', '>=', now()->subYear())
            ->groupBy(DB::raw("DATE_FORMAT(tickets.created_at, '%Y-%m')"))
            ->orderBy('bulan')
            ->get();

        // ── Daily Chart (7 Hari Terakhir) ──
        $startDate = now()->subDays(6)->startOfDay();
        $dailyRaw = Ticket::selectRaw('DATE(created_at) as date, unit_id, COUNT(*) as total')
            ->where('created_at', '>=', $startDate)
            ->groupBy('date', 'unit_id')
            ->get();

        $dates = collect();
        for ($i = 6; $i >= 0; $i--) {
            $dates->push(now()->subDays($i)->format('Y-m-d'));
        }

        $dailyChartData = $dates->map(function ($dateStr) use ($dailyRaw, $unitNames) {
            $row = ['date' => $dateStr];
            foreach ($unitNames as $id => $name) {
                $row[$name] = $dailyRaw->firstWhere(fn($r) => $r->date === $dateStr && $r->unit_id === $id)?->total ?? 0;
            }
            return $row;
        });

        return Inertia::render('Admin/Dashboard/Index', [
            'totalTickets' => $totalTickets,
            'statusCounts' => $statusCounts,
            'topUsers' => $topUsers,
            'topUsersAll' => $topUsersAll,
            'csatTrend' => $csatTrend,
            'tiketBulanan' => $tiketBulanan,
            'followUpTickets' => $followUpTickets,
            'monthlyChartData' => $monthlyChartData,
            'yearlyChartData' => $yearlyChartData,
            'dailyChartData' => $dailyChartData,
            'subUnitChartData' => $subUnitChartData,
            'units' => $units,
            'filters' => ['month' => $month, 'year' => $year],
            'slaStats' => [
                'responseCompliance' => $responseCompliance,
                'resolutionCompliance' => $resolutionCompliance,
                'totalBreach' => $responseBreach + $resolutionBreach,
                'totalWarning' => $totalWarning,
                'totalAll' => $totalAll,
            ],
            'slaPieChartData' => $slaPieChartData,
            'slaBarChartData' => $slaBarChartData,
            'slaTrendData' => $slaTrendData,
            'slaFilters' => [
                'period' => $slaPeriod,
                'unitId' => $slaUnitId,
            ],
        ]);
    }
}
