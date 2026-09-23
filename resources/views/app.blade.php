<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if(request()->is('login') || request()->is('/'))
    <meta name="robots" content="index, follow">
    @else
    <meta name="robots" content="noindex, nofollow">
    @endif
    <meta name="description" content="Sistem Layanan Helpdesk Internal Halo APU. Akses terproteksi untuk pelaporan tiket dan pemantauan operasional.">
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Open Graph Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ config('app.name', 'Halo APU') }}">
    <meta property="og:description" content="Sistem Layanan Helpdesk Internal Halo APU">
    <meta property="og:image" content="{{ asset('images/logo.png') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="theme-color" content="#0088cc">

    <link rel="preload" href="{{ asset('build/assets/geist-latin-wght-normal-BgDaEnEv.woff2') }}" as="font" type="font/woff2" crossorigin>

    @if(request()->is('login') || request()->is('/'))
    @php
        $banner = \App\Models\SystemConfig::getValue('banner_path');
    @endphp
    @if($banner)
    <link rel="preload" as="image" href="{{ asset('storage/' . $banner) }}" fetchpriority="high">
    @elseif(file_exists(public_path('images/bg-login.webp')))
    <link rel="preload" as="image" href="{{ asset('images/bg-login.webp') }}" type="image/webp" fetchpriority="high">
    @else
    <link rel="preload" as="image" href="{{ asset('images/bg-login.png') }}" fetchpriority="high">
    @endif
    @endif

    <title inertia>{{ config('app.name', 'Halo APU') }} - Layanan Helpdesk Terpadu</title>
    @php
        $favicon = \App\Models\SystemConfig::getValue('favicon_path');
    @endphp
    @if($favicon)
        <link rel="icon" href="{{ asset('storage/' . $favicon) }}" />
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}" />
    @endif
    <script>
        (function() {
            var theme = localStorage.getItem('halo-apu-theme');
            if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @unless(request()->is('login') || request()->is('/'))
    @routes
    @endunless
    @inertiaHead
</head>
<body class="font-sans antialiased bg-background text-foreground">
    @inertia
</body>
</html>
