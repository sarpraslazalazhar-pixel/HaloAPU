<?php

namespace App\Http\Controllers\Admin;

use App\Exports\LaporanTiketExport;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\SubUnit;
use App\Models\OrgDivisi;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

class LaporanTiketController extends Controller
{
    /**
     * Ambil filter params dari request — satu titik kebenaran.
     */
    private function filterParams(Request $request): array
    {
        return $request->only([
            'year', 'month', 'date_from', 'date_to',
            'unit_id', 'sub_unit_id', 'status', 'divisi_id', 'search',
        ]);
    }

    /**
     * Cakupan laporan: izin laporan penuh memakai semua tiket, penilai hanya unit/subunit yang dikoordinasikan.
     */
    private function reportTickets(Admin $admin, bool $hasReportPermission): Builder
    {
        return $hasReportPermission ? Ticket::query() : Ticket::forCoordinatedServices($admin);
    }

    private function workloadQueue(Admin $admin): Builder
    {
        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin']);

        return $this->reportTickets($admin, $isSuperAdmin)->awaitingWorkloadAssessment();
    }

    public function workloadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->workloadQueue($request->user('admin'))->count()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $filters = $this->filterParams($request);

        $admin = $request->user('admin');
        $hasReport = $admin->can('akses-laporan');

        $showWorkloadQueue = $request->input('workload') === 'unassessed';
        $summaryYear = date('Y');
        if ($showWorkloadQueue) {
            abort_unless($admin->can('assess-ticket-workload'), 403);
            $filters['workload'] = 'unassessed';
        } else {
            $filters['year'] ??= $summaryYear;
        }

        // References for Dropdowns
        $units = Unit::aktif()->orderBy('nama_unit')->get();
        $subUnits = !empty($filters['unit_id']) ? SubUnit::where('unit_id', $filters['unit_id'])->orderBy('nama_layanan')->get() : [];
        $divisiList = OrgDivisi::orderBy('nama_divisi')->get();

        // Base Query with shared scope
        $baseQuery = $this->reportTickets($admin, $hasReport)
            ->laporanFilter(array_replace($filters, ['year' => $filters['year'] ?? $summaryYear]));

        // 1. Status Counts
        $statusAggregates = (clone $baseQuery)->selectRaw('
            COUNT(*) as total_tickets,
            SUM(CASE WHEN status = "open" THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = "on_proses" THEN 1 ELSE 0 END) as on_proses_count,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN LOWER(tickets.status) IN ("solve", "selesai") THEN 1 ELSE 0 END) as solve_count,
            SUM(CASE WHEN status = "reject" THEN 1 ELSE 0 END) as reject_count,
            SUM(CASE WHEN status = "dibatalkan" THEN 1 ELSE 0 END) as dibatalkan_count
        ')->first();

        $totalTickets = (int) ($statusAggregates->total_tickets ?? 0);
        $statusCounts = [
            'open' => (int) ($statusAggregates->open_count ?? 0),
            'on_proses' => (int) ($statusAggregates->on_proses_count ?? 0),
            'pending' => (int) ($statusAggregates->pending_count ?? 0),
            'solve' => (int) ($statusAggregates->solve_count ?? 0),
            'reject' => (int) ($statusAggregates->reject_count ?? 0),
            'dibatalkan' => (int) ($statusAggregates->dibatalkan_count ?? 0),
        ];

