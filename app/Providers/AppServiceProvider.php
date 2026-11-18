<?php

namespace App\Providers;

use App\Models\SystemConfig;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Realtime works out of the box from the Pusher credentials already present.
     * Without this, a stale BROADCAST_CONNECTION=log silently drops every event
     * to the log file and no browser ever receives a chat message.
     */
    protected function configureRealtime(): void
    {
        if (config('broadcasting.default') !== 'log') {
            return;
        }

        $connection = 'pusher';

        if (!config("broadcasting.connections.{$connection}.key")) {
            return;
        }

        config(['broadcasting.default' => $connection]);
    }

    /**
     * Public realtime settings handed to the browser. The app key is public by
     * design (it ships in every Pusher client); the secret never leaves the server.
     */
    public static function realtimeConfig(): ?array
    {
        $connection = config('broadcasting.default');

        $config = config("broadcasting.connections.{$connection}");

        if (empty($config['key'])) {
            return null;
        }

        if ($connection === 'reverb') {
            $host = $config['options']['host'] ?? null;

            // A loopback host is only reachable from the server itself. Browsers
            // on other devices (phones, other PCs) would dial their own machine,
            // so fall back to the hostname serving the page.
            if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                $host = null;
            }

            return [
                'broadcaster' => 'reverb',
                'key' => $config['key'],
                'host' => $host,
                'port' => $config['options']['port'] ?? 8080,
                'scheme' => $config['options']['scheme'] ?? 'https',
            ];
        }

        if ($connection === 'pusher') {
            return [
                'broadcaster' => 'pusher',
                'key' => $config['key'],
                'cluster' => $config['options']['cluster'] ?? 'mt1',
            ];
        }

        return null;
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            \URL::forceScheme('https');
        }

        $this->configureRealtime();

        Event::listen(NotificationSent::class, function ($event) {
            if ($event->notifiable) {
                $userType = get_class($event->notifiable);
                $userId = $event->notifiable->id;
                Cache::forget("unread_notif_{$userType}_{$userId}");
            }
        });

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
            if ($this->app->environment('local', 'development')) {
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
