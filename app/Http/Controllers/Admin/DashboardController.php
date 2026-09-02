<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Tampilkan halaman utama Dashboard Admin beserta seluruh metrik & agregasi.
     */
    public function index(Request $request): Response
    {
        // ── 1. Filter Bulan & Tahun (Nullable untuk mendukung "Semua Bulan" & "Semua Tahun") ──
        $monthInput = $request->input('month');
        $yearInput = $request->input('year');

        $month = ($monthInput !== null && $monthInput !== '') ? (int) $monthInput : null;
        $year = ($yearInput !== null && $yearInput !== '') ? (int) $yearInput : null;

        // ── 2. Ringkasan Status Tiket (Mengikuti filter bulan & tahun jika aktif) ──
        $baseQuery = Ticket::query();
        if ($year) {
            $baseQuery->whereYear('created_at', $year);
        }
        if ($month) {
            $baseQuery->whereMonth('created_at', $month);
        }

        $statusAggregates = (clone $baseQuery)->selectRaw('
            COUNT(*) as total_tickets,
            SUM(CASE WHEN status = "open" THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = "on_proses" THEN 1 ELSE 0 END) as on_proses_count,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status IN ("solve", "selesai") THEN 1 ELSE 0 END) as solve_count,
            SUM(CASE WHEN status = "reject" THEN 1 ELSE 0 END) as reject_count
        ')->first();

        $totalTickets = (int) ($statusAggregates->total_tickets ?? 0);
        $statusCounts = [
            'open' => (int) ($statusAggregates->open_count ?? 0),
            'on_proses' => (int) ($statusAggregates->on_proses_count ?? 0),
            'pending' => (int) ($statusAggregates->pending_count ?? 0),
            'solve' => (int) ($statusAggregates->solve_count ?? 0),
            'reject' => (int) ($statusAggregates->reject_count ?? 0),
        ];

        // ── 3. Top 5 Pengaju Tiket (Mengikuti filter bulan & tahun jika aktif) ──
        $topUsersQuery = User::select('users.id', 'users.username', 'org_divisi.nama_divisi')
            ->selectRaw('COUNT(tickets.id) as total_tiket')
            ->join('tickets', 'users.id', '=', 'tickets.user_id')
            ->leftJoin('org_divisi', 'users.divisi_id', '=', 'org_divisi.id');

        if ($year) {
            $topUsersQuery->whereYear('tickets.created_at', $year);
        }
        if ($month) {
            $topUsersQuery->whereMonth('tickets.created_at', $month);
        }

        $topUsers = $topUsersQuery->groupBy('users.id', 'users.username', 'org_divisi.nama_divisi')
            ->orderByDesc('total_tiket')
            ->limit(5)
            ->get()
            ->map(fn($u) => [
                'id' => (int) $u->id,
                'username' => (string) ($u->username ?? '-'),
                'nama_divisi' => (string) ($u->nama_divisi ?? '-'),
                'total_tiket' => (int) $u->total_tiket,
            ])
            ->toArray();

        // ── 4. Tiket Perlu Ditindak Lanjuti (Open & Pending, ikut filter periode dashboard, maks 10) ──
        $followUpQuery = Ticket::query()
            ->whereIn('status', ['open', 'pending']);

        if ($year) {
            $followUpQuery->whereYear('created_at', $year);
        }
        if ($month) {
            $followUpQuery->whereMonth('created_at', $month);
        }

        // Hanya eager load kolom yang dibutuhkan UI; sub_unit distrukturkan konsisten untuk React
        $followUpTickets = $followUpQuery
            ->with([
                'user:id,username',
                'unit:id,nama_unit',
                'subUnit:id,unit_id,nama_layanan'
            ])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($ticket) {
                return [
                    'id' => (int) $ticket->id,
                    'status' => (string) $ticket->status,
                    'created_at' => $ticket->created_at?->toIso8601String(),
                    'user' => $ticket->user ? [
                        'id' => (int) $ticket->user->id,
                        'username' => (string) $ticket->user->username,
                    ] : null,
                    'unit' => $ticket->unit ? [
                        'id' => (int) $ticket->unit->id,
                        'nama_unit' => (string) $ticket->unit->nama_unit,
                    ] : null,
                    'sub_unit' => $ticket->subUnit ? [
                        'id' => (int) $ticket->subUnit->id,
                        'nama_layanan' => (string) $ticket->subUnit->nama_layanan,
                    ] : null,
                ];
            })
            ->values()
            ->toArray();

        // ── 5. Master Unit Aktif & Lookup Nama Unit ──
        $unitsList = Unit::where('aktif', true)->orderBy('nama_unit')->get();
        $units = $unitsList->map(fn($u) => [
            'id' => (int) $u->id,
            'nama_unit' => (string) $u->nama_unit,
            'aktif' => (bool) $u->aktif,
        ])->values()->toArray();

        // Lookup map [unit_id (int) => nama_unit (string)]
        $unitNames = $unitsList->pluck('nama_unit', 'id')->mapWithKeys(fn($name, $id) => [(int) $id => (string) $name]);

        // ── 6. Grafik Bulanan (Januari–Desember) — Cached 60 Detik per Tahun ──
        // Jika filter tahun tidak dipilih ("Semua Tahun"), fallback ke tahun berjalan (current year)
        // agar grafik bulanan tidak kosong saat dashboard pertama kali dimuat.
        $chartYear = $year ?: (int) date('Y');
        $monthlyCacheKey = "admin_dashboard_monthly_{$chartYear}";

        $monthlyChartData = Cache::remember($monthlyCacheKey, 60, function () use ($chartYear, $unitNames) {
            $monthlyRaw = Ticket::selectRaw('MONTH(created_at) as bulan, unit_id, COUNT(*) as total')
                ->whereYear('created_at', $chartYear)
                ->groupBy('bulan', 'unit_id')
                ->get();

            // Optimasi O(1) key lookup: [bulan][unit_id] => total
            $monthlyLookup = [];
            foreach ($monthlyRaw as $r) {
                $b = (int) $r->bulan;
                $uId = (int) $r->unit_id;
                $monthlyLookup[$b][$uId] = (int) $r->total;
            }

            return collect(range(1, 12))->map(function ($b) use ($monthlyLookup, $unitNames) {
                $row = ['bulan' => date('M', mktime(0, 0, 0, $b, 1))];
                foreach ($unitNames as $id => $name) {
                    $row[$name] = $monthlyLookup[$b][$id] ?? 0;
                }
                return $row;
            })->toArray();
        });

        // ── 7. Grafik Tahunan — Cached 60 Detik ──
        $yearlyChartData = Cache::remember('admin_dashboard_yearly', 60, function () use ($unitNames) {
            $yearlyRaw = Ticket::selectRaw('YEAR(created_at) as tahun, unit_id, COUNT(*) as total')
                ->groupBy('tahun', 'unit_id')
                ->get();

            // Optimasi O(1) key lookup: [tahun][unit_id] => total
            $yearlyLookup = [];
            $allYears = [];
            foreach ($yearlyRaw as $r) {
                $t = (string) $r->tahun;
                $uId = (int) $r->unit_id;
                $yearlyLookup[$t][$uId] = (int) $r->total;
                $allYears[$t] = true;
            }
            ksort($allYears);

            $result = [];
            foreach (array_keys($allYears) as $tahun) {
                $row = ['tahun' => (string) $tahun];
                foreach ($unitNames as $id => $name) {
                    $row[$name] = $yearlyLookup[$tahun][$id] ?? 0;
                }
                $result[] = $row;
            }

            return $result;
        });

        // ── 8. Grafik per Sub Unit (Mengikuti filter bulan & tahun) ──
        $subUnitQuery = Ticket::selectRaw('tickets.unit_id, tickets.sub_unit_id, sub_units.nama_layanan, COUNT(tickets.id) as total')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->whereNotNull('tickets.sub_unit_id');

        if ($year) {
            $subUnitQuery->whereYear('tickets.created_at', $year);
        }
        if ($month) {
            $subUnitQuery->whereMonth('tickets.created_at', $month);
        }

        $subUnitRaw = $subUnitQuery
            ->groupBy('tickets.unit_id', 'tickets.sub_unit_id', 'sub_units.nama_layanan')
            ->get();

        $subUnitChartData = [];
        // Inisialisasi slot untuk seluruh unit aktif agar dropdown aman dari undefined
        foreach ($units as $u) {
            $subUnitChartData[(string) $u['id']] = [];
        }

        foreach ($subUnitRaw->groupBy('unit_id') as $unitId => $items) {
            $subUnitChartData[(string) $unitId] = $items->map(fn($i) => [
                'name' => (string) ($i->nama_layanan ?? 'Unknown'),
                'value' => (int) $i->total,
            ])->values()->toArray();
        }

        // Agregat lintas seluruh unit
        $subUnitChartData['_all'] = $subUnitRaw
            ->groupBy('sub_unit_id')
            ->map(fn($items) => [
                'name' => (string) ($items->first()->nama_layanan ?? 'Unknown'),
                'value' => (int) $items->sum('total'),
            ])->values()->toArray();

        // ── 9. Top 5 User Sepanjang Waktu (Cached 60s) ──
        $topUsersAll = Cache::remember('admin_dashboard_top_users_all', 60, function () {
            return DB::table('tickets')
                ->join('users', 'tickets.user_id', '=', 'users.id')
                ->select('users.username', DB::raw('COUNT(*) as total_tiket'))
                ->groupBy('users.id', 'users.username')
                ->orderByDesc('total_tiket')
                ->limit(5)
                ->get()
                ->map(fn($u) => [
                    'username' => (string) $u->username,
                    'total_tiket' => (int) $u->total_tiket,
                ])
                ->toArray();
        });

        // ── 10. Tren CSAT 12 Bulan Terakhir (Cached 60s) ──
        $csatTrend = Cache::remember('admin_dashboard_csat_trend_12m', 60, function () {
            return DB::table('csats')
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as bulan"),
                    DB::raw('ROUND(AVG(rating), 2) as rata_rata'),
                    DB::raw('COUNT(*) as total')
                )
                ->where('created_at', '>=', now()->subYear())
                ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
                ->orderBy('bulan')
                ->get()
                ->map(fn($c) => [
                    'bulan' => (string) $c->bulan,
                    'rata_rata' => (float) $c->rata_rata,
                    'total' => (int) $c->total,
                ])
                ->toArray();
        });

        // ── 11. Tiket Bulanan 12 Bulan Terakhir (Cached 60s) ──
        $tiketBulanan = Cache::remember('admin_dashboard_ticket_monthly_trend_12m', 60, function () {
            return DB::table('tickets')
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as bulan"),
                    DB::raw('COUNT(*) as total'),
                    DB::raw("SUM(CASE WHEN status IN ('Selesai', 'Solve', 'selesai', 'solve') THEN 1 ELSE 0 END) as selesai"),
                    DB::raw("SUM(CASE WHEN status NOT IN ('Selesai', 'Solve', 'selesai', 'solve') THEN 1 ELSE 0 END) as aktif")
                )
                ->where('created_at', '>=', now()->subYear())
                ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
                ->orderBy('bulan')
                ->get()
                ->map(fn($t) => [
                    'bulan' => (string) $t->bulan,
                    'total' => (int) $t->total,
                    'selesai' => (int) $t->selesai,
                    'aktif' => (int) $t->aktif,
                ])
                ->toArray();
        });

        // ── 12. Data Kepatuhan SLA ──
        // Sinkronisasi: otomatis mengikuti filter bulan/tahun dashboard jika dipilih
        $defaultSlaPeriod = ($year && $month) ? sprintf('%04d-%02d', $year, $month) : now()->format('Y-m');
        $slaPeriod = (string) $request->input('sla_period', $defaultSlaPeriod);
        $slaUnitIdInput = $request->input('sla_unit_id');
        $slaUnitId = ($slaUnitIdInput !== null && $slaUnitIdInput !== '') ? (int) $slaUnitIdInput : null;

        $slaQuery = TicketSlaTracking::query()
            ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->join('units', 'sub_units.unit_id', '=', 'units.id');

        // Gunakan $slaPeriod pada $slaQuery secara konsisten
        if (strlen($slaPeriod) === 7) {
            $slaYear = (int) substr($slaPeriod, 0, 4);
            $slaMonth = (int) substr($slaPeriod, 5, 2);
            $slaQuery->whereYear('tickets.created_at', $slaYear)
                     ->whereMonth('tickets.created_at', $slaMonth);
        }

        if ($slaUnitId) {
            $slaQuery->where('units.id', $slaUnitId);
        }

        $slaAggregates = (clone $slaQuery)->selectRaw('
            COUNT(*) as total_all,
            SUM(CASE WHEN ticket_sla_tracking.responded_at IS NOT NULL AND ticket_sla_tracking.is_response_breached = 0 THEN 1 ELSE 0 END) as responded_on_time,
            SUM(CASE WHEN ticket_sla_tracking.responded_at IS NOT NULL OR ticket_sla_tracking.is_response_breached = 1 THEN 1 ELSE 0 END) as response_evaluated,
            SUM(CASE WHEN ticket_sla_tracking.resolved_at IS NOT NULL AND ticket_sla_tracking.is_resolution_breached = 0 THEN 1 ELSE 0 END) as resolved_on_time,
            SUM(CASE WHEN ticket_sla_tracking.resolved_at IS NOT NULL OR ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_evaluated,
            SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 1 THEN 1 ELSE 0 END) as response_breach,
            SUM(CASE WHEN ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breach,
            SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 1 OR ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as total_unique_breach,
            SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 0 AND ticket_sla_tracking.is_resolution_breached = 0 THEN 1 ELSE 0 END) as within_sla,
            SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 1 AND ticket_sla_tracking.is_resolution_breached = 0 THEN 1 ELSE 0 END) as response_breach_only,
            SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 0 AND ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breach_only,
            SUM(CASE WHEN ticket_sla_tracking.is_response_breached = 1 AND ticket_sla_tracking.is_resolution_breached = 1 THEN 1 ELSE 0 END) as both_breach,
            SUM(CASE WHEN ticket_sla_tracking.resolved_at IS NULL 
                          AND ticket_sla_tracking.is_resolution_breached = 0 
                          AND ticket_sla_tracking.sla_resolution_deadline > ? 
                          AND ticket_sla_tracking.sla_resolution_deadline <= ? 
                     THEN 1 ELSE 0 END) as total_warning
        ', [now(), now()->addDay()])->first();

        $totalAll = (int) ($slaAggregates->total_all ?? 0);
        $respondedOnTime = (int) ($slaAggregates->responded_on_time ?? 0);
        $responseEvaluated = (int) ($slaAggregates->response_evaluated ?? 0);
        $resolvedOnTime = (int) ($slaAggregates->resolved_on_time ?? 0);
        $resolutionEvaluated = (int) ($slaAggregates->resolution_evaluated ?? 0);
        $totalBreach = (int) ($slaAggregates->total_unique_breach ?? 0);
        $totalWarning = (int) ($slaAggregates->total_warning ?? 0);

        // Kepatuhan SLA dihitung berdasarkan tiket yang telah dievaluasi statusnya:
        // (tiket tepat waktu / total tiket dievaluasi) * 100.
        // Tiket yang belum mencapai deadline respon/resolusi tidak menurunkan skor sebelum jatuh tempo.
        $responseCompliance = $responseEvaluated > 0
            ? (float) min(100.0, max(0.0, round(($respondedOnTime / $responseEvaluated) * 100, 1)))
            : 100.0;

        $resolutionCompliance = $resolutionEvaluated > 0
            ? (float) min(100.0, max(0.0, round(($resolvedOnTime / $resolutionEvaluated) * 100, 1)))
            : 100.0;

        // Distribusi Kepatuhan SLA (Mutually Exclusive — total elemen sama persis dengan $totalAll)
        $slaPieChartData = [
            ['name' => 'Dalam SLA', 'value' => (int) ($slaAggregates->within_sla ?? 0)],
            ['name' => 'Pelanggaran Respon', 'value' => (int) ($slaAggregates->response_breach_only ?? 0)],
            ['name' => 'Pelanggaran Penyelesaian', 'value' => (int) ($slaAggregates->resolution_breach_only ?? 0)],
            ['name' => 'Pelanggaran Keduanya', 'value' => (int) ($slaAggregates->both_breach ?? 0)],
        ];

        // Kepatuhan SLA per Unit (Bulan Ini / Periode Terpilih) — Cached 60s
        $slaBarCacheKey = "admin_dashboard_sla_bar_" . md5($slaPeriod . '_' . ($slaUnitId ?? 'all'));
        $slaBarChartData = Cache::remember($slaBarCacheKey, 60, function () use ($slaPeriod, $slaUnitId) {
            $query = DB::table('ticket_sla_tracking')
                ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
                ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
                ->join('units', 'sub_units.unit_id', '=', 'units.id')
                ->select(
                    'units.nama_unit as unit_nama',
                    DB::raw('COUNT(*) as total'),
                    DB::raw('SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) as dalam_sla'),
                    DB::raw('SUM(CASE WHEN is_response_breached = 1 OR is_resolution_breached = 1 THEN 1 ELSE 0 END) as breach')
                );

            if (strlen($slaPeriod) === 7) {
                $query->whereYear('tickets.created_at', substr($slaPeriod, 0, 4))
                      ->whereMonth('tickets.created_at', substr($slaPeriod, 5, 2));
            }

            if ($slaUnitId) {
                $query->where('units.id', $slaUnitId);
            }

            return $query->groupBy('units.id', 'units.nama_unit')
                ->get()
                ->map(fn($row) => [
                    'unit_nama' => (string) $row->unit_nama,
                    'total' => (int) $row->total,
                    'dalam_sla' => (int) $row->dalam_sla,
                    'breach' => (int) $row->breach,
                ])
                ->toArray();
        });

        // Tren Kepatuhan SLA 12 Bulan Terakhir (Cached 60s)
        $slaTrendData = Cache::remember('admin_dashboard_sla_trend_12m', 60, function () {
            return DB::table('ticket_sla_tracking')
                ->join('tickets', 'ticket_sla_tracking.ticket_id', '=', 'tickets.id')
                ->select(
                    DB::raw("DATE_FORMAT(tickets.created_at, '%Y-%m') as bulan"),
                    DB::raw('COUNT(*) as total'),
                    DB::raw('SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) as dalam_sla'),
                    DB::raw('ROUND(SUM(CASE WHEN is_response_breached = 0 AND is_resolution_breached = 0 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as persentase_sla')
                )
                ->where('tickets.created_at', '>=', now()->subYear())
                ->groupBy(DB::raw("DATE_FORMAT(tickets.created_at, '%Y-%m')"))
                ->orderBy('bulan')
                ->get()
                ->map(fn($row) => [
                    'bulan' => (string) $row->bulan,
                    'total' => (int) $row->total,
                    'dalam_sla' => (int) $row->dalam_sla,
                    'persentase_sla' => (float) $row->persentase_sla,
                ])
                ->toArray();
        });

        // ── 13. Grafik Tiket Harian (7 Hari Terakhir) ──
        $startDate = now()->subDays(6)->startOfDay();
        $dailyRaw = Ticket::selectRaw('DATE(created_at) as date, unit_id, COUNT(*) as total')
            ->where('created_at', '>=', $startDate)
            ->groupBy('date', 'unit_id')
            ->get();

        // Optimasi O(1) key lookup: [date][unit_id] => total
        $dailyLookup = [];
        foreach ($dailyRaw as $r) {
            $dailyLookup[$r->date][(int) $r->unit_id] = (int) $r->total;
        }

        $dailyChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = now()->subDays($i)->format('Y-m-d');
            $row = ['date' => $dateStr];
            foreach ($unitNames as $id => $name) {
                $row[$name] = $dailyLookup[$dateStr][$id] ?? 0;
            }
            $dailyChartData[] = $row;
        }

        // ── 14. Serialisasi Props & Render ke Inertia ──
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
            'filters' => [
                'month' => $month,
                'year' => $year,
            ],
            'slaStats' => [
                'responseCompliance' => $responseCompliance,
                'resolutionCompliance' => $resolutionCompliance,
                'totalBreach' => $totalBreach,
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
