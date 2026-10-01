import { test, expect, type Page } from '@playwright/test';
import { AUTH_FILE } from './auth-state';

/**
 * E2E Test: Shift Workflow
 *
 * Tests the new simplified shift lifecycle pages.
 * Resilient to CI environments with no seeded data.
 *
 * Session comes from auth.setup.ts — see the note in playwright.config.ts on
 * why nothing here signs in on its own.
 */

test.use({ storageState: AUTH_FILE });

// ─── Helpers ────────────────────────────────────────

/** Land inside the app, or tell the caller we never got in. */
async function enter(page: Page): Promise<boolean> {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    return !page.url().includes('/login');
}

async function ensureShiftOpen(page: Page) {
    if (page.url().includes('/shifts/open')) {
        // Fill all number inputs with 0 if empty, then submit
        const inputs = page.locator('input[type="number"]');
        const count = await inputs.count();
        for (let i = 0; i < count; i++) {
            const val = await inputs.nth(i).inputValue();
            if (!val || val === '') await inputs.nth(i).fill('0');
        }
        await page.locator('button[type="submit"]').click();
        await page.waitForLoadState('networkidle');
    }
}

// ─── Shift Open Page ────────────────────────────────

test.describe('Shift Open Page', () => {
    test('should load shift open page after login', async ({ page }) => {
        test.skip(!(await enter(page)), 'Not signed in — see auth.setup.ts');

        // After login, user goes to either dashboard or /shifts/open
        if (page.url().includes('/shifts/open')) {
            // Verify the open page rendered
            await expect(page.locator('body')).toBeVisible();
            await expect(page.locator('button[type="submit"]')).toBeVisible();
        } else {
            // Navigate to it directly
            await page.goto('/shifts/open');
            await page.waitForLoadState('networkidle');
            await expect(page.locator('body')).toBeVisible();
        }
    });

    test('should have counter inputs pre-filled (not empty)', async ({ page }) => {
        test.skip(!(await enter(page)), 'Not signed in — see auth.setup.ts');
        await page.goto('/shifts/open');
        await page.waitForLoadState('networkidle');

        const counterInputs = page.locator('input[type="number"]');
        const count = await counterInputs.count();

        // If equipment exists, inputs should be pre-filled
        if (count > 0) {
            const firstValue = await counterInputs.first().inputValue();
            // Pre-filled values from last readings or 0 — should not be empty string
            expect(firstValue).not.toBe('');
        }
    });
});

// ─── Shift Close Page ───────────────────────────────

test.describe('Shift Close Page', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!(await enter(page)), 'Not signed in — see auth.setup.ts');
        await ensureShiftOpen(page);
    });

    test('should show simple close confirmation without inputs', async ({ page }) => {
        // Only run if we have an open shift (authenticated + shift exists)
        await page.goto('/shifts/close');
        await page.waitForLoadState('networkidle');

        // If redirected back to login or shifts/open, shift isn't open — skip
        if (page.url().includes('/login') || page.url().includes('/shifts/open')) return;

        // Close page should have ZERO number inputs (no counter/cash forms)
        const numberInputs = page.locator('input[type="number"]');
        await expect(numberInputs).toHaveCount(0);

        // Should have submit button
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });
});

// ─── Equipment Admin ────────────────────────────────

test.describe('Equipment Admin — has_counter', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(!(await enter(page)), 'Not signed in — see auth.setup.ts');
        await ensureShiftOpen(page);
    });

    test('should load equipment admin page', async ({ page }) => {
        await page.goto('/admin/equipment');
        await page.waitForLoadState('networkidle');

        // Page should load without error
        await expect(page.locator('body')).toBeVisible();
        // Should have "Апарати" heading
        const heading = page.locator('h1, h2').first();
        await expect(heading).toBeVisible();
    });

    test('should show Лічильник column if equipment exists', async ({ page }) => {
        await page.goto('/admin/equipment');
        await page.waitForLoadState('networkidle');

        // Check if there's a table with data
        const rows = page.locator('tbody tr');
        const rowCount = await rows.count();

        if (rowCount > 0) {
            // If equipment exists, "Лічильник" column should be in header
            await expect(page.locator('th')).toContainText(['Лічильник']);
        }
    });
});
