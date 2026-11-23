<?php

namespace App\Exports;

use App\Services\OperatorPerformanceReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class LaporanKinerjaOperatorExport implements FromCollection, WithMapping, WithHeadings, WithColumnWidths, WithTitle, WithEvents, WithCustomStartCell, WithStrictNullComparison
{
    private int $rowNumber = 0;

    private const HEADER_ROW = 6;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $reportCache = null;

    private OperatorPerformanceReportService $reportService;

    public function __construct(
        private array $filters,
        private $admin,
        ?OperatorPerformanceReportService $reportService = null,
    ) {
        $this->reportService = $reportService ?? app(OperatorPerformanceReportService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        return $this->reportCache ??= $this->reportService->buildReport($this->filters);
    }

    public function collection(): Collection
    {
        return $this->report()['operators'];
    }

    public function headings(): array
    {
        return [
            'No',
            'Nama Individu',
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
        $units = is_array($operator['units'] ?? null)
            ? implode(', ', $operator['units'])
            : (string) ($operator['units'] ?? '');

        return [
            $this->rowNumber,
            $operator['name'],
            $operator['username'],
            $operator['email'],
            $operator['no_wa'] ?: '-',
            $units !== '' ? $units : '-',
            $operator['active_tickets'],
            $operator['solved_tickets'],
            $operator['rejected_tickets'],
            $operator['total_tickets'],
            $operator['resolution_compliance'] !== null ? $operator['resolution_compliance'] : '-',
            $operator['resolution_breaches'],
            $operator['avg_rating'] !== null ? $operator['avg_rating'] : '-',
            $operator['total_reviews'],
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
        return 'Kinerja Individu';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $report = $this->report();
                $operators = $report['operators'];
                $summary = $report['summary'];
                $normalizedFilters = $report['filters'];

                $lastCol = 'N';
                $headerRow = self::HEADER_ROW;
                $dataStartRow = $headerRow + 1;
                $totalDataRows = $this->rowNumber;
                $lastDataRow = $totalDataRows > 0 ? ($dataStartRow + $totalDataRows - 1) : $dataStartRow;

                // Title block (rows 1-4)
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->setCellValue('A1', 'LAPORAN BEBAN & KINERJA INDIVIDU');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F3864']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(30);

                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->setCellValue('A2', 'Periode: ' . ($this->reportService->buildPeriodeLabel($normalizedFilters) ?? 'Semua Periode'));
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['size' => 11, 'italic' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                $sheet->mergeCells("A3:{$lastCol}3");
                $filterText = $this->reportService->buildFilterLabel($normalizedFilters, 'Unit Layanan');
                $sheet->setCellValue('A3', $filterText ? 'Filter: ' . $filterText : 'Tanpa filter');
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

                    // Apply numeric formats only when values are numeric (avoid formatting '-' strings)
                    foreach ($operators->values() as $idx => $op) {
                        $row = $dataStartRow + $idx;
                        if ($op['resolution_compliance'] !== null) {
                            $sheet->getStyle("K{$row}")->getNumberFormat()->setFormatCode('0.0"%"');
                        }
                        if ($op['avg_rating'] !== null) {
                            $sheet->getStyle("M{$row}")->getNumberFormat()->setFormatCode('0.00');
                        }
                    }

                    // Zebra striping
                    for ($row = $dataStartRow; $row <= $lastDataRow; $row++) {
                        if (($row - $dataStartRow) % 2 === 1) {
                            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F6FC']],
                            ]);
                        }
                    }
                }

                // Total row (aggregate summary from cached report — no duplicate query)
                $totalRow = $lastDataRow + 2;

                $sheet->setCellValue("A{$totalRow}", 'RINGKASAN');
                $sheet->setCellValue("B{$totalRow}", "Total {$summary['totalOperators']} individu");
                $sheet->setCellValue("G{$totalRow}", $summary['totalActive']);
                $sheet->setCellValue("H{$totalRow}", $summary['totalSolved']);
                $sheet->setCellValue("I{$totalRow}", $summary['totalRejected']);
                $sheet->setCellValue("J{$totalRow}", $summary['totalAssigned']);
                $sheet->setCellValue("K{$totalRow}", $summary['avgCompliance'] !== null ? $summary['avgCompliance'] : '-');
                $sheet->setCellValue("L{$totalRow}", $summary['totalResolutionBreaches']);
                $sheet->setCellValue("M{$totalRow}", $summary['overallAvgRating'] > 0 ? $summary['overallAvgRating'] : '-');
                $sheet->setCellValue("N{$totalRow}", $summary['totalReviews']);

                $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                    ],
                ]);

                foreach (['G', 'H', 'I', 'J', 'L', 'N'] as $col) {
                    $sheet->getStyle("{$col}{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                foreach (['K', 'M'] as $col) {
                    $sheet->getStyle("{$col}{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                if ($summary['avgCompliance'] !== null) {
                    $sheet->getStyle("K{$totalRow}")->getNumberFormat()->setFormatCode('0.0"%"');
                }
                if ($summary['overallAvgRating'] > 0) {
                    $sheet->getStyle("M{$totalRow}")->getNumberFormat()->setFormatCode('0.00');
                }

                // Page setup
                $pageSetup = $sheet->getPageSetup();
                $pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
                $pageSetup->setFitToWidth(1);
                $pageSetup->setFitToHeight(0);
                $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

                $sheet->getHeaderFooter()->setOddFooter('&LHaloAPU - Laporan Kinerja Individu&CHalaman &P dari &N&R' . now()->format('d/m/Y'));
            },
        ];
    }

    public function startCell(): string
    {
        return 'A' . self::HEADER_ROW;
    }
}
