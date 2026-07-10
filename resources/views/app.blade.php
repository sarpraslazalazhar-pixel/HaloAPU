<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Halo APU') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @routes
    @inertiaHead
</head>
<body class="font-sans antialiased text-gray-900 bg-gray-50">
    @inertia
</body>
</html>
