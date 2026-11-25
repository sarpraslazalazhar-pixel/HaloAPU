<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Laporan Analisis HaloAPU AI' }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @page {
            size: A4 portrait;
            margin: 16mm 14mm 18mm 14mm;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9pt;
            line-height: 1.45;
            color: #1e293b;
        }

        .header-container {
            display: table;
            width: 100%;
            border-bottom: 2.5px solid #0f4c81;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }

        .header-left {
            display: table-cell;
            width: 15%;
            vertical-align: middle;
        }

        .header-center {
            display: table-cell;
            width: 85%;
            vertical-align: middle;
        }

        .logo-img {
            max-width: 52px;
            max-height: 52px;
        }

        .doc-title {
            font-size: 13pt;
            font-weight: bold;
            color: #0f4c81;
            margin-bottom: 2px;
        }

        .doc-subtitle {
            font-size: 8.5pt;
            color: #475569;
        }

        .meta-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 3px solid #0284c7;
            padding: 7px 10px;
            margin-bottom: 14px;
            font-size: 8pt;
            color: #334155;
        }

        .meta-row {
            margin-bottom: 2px;
        }

        .message-block {
            margin-bottom: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 10px 12px;
            page-break-inside: avoid;
        }

        .message-user {
            background: #f0f9ff;
            border-color: #bae6fd;
        }

        .message-model {
            background: #ffffff;
        }

        .message-header {
            font-size: 8pt;
            font-weight: bold;
            color: #0f4c81;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        .message-meta {
            font-weight: normal;
            color: #64748b;
            font-size: 7.5pt;
        }

        .markdown-body h1,
        .markdown-body h2,
        .markdown-body h3,
        .markdown-body h4 {
            color: #0f172a;
            margin-top: 8px;
            margin-bottom: 4px;
            font-weight: bold;
        }

        .markdown-body h1 { font-size: 12pt; }
        .markdown-body h2 { font-size: 11pt; border-bottom: 1px solid #e2e8f0; padding-bottom: 2px; }
        .markdown-body h3 { font-size: 10pt; }
        .markdown-body h4 { font-size: 9.5pt; }

        .markdown-body p {
            margin-bottom: 6px;
        }

        .markdown-body ul,
        .markdown-body ol {
            margin: 4px 0 6px 18px;
        }

        .markdown-body li {
            margin-bottom: 2px;
        }

        .markdown-body blockquote {
            border-left: 3px solid #0284c7;
            background: #f8fafc;
            padding: 5px 8px;
            margin: 6px 0;
            color: #334155;
            font-size: 8.5pt;
        }

        .markdown-body hr {
            border: none;
            border-top: 1px solid #cbd5e1;
            margin: 8px 0;
        }

        .markdown-body a {
            color: #0284c7;
            text-decoration: underline;
        }

        .md-inline-code {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 1px 3px;
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 8pt;
        }

        .md-code {
            background: #0f172a;
            color: #e2e8f0;
            padding: 7px 9px;
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 7.5pt;
            margin: 6px 0;
            white-space: pre-wrap;
        }

        .md-table,
        .chart-table {
            width: 100%;
            border-collapse: collapse;
            margin: 7px 0;
            font-size: 8pt;
        }

        .md-table th,
        .chart-table th {
            background: #0f4c81;
            color: #ffffff;
            text-align: left;
            padding: 4px 6px;
            border: 1px solid #0f4c81;
            font-weight: bold;
        }

        .md-table td,
        .chart-table td {
            padding: 4px 6px;
            border: 1px solid #cbd5e1;
        }

        .md-table tbody tr:nth-child(even),
        .chart-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .chart-section {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #cbd5e1;
        }

        .chart-title {
            font-size: 8.5pt;
            font-weight: bold;
            color: #0f4c81;
            margin-bottom: 3px;
        }

        .footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #64748b;
            border-top: 1px solid #cbd5e1;
            padding-top: 4px;
            display: table;
            width: 100%;
        }

        .footer-left { display: table-cell; text-align: left; }
        .footer-right { display: table-cell; text-align: right; }
    </style>
</head>
<body>
    <div class="header-container">
        @if(!empty($logo))
            <div class="header-left">
                <img src="{{ $logo }}" alt="Logo" class="logo-img">
            </div>
        @endif
        <div class="header-center">
            <div class="doc-title">LAPORAN ANALISIS HALOAPU AI</div>
            <div class="doc-subtitle">{{ $conversation->title }}</div>
        </div>
    </div>

    <div class="meta-box">
        <div class="meta-row"><strong>Admin:</strong> {{ $adminName }} &nbsp;|&nbsp; <strong>Cakupan Akses:</strong> {{ $scopeLabel }}</div>
        <div class="meta-row"><strong>Dicetak:</strong> {{ $printedAt }} WIB</div>
    </div>

    @forelse($entries as $entry)
        <div class="message-block {{ $entry['role'] === 'user' ? 'message-user' : 'message-model' }}">
            <div class="message-header">
                {{ $entry['role'] === 'user' ? 'Pertanyaan Admin' : 'Analisis HaloAPU AI' }}
                <span class="message-meta">
                    &bull; {{ $entry['created_at'] }}
                    @if(!empty($entry['model_name']) && $entry['role'] === 'model')
                        &bull; {{ $entry['is_fallback'] ? 'Analitik Lokal' : $entry['model_name'] }}
                    @endif
                </span>
            </div>

            <div class="markdown-body">
                {!! $entry['html'] !!}
            </div>

            @if(!empty($entry['charts']))
                @foreach($entry['charts'] as $chart)
                    <div class="chart-section">
                        <div class="chart-title">
                            Data Grafik: {{ $chart['title'] ?? 'Ringkasan' }}
                            @if(!empty($chart['subtitle']))
                                ({{ $chart['subtitle'] }})
                            @endif
                        </div>
                        @if(!empty($chart['labels']) && !empty($chart['series']))
                            <table class="chart-table">
                                <thead>
                                    <tr>
                                        <th>Kategori</th>
                                        @foreach($chart['series'] as $s)
                                            <th>{{ $s['name'] ?? 'Nilai' }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($chart['labels'] as $idx => $label)
                                        <tr>
                                            <td>{{ $label }}</td>
                                            @foreach($chart['series'] as $s)
                                                <td>
                                                    {{ $s['data'][$idx] ?? 0 }}
                                                    @if(!empty($chart['unit']))
                                                        {{ $chart['unit'] }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    @empty
        <div class="message-block">
            <p>Belum ada pesan dalam sesi percakapan ini.</p>
        </div>
    @endforelse

    <div class="footer">
        <div class="footer-left">HaloAPU AI — Laporan Analitik Operasional</div>
        <div class="footer-right">Dicetak {{ $printedAt }} WIB</div>
    </div>
</body>
</html>
