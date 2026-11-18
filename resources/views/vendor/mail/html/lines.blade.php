@props([
    'lines' => [],
])
@php
    $blocks = [];

    foreach ($lines as $rawLine) {
        $line = trim((string) $rawLine);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^\*\*Pesan:\*\*\s*\n(.+)$/su', $line, $m)) {
            $blocks[] = [
                'type' => 'message_box',
                'label' => 'Pesan:',
                'content' => trim($m[1]),
            ];
            continue;
        }

        if (preg_match('/^\*\*(?!PENTING:)(.+?):\*\*\s*(.+)$/su', $line, $m)) {
            $lastIndex = count($blocks) - 1;
            if ($lastIndex >= 0 && $blocks[$lastIndex]['type'] === 'details') {
                $blocks[$lastIndex]['items'][] = [
                    'label' => trim($m[1]),
                    'value' => trim($m[2]),
                ];
            } else {
                $blocks[] = [
                    'type' => 'details',
                    'items' => [
                        [
                            'label' => trim($m[1]),
                            'value' => trim($m[2]),
                        ],
                    ],
                ];
            }
            continue;
        }

        if (str_contains($line, '⛔') || stripos($line, 'BREACH') !== false) {
            $blocks[] = [
                'type' => 'callout_danger',
                'content' => $line,
            ];
            continue;
        }

        if (str_starts_with($line, '**PENTING:**') || stripos($line, 'batas SLA') !== false) {
            $blocks[] = [
                'type' => 'callout_warning',
                'content' => $line,
            ];
            continue;
        }

        if (stripos($line, 'kedaluwarsa') !== false || stripos($line, 'mengabaikan email ini') !== false) {
            $lastIndex = count($blocks) - 1;
            if ($lastIndex >= 0 && $blocks[$lastIndex]['type'] === 'notices') {
                $blocks[$lastIndex]['items'][] = $line;
            } else {
                $blocks[] = [
                    'type' => 'notices',
                    'items' => [$line],
                ];
            }
            continue;
        }

        $blocks[] = [
            'type' => 'paragraph',
            'content' => $line,
        ];
    }
@endphp
@foreach ($blocks as $block)
@if ($block['type'] === 'details')
<table class="detail-card" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="detail-card-inner">
<table class="detail-table" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($block['items'] as $idx => $item)
<tr class="{{ $idx < count($block['items']) - 1 ? 'detail-row-divider' : '' }}">
<td class="detail-label" valign="top"><strong>{{ str_replace(["\r\n", "\r", "\n"], ' ', $item['label']) }}:</strong></td>
<td class="detail-value" valign="top">{{ str_replace(["\r\n", "\r", "\n"], ' ', $item['value']) }}</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>

@elseif ($block['type'] === 'message_box')
<table class="message-box" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="message-box-inner">
<p class="message-box-title"><strong>{{ $block['label'] }}</strong></p>
<p class="message-box-content">{!! str_replace(["\r\n", "\r", "\n"], '<br>', e($block['content'])) !!}</p>
</td>
</tr>
</table>

@elseif ($block['type'] === 'notices')
<table class="notice-card" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="notice-card-inner">
@foreach ($block['items'] as $nIdx => $noticeLine)
<p class="notice-line {{ $nIdx === 0 ? 'notice-line-primary' : 'notice-line-secondary' }}">{{ str_replace(["\r\n", "\r", "\n"], ' ', $noticeLine) }}</p>
@endforeach
</td>
</tr>
</table>

@elseif ($block['type'] === 'callout_danger')
<table class="callout callout-danger" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="callout-inner">{!! str_replace(["\r\n", "\r", "\n"], ' ', trim((string) Illuminate\Mail\Markdown::parse($block['content']))) !!}</td>
</tr>
</table>

@elseif ($block['type'] === 'callout_warning')
<table class="callout callout-warning" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="callout-inner">{!! str_replace(["\r\n", "\r", "\n"], ' ', trim((string) Illuminate\Mail\Markdown::parse($block['content']))) !!}</td>
</tr>
</table>

@else
{!! $block['content'] !!}

@endif
@endforeach
