<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
