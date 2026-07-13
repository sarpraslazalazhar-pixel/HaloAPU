<?php

namespace App\Providers;

use App\Models\SystemConfig;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        try {
            $tz = SystemConfig::getValue('app_timezone');
            if ($tz) {
                config(['app.timezone' => $tz]);
                date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // table not ready yet (migration)
        }

        RateLimiter::for('login', function ($request) {
            return Limit::perMinute(5)
                ->by($request->input('username') . '|' . $request->ip())
                ->response(function () {
                    return back()->withErrors([
                        'username' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam 1 menit.',
                    ]);
                });
        });
    }
}
