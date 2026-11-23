<?php

namespace App\Http\Controllers\Admin;

use App\Exports\LaporanKinerjaOperatorExport;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Unit;
use App\Services\OperatorPerformanceReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class LaporanOperatorController extends Controller
{
    public function __construct(
        private OperatorPerformanceReportService $reportService,
    ) {}

    public function index(Request $request)
    {
        $report = $this->reportService->buildReport(
            $request->only(['year', 'month', 'date_from', 'date_to', 'unit_id', 'sub_unit_id', 'search'])
        );

        $filters = $report['filters'];
        $unitId = $filters['unit_id'];

        $units = Unit::aktif()->orderBy('nama_unit')->get(['id', 'nama_unit']);
        $subUnits = $unitId
            ? SubUnit::where('unit_id', $unitId)->orderBy('nama_layanan')->get(['id', 'unit_id', 'nama_layanan'])
            : [];

        $summary = $report['summary'];

        return Inertia::render('Admin/Laporan/KinerjaOperator', [
            'filters' => [
                'year' => $filters['year'],
                'month' => $filters['month'],
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'unit_id' => $filters['unit_id'],
                'sub_unit_id' => $filters['sub_unit_id'],
                'search' => $filters['search'],
            ],
            'units' => $units,
            'subUnits' => $subUnits,
            'operators' => $report['operators'],
            'summary' => [
                'totalOperators' => $summary['totalOperators'],
                'activeOperators' => $summary['activeOperators'],
                'totalAssigned' => $summary['totalAssigned'],
                'totalActive' => $summary['totalActive'],
                'totalSolved' => $summary['totalSolved'],
                'totalRejected' => $summary['totalRejected'],
                'totalReviews' => $summary['totalReviews'],
                'avgActivePerOperator' => $summary['avgActivePerOperator'],
                'overallAvgRating' => $summary['overallAvgRating'],
                'avgCompliance' => $summary['avgCompliance'],
            ],
            'workloadChartData' => $report['workloadChartData'],
            'performanceChartData' => $report['performanceChartData'],
        ]);
    }

    public function ulasan(Admin $admin, Request $request)
    {
        $reviews = $this->reportService->reviewsForAdmin(
            $admin,
            $request->only(['year', 'month', 'date_from', 'date_to', 'unit_id', 'sub_unit_id'])
        );

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'username' => $admin->username,
            ],
            'reviews' => $reviews,
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->only(['year', 'month', 'date_from', 'date_to', 'unit_id', 'sub_unit_id', 'search']);
        $admin = auth('admin')->user();
        $filename = 'laporan-kinerja-individu-' . date('Ymd-His') . '.xlsx';

        return Excel::download(
            new LaporanKinerjaOperatorExport($filters, $admin, $this->reportService),
            $filename
        );
    }

    public function exportPdf(Request $request)
    {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);

        $report = $this->reportService->buildReport(
            $request->only(['year', 'month', 'date_from', 'date_to', 'unit_id', 'sub_unit_id', 'search'])
        );
        $filters = $report['filters'];
        $summary = $report['summary'];
        $admin = auth('admin')->user();

        $operatorData = $report['operators']->map(fn (array $op) => [
            'name' => $op['name'],
            'username' => $op['username'],
            'units' => implode(', ', $op['units']),
            'active_tickets' => $op['active_tickets'],
            'solved_tickets' => $op['solved_tickets'],
            'rejected_tickets' => $op['rejected_tickets'],
            'total_tickets' => $op['total_tickets'],
            'resolution_compliance' => $op['resolution_compliance'],
            'resolution_breaches' => $op['resolution_breaches'],
            'avg_rating' => $op['avg_rating'],
            'total_reviews' => $op['total_reviews'],
        ])->values();

        $periodePrint = $this->reportService->buildPeriodeLabel($filters);
        $filterPrint = $this->reportService->buildFilterLabel($filters, 'Unit');

        $logo = null;
        try {
            $logoPath = public_path('storage/logo.png');
            if (File::exists($logoPath)) {
                $logo = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
            }
        } catch (\Exception $e) {
        }

        $pdf = Pdf::loadView('exports.laporan-kinerja-operator-pdf', [
            'operators' => $operatorData,
            'summary' => [
                'totalOperators' => $summary['totalOperators'],
                'totalTickets' => $summary['totalAssigned'],
                'totalSolved' => $summary['totalSolved'],
                'totalActive' => $summary['totalActive'],
                'avgCompliance' => $summary['avgCompliance'],
                'avgRating' => $summary['overallAvgRating'],
            ],
            'totals' => [
                'active' => $summary['totalActive'],
                'solved' => $summary['totalSolved'],
                'rejected' => $summary['totalRejected'],
                'total' => $summary['totalAssigned'],
                'avgCompliance' => $summary['avgCompliance'],
                'breaches' => $summary['totalResolutionBreaches'],
                'avgRating' => $summary['overallAvgRating'],
                'reviews' => $summary['totalReviews'],
            ],
            'periodePrint' => $periodePrint,
            'filterPrint' => $filterPrint,
            'logo' => $logo,
            'userName' => $admin->name ?? $admin->username,
        ])->setOption([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        $filename = 'laporan-kinerja-individu-' . date('Ymd-His') . '.pdf';

        return $pdf->download($filename);
    }
}
