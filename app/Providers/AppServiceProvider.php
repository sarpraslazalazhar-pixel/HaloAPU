<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

        Event::listen(Login::class, function (Login $event): void {
            if (! in_array($event->guard, ['web', 'admin'], true) || ! request()->hasSession()) {
                return;
            }

            request()->session()->put("auth.remember.{$event->guard}", (bool) $event->remember);

            if (! $event->remember) {
                $guard = Auth::guard($event->guard);
                if ($guard instanceof SessionGuard) {
                    Cookie::queue(Cookie::forget($guard->getRecallerName()));
                }
            }

            if ($event->guard === 'web' && $event->user instanceof User && $event->user->is_active) {
                request()->session()->put('user_session_version', (int) $event->user->session_version);
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if (in_array($event->guard, ['web', 'admin'], true) && request()->hasSession()) {
                request()->session()->forget("auth.remember.{$event->guard}");
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

        RateLimiter::for('login', function (Request $request) {
            $username = Str::lower(trim((string) $request->input('username', '')));
            $ip = (string) $request->ip();

            $responseCallback = function (Request $request, array $headers) {
                $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

                return back()
                    ->withErrors([
                        'username' => "Terlalu banyak percobaan login. Silakan coba lagi dalam {$seconds} detik.",
                    ])
                    ->onlyInput('username');
            };

            return [
                Limit::perMinute(5)
                    ->by('web-login:' . $username . '|' . $ip)
                    ->response($responseCallback),
                Limit::perMinute(30)
                    ->by('web-login-ip:' . $ip)
                    ->response($responseCallback),
            ];
        });

        RateLimiter::for('api-login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email', '')));
            $ip = (string) $request->ip();

            $responseCallback = function (Request $request, array $headers) {
                $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

                return response()->json([
                    'message' => "Terlalu banyak percobaan login. Silakan coba lagi dalam {$seconds} detik.",
                    'error_code' => 'RATE_LIMITED',
                    'retry_after' => $seconds,
                ], 429, $headers);
            };

            return [
                Limit::perMinute(6)
                    ->by('api-login:' . $email . '|' . $ip)
                    ->response($responseCallback),
                Limit::perMinute(30)
                    ->by('api-login-ip:' . $ip)
                    ->response($responseCallback),
            ];
        });

        // Implicitly grant "Super Admin" role all permissions
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole(['superadmin', 'Super Admin']) ? true : null;
        });

        Gate::define('assess-ticket-workload', fn (Admin $admin): bool => $admin->isCoordinator());

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
