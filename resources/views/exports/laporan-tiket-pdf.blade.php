<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Tiket</title>
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
            margin-bottom: 15px;
            border-bottom: 3px solid #1F3864;
            padding-bottom: 10px;
        }

        .header-left {
            display: table-cell;
            width: 20%;
            vertical-align: top;
        }

        .header-center {
            display: table-cell;
            width: 60%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
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
            margin-top: 5px;
            text-align: left;
        }

        .summary-container {
            display: table;
            width: 100%;
            margin-bottom: 15px;
            gap: 10px;
        }

        .summary-box {
            display: table-cell;
            width: 18%;
            background: #F2F6FC;
            border: 1px solid #BFBFBF;
            padding: 8px;
            text-align: center;
            vertical-align: middle;
        }

        .summary-value {
            font-size: 14pt;
            font-weight: bold;
            color: #1F3864;
        }

        .summary-label {
            font-size: 7pt;
            color: #666;
            margin-top: 2px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .data-table thead {
            display: table-header-group;
            background: #1F3864;
            color: white;
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

        .text-center {
            text-align: center;
        }

        .text-nowrap {
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 7pt;
            text-align: center;
        }

        .badge-baru {
            background: #BFDBFE;
            color: #1E3A8A;
        }

        .badge-diproses {
            background: #FEF3C7;
            color: #92400E;
        }

        .badge-pending {
            background: #FED7AA;
            color: #92400E;
        }

        .badge-solve {
            background: #BBFBEE;
            color: #134E4A;
        }

        .badge-reject, .badge-dibatalkan {
            background: #FECACA;
            color: #991B1B;
        }

        .badge-urgen {
            background: #FECACA;
            color: #991B1B;
            font-weight: bold;
        }

        .badge-tinggi {
            background: #FED7AA;
            color: #92400E;
        }

        .badge-sedang {
            background: #FEF3C7;
            color: #78350F;
        }

        .badge-rendah {
            background: #BBFBEE;
            color: #134E4A;
        }

        .badge-tepat {
            background: #BBFBEE;
            color: #134E4A;
        }

        .badge-terlambat {
            background: #FECACA;
            color: #991B1B;
        }

        .total-row {
            background: #E8E8E8;
            font-weight: bold;
        }

        .empty-msg {
            text-align: center;
            padding: 15px;
            color: #999;
            font-style: italic;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 15px;
            display: table;
            width: 100%;
            font-size: 7pt;
            color: #999;
            padding: 5px 12px;
            border-top: 1px solid #CCC;
        }

        .footer-left {
            display: table-cell;
            text-align: left;
        }

        .footer-right {
            display: table-cell;
            text-align: right;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header-container">
        <div class="header-left">
            @if($logo)
                <img src="{{ $logo }}" alt="Logo" class="logo-img">
            @endif
        </div>
        <div class="header-center">
            <div class="title">LAPORAN TIKET HELPDESK HALOAPU</div>
            <div class="subtitle">
                @if($periodePrint)
                    Periode: {{ $periodePrint }}
                @else
                    Semua Periode
                @endif
            </div>
            @if($filterPrint)
                <div class="subtitle">Filter: {{ $filterPrint }}</div>
            @else
                <div class="subtitle">Tanpa filter</div>
            @endif
        </div>
    </div>

    <div class="print-info">
        Dicetak: {{ now()->format('d-m-Y H:i') }} oleh {{ $userName }}
    </div>

    <!-- Summary Boxes -->
    <div class="summary-container">
        <div class="summary-box">
            <div class="summary-value">{{ $totalTickets }}</div>
            <div class="summary-label">Total Tiket</div>
        </div>
        <div class="summary-box">
            <div class="summary-value">{{ $statusCounts['open'] ?? 0 }}</div>
            <div class="summary-label">Baru</div>
        </div>
        <div class="summary-box">
            <div class="summary-value">{{ $statusCounts['on_proses'] ?? 0 }}</div>
            <div class="summary-label">Diproses</div>
        </div>
        <div class="summary-box">
            <div class="summary-value">{{ $statusCounts['pending'] ?? 0 }}</div>
            <div class="summary-label">Pending</div>
        </div>
        <div class="summary-box">
            <div class="summary-value">{{ $statusCounts['solve'] ?? 0 }}</div>
            <div class="summary-label">Selesai</div>
        </div>
        <div class="summary-box">
            <div class="summary-value">{{ $slaStats['responseCompliance'] }}%</div>
            <div class="summary-label">SLA Respon</div>
        </div>
        <div class="summary-box">
            <div class="summary-value">{{ $slaStats['resolutionCompliance'] }}%</div>
            <div class="summary-label">SLA Resolusi</div>
        </div>
    </div>

    <!-- Data Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 4%;">No</th>
                <th style="width: 9%;">No Tiket</th>
                <th style="width: 9%;">Tanggal</th>
                <th style="width: 9%;">Pengaju</th>
                <th style="width: 9%;">Divisi</th>
                <th style="width: 10%;">Unit Layanan</th>
                <th style="width: 12%;">Layanan</th>
                <th style="width: 8%;">Status</th>
                <th style="width: 8%;">Prioritas</th>
                <th style="width: 8%;">SLA Respon</th>
                <th style="width: 8%;">SLA Resolusi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tickets as $index => $ticket)
                <tr>
                    <td class="text-center">{{ $loop->iteration }}</td>
                    <td class="text-center text-nowrap">#{{ $ticket->formatted_id }}</td>
                    <td class="text-center text-nowrap">{{ $ticket->created_at->format('d-m-Y H:i') }}</td>
                    <td>{{ $ticket->user->username ?? '-' }}</td>
                    <td>{{ $ticket->user->divisi->nama_divisi ?? '-' }}</td>
                    <td>{{ $ticket->unit->nama_unit ?? '-' }}</td>
                    <td>{{ $ticket->subUnit->nama_layanan ?? '-' }}</td>
                    <td class="text-center">
                        @php
                            $statusMap = [
                                'open' => ['label' => 'Baru', 'class' => 'badge-baru'],
                                'on_proses' => ['label' => 'Diproses', 'class' => 'badge-diproses'],
                                'pending' => ['label' => 'Pending', 'class' => 'badge-pending'],
                                'solve' => ['label' => 'Solve', 'class' => 'badge-solve'],
                                'reject' => ['label' => 'Reject', 'class' => 'badge-reject'],
                                'dibatalkan' => ['label' => 'Dibatalkan', 'class' => 'badge-dibatalkan'],
                            ];
                            $statusInfo = $statusMap[$ticket->status] ?? ['label' => $ticket->status, 'class' => ''];
                        @endphp
                        <span class="badge {{ $statusInfo['class'] }}">{{ $statusInfo['label'] }}</span>
                    </td>
                    <td class="text-center">
                        @php
                            $priorityMap = [
                                'Urgen' => 'badge-urgen',
                                'Tinggi' => 'badge-tinggi',
                                'Sedang' => 'badge-sedang',
                                'Rendah' => 'badge-rendah',
                            ];
                            $priorityClass = $priorityMap[$ticket->priority] ?? '';
                        @endphp
                        @if($ticket->priority)
                            <span class="badge {{ $priorityClass }}">{{ $ticket->priority }}</span>
                        @else
                            <span>-</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($ticket->slaTracking)
                            @php
                                $isResponseBreached = $ticket->slaTracking->is_response_breached ?? false;
                                $responseLabel = $isResponseBreached ? 'Terlambat' : 'Tepat Waktu';
                                $responseClass = $isResponseBreached ? 'badge-terlambat' : 'badge-tepat';
                            @endphp
                            <span class="badge {{ $responseClass }}">{{ $responseLabel }}</span>
                        @else
                            <span>-</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($ticket->slaTracking)
                            @php
                                $isResolutionBreached = $ticket->slaTracking->is_resolution_breached ?? false;
                                $resolutionLabel = $isResolutionBreached ? 'Terlambat' : 'Tepat Waktu';
                                $resolutionClass = $isResolutionBreached ? 'badge-terlambat' : 'badge-tepat';
                            @endphp
                            <span class="badge {{ $resolutionClass }}">{{ $resolutionLabel }}</span>
                        @else
                            <span>-</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="empty-msg">
                        Tidak ada data untuk filter yang dipilih
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($tickets->count() > 0)
            <tfoot>
                <tr class="total-row">
                    <td colspan="11" style="text-align: right; padding-right: 5px;">
                        Total Tiket: {{ $totalTickets }}
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-left">HaloAPU - Laporan Tiket</div>
        <div class="footer-right">Halaman <span class="page">1</span> dari <span class="numpage">1</span></div>
    </div>
</body>
</html>
