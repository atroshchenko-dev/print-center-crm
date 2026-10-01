import { test as setup } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { AUTH_FILE } from './auth-state';

/**
 * Sign in once for the whole run and hand the session to every spec that needs
 * one.
 *
 * Before this, each of the 43 tests logged in on its own. That was wasteful,
 * and — more to the point — impossible: `/login` is rate-limited to five
 * attempts a minute, so with one worker the sixth test onward would have been
 * answered with 429. The suite only got away with it because the credentials
 * were wrong anyway (the specs defaulted to admin@example.com, the seeder creates
 * admin@crm.local), so nothing depended on being signed in. The eight
 * end-to-end tests in order-business-flow.spec.ts skipped on every CI run.
 *
 * If the credentials do not work — running against production, say — this
 * writes an empty state instead of failing. Specs then land on /login and skip
 * themselves, which is the behaviour they had before.
 */

const EMPTY_STATE = { cookies: [], origins: [] };

setup('sign in once', async ({ page }) => {
    fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });
    fs.writeFileSync(AUTH_FILE, JSON.stringify(EMPTY_STATE));

    const login = process.env.E2E_LOGIN || 'admin@crm.local';
    const password = process.env.E2E_PASSWORD || 'password';

    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    await page.locator('input[autocomplete="username"]').fill(login);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('button[type="submit"]').click();

    // `networkidle` returns while the Inertia POST is still in flight — the
    // button still reads "Входжу…" and the URL is still /login. Wait for the
    // redirect itself.
    await page
        .waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20_000 })
        .catch(() => {});

    if (page.url().includes('/login')) {
        console.warn(
            `[e2e] Could not sign in as ${login}. Authenticated specs will skip. ` +
            'Set E2E_LOGIN / E2E_PASSWORD for this environment.',
        );
        return;
    }

    await page.context().storageState({ path: AUTH_FILE });
});
