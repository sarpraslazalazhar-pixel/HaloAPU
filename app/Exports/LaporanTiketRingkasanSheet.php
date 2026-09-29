<?php

namespace App\Exports;

use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class LaporanTiketRingkasanSheet implements FromArray, WithTitle, WithColumnWidths, WithEvents
{
    private array $summaryData = [];

    public function __construct(private array $filters) {}

    public function title(): string
    {
        return 'Ringkasan';
    }

    public function array(): array
    {
        $this->summaryData = $this->computeSummary();

        // Mengembalikan array kosong — semua isi ditulis manual via AfterSheet
        // agar kita punya kendali penuh atas layout multi-tabel.
        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 30,
            'B' => 20,
            'C' => 20,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $data = $this->summaryData;
                $row = 1;

                // ── Judul ──
                $sheet->mergeCells("A1:C1");
                $sheet->setCellValue('A1', 'RINGKASAN LAPORAN TIKET');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '1F3864']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(28);
                $row = 3;

                // ── Tabel 1: Status Tiket ──
                $row = $this->writeTable($sheet, $row, 'Distribusi Status Tiket', ['Status', 'Jumlah'], $data['status']);

                $row++;

                // ── Tabel 2: Prioritas ──
                $row = $this->writeTable($sheet, $row, 'Distribusi Prioritas', ['Prioritas', 'Jumlah'], $data['priority']);

                $row++;

                // ── Tabel 3: Unit Layanan ──
                $row = $this->writeTable($sheet, $row, 'Distribusi Unit Layanan', ['Unit Layanan', 'Jumlah'], $data['units']);

                $row++;

                // ── Tabel 4: Kepatuhan SLA ──
                $slaRows = [
                    ['SLA Respon Tepat Waktu', $data['sla']['response_compliance']],
                    ['SLA Resolusi Tepat Waktu', $data['sla']['resolution_compliance']],
                    ['Total Tiket Ter-track SLA', $data['sla']['total_tracked']],
                    ['Pelanggaran Respon', $data['sla']['response_breach']],
                    ['Pelanggaran Resolusi', $data['sla']['resolution_breach']],
                ];
                $row = $this->writeTable($sheet, $row, 'Kepatuhan SLA', ['Metrik', 'Nilai'], $slaRows);

                // Format persen untuk SLA
                $slaDataStart = $row - count($slaRows);
                $sheet->getStyle("B{$slaDataStart}")->getNumberFormat()->setFormatCode('0.0%');
                $slaDataStart++;
                $sheet->getStyle("B{$slaDataStart}")->getNumberFormat()->setFormatCode('0.0%');

                // ── Pengaturan Cetak ──
                $pageSetup = $sheet->getPageSetup();
                $pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
                $pageSetup->setFitToWidth(1);
                $pageSetup->setFitToHeight(0);
                $sheet->getHeaderFooter()->setOddFooter('&LHaloAPU - Ringkasan&CHalaman &P dari &N');
            },
        ];
    }

    // ── Helper: tulis satu tabel kecil, return baris setelah tabel ──
    private function writeTable($sheet, int $startRow, string $title, array $headers, array $rows): int
    {
        $lastCol = chr(64 + count($headers)); // B atau C
        $row = $startRow;

        // Judul tabel
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '1F3864']],
        ]);
        $row++;

        // Header
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}{$row}", $h);
        }
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(24);
        $row++;

        // Data rows
        foreach ($rows as $idx => $rowData) {
            foreach ($rowData as $i => $val) {
                $col = chr(65 + $i);
                $sheet->setCellValue("{$col}{$row}", $val);
            }

            $style = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ];

            // Zebra
            if ($idx % 2 === 1) {
                $style['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F6FC']];
            }

            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($style);

            // Kolom nilai: rata tengah
            for ($i = 1; $i < count($rowData); $i++) {
                $col = chr(65 + $i);
                $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $row++;
        }

        return $row;
    }

    // ── Compute semua ringkasan dari query yang sama ──
    private function computeSummary(): array
    {
        $base = Ticket::query()->laporanFilter($this->filters);

        // Status
        $statusRaw = (clone $base)->selectRaw('
            SUM(CASE WHEN status = "open" THEN 1 ELSE 0 END) as "Baru",
            SUM(CASE WHEN status = "on_proses" THEN 1 ELSE 0 END) as "Diproses",
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as "Pending",
            SUM(CASE WHEN status = "need_revision" THEN 1 ELSE 0 END) as "Butuh Revisi",
            SUM(CASE WHEN status IN ("solve","selesai") THEN 1 ELSE 0 END) as "Solve",
            SUM(CASE WHEN status = "reject" THEN 1 ELSE 0 END) as "Reject",
            SUM(CASE WHEN status = "dibatalkan" THEN 1 ELSE 0 END) as "Dibatalkan",
            COUNT(*) as total
        ')->first();

        $statusData = [];
        foreach (['Baru', 'Diproses', 'Pending', 'Butuh Revisi', 'Solve', 'Reject', 'Dibatalkan'] as $label) {
            $val = (int) ($statusRaw->{$label} ?? 0);
            if ($val > 0 || $label === 'Baru') { // selalu tampilkan Baru
                $statusData[] = [$label, $val];
            }
        }
        $statusData[] = ['TOTAL', (int) ($statusRaw->total ?? 0)];

        // Prioritas
        $prioRaw = (clone $base)->selectRaw('
            COALESCE(priority, "-") as prio, COUNT(*) as total
        ')->groupBy('prio')->pluck('total', 'prio');

        $prioData = [];
        foreach (['Urgen', 'Tinggi', 'Sedang', 'Rendah'] as $p) {
            $val = (int) ($prioRaw[$p] ?? 0);
            $prioData[] = [$p, $val];
        }
        if (isset($prioRaw['-']) || isset($prioRaw[''])) {
            $prioData[] = ['Belum Diset', (int) ($prioRaw['-'] ?? 0) + (int) ($prioRaw[''] ?? 0)];
        }

        // Unit
        $unitRaw = (clone $base)
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->join('units', 'sub_units.unit_id', '=', 'units.id')
            ->selectRaw('units.nama_unit, COUNT(tickets.id) as total')
            ->groupBy('units.id', 'units.nama_unit')
            ->orderByDesc('total')
            ->get();

        $unitData = $unitRaw->map(fn ($r) => [$r->nama_unit, (int) $r->total])->toArray();

        // SLA
        $slaRaw = (clone $base)
            ->join('ticket_sla_tracking', 'tickets.id', '=', 'ticket_sla_tracking.ticket_id')
            ->selectRaw('
                COUNT(*) as total_tracked,
                SUM(CASE WHEN responded_at IS NOT NULL THEN 1 ELSE 0 END) as responded,
                SUM(CASE WHEN is_response_breached = 1 THEN 1 ELSE 0 END) as resp_breach,
                SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN is_resolution_breached = 1 THEN 1 ELSE 0 END) as res_breach
            ')->first();

        $totalTracked = (int) ($slaRaw->total_tracked ?? 0);
        $responded = (int) ($slaRaw->responded ?? 0);
        $respBreach = (int) ($slaRaw->resp_breach ?? 0);
        $resolved = (int) ($slaRaw->resolved ?? 0);
        $resBreach = (int) ($slaRaw->res_breach ?? 0);

        $respCompliance = $responded > 0 ? ($responded - $respBreach) / $responded : ($totalTracked > 0 ? 0 : 1);
        $resCompliance = $resolved > 0 ? ($resolved - $resBreach) / $resolved : ($totalTracked > 0 ? 0 : 1);

        return [
            'status' => $statusData,
            'priority' => $prioData,
            'units' => $unitData,
            'sla' => [
                'response_compliance' => round($respCompliance, 3),
                'resolution_compliance' => round($resCompliance, 3),
                'total_tracked' => $totalTracked,
                'response_breach' => $respBreach,
                'resolution_breach' => $resBreach,
            ],
        ];
    }
}
