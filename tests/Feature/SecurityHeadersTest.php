<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SecurityHeadersTest — the middleware's header contract, pinned server-side.
 *
 * Until this file the header values were asserted only by the E2E suite,
 * which runs against the built app in CI; nothing on the PHP side failed
 * when a value drifted. Started for finding I-5 (2026-08-09 audit):
 * X-XSS-Protection carried `1; mode=block` — the legacy filter is gone from
 * every current browser, and in the old ones that still ship it, mode=block's
 * false positives were themselves usable as an attack primitive. The modern
 * value is an explicit `0`; the CSP one line above it is the real defence.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_xss_filter_is_explicitly_disabled(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-XSS-Protection', '0');
    }

    /**
     * The rest of the contract, since a file promising one is expected to
     * hold all of it (F-4): the six always-on headers with their exact
     * values, the CSP's structure, and HSTS *absent* outside production —
     * a test env that saw it would mean the environment gate broke.
     */
    public function test_the_whole_header_contract_holds(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    /**
     * The session cookie's Secure flag was nobody's job (finding N-1, fifth
     * pass): config read SESSION_SECURE_COOKIE with no default, the variable
     * appeared in no .env.example and no runbook, and there is no
     * forceScheme('https') — so an unset variable on the server meant the
     * session and XSRF cookies travelled without Secure, and HSTS only
     * covers a browser that has already visited once. The config now
     * defaults by environment, and the variable is documented where
     * production configuration is copied from.
     */
    public function test_the_secure_cookie_flag_is_somebody_is_job_now(): void
    {
        $this->assertStringContainsString(
            'SESSION_SECURE_COOKIE',
            (string) file_get_contents(base_path('.env.example')),
            'The variable must be visible where production .env files are copied from.',
        );

        $this->assertStringContainsString(
            "env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')",
            (string) file_get_contents(base_path('config/session.php')),
            'An unset variable must mean Secure in production, not unsecured everywhere.',
        );
    }
}
