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
        if ($this->app->environment('production')) {
            \URL::forceScheme('https');
        }

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

        // Implicitly grant "Super Admin" role all permissions
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole(['superadmin', 'Super Admin']) ? true : null;
        });

        // Authorization Gate for Laravel Pulse
        \Illuminate\Support\Facades\Gate::define('viewPulse', function ($user = null) {
            if ($this->app->environment('local')) {
                return true;
            }

            $admin = auth('admin')->user() ?? $user;
            return $admin && method_exists($admin, 'hasRole') && $admin->hasRole(['superadmin', 'Super Admin']);
        });

        // Pulse User Details Resolver (only when Pulse is registered and bound)
        if ($this->app->bound(\Laravel\Pulse\Contracts\ResolvesUsers::class)) {
            try {
                \Laravel\Pulse\Facades\Pulse::user(function ($user) {
                    return [
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar' => !empty($user->avatar_path) ? asset('storage/' . $user->avatar_path) : null,
                    ];
                });
            } catch (\Throwable $e) {
                // Ignore if Pulse is not yet fully initialized
            }
        }
    }
}
