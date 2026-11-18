<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
<x-mail::lines :lines="$introLines" />

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
<x-mail::lines :lines="$outroLines" />

{{-- Salutation --}}
@if (! empty($salutation))
@if (preg_match('/^(Salam hormat,)\s*(.+)$/i', trim((string) $salutation), $salMatch))
<p class="salutation">{{ $salMatch[1] }}<br><strong class="salutation-team">{{ $salMatch[2] }}</strong></p>
@else
<p class="salutation">{!! nl2br(e($salutation)) !!}</p>
@endif
@else
<p class="salutation">Salam hormat,<br><strong class="salutation-team">Tim {{ config('app.name', 'Halo APU') }}</strong></p>
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Jika kamu mengalami kendala saat mengklik tombol "{{ $actionText }}", salin dan tempel tautan berikut ke web browser kamu:

<div class="subcopy-url-box"><a href="{{ $actionUrl }}" target="_blank" rel="noopener">{{ $displayableActionUrl }}</a></div>
</x-slot:subcopy>
@endisset
</x-mail::message>
