import { test, expect, type Page } from '@playwright/test';
import { AUTH_FILE } from './auth-state';

/**
 * E2E Test: route guards and authenticated navigation.
 *
 * Prerequisites:
 * - App running (local: php artisan serve, or production)
 * - Set E2E_BASE_URL env var (default: http://localhost:8000)
 * - Credentials that auth.setup.ts can sign in with (E2E_LOGIN / E2E_PASSWORD,
 *   defaulting to the seeded admin@crm.local)
 *
 * The "Authenticated Navigation" block below only proves that pages render for
 * a signed-in user — each test asserts that `body` is visible and no more. The
 * business guarantees live in order-business-flow.spec.ts.
 */

// ─── Helpers ────────────────────────────────────────

async function ensureShiftOpen(page: Page) {
    if (page.url().includes('/shifts/open')) {
        await page.locator('input[type="number"]').first().fill('0');
        await page.locator('button[type="submit"]').click();
        await page.waitForLoadState('networkidle');
    }
}

// ─── Auth Guard (no login needed) ───────────────────

test.describe('Auth Guard', () => {
    test('should redirect unauthenticated users to login', async ({ page }) => {
        await page.goto('/orders');
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveURL(/\/login/);
    });

    test('should show login form with inputs and button', async ({ page }) => {
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('input[autocomplete="username"]')).toBeVisible();
        await expect(page.locator('input[type="password"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test('should reject invalid credentials and stay on login', async ({ page }) => {
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        await page.locator('input[autocomplete="username"]').fill('wrong@test.com');
        await page.locator('input[type="password"]').fill('wrongpass');
        await page.locator('button[type="submit"]').click();
        await page.waitForLoadState('networkidle');

        // Should stay on login page (not redirect to dashboard)
        await expect(page).toHaveURL(/\/login/);
        // Credentials should still be present (form wasn't reset to a new page)
        await expect(page.locator('input[autocomplete="username"]')).toBeVisible();
    });

    test('should protect admin routes', async ({ page }) => {
        await page.goto('/admin/services');
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveURL(/\/login/);
    });

    test('should protect reports routes', async ({ page }) => {
        await page.goto('/reports/internal');
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveURL(/\/login/);
    });
});

// ─── Dashboard & Navigation (requires login) ────────

test.describe('Authenticated Navigation', () => {
    // Session from auth.setup.ts — signing in per test would exhaust the
    // five-attempts-a-minute limit on /login by the sixth test.
    test.use({ storageState: AUTH_FILE });

    test.beforeEach(async ({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('networkidle');
        test.skip(page.url().includes('/login'), 'Not signed in — see auth.setup.ts');
        await ensureShiftOpen(page);
    });

    test('should see dashboard after login', async ({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('networkidle');
        // Dashboard page should load without errors
        await expect(page.locator('body')).toBeVisible();
    });

    test('should navigate to create order page', async ({ page }) => {
        await page.goto('/orders/create');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('body')).toBeVisible();
    });

    test('should navigate to orders list', async ({ page }) => {
        await page.goto('/orders');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('body')).toBeVisible();
    });

    test('should navigate to ledger', async ({ page }) => {
        await page.goto('/ledger/history');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('body')).toBeVisible();
    });

    test('should navigate to admin services', async ({ page }) => {
        await page.goto('/admin/services');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('body')).toBeVisible();
    });

    test('should navigate to admin equipment', async ({ page }) => {
        await page.goto('/admin/equipment');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('body')).toBeVisible();
    });

    test('should navigate to admin inventory', async ({ page }) => {
        await page.goto('/admin/inventory');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('body')).toBeVisible();
    });

    test('should navigate to reports', async ({ page }) => {
        await page.goto('/reports/internal');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('body')).toBeVisible();
    });
});
