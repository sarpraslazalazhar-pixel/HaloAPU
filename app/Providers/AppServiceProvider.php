<?php

namespace App\Providers;

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
