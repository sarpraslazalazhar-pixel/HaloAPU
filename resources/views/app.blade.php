<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <meta name="description" content="Sistem Layanan Helpdesk Internal Halo APU. Akses terproteksi untuk pelaporan tiket dan pemantauan operasional.">
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Open Graph Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ config('app.name', 'Halo APU') }}">
    <meta property="og:description" content="Sistem Layanan Helpdesk Internal Halo APU">
    <meta property="og:image" content="{{ asset('images/logo.png') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="theme-color" content="#0088cc">

    @php
        $fontFiles = glob(public_path('build/assets/geist-latin-wght-normal-*.woff2'));
        $fontAsset = !empty($fontFiles) ? 'build/assets/' . basename($fontFiles[0]) : null;
    @endphp
    @if($fontAsset)
    <link rel="preload" href="{{ asset($fontAsset) }}" as="font" type="font/woff2" crossorigin>
    @endif

    @php
        $isLogin = request()->is('login') || request()->is('/');
        $logo = $isLogin ? \App\Models\SystemConfig::getValue('logo_path') : null;
        $logoUrl = $logo ? asset('storage/' . $logo) : asset('images/logo.png');
        $banner = $isLogin ? \App\Models\SystemConfig::getValue('banner_path') : null;
        $bannerUrl = $banner
            ? asset('storage/' . $banner)
            : (file_exists(public_path('images/bg-login.webp')) ? asset('images/bg-login.webp') : asset('images/bg-login.png'));
    @endphp

    @if($isLogin)
    <!-- Preload Logo (Primary LCP Element) -->
    <link rel="preload" as="image" href="{{ $logoUrl }}" fetchpriority="high">

    <!-- Preload Background Banner (Normal priority to not starve critical JS/CSS) -->
    <link rel="preload" as="image" href="{{ $bannerUrl }}">
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
    @inertiaHead
</head>
<body class="font-sans antialiased bg-background text-foreground @if($isLogin) bg-cover bg-center @endif" @if($isLogin) style="background-image: url('{{ $bannerUrl }}');" @endif>
    @routes
    @inertia
</body>
</html>
