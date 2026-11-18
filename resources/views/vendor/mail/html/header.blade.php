@props(['url'])
@php
    $favicon = null;
    try {
        $favicon = \App\Models\SystemConfig::getValue('favicon_path');
    } catch (\Throwable $e) {
        $favicon = null;
    }

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

    $brandName = trim(strip_tags((string) $slot)) ?: config('app.name', 'Halo APU');
@endphp
<tr>
<td class="header-cell">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td width="56" valign="middle" style="width: 56px; padding-right: 14px;">
<a href="{{ $url }}" target="_blank" rel="noopener" style="display: inline-block; text-decoration: none;">
<img src="{{ $faviconUrl }}" class="logo" alt="{{ $brandName }}" width="46" height="46" style="width: 46px; height: 46px; max-width: 46px; max-height: 46px; object-fit: contain; display: block; border: 0;">
</a>
</td>
<td valign="middle" align="left">
<a href="{{ $url }}" target="_blank" rel="noopener" class="brand-title">{{ strtoupper($brandName) }}</a>
<div class="brand-subtitle">Ajukan. Pantau. Selesai</div>
</td>
<td valign="middle" align="right" class="header-badge-cell">
<span class="header-badge">Layanan Helpdesk</span>
</td>
</tr>
</table>
</td>
</tr>
