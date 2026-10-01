<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeaders Middleware
 *
 * Adds security headers to all responses:
 * - Content-Security-Policy: modern defense-in-depth against XSS/injection
 * - X-Frame-Options: prevents clickjacking (legacy fallback for CSP frame-ancestors)
 * - X-Content-Type-Options: prevents MIME sniffing
 * - X-XSS-Protection: explicit 0 — the legacy filter is gone from current
 *   browsers, and where it survives, mode=block's false positives were an
 *   attack primitive of their own; CSP above is the actual XSS defence
 * - Referrer-Policy: limits referrer info leakage
 * - Permissions-Policy: restricts browser features
 * - Strict-Transport-Security: enforces HTTPS
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate CSP nonce BEFORE response — Vite adds it to script/style tags
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // Content-Security-Policy (defense-in-depth against XSS)
        $connectSrc = app()->environment('local')
            ? "'self' ws://localhost:* ws://127.0.0.1:*"
            : "'self'";

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",        // Tailwind + Vue scoped styles
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src {$connectSrc}",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
