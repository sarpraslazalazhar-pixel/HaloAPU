<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Sistem Layanan Helpdesk Internal Halo APU. Akses terproteksi untuk pelaporan tiket dan pemantauan operasional.">
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Open Graph Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ config('app.name', 'Halo APU') }}">
    <meta property="og:description" content="Sistem Layanan Helpdesk Internal Halo APU">
    <meta property="og:image" content="{{ asset('images/logo.png') }}">
    <meta property="og:url" content="{{ url()->current() }}">

    <!-- Preconnect for external resources -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <title inertia>{{ config('app.name', 'Halo APU') }}</title>
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
    @routes
    @inertiaHead
</head>
<body class="font-sans antialiased bg-background text-foreground">
    @inertia
</body>
</html>
