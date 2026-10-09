<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Kinerja Operator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8pt;
            line-height: 1.3;
            color: #333;
        }

        @page {
            size: A4 landscape;
            margin: 15mm 12mm;
        }

        .header-container {
            display: table;
            width: 100%;
            margin-bottom: 12px;
            border-bottom: 3px solid #1F3864;
            padding-bottom: 10px;
        }

        .header-left {
            display: table-cell;
            width: 15%;
            vertical-align: top;
        }

        .header-center {
            display: table-cell;
            width: 70%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
        }

        .header-right {
            display: table-cell;
            width: 15%;
            vertical-align: bottom;
            text-align: right;
        }

        .logo-img {
            max-width: 60px;
            max-height: 60px;
        }

        .title {
            font-size: 14pt;
            font-weight: bold;
            color: #1F3864;
            margin-bottom: 3px;
        }

        .subtitle {
            font-size: 8pt;
            color: #666;
            margin-bottom: 2px;
        }

        .print-info {
            font-size: 7pt;
            color: #999;
            margin-bottom: 10px;
        }

        /* Summary KPI Cards */
        .summary-container {
            display: table;
            width: 100%;
            margin-bottom: 12px;
            border-collapse: separate;
            border-spacing: 4px;
        }

        .summary-box {
            display: table-cell;
            width: 16.6%;
            background: #F2F6FC;
            border: 1px solid #BFBFBF;
            border-top: 3px solid #1F3864;
            padding: 6px 8px;
            text-align: center;
            vertical-align: middle;
        }

        .summary-box.green { border-top-color: #166534; }
        .summary-box.amber { border-top-color: #92400E; }
        .summary-box.red   { border-top-color: #991B1B; }
        .summary-box.teal  { border-top-color: #0F766E; }
        .summary-box.purple{ border-top-color: #6D28D9; }

        .summary-value {
            font-size: 15pt;
            font-weight: bold;
            color: #1F3864;
        }

        .summary-value.green  { color: #166534; }
        .summary-value.amber  { color: #92400E; }
        .summary-value.teal   { color: #0F766E; }
        .summary-value.purple { color: #6D28D9; }

        .summary-label {
            font-size: 7pt;
            color: #666;
            margin-top: 2px;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .data-table thead {
            display: table-header-group;
        }

        .data-table th {
            background: #1F3864;
            color: white;
            font-weight: bold;
            text-align: center;
            border: 1px solid #1F3864;
            padding: 5px 3px;
            font-size: 7pt;
        }

        .data-table td {
            border: 1px solid #BFBFBF;
            padding: 4px 3px;
            font-size: 7pt;
        }

        .data-table tbody tr {
            page-break-inside: avoid;
        }

        .data-table tbody tr:nth-child(even) {
            background: #F2F6FC;
        }

        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .text-bold   { font-weight: bold; }

        .badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 6.5pt;
            text-align: center;
        }

        .badge-green  { background: #DCFCE7; color: #166534; }
        .badge-amber  { background: #FEF3C7; color: #92400E; }
        .badge-red    { background: #FEE2E2; color: #991B1B; }
        .badge-blue   { background: #DBEAFE; color: #1E40AF; }
        .badge-purple { background: #EDE9FE; color: #6D28D9; }

        .total-row {
            background: #E2E8F0 !important;
            font-weight: bold;
        }

        .empty-msg {
            text-align: center;
            padding: 15px;
            color: #999;
            font-style: italic;
        }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            display: table;
            width: 100%;
            font-size: 7pt;
            color: #999;
            padding: 5px 12px;
            border-top: 1px solid #CCC;
        }

        .footer-left  { display: table-cell; text-align: left; }
        .footer-right { display: table-cell; text-align: right; }

        /* Signature block */
        .signature-block {
            margin-top: 20px;
            display: table;
            width: 100%;
        }

        .signature-cell {
            display: table-cell;
            width: 33%;
            text-align: center;
            padding: 0 10px;
        }

        .signature-title {
            font-size: 8pt;
            margin-bottom: 45px;
        }

        .signature-line {
            border-top: 1px solid #333;
            padding-top: 3px;
            font-size: 8pt;
        }
    </style>
</head>
<body>
    <!-- Header Kop -->
    <div class="header-container">
        <div class="header-left">
            @if($logo)
                <img src="{{ $logo }}" alt="Logo" class="logo-img">
            @endif
        </div>
        <div class="header-center">
            <div class="title">LAPORAN BEBAN &amp; KINERJA OPERATOR</div>
            <div class="subtitle">HaloAPU — Sistem Helpdesk</div>
            <div class="subtitle">
                Periode:
                @if($periodePrint)
                    {{ $periodePrint }}
                @else
                    Semua Periode
                @endif
            </div>
            @if($filterPrint)
                <div class="subtitle">Filter: {{ $filterPrint }}</div>
            @endif
        </div>
        <div class="header-right"></div>
    </div>

    <div class="print-info">
        Dicetak: {{ now()->format('d-m-Y H:i') }} &nbsp;|&nbsp; Oleh: {{ $userName }}
    </div>

    <!-- KPI Summary Cards -->
    <div class="summary-container">
        <div class="summary-box">
            <div class="summary-value">{{ $summary['totalOperators'] }}</div>
            <div class="summary-label">Total Operator</div>
        </div>
        <div class="summary-box">
            <div class="summary-value">{{ $summary['totalTickets'] }}</div>
            <div class="summary-label">Total Tiket Dikelola</div>
        </div>
        <div class="summary-box green">
            <div class="summary-value green">{{ $summary['totalSolved'] }}</div>
            <div class="summary-label">Tiket Selesai</div>
        </div>
        <div class="summary-box amber">
            <div class="summary-value amber">{{ $summary['totalActive'] }}</div>
            <div class="summary-label">Tiket Aktif</div>
        </div>
        <div class="summary-box teal">
            <div class="summary-value teal">{{ $summary['avgCompliance'] }}%</div>
            <div class="summary-label">Rata-rata Kepatuhan SLA</div>
        </div>
        <div class="summary-box purple">
            <div class="summary-value purple">{{ $summary['avgRating'] > 0 ? $summary['avgRating'] : '-' }}</div>
            <div class="summary-label">Rata-rata Rating CSAT</div>
        </div>
    </div>

    <!-- Data Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 3%;">No</th>
                <th style="width: 12%;">Operator</th>
                <th style="width: 10%;">Unit Layanan</th>
                <th style="width: 7%;">Aktif</th>
                <th style="width: 7%;">Selesai</th>
                <th style="width: 8%;">Batal/Tolak</th>
                <th style="width: 7%;">Total</th>
                <th style="width: 9%;">Kepatuhan SLA</th>
                <th style="width: 9%;">Pelanggaran SLA</th>
                <th style="width: 9%;">Rating CSAT</th>
                <th style="width: 7%;">Ulasan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($operators as $index => $op)
                <tr>
                    <td class="text-center">{{ $loop->iteration }}</td>
                    <td>
                        <strong>{{ $op['name'] }}</strong>
                        <br><span style="color:#888;font-size:6.5pt;">{{ $op['username'] }}</span>
                    </td>
                    <td>{{ $op['units'] ?: '-' }}</td>
                    <td class="text-center">
                        @if($op['active_tickets'] > 0)
                            <span class="badge badge-amber">{{ $op['active_tickets'] }}</span>
                        @else
                            <span style="color:#aaa;">0</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($op['solved_tickets'] > 0)
                            <span class="badge badge-green">{{ $op['solved_tickets'] }}</span>
                        @else
                            <span style="color:#aaa;">0</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($op['rejected_tickets'] > 0)
                            <span class="badge badge-red">{{ $op['rejected_tickets'] }}</span>
                        @else
                            <span style="color:#aaa;">0</span>
                        @endif
                    </td>
                    <td class="text-center text-bold">{{ $op['total_tickets'] }}</td>
                    <td class="text-center">
                        @php $sla = $op['resolution_compliance']; @endphp
                        @if($sla >= 90)
                            <span class="badge badge-green">{{ $sla }}%</span>
                        @elseif($sla >= 70)
                            <span class="badge badge-amber">{{ $sla }}%</span>
                        @else
                            <span class="badge badge-red">{{ $sla }}%</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($op['resolution_breaches'] > 0)
                            <span class="badge badge-red">{{ $op['resolution_breaches'] }}</span>
                        @else
                            <span class="badge badge-green">0</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($op['avg_rating'] !== null)
                            @php $r = $op['avg_rating']; @endphp
                            @if($r >= 4)
                                <span class="badge badge-green">{{ number_format($r, 2) }}</span>
                            @elseif($r >= 3)
                                <span class="badge badge-amber">{{ number_format($r, 2) }}</span>
                            @else
                                <span class="badge badge-red">{{ number_format($r, 2) }}</span>
                            @endif
                        @else
                            <span style="color:#aaa;">-</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $op['total_reviews'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="empty-msg">Tidak ada data untuk filter yang dipilih</td>
                </tr>
            @endforelse
        </tbody>
        @if(count($operators) > 0)
        <tfoot>
            <tr class="total-row">
                <td colspan="3" class="text-right" style="padding-right: 6px;">Total</td>
                <td class="text-center">{{ $totals['active'] }}</td>
                <td class="text-center">{{ $totals['solved'] }}</td>
                <td class="text-center">{{ $totals['rejected'] }}</td>
                <td class="text-center">{{ $totals['total'] }}</td>
                <td class="text-center">{{ $totals['avgCompliance'] }}%</td>
                <td class="text-center">{{ $totals['breaches'] }}</td>
                <td class="text-center">{{ $totals['avgRating'] > 0 ? number_format($totals['avgRating'], 2) : '-' }}</td>
                <td class="text-center">{{ $totals['reviews'] }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    <!-- Tanda Tangan -->
    <div class="signature-block">
        <div class="signature-cell"></div>
        <div class="signature-cell"></div>
        <div class="signature-cell">
            <div class="signature-title">Dicetak oleh,</div>
            <div class="signature-line">{{ $userName }}</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-left">HaloAPU - Laporan Beban &amp; Kinerja Operator</div>
        <div class="footer-right">Dicetak: {{ now()->format('d-m-Y H:i') }}</div>
    </div>
</body>
</html>
