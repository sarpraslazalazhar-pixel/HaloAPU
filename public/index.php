<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Auto-clear stale route & config cache in local development
if (file_exists($routeCache = __DIR__ . '/../bootstrap/cache/routes-v7.php')) {
    @unlink($routeCache);
}
if (file_exists($configCache = __DIR__ . '/../bootstrap/cache/config.php')) {
    @unlink($configCache);
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