        // 2. SLA Compliance Stats
        $slaQuery = (clone $baseQuery)
            ->join('ticket_sla_tracking', 'tickets.id', '=', 'ticket_sla_tracking.ticket_id')
            ->selectRaw('
                COUNT(*) as total_all,
                SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) as total_resolved,
                SUM(CASE WHEN responded_at IS NOT NULL THEN 1 ELSE 0 END) as total_responded,
                SUM(CASE WHEN is_response_breached = 1 THEN 1 ELSE 0 END) as response_breach,
                SUM(CASE WHEN is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breach
            ')->first();

        $totalSlaAll = (int) ($slaQuery->total_all ?? 0);
        $totalResolved = (int) ($slaQuery->total_resolved ?? 0);
        $totalResponded = (int) ($slaQuery->total_responded ?? 0);
        $responseBreach = (int) ($slaQuery->response_breach ?? 0);
        $resolutionBreach = (int) ($slaQuery->resolution_breach ?? 0);

        $responseCompliance = $totalResponded > 0
            ? round((($totalResponded - $responseBreach) / $totalResponded) * 100, 1)
            : ($totalSlaAll > 0 ? 0 : 100);

        $resolutionCompliance = $totalResolved > 0
            ? round((($totalResolved - $resolutionBreach) / $totalResolved) * 100, 1)
            : ($totalSlaAll > 0 ? 0 : 100);

        $slaPieChartData = [
            ['name' => 'Dalam SLA', 'value' => max(0, $totalSlaAll - $responseBreach - $resolutionBreach)],
            ['name' => 'Pelanggaran Respon', 'value' => $responseBreach],
            ['name' => 'Pelanggaran Penyelesaian', 'value' => $resolutionBreach],
        ];

        $slaStats = [
            'responseCompliance' => $responseCompliance,
            'resolutionCompliance' => $resolutionCompliance,
            'totalBreach' => $responseBreach + $resolutionBreach,
            'totalAll' => $totalSlaAll,
        ];

        // 3. ECharts Data: Trend Bulanan
        $monthlyRaw = (clone $baseQuery)->selectRaw('SUBSTR(tickets.created_at, 6, 2) as bulan, COUNT(*) as total')
            ->groupBy('bulan')
            ->get();
        $monthlyTrend = collect(range(1, 12))->map(function ($b) use ($monthlyRaw) {
            return [
                'bulan' => date('M', mktime(0, 0, 0, $b, 1)),
                'total' => $monthlyRaw->first(fn ($row) => (int) $row->bulan === $b)?->total ?? 0
            ];
        });

        // 4. ECharts Data: Distribusi Tiket per Unit
        $ticketsByUnitRaw = (clone $baseQuery)->selectRaw('tickets.unit_id, units.nama_unit, COUNT(tickets.id) as total')
            ->join('units', 'tickets.unit_id', '=', 'units.id')
            ->groupBy('tickets.unit_id', 'units.nama_unit')
            ->get();

        $ticketsByUnit = $ticketsByUnitRaw->map(fn ($item) => [
            'name' => $item->nama_unit,
            'value' => $item->total,
        ]);

        // Tiket per layanan
        $ticketsByLayanan = (clone $baseQuery)->selectRaw('sub_units.nama_layanan as layanan, count(tickets.id) as count')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->groupBy('sub_units.id', 'sub_units.nama_layanan')
            ->orderByDesc('count')
            ->get();

        // 5. Paginated Tickets
        $canAssess = $admin->can('assess-ticket-workload');
        $listQuery = $showWorkloadQueue
            ? $this->workloadQueue($admin)->laporanFilter($filters)
            : clone $baseQuery;

        $relations = ['user.divisi', 'createdByAdmin', 'subUnit.unit', 'slaTracking'];
        if ($canAssess) {
            $relations[] = 'workloadAssessment.assessor:id,name,username';
            $relations[] = 'assignedAdmin:id,name,username';
        }

        $tickets = $listQuery->with($relations)
            ->latest('tickets.created_at')
            ->paginate(15)
            ->withQueryString();

        if ($showWorkloadQueue && $tickets->currentPage() > $tickets->lastPage()) {
            return redirect()->route('admin.laporan.tiket', array_filter(
                [...$filters, 'page' => max(1, $tickets->lastPage())],
                fn ($v) => $v !== null && $v !== ''
            ));
        }

        return Inertia::render('Admin/Laporan/Tiket', [
            'filters' => $filters,
            'canExport' => $hasReport,
            'units' => $units,
            'subUnits' => $subUnits,
            'divisiList' => $divisiList,
            'totalTickets' => $totalTickets,
            'statusCounts' => $statusCounts,
            'slaStats' => $slaStats,
            'slaPieChartData' => $slaPieChartData,
            'monthlyTrend' => $monthlyTrend,
            'ticketsByUnit' => $ticketsByUnit,
            'ticketsByStatus' => [],
            'ticketsByLayanan' => $ticketsByLayanan,
            'tickets' => $tickets,
        ]);
    }

