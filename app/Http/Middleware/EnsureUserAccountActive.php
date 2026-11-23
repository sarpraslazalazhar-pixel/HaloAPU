<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects accounts that were deactivated after their session/token was issued.
 *
 * Web sessions carry `user_session_version`; a mismatch (or a deactivated
 * account) ends only the `web` guard so an admin session on the same browser
 * keeps working. Bearer tokens are checked per request because revocation
 * alone cannot stop an in-flight token.
 */
class EnsureUserAccountActive
{
    public function handle(Request $request, Closure $next, string $mode = 'web'): Response
    {
        if ($mode === 'api') {
            $user = $request->user();

            if ($user instanceof User && ! $user->is_active) {
                $user->currentAccessToken()?->delete();

                return response()->json([
                    'message' => 'Akun tidak aktif. Hubungi admin untuk mengaktifkannya kembali.',
                    'error_code' => 'ACCOUNT_INACTIVE',
                ], 401);
            }

            return $next($request);
        }

        $guard = Auth::guard('web');
        $user = $guard->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->is_active) {
            $hasVersion = $request->session()->has('user_session_version');
            $expectedVersion = (int) $request->session()->get('user_session_version', 0);

            // Sessions created before this column existed (version 0) or restored
            // via a valid remember-me cookie adopt the user's current version.
            if (! $hasVersion && ((int) $user->session_version === 0 || $guard->viaRemember())) {
                $request->session()->put('user_session_version', (int) $user->session_version);

                return $next($request);
            }

            if ($hasVersion && (int) $user->session_version === $expectedVersion) {
                return $next($request);
            }
        }

        $guard->logoutCurrentDevice();
        $request->session()->forget('user_session_version');

        // An admin session on the same browser must survive a stale web guard.
        if (Auth::guard('admin')->check() && $request->is('admin', 'admin/*')) {
            return $next($request);
        }

        // Route auth middleware may already have run, so this middleware owns
        // the rejection for the browser as well.
        if ($request->expectsJson() || $request->is('broadcasting/*') || $request->is('push/*')) {
            return response()->json([
                'message' => 'Akun tidak aktif. Hubungi admin untuk mengaktifkannya kembali.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 401);
        }

        return redirect()->route('login');
    }
}
