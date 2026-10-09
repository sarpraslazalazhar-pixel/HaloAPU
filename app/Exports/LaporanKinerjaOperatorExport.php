<?php

namespace App\Exports;

use App\Models\Admin;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\SubUnit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class LaporanKinerjaOperatorExport implements FromCollection, WithMapping, WithHeadings, WithColumnWidths, WithTitle, WithEvents, WithCustomStartCell
{
    private int $rowNumber = 0;
    private const HEADER_ROW = 6;

    public function __construct(
        private array $filters,
        private $admin,
    ) {}

    public function collection(): Collection
    {
        $year = $this->filters['year'] ?? date('Y');
        $month = $this->filters['month'] ?? null;
        $dateFrom = $this->filters['date_from'] ?? null;
        $dateTo = $this->filters['date_to'] ?? null;
        $unitId = $this->filters['unit_id'] ?? null;
        $subUnitId = $this->filters['sub_unit_id'] ?? null;
        $search = $this->filters['search'] ?? null;

        $adminQuery = Admin::with(['units:id,nama_unit'])
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

        $ticketSubQuery = Ticket::query()->whereNotNull('tickets.assigned_admin_id');

        if ($dateFrom && $dateTo) {
            $ticketSubQuery->whereBetween('tickets.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } else {
            if ($year) $ticketSubQuery->whereYear('tickets.created_at', $year);
            if ($month) $ticketSubQuery->whereMonth('tickets.created_at', $month);
        }

        if ($unitId) {
            $ticketSubQuery->where('tickets.unit_id', $unitId);
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

        return $operators->map(function ($operator) use ($statsByAdmin) {
            $stat = $statsByAdmin->get($operator->id);
            
            $total = (int) ($stat->total_tickets ?? 0);
            $active = (int) ($stat->active_tickets ?? 0);
            $solved = (int) ($stat->solved_tickets ?? 0);
            $rejected = (int) ($stat->rejected_tickets ?? 0);
            $totalResolved = (int) ($stat->total_resolved ?? 0);
            $breaches = (int) ($stat->resolution_breaches ?? 0);
            
            $compliance = $totalResolved > 0
                ? round((($totalResolved - $breaches) / $totalResolved) * 100, 1)
                : 100;
            
            $avgRating = $stat && $stat->avg_rating !== null ? (float) $stat->avg_rating : null;
            $totalReviews = (int) ($stat->total_reviews ?? 0);
            
            $operator->_stats = [
                'active' => $active,
                'solved' => $solved,
                'rejected' => $rejected,
                'total' => $total,
                'compliance' => $compliance,
                'breaches' => $breaches,
                'rating' => $avgRating,
                'reviews' => $totalReviews,
            ];
            
            return $operator;
        });
    }

    public function headings(): array
    {
        return [
            'No',
            'Nama Operator',
            'Username',
            'Email',
            'No. WhatsApp',
            'Unit Layanan',
            'Tiket Aktif',
            'Tiket Selesai',
            'Tiket Ditolak/Batal',
            'Total Tiket',
            'Kepatuhan SLA (%)',
            'Pelanggaran SLA',
            'Rating CSAT',
            'Total Ulasan',
        ];
    }

    public function map(mixed $operator): array
    {
        $this->rowNumber++;
        $stats = $operator->_stats;
        
        return [
            $this->rowNumber,
            $operator->name,
            $operator->username,
            $operator->email,
            $operator->no_wa ?? '-',
            $operator->units->pluck('nama_unit')->join(', ') ?: '-',
            $stats['active'],
            $stats['solved'],
            $stats['rejected'],
            $stats['total'],
            $stats['compliance'],
            $stats['breaches'],
            $stats['rating'] !== null ? $stats['rating'] : '-',
            $stats['reviews'],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,   // No
            'B' => 22,  // Nama
            'C' => 16,  // Username
            'D' => 24,  // Email
            'E' => 16,  // WhatsApp
            'F' => 20,  // Unit
            'G' => 12,  // Aktif
            'H' => 12,  // Selesai
            'I' => 14,  // Ditolak
            'J' => 12,  // Total
            'K' => 14,  // SLA %
            'L' => 14,  // Pelanggaran
            'M' => 12,  // Rating
            'N' => 12,  // Ulasan
        ];
    }

    public function title(): string
    {
        return 'Kinerja Operator';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = 'N';
                $headerRow = self::HEADER_ROW;
                $dataStartRow = $headerRow + 1;
                $totalDataRows = $this->rowNumber;
                $lastDataRow = $dataStartRow + $totalDataRows - 1;
                if ($totalDataRows === 0) {
                    $lastDataRow = $dataStartRow;
                }

                // Title block (rows 1-4)
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->setCellValue('A1', 'LAPORAN BEBAN & KINERJA OPERATOR');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F3864']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(30);

                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->setCellValue('A2', 'Periode: ' . $this->buildPeriodeLabel());
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['size' => 11, 'italic' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                $sheet->mergeCells("A3:{$lastCol}3");
                $sheet->setCellValue('A3', $this->buildFilterLabel());
                $sheet->getStyle('A3')->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '555555']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                $sheet->mergeCells("A4:{$lastCol}4");
                $adminName = $this->admin->name ?? $this->admin->username ?? 'Admin';
                $sheet->setCellValue('A4', 'Diunduh: ' . now()->format('d-m-Y H:i') . ' oleh ' . $adminName);
                $sheet->getStyle('A4')->applyFromArray([
                    'font' => ['size' => 9, 'color' => ['rgb' => '888888']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Header table (row 6)
                $headerRange = "A{$headerRow}:{$lastCol}{$headerRow}";
                $sheet->getStyle($headerRange)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                    ],
                ]);
                $sheet->getRowDimension($headerRow)->setRowHeight(28);

                $sheet->freezePane("C{$dataStartRow}");
                $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$headerRow}");

                // Data rows styling
                if ($totalDataRows > 0) {
                    $dataRange = "A{$dataStartRow}:{$lastCol}{$lastDataRow}";

                    $sheet->getStyle($dataRange)->applyFromArray([
                        'borders' => [
                            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                        ],
                        'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                    ]);

                    // No column center
                    $sheet->getStyle("A{$dataStartRow}:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    // Numbers right-aligned
                    foreach (['G', 'H', 'I', 'J', 'L', 'N'] as $col) {
                        $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    }

                    // Percent and rating centered
                    foreach (['K', 'M'] as $col) {
                        $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }

                    // Format percent
                    $sheet->getStyle("K{$dataStartRow}:K{$lastDataRow}")->getNumberFormat()->setFormatCode('0.0"%"');

                    // Format rating
                    $sheet->getStyle("M{$dataStartRow}:M{$lastDataRow}")->getNumberFormat()->setFormatCode('0.00');

                    // Zebra striping
                    for ($row = $dataStartRow; $row <= $lastDataRow; $row++) {
                        if (($row - $dataStartRow) % 2 === 1) {
                            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F6FC']],
                            ]);
                        }
                    }
                }

                // Total row (aggregate summary)
                $totalRow = $lastDataRow + 2;
                $collection = $this->collection();
                
                $totalOperators = $collection->count();
                $totalActive = $collection->sum(fn($o) => $o->_stats['active']);
                $totalSolved = $collection->sum(fn($o) => $o->_stats['solved']);
                $totalRejected = $collection->sum(fn($o) => $o->_stats['rejected']);
                $totalTickets = $collection->sum(fn($o) => $o->_stats['total']);
                $totalBreaches = $collection->sum(fn($o) => $o->_stats['breaches']);
                $totalReviews = $collection->sum(fn($o) => $o->_stats['reviews']);
                
                $avgCompliance = $collection->avg(fn($o) => $o->_stats['compliance']);
                $ratedOps = $collection->filter(fn($o) => $o->_stats['rating'] !== null);
                $avgRating = $ratedOps->count() > 0 ? $ratedOps->avg(fn($o) => $o->_stats['rating']) : 0;

                $sheet->setCellValue("A{$totalRow}", 'RINGKASAN');
                $sheet->setCellValue("B{$totalRow}", "Total {$totalOperators} operator");
                $sheet->setCellValue("G{$totalRow}", $totalActive);
                $sheet->setCellValue("H{$totalRow}", $totalSolved);
                $sheet->setCellValue("I{$totalRow}", $totalRejected);
                $sheet->setCellValue("J{$totalRow}", $totalTickets);
                $sheet->setCellValue("K{$totalRow}", round($avgCompliance, 1));
                $sheet->setCellValue("L{$totalRow}", $totalBreaches);
                $sheet->setCellValue("M{$totalRow}", $avgRating > 0 ? round($avgRating, 2) : '-');
                $sheet->setCellValue("N{$totalRow}", $totalReviews);

                $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                    ],
                ]);

                // Page setup
                $pageSetup = $sheet->getPageSetup();
                $pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
                $pageSetup->setFitToWidth(1);
                $pageSetup->setFitToHeight(0);
                $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

                $sheet->getHeaderFooter()->setOddFooter('&LHaloAPU - Laporan Kinerja Operator&CHalaman &P dari &N&R' . now()->format('d/m/Y'));
            },
        ];
    }

    public function startCell(): string
    {
        return 'A' . self::HEADER_ROW;
    }

    private function buildPeriodeLabel(): string
    {
        $dateFrom = $this->filters['date_from'] ?? null;
        $dateTo = $this->filters['date_to'] ?? null;
        $year = $this->filters['year'] ?? null;
        $month = $this->filters['month'] ?? null;

        if ($dateFrom && $dateTo) {
            return Carbon::parse($dateFrom)->format('d M Y') . ' - ' . Carbon::parse($dateTo)->format('d M Y');
        }
        if ($year && $month) {
            $dt = Carbon::createFromDate($year, $month, 1);
            return $dt->translatedFormat('F Y');
        }
        if ($year) {
            return "Tahun {$year}";
        }
        return 'Semua Periode';
    }

    private function buildFilterLabel(): string
    {
        $parts = [];

        if (!empty($this->filters['unit_id'])) {
            $unit = Unit::find($this->filters['unit_id']);
            if ($unit) $parts[] = "Unit Layanan = {$unit->nama_unit}";
        }
        if (!empty($this->filters['sub_unit_id'])) {
            $sub = SubUnit::find($this->filters['sub_unit_id']);
            if ($sub) $parts[] = "Layanan = {$sub->nama_layanan}";
        }
        if (!empty($this->filters['search'])) {
            $parts[] = "Kata kunci = {$this->filters['search']}";
        }

        return $parts ? 'Filter: ' . implode(' | ', $parts) : 'Tanpa filter';
    }
}