    public function saveWorkloadAssessment(Request $request, Ticket $ticket): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin->can('assess-ticket-workload'), 403);

        $scoped = $this->reportTickets($admin, $admin->can('akses-laporan'))->whereKey($ticket->getKey());

        DB::transaction(function () use ($scoped, $request, $admin) {
            $locked = $scoped->lockForUpdate()->firstOrFail();

            if (! in_array(strtolower((string) $locked->status), Ticket::WORKLOAD_FINISHED_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'score' => 'Penilaian beban hanya bisa diisi setelah tiket selesai.',
                ]);
            }

            $data = $request->validate([
                'score' => 'required|integer|min:1|max:5',
                'effort_hours' => 'nullable|numeric|decimal:0,2|min:0.01|max:9999.99',
                'note' => 'nullable|string|max:1000',
            ]);

            $locked->workloadAssessment()->updateOrCreate([], [
                'score' => (int) $data['score'],
                'effort_hours' => ($data['effort_hours'] ?? '') === '' ? null : $data['effort_hours'],
                'note' => ($data['note'] ?? null) ?: null,
                'assessed_by_admin_id' => $admin->id,
            ]);
        });

        return redirect()->back(303, [], route('admin.laporan.tiket'))
            ->with('success', 'Penilaian beban tersimpan.');
    }

    public function export(Request $request)
    {
        $filters = $this->filterParams($request);
        $filters['year'] = $filters['year'] ?? date('Y');

        $admin = auth('admin')->user();
        $filename = 'laporan-tiket-' . date('Ymd-His') . '.xlsx';

        return Excel::download(new LaporanTiketExport($filters, $admin), $filename);
    }

    public function exportPdf(Request $request)
    {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);

        $filters = $this->filterParams($request);
        $filters['year'] = $filters['year'] ?? date('Y');
        $admin = auth('admin')->user();

        // Build query with same filters as index()
        $query = Ticket::query()->laporanFilter($filters);

        // Check limit
        $total = (clone $query)->count();
        if ($total > 1000) {
            return response()->json([
                'error' => 'Data terlalu banyak untuk PDF (' . $total . ' tiket). Persempit filter atau gunakan Export Excel.'
            ], 422);
        }

        // Get ticket data with eager load
        $tickets = (clone $query)
            ->with(['user.divisi', 'createdByAdmin', 'subUnit.unit', 'slaTracking'])
            ->latest('tickets.created_at')
            ->get();

        // Status counts
        $statusAggregates = (clone $query)->selectRaw('
            COUNT(*) as total_tickets,
            SUM(CASE WHEN status = "open" THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = "on_proses" THEN 1 ELSE 0 END) as on_proses_count,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status IN ("solve", "selesai") THEN 1 ELSE 0 END) as solve_count,
            SUM(CASE WHEN status = "reject" THEN 1 ELSE 0 END) as reject_count,
            SUM(CASE WHEN status = "dibatalkan" THEN 1 ELSE 0 END) as dibatalkan_count
        ')->first();

        $statusCounts = [
            'open' => (int) ($statusAggregates->open_count ?? 0),
            'on_proses' => (int) ($statusAggregates->on_proses_count ?? 0),
            'pending' => (int) ($statusAggregates->pending_count ?? 0),
            'solve' => (int) ($statusAggregates->solve_count ?? 0),
            'reject' => (int) ($statusAggregates->reject_count ?? 0),
            'dibatalkan' => (int) ($statusAggregates->dibatalkan_count ?? 0),
        ];

        // SLA stats
        $slaQuery = (clone $query)
            ->join('ticket_sla_tracking', 'tickets.id', '=', 'ticket_sla_tracking.ticket_id')
            ->selectRaw('
                COUNT(*) as total_all,
                SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) as total_resolved,
                SUM(CASE WHEN responded_at IS NOT NULL THEN 1 ELSE 0 END) as total_responded,
                SUM(CASE WHEN is_response_breached = 1 THEN 1 ELSE 0 END) as response_breach,
                SUM(CASE WHEN is_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breach
            ')->first();

        $totalSlaAll = (int) ($slaQuery->total_all ?? 0);
        $totalResolved = (int) ($slaQuery->total_resolved ?? 0);
        $totalResponded = (int) ($slaQuery->total_responded ?? 0);
        $responseBreach = (int) ($slaQuery->response_breach ?? 0);
        $resolutionBreach = (int) ($slaQuery->resolution_breach ?? 0);

        $responseCompliance = $totalResponded > 0
            ? round((($totalResponded - $responseBreach) / $totalResponded) * 100, 1)
            : ($totalSlaAll > 0 ? 0 : 100);

        $resolutionCompliance = $totalResolved > 0
            ? round((($totalResolved - $resolutionBreach) / $totalResolved) * 100, 1)
            : ($totalSlaAll > 0 ? 0 : 100);

        $slaStats = [
            'responseCompliance' => $responseCompliance,
            'resolutionCompliance' => $resolutionCompliance,
        ];

        // Build filter display string
        $filterParts = [];
        if (!empty($filters['unit_id'])) {
            $unit = Unit::find($filters['unit_id']);
            if ($unit) $filterParts[] = "Unit = {$unit->nama_unit}";
        }
        if (!empty($filters['status'])) {
            $statusLabel = is_array($filters['status']) ? implode(', ', $filters['status']) : $filters['status'];
            $filterParts[] = "Status = {$statusLabel}";
        }
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $dateStr = ($filters['date_from'] ?? '*') . ' - ' . ($filters['date_to'] ?? '*');
            $filterParts[] = "Tanggal = {$dateStr}";
        }
        if (!empty($filters['search'])) {
            $filterParts[] = "Kata kunci = {$filters['search']}";
        }
        $filterPrint = implode(' | ', $filterParts) ?: null;

        // Build period display string
        $year = $filters['year'] ?? date('Y');
        $periodePrint = null;
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $from = $filters['date_from'] ? \Carbon\Carbon::createFromFormat('Y-m-d', $filters['date_from'])->format('d M Y') : '01 Jan ' . $year;
            $to = $filters['date_to'] ? \Carbon\Carbon::createFromFormat('Y-m-d', $filters['date_to'])->format('d M Y') : '31 Dec ' . $year;
            $periodePrint = "{$from} - {$to}";
        }

        // Get logo as base64
        $logo = null;
        try {
            $logoPath = public_path('storage/logo.png');
            if (File::exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logo = 'data:image/png;base64,' . base64_encode($logoData);
            }
        } catch (\Exception $e) {
            // Ignore logo error, PDF will render without it
        }

        // Generate PDF
        $pdf = Pdf::loadView('exports.laporan-tiket-pdf', [
            'tickets' => $tickets,
            'totalTickets' => $total,
            'statusCounts' => $statusCounts,
            'slaStats' => $slaStats,
            'filterPrint' => $filterPrint,
            'periodePrint' => $periodePrint,
            'logo' => $logo,
            'userName' => $admin->name ?? $admin->username,
        ])->setOption([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        $filename = 'laporan-tiket-' . date('Ymd-His') . '.pdf';
        return $pdf->download($filename);
    }
}
