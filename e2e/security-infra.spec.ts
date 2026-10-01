import { test, expect, type Page } from '@playwright/test';

/**
 * E2E Test: Security & Infrastructure
 *
 * Tests security headers, health endpoints, and CORS behavior.
 * Does NOT require authentication — tests public endpoints.
 */

// ─── Security Headers ───────────────────────────────

test.describe('Security Headers', () => {
    test('should include X-Frame-Options header', async ({ page }) => {
        const response = await page.goto('/login');
        expect(response?.headers()['x-frame-options']).toBe('SAMEORIGIN');
    });

    test('should include X-Content-Type-Options header', async ({ page }) => {
        const response = await page.goto('/login');
        expect(response?.headers()['x-content-type-options']).toBe('nosniff');
    });

    test('should disable the legacy XSS filter explicitly', async ({ page }) => {
        // '0', not '1; mode=block': the filter is gone from current browsers,
        // and where it survives its false positives are an attack primitive.
        const response = await page.goto('/login');
        expect(response?.headers()['x-xss-protection']).toBe('0');
    });

    test('should include Referrer-Policy header', async ({ page }) => {
        const response = await page.goto('/login');
        expect(response?.headers()['referrer-policy']).toBe('strict-origin-when-cross-origin');
    });

    test('should include Permissions-Policy header', async ({ page }) => {
        const response = await page.goto('/login');
        const pp = response?.headers()['permissions-policy'];
        expect(pp).toContain('camera=()');
        expect(pp).toContain('microphone=()');
    });
});

// ─── Health Endpoints ───────────────────────────────

test.describe('Health Endpoints', () => {
    test('ping should return ok', async ({ request }) => {
        const response = await request.get('/ping');
        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(body.status).toBe('ok');
    });

    test('health endpoint should require auth', async ({ request }) => {
        const response = await request.get('/health', {
            maxRedirects: 0,
        });
        // Accepting [200, 302] passed whether or not the endpoint was
        // protected. Unauthenticated must be turned away, full stop.
        expect(response.status()).toBe(302);
    });

    test('Laravel built-in /up should return 200', async ({ request }) => {
        const response = await request.get('/up');
        expect(response.status()).toBe(200);
    });
});

// ─── CSRF Protection ────────────────────────────────

test.describe('CSRF Protection', () => {
    test('POST to /login without CSRF token should fail', async ({ request }) => {
        const response = await request.post('/login', {
            data: { login: 'test@test.com', password: 'wrong' },
            headers: { 'Content-Type': 'application/json' },
        });
        // Should get 419 (CSRF mismatch) or redirect
        expect([302, 419]).toContain(response.status());
    });
});

// ─── Protected Routes (without auth) ────────────────

test.describe('Route Protection', () => {
    const protectedRoutes = [
        '/orders',
        '/orders/create',
        '/ledger/history',
        '/admin/services',
        '/admin/equipment',
        '/admin/inventory',
        '/reports/internal',
        '/shifts/close',
    ];

    for (const route of protectedRoutes) {
        test(`${route} should redirect to login`, async ({ page }) => {
            await page.goto(route);
            await page.waitForLoadState('networkidle');
            await expect(page).toHaveURL(/\/login/);
        });
    }
});
