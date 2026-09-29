<?php

namespace App\Exports;

use App\Models\Ticket;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class LaporanTiketExport implements FromQuery, WithMapping, WithHeadings, WithColumnWidths, WithTitle, WithEvents, WithCustomStartCell
{
    private int $rowNumber = 0;

    private const STATUS_LABELS = [
        'open'            => 'Baru',
        'on_proses'       => 'Diproses',
        'pending'         => 'Pending',
        'need_revision'   => 'Butuh Revisi',
        'waiting_approval'=> 'Menunggu Review',
        'solve'           => 'Solve',
        'selesai'         => 'Solve',
        'reject'          => 'Reject',
        'dibatalkan'      => 'Dibatalkan',
    ];

    private const HEADER_ROW = 6; // baris 1-4 judul, 5 kosong, 6 header tabel

    public function __construct(
        private array $filters,
        private $admin,
    ) {}

    public function query(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return Ticket::query()
            ->laporanFilter($this->filters)
            ->with([
                'user:id,username,name,divisi_id,org_unit_id',
                'user.divisi:id,nama_divisi',
                'user.orgUnit:id,nama_unit_organisasi',
                'subUnit:id,unit_id,nama_layanan',
                'subUnit.unit:id,nama_unit',
                'slaTracking',
            ])
            ->latest('tickets.created_at');
    }

    public function headings(): array
    {
        return [
            'No',
            'No Tiket',
            'Tanggal',
            'Pengaju',
            'Divisi',
            'Unit Organisasi',
            'Unit Layanan',
            'Layanan',
            'Status',
            'Prioritas',
            'SLA Respon',
            'SLA Resolusi',
        ];
    }

    public function map(mixed $ticket): array
    {
        $this->rowNumber++;

        $sla = $ticket->slaTracking;

        // SLA Respon
        $slaRespon = '-';
        if ($sla) {
            if ($sla->responded_at) {
                $slaRespon = $sla->is_response_breached ? 'Terlambat' : 'Tepat Waktu';
            } elseif ($sla->sla_response_deadline && $sla->sla_response_deadline->isPast()) {
                $slaRespon = 'Terlambat';
            }
        }

        // SLA Resolusi
        $slaResolusi = '-';
        if ($sla) {
            if ($sla->resolved_at) {
                $slaResolusi = $sla->is_resolution_breached ? 'Terlambat' : 'Tepat Waktu';
            } elseif ($sla->sla_resolution_deadline && $sla->sla_resolution_deadline->isPast()) {
                $slaResolusi = 'Terlambat';
            }
        }

        return [
            $this->rowNumber,
            '#TKT-' . $ticket->formatted_id,
            $ticket->created_at ? Date::dateTimeToExcel(Carbon::parse($ticket->created_at)) : '',
            $ticket->user->name ?? $ticket->user->username ?? '-',
            $ticket->user->divisi->nama_divisi ?? '-',
            $ticket->user->orgUnit->nama_unit_organisasi ?? '-',
            $ticket->subUnit->unit->nama_unit ?? '-',
            $ticket->subUnit->nama_layanan ?? '-',
            self::STATUS_LABELS[$ticket->status] ?? $ticket->status,
            $ticket->priority ?? '-',
            $slaRespon,
            $slaResolusi,
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,   // No
            'B' => 18,  // No Tiket
            'C' => 20,  // Tanggal
            'D' => 22,  // Pengaju
            'E' => 20,  // Divisi
            'F' => 22,  // Unit Organisasi
            'G' => 20,  // Unit Layanan
            'H' => 28,  // Layanan
            'I' => 16,  // Status
            'J' => 14,  // Prioritas
            'K' => 16,  // SLA Respon
            'L' => 16,  // SLA Resolusi
        ];
    }

    public function title(): string
    {
        return 'Data Tiket';
    }

    // ──────────────────────────────────────────────
    // AfterSheet: judul, styling, warna kondisional
    // ──────────────────────────────────────────────
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = 'L';
                $headerRow = self::HEADER_ROW;
                $dataStartRow = $headerRow + 1;
                $totalDataRows = $this->rowNumber;
                $lastDataRow = $dataStartRow + $totalDataRows - 1;
                if ($totalDataRows === 0) {
                    $lastDataRow = $dataStartRow; // minimal satu baris
                }

                // ── 1. Blok Judul (baris 1-4) ──
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->setCellValue('A1', 'LAPORAN TIKET HELPDESK HALOAPU');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F3864']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(30);

                // Baris 2: Periode
                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->setCellValue('A2', 'Periode: ' . $this->buildPeriodeLabel());
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['size' => 11, 'italic' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Baris 3: Filter aktif
                $sheet->mergeCells("A3:{$lastCol}3");
                $sheet->setCellValue('A3', $this->buildFilterLabel());
                $sheet->getStyle('A3')->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '555555']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Baris 4: Dicetak oleh
                $sheet->mergeCells("A4:{$lastCol}4");
                $adminName = $this->admin->name ?? $this->admin->username ?? 'Admin';
                $sheet->setCellValue('A4', 'Dicetak: ' . now()->format('d-m-Y H:i') . ' oleh ' . $adminName);
                $sheet->getStyle('A4')->applyFromArray([
                    'font' => ['size' => 9, 'color' => ['rgb' => '888888']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // ── 2. Header Tabel (baris 6) ──
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

                // ── 3. Freeze Pane ── di bawah header, setelah kolom B (No Tiket)
                $sheet->freezePane("C{$dataStartRow}");

                // ── 4. AutoFilter ──
                $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$headerRow}");

                // ── 5. Styling Data Rows ──
                if ($totalDataRows > 0) {
                    $dataRange = "A{$dataStartRow}:{$lastCol}{$lastDataRow}";

                    // Border semua data
                    $sheet->getStyle($dataRange)->applyFromArray([
                        'borders' => [
                            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                        ],
                        'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                    ]);

                    // Kolom No: rata tengah
                    $sheet->getStyle("A{$dataStartRow}:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    // Kolom Tanggal: format date dan rata tengah
                    $sheet->getStyle("C{$dataStartRow}:C{$lastDataRow}")->getNumberFormat()->setFormatCode('dd-mm-yyyy hh:mm');
                    $sheet->getStyle("C{$dataStartRow}:C{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    // Kolom No Tiket (B): format teks agar tidak jadi angka
                    $sheet->getStyle("B{$dataStartRow}:B{$lastDataRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

                    // Kolom Layanan (H): wrap text
                    $sheet->getStyle("H{$dataStartRow}:H{$lastDataRow}")->getAlignment()->setWrapText(true);

                    // Kolom Status, Prioritas, SLA: rata tengah
                    foreach (['I', 'J', 'K', 'L'] as $col) {
                        $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }

                    // ── Zebra striping + Warna kondisional ──
                    for ($row = $dataStartRow; $row <= $lastDataRow; $row++) {
                        // Zebra: baris genap (relatif terhadap data)
                        if (($row - $dataStartRow) % 2 === 1) {
                            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F6FC']],
                            ]);
                        }

                        // Warna Status (kolom I)
                        $statusVal = $sheet->getCell("I{$row}")->getValue();
                        $this->applyStatusColor($sheet, "I{$row}", $statusVal);

                        // Warna Prioritas (kolom J)
                        $prioVal = $sheet->getCell("J{$row}")->getValue();
                        $this->applyPriorityColor($sheet, "J{$row}", $prioVal);

                        // Warna SLA Respon (K) dan SLA Resolusi (L)
                        foreach (['K', 'L'] as $slaCol) {
                            $slaVal = $sheet->getCell("{$slaCol}{$row}")->getValue();
                            $this->applySlaColor($sheet, "{$slaCol}{$row}", $slaVal);
                        }
                    }
                }

                // ── 6. Baris Total ──
                $totalRow = $lastDataRow + 1;
                if ($totalDataRows === 0) {
                    $totalRow = $dataStartRow;
                }
                $sheet->mergeCells("A{$totalRow}:H{$totalRow}");
                $sheet->setCellValue("A{$totalRow}", "Total Tiket: {$totalDataRows}");
                $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
                    ],
                ]);

                // ── 7. Pengaturan Cetak ──
                $pageSetup = $sheet->getPageSetup();
                $pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
                $pageSetup->setFitToWidth(1);
                $pageSetup->setFitToHeight(0);
                $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

                $sheet->getHeaderFooter()->setOddFooter('&LHaloAPU - Laporan Tiket&CHalaman &P dari &N&R' . now()->format('d/m/Y'));
            },
        ];
    }

    /**
     * Data dimulai dari baris 7 (setelah judul + header).
     * Maatwebsite menulis heading di startRow, data di startRow+1.
     */
    public function startCell(): string
    {
        return 'A' . self::HEADER_ROW;
    }

    // ── Helpers ──

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
            $unit = \App\Models\Unit::find($this->filters['unit_id']);
            if ($unit) $parts[] = "Unit Layanan = {$unit->nama_unit}";
        }
        if (!empty($this->filters['sub_unit_id'])) {
            $sub = \App\Models\SubUnit::find($this->filters['sub_unit_id']);
            if ($sub) $parts[] = "Layanan = {$sub->nama_layanan}";
        }
        if (!empty($this->filters['status'])) {
            $st = $this->filters['status'];
            $label = is_array($st)
                ? implode(', ', array_map(fn ($s) => self::STATUS_LABELS[$s] ?? $s, $st))
                : (self::STATUS_LABELS[$st] ?? $st);
            $parts[] = "Status = {$label}";
        }
        if (!empty($this->filters['divisi_id'])) {
            $divisi = \App\Models\OrgDivisi::find($this->filters['divisi_id']);
            if ($divisi) $parts[] = "Divisi = {$divisi->nama_divisi}";
        }
        if (!empty($this->filters['search'])) {
            $parts[] = "Kata kunci = {$this->filters['search']}";
        }

        return $parts ? 'Filter: ' . implode(' | ', $parts) : 'Tanpa filter';
    }

    private function applyStatusColor($sheet, string $cell, ?string $value): void
    {
        $map = [
            'Baru'            => ['fill' => 'DBEAFE', 'font' => '1E40AF'],
            'Diproses'        => ['fill' => 'FEF3C7', 'font' => '92400E'],
            'Pending'         => ['fill' => 'FFEDD5', 'font' => '9A3412'],
            'Butuh Revisi'    => ['fill' => 'FFEDD5', 'font' => '9A3412'],
            'Menunggu Review' => ['fill' => 'FEF3C7', 'font' => '92400E'],
            'Solve'           => ['fill' => 'DCFCE7', 'font' => '166534'],
            'Reject'          => ['fill' => 'FEE2E2', 'font' => '991B1B'],
            'Dibatalkan'      => ['fill' => 'FEE2E2', 'font' => '991B1B'],
        ];

        if (isset($map[$value])) {
            $sheet->getStyle($cell)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $map[$value]['fill']]],
                'font' => ['color' => ['rgb' => $map[$value]['font']]],
            ]);
        }
    }

    private function applyPriorityColor($sheet, string $cell, ?string $value): void
    {
        $map = [
            'Urgen'  => ['fill' => 'FEE2E2', 'font' => '991B1B', 'bold' => true],
            'Tinggi' => ['fill' => 'FFEDD5', 'font' => '9A3412', 'bold' => false],
            'Sedang' => ['fill' => 'FEF3C7', 'font' => '92400E', 'bold' => false],
            'Rendah' => ['fill' => 'DCFCE7', 'font' => '166534', 'bold' => false],
        ];

        if (isset($map[$value])) {
            $sheet->getStyle($cell)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $map[$value]['fill']]],
                'font' => ['color' => ['rgb' => $map[$value]['font']], 'bold' => $map[$value]['bold']],
            ]);
        }
    }

    private function applySlaColor($sheet, string $cell, ?string $value): void
    {
        if ($value === 'Tepat Waktu') {
            $sheet->getStyle($cell)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']],
                'font' => ['color' => ['rgb' => '166534']],
            ]);
        } elseif ($value === 'Terlambat') {
            $sheet->getStyle($cell)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']],
                'font' => ['color' => ['rgb' => '991B1B']],
            ]);
        }
    }
}
