<?php
// hapus file ini setelah deploy selesai!

// Cari bootstrap/app.php (baik setup.php di root proyek maupun di folder public/)
$paths = [
    __DIR__.'/bootstrap/app.php',
    __DIR__.'/../bootstrap/app.php',
];

$app = null;
foreach ($paths as $path) {
    if (file_exists($path)) {
        $app = require $path;
        break;
    }
}

if (!$app) {
    die('bootstrap/app.php tidak ditemukan. Pastikan setup.php berada di folder proyek.');
}

$_SERVER['argv'] = ['artisan'];
$_SERVER['argc'] = 1;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$app->make('config')->set('app.env', 'production');

$commands = [
    'optimize:clear' => [],
    'key:generate'   => ['--force' => true],
    'migrate'        => ['--force' => true],
    'storage:link'   => [],
    'config:cache'   => [],
    'route:cache'    => [],
    'view:cache'     => [],
    'event:cache'    => [],
];

foreach ($commands as $command => $params) {
    try {
        $exitCode = $kernel->call($command, $params);
        echo "[$command] " . ($exitCode === 0 ? 'OK' : "FAIL (exit: $exitCode)") . "<br>";
    } catch (\Throwable $e) {
        echo "[$command] ERROR: " . $e->getMessage() . "<br>";
    }
}

echo "<br><strong>DONE — HAPUS FILE INI SEKARANG</strong>";
