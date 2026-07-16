<?php
// hapus file ini setelah deploy selesai!
$_SERVER['argv'] = ['artisan'];
$_SERVER['argc'] = 1;

$app = require __DIR__.'/bootstrap/app.php';
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
