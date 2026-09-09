@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@php
    $favicon = \App\Models\SystemConfig::getValue('favicon_path');
    if ($favicon) {
        $faviconUrl = str_starts_with($favicon, 'http')
            ? $favicon
            : (str_starts_with($favicon, 'storage/') || str_starts_with($favicon, '/storage/')
                ? asset(ltrim($favicon, '/'))
                : asset('storage/' . $favicon));
    } elseif (file_exists(public_path('images/favicon.png'))) {
        $faviconUrl = asset('images/favicon.png');
    } elseif (file_exists(public_path('favicon.png'))) {
        $faviconUrl = asset('favicon.png');
    } else {
        $faviconUrl = asset('images/logo.png');
    }
@endphp
<img src="{{ $faviconUrl }}" class="logo" alt="{{ config('app.name') }}" width="64" height="64" style="max-height: 64px; max-width: 64px; width: 64px; height: 64px; object-fit: contain; display: inline-block;">
</a>
</td>
</tr>
