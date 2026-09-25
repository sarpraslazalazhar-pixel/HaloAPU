<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Security headers middleware.
     * 
     * Adds essential security headers to every HTTP response to protect against:
     * - Clickjacking (X-Frame-Options)
     * - MIME sniffing (X-Content-Type-Options)  
     * - XSS attacks (X-XSS-Protection, CSP)
     * - Protocol downgrade (HSTS)
     * - Information leakage (Referrer-Policy)
     * - Unauthorized feature access (Permissions-Policy)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent clickjacking — page cannot be embedded in iframes
        $response->headers->set('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Enable XSS filter in legacy browsers
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Control referrer information sent with requests
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict browser features (camera, microphone, geolocation)
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Prevent search engines from indexing internal helpdesk data (allow public login/home)
        if (!$request->is('login') && !$request->is('/') && !$request->is('images/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        // Allow aggressive browser caching for hashed build assets and images (saves shared hosting CPU/bandwidth)
        if ($request->is('build/*') || $request->is('images/*') || $request->is('storage/*')) {
            $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        }

        // HSTS — Force HTTPS for 1 year (only in production with HTTPS)
        if ($request->secure() || config('app.env') === 'production') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy
        // Allows inline scripts/styles needed by Inertia.js + Vite
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net",
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net",
            "img-src 'self' data: https: blob:",
            "connect-src 'self' wss: https:",
            "media-src 'self' blob:",
            "frame-ancestors 'none'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
