<?php

namespace App\Http\Middleware;

use App\Models\SystemConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySparcIntegrationToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) SystemConfig::getValue('sparc_api_token', '');
        $bearer = (string) ($request->bearerToken()
            ?: $request->header('X-SPARC-Token')
            ?: $request->header('X-Sparc-Token')
            ?: $request->query('api_token')
            ?: $request->server('HTTP_AUTHORIZATION')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION'));

        if (str_starts_with($bearer, 'Bearer ')) {
            $bearer = substr($bearer, 7);
        }

        if (empty($token) || ! hash_equals($token, $bearer)) {
            return response()->json([
                'message' => 'Unauthorized: Invalid SPARC integration bearer token.',
                'debug_received' => ! empty($bearer) ? substr($bearer, 0, 10).'...' : 'empty',
                'debug_expected' => ! empty($token) ? substr($token, 0, 10).'...' : 'empty',
            ], 401);
        }

        return $next($request);
    }
}
