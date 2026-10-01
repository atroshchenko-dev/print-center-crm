import { test, expect, type Page } from '@playwright/test';
import { AUTH_FILE } from './auth-state';

/**
 * E2E: an order all the way through, end to end.
 *
 * Audit finding H-8. The other three specs check authentication, route guards,
 * navigation and security headers — real coverage, but none of it touches the
 * business. `order-lifecycle.spec.ts` is named after a lifecycle it never
 * walks: every one of its tests asserts that `body` is visible.
 *
 * These tests do what the department actually does — build an order in the
 * constructor, submit it, drive it through the status machine, take payment —
 * and check the guarantees that only appear when the whole stack runs together:
 *
 *  - a submitted internal order exists, with the signatory and cost centre on it
 *  - the constructor's price reaches the order unchanged
 *  - a mandatory signatory is actually mandatory
 *  - completing an order advances it and records the step in the history
 *  - paying a commercial order writes a ledger entry for the payable amount
 *  - cancelling and paying both refuse to proceed on incomplete input
 *
 * Prerequisites: app running with seeded reference data, admin credentials,
 * E2E_BASE_URL (default http://localhost:8000).
 *
 * Tests skip rather than fail when the environment cannot supply what they need
 * — no credentials, no open shift, no scanning service. A red run here means a
 * real regression, not an empty CI database.
 *
 * The session comes from auth.setup.ts, which signs in once for the whole run.
 * Do not add a per-test login: `/login` allows five attempts a minute, and with
 * one worker that runs out by the sixth test.
 */

test.use({ storageState: AUTH_FILE });

/** A service with one parameter group and no stock behind it — the least brittle path. */
const SERVICE = 'Сканування';
const FORMAT = 'А4';

const ORDER_NUMBER = /\b(INT|COM)-\d{4}-\d+\b/;

// Each test walks several full page loads against a single-process dev server,
// and the constructor prices every change over the network. The 30s default in
// playwright.config.ts is not enough for a whole order.
test.describe.configure({ timeout: 120_000 });

// ─── Helpers ────────────────────────────────────────────────────────

async function openShiftIfAsked(page: Page) {
    if (!page.url().includes('/shifts/open')) return;

    const inputs = page.locator('input[type="number"]');
    for (let i = 0; i < (await inputs.count()); i++) {
        if (!(await inputs.nth(i).inputValue())) await inputs.nth(i).fill('0');
    }
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/shifts/open'), { timeout: 15_000 }).catch(() => {});
    await page.waitForLoadState('networkidle');
}

/**
 * Land inside the app with a shift open, or skip — orders need both.
 *
 * The session arrives from auth.setup.ts. If it could not sign in, every
 * navigation redirects to /login and these tests skip rather than fail, which
 * is what should happen when the suite is pointed at an environment whose
 * credentials we do not have.
 */
async function signedInWithShift(page: Page) {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    test.skip(page.url().includes('/login'), 'Not signed in — see auth.setup.ts');
    await openShiftIfAsked(page);
    test.skip(page.url().includes('/shifts/open'), 'Could not open a shift');
}

/** Put one configured item of SERVICE into the cart. Returns its cart price. */
async function addScanToCart(page: Page, quantity: number): Promise<void> {
    const service = page.getByRole('button', { name: SERVICE, exact: true });
    test.skip(!(await service.count()), `Reference data has no "${SERVICE}" service`);
    await service.click();

    // Single parameter group: paper format.
    await page.locator('button').filter({ hasText: FORMAT }).first().click();

    // By aria-label, not position — added for finding H-9, and it is what makes
    // this readable instead of `input[type=number]` roulette. `.first()` is the
    // constructor's own field; cart rows carry the same label.
    await page.getByLabel('Кількість').first().fill(String(quantity));

    // "Додати" enables as soon as every parameter is chosen, which is *before*
    // the price comes back from the server. Clicking then adds the item at 0.00
    // — a genuinely wrong order, and the reason this needs an explicit wait.
    await waitForQuote(page);

    const add = page.getByRole('button', { name: /Додати/ });
    await expect(add).toBeEnabled();

    const before = await cartTotal(page);
    await add.click();

    await expect.poll(() => cartTotal(page), { timeout: 30_000 }).toBeGreaterThan(before);
}

/** Wait until the constructor has a real price, not "Рахуємо…" and not zero. */
async function waitForQuote(page: Page) {
    await expect
        .poll(
            async () => {
                const text = await page.locator('main').innerText();
                if (text.includes('Рахуємо')) return 0;
                const match = text.match(/Собівартість:\s*([\d.,]+)/);
                return match ? parseFloat(match[1].replace(',', '.')) || 0 : 0;
            },
            { timeout: 30_000 },
        )
        .toBeGreaterThan(0);
}

/**
 * The reference-data seeder (`database/seeders/DatabaseSeeder.php`) never
 * populates `departments` — a fresh CI database has none, and a signatory who
 * has never had an order has no learned cost centres either. `CostCenterSelect`'s
 * <select> is therefore empty of real options on a clean run: there is nothing
 * for `selectOption` to pick. "+ Новий центр витрат" is not a fallback here,
 * it is the only path a first order can take — exactly the one `Department::remember()`
 * still turns into a reference-book row on save, same as the old free-text field did.
 */
const COST_CENTRE = 'E2E';

/** Go through "+ Новий центр витрат" and type a cost-centre name. */
async function fillNewCostCentre(page: Page, name: string) {
    await page.getByTestId('new-centre').click();
    await page.getByLabel('Новий центр витрат').fill(name);
}

/**
 * Build and submit an order, and return its number.
 *
 * Internal orders require a signatory and are billed at cost; commercial ones
 * need neither a signatory nor a cost centre and are what reaches the till.
 */
async function createOrder(
    page: Page,
    opts: { quantity?: number; commercial?: boolean } = {},
): Promise<string> {
    const { quantity = 1, commercial = false } = opts;

    await page.goto('/orders/create');
    await page.waitForLoadState('networkidle');

    await addScanToCart(page, quantity);

    if (commercial) {
        await page.getByRole('button', { name: 'Комерц.' }).click();
    } else {
        const signatory = page.getByLabel('Підписант');
        const options = await signatory.locator('option').all();
        test.skip(options.length < 2, 'No signatories in reference data');
        await signatory.selectOption({ index: 1 });
        await fillNewCostCentre(page, COST_CENTRE);
    }

    await page.getByRole('button', { name: 'Оформити замовлення' }).click();
    await page.waitForLoadState('networkidle');

    const number = await newestOrderNumber(page);
    expect(number, 'A submitted order must appear in the list').toMatch(ORDER_NUMBER);

    return number;
}

async function newestOrderNumber(page: Page): Promise<string> {
    await page.goto('/orders');
    await page.waitForLoadState('networkidle');
    const text = await page.locator('main').innerText();
    return text.match(ORDER_NUMBER)?.[0] ?? '';
}

/**
 * Open a specific order's detail page by its number.
 *
 * Navigates by href rather than clicking: Inertia swaps the page without a
 * `load` event, so `waitForURL` after a click just sits there until it times
 * out.
 */
async function openOrder(page: Page, number: string) {
    await page.goto('/orders');
    await page.waitForLoadState('networkidle');

    const link = page.locator('a[href*="/orders/"]').filter({ hasText: number }).first();
    const href = await link.getAttribute('href');
    test.skip(!href, `Order ${number} is not in the list`);

    await page.goto(new URL(href!, page.url()).pathname);
    await page.waitForLoadState('networkidle');
}

/** The status badge on the detail page. */
async function status(page: Page): Promise<string> {
    const badge = page.locator('[class*="badge"]').first();
    if (!(await badge.count())) return '';
    return (await badge.innerText()).trim();
}

/**
 * The cart footer reads `Разом: <commercial> грн (<cost>)`. For an internal-only
 * service the commercial figure is 0 by design and the cost is the real number,
 * so take whichever is larger — that is what the operator is being quoted.
 */
async function cartTotal(page: Page): Promise<number> {
    const text = await page.locator('main').innerText();
    const match = text.match(/Разом:\s*([\d.,]+)\s*грн\s*\(([\d.,]+)\)/);
    if (!match) return 0;

    const [, commercial, cost] = match;
    const num = (v: string) => parseFloat(v.replace(',', '.')) || 0;
    return Math.max(num(commercial), num(cost));
}

/** Wait until the status badge stops reading `from`. */
async function waitForStatusChange(page: Page, from: string) {
    await expect
        .poll(() => status(page), { timeout: 30_000 })
        .not.toBe(from);
}

/**
 * Click a status action and wait for it to have actually landed.
 *
 * A successful transition changes which actions the page offers, so the clicked
 * button disappearing is the signal — more reliable than `networkidle`, which
 * returns while the Inertia POST is still in flight.
 */
async function act(page: Page, name: RegExp) {
    const button = page.getByRole('button', { name }).first();
    if (!(await button.count()) || !(await button.isEnabled())) return false;

    await button.click();
    await expect(button).toHaveCount(0, { timeout: 30_000 });
    await page.waitForLoadState('networkidle');
    return true;
}

// ─── Creating an order ──────────────────────────────────────────────

test.describe('Order creation', () => {
    test('an internal order is submitted with its signatory and cost centre', async ({ page }) => {
        await signedInWithShift(page);

        const number = await createOrder(page, { quantity: 5 });
        await openOrder(page, number);

        const detail = await page.locator('main').innerText();
        expect(detail).toContain(number);
        expect(detail).toContain('Внутрішнє');
        expect(detail, 'The cost centre must survive the round trip').toContain(COST_CENTRE);
        expect(detail).toContain(SERVICE);
        expect(await status(page)).toBe('Нове');

        // The order above is also this signatory's first — it just taught the
        // reference data the pair. Reopening the constructor for the same
        // signatory should now offer a narrowed field instead of the empty
        // book "createOrder" started from, which is the one thing about
        // CostCenterSelect this flow doesn't otherwise exercise.
        await page.goto('/orders/create');
        await page.waitForLoadState('networkidle');
        await page.getByLabel('Підписант').selectOption({ index: 1 });

        const centre = page.getByLabel('Центр витрат');
        await expect(page.getByTestId('show-all'), 'A learned pair must narrow the list').toBeVisible();
        await expect(centre.locator('option[data-centre]')).toHaveCount(1);
        await expect(centre.locator('option[data-centre]')).toHaveText(COST_CENTRE);
    });

    test('the price the constructor quotes is the price on the order', async ({ page }) => {
        await signedInWithShift(page);

        await page.goto('/orders/create');
        await page.waitForLoadState('networkidle');
        await addScanToCart(page, 4);

        const quoted = await cartTotal(page);
        expect(quoted, 'The constructor must quote something').toBeGreaterThan(0);

        const signatory = page.getByLabel('Підписант');
        test.skip((await signatory.locator('option').count()) < 2, 'No signatories in reference data');
        await signatory.selectOption({ index: 1 });
        await fillNewCostCentre(page, COST_CENTRE);
        await page.getByRole('button', { name: 'Оформити замовлення' }).click();
        await page.waitForLoadState('networkidle');

        const number = await newestOrderNumber(page);
        await openOrder(page, number);

        const detail = await page.locator('main').innerText();
        expect(
            detail,
            'The stored total must equal what the operator was shown',
        ).toContain(quoted.toFixed(2));
    });

    test('the cart total follows the quantity', async ({ page }) => {
        await signedInWithShift(page);

        await page.goto('/orders/create');
        await page.waitForLoadState('networkidle');

        await addScanToCart(page, 1);
        const one = await cartTotal(page);

        await addScanToCart(page, 10);
        const eleven = await cartTotal(page);

        expect(one).toBeGreaterThan(0);
        expect(eleven, 'Adding ten more units must raise the total').toBeGreaterThan(one);
    });

    test('an internal order cannot be submitted without a signatory', async ({ page }) => {
        await signedInWithShift(page);

        await page.goto('/orders/create');
        await page.waitForLoadState('networkidle');
        await addScanToCart(page, 1);

        // Signatory deliberately left at "— Оберіть —". The UI does better than
        // refuse the submission: it never offers it.
        await expect(
            page.getByRole('button', { name: 'Оформити замовлення' }),
            'Submission must stay closed until a signatory is chosen',
        ).toBeDisabled();

        // Choosing one opens it.
        const signatory = page.getByLabel('Підписант');
        test.skip((await signatory.locator('option').count()) < 2, 'No signatories in reference data');
        await signatory.selectOption({ index: 1 });

        await expect(page.getByRole('button', { name: 'Оформити замовлення' })).toBeEnabled();
    });
});

// ─── Driving the status machine ─────────────────────────────────────

test.describe('Order status machine', () => {
    test('an internal order goes new → in progress → issued, and the history records it', async ({ page }) => {
        await signedInWithShift(page);

        const number = await createOrder(page, { quantity: 2 });
        await openOrder(page, number);
        expect(await status(page)).toBe('Нове');

        expect(await act(page, /^В роботу$/), 'The order must accept "В роботу"').toBeTruthy();
        expect(await status(page)).toMatch(/роботі|роботу/i);

        expect(await act(page, /Виконано\/Видано/), 'The order must accept completion').toBeTruthy();
        expect(await status(page)).toBe('Завершено');

        // Each step has to leave a row behind, not just move the badge.
        const detail = await page.locator('main').innerText();
        expect(detail).toContain('Історія статусів');
        expect(
            (detail.match(/\d{2}\.\d{2}, \d{2}:\d{2}/g) ?? []).length,
            'The history must hold a row per transition',
        ).toBeGreaterThan(1);
    });

    test('a finished order offers no way forward', async ({ page }) => {
        await signedInWithShift(page);

        const number = await createOrder(page, { quantity: 1 });
        await openOrder(page, number);

        await act(page, /^В роботу$/);
        await act(page, /Виконано\/Видано/);

        // Terminal: the forward actions are gone, and so is cancellation.
        await expect(page.getByRole('button', { name: /^В роботу$/ })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /Виконано\/Видано/ })).toHaveCount(0);
    });

    test('cancelling without a reason does not cancel', async ({ page }) => {
        await signedInWithShift(page);

        const number = await createOrder(page, { quantity: 1 });
        await openOrder(page, number);

        const cancel = page.getByRole('button', { name: /^Скасувати$/ }).first();
        test.skip(!(await cancel.count()), 'This order cannot be cancelled from here');
        await cancel.click();

        const reason = page.locator('textarea').first();
        await expect(reason).toBeVisible();

        // With the reason empty, confirmation is not on offer.
        const confirm = page.getByRole('button', { name: /^Скасувати замовлення$/ });
        await expect(confirm, 'Cancelling with no reason must not be possible').toBeDisabled();

        // A too-short reason does not count either — the dialog wants five characters.
        await reason.fill('ні');
        await expect(confirm, 'A token reason must not be enough').toBeDisabled();

        // A real one opens it, and then it goes through.
        await reason.fill('E2E: клієнт передумав');
        await expect(confirm).toBeEnabled();

        const before = await status(page);
        await confirm.click();
        await waitForStatusChange(page, before);

        expect(await status(page)).toMatch(/Скасован/i);
    });
});
// ─── Payment reaches the ledger ─────────────────────────────────────

test.describe('Payment and the ledger', () => {
    /**
     * The one path where an order touches money. Internal orders are billed at
     * cost and never reach the till, so this has to be a commercial one:
     * new → in progress → ready → paid, with a payment method on the way.
     */
    test('paying a commercial order writes its amount into the ledger', async ({ page }) => {
        await signedInWithShift(page);

        const number = await createOrder(page, { quantity: 3, commercial: true });
        expect(number, 'A commercial order must get a COM number').toMatch(/^COM-/);

        await openOrder(page, number);
        const amount = (await page.locator('main').innerText()).match(/Всього:\s*(\d+\.\d{2})/)?.[1];
        expect(amount, 'The order must show a total to be paid').toBeTruthy();

        expect(await act(page, /^В роботу$/)).toBeTruthy();
        expect(await act(page, /^Готово$/)).toBeTruthy();

        // One payment method is enabled by default, and that is what production
        // runs — so there is nothing to choose and the click pays outright.
        //
        // This used to select a method and press «Підтвердити», which is why the
        // test was flaky: the screen put the dialog up and sent the PATCH in the
        // same tick, so the click raced the response, and when it lost, the
        // button was detached for good (the order is terminal by then). The
        // dialog is gone; a second status request would have been an
        // optimistic-lock error on a payment that had already gone through.
        const statusRequests: string[] = [];
        page.on('request', r => {
            if (/\/orders\/\d+\/status/.test(r.url())) statusRequests.push(r.method());
        });

        // Hold the response open, so "while the request is in flight" is a
        // window wide enough to look at rather than a race to lose. Without it
        // the dialog is gone before any assertion can see it, and the bug reads
        // as absent.
        await page.route(/\/orders\/\d+\/status/, async route => {
            await new Promise(resolve => setTimeout(resolve, 1500));
            await route.continue();
        });

        const beforePayment = await status(page);
        await page.getByRole('button', { name: /Оплачено\/Видано/ }).click();

        // The screen must not be asking anything: there is one method, so the
        // click pays outright. A dialog here is a confirmation for a request
        // that has already gone, and pressing its button sends a second one
        // carrying the version captured before the first.
        await page.waitForTimeout(400);
        expect(
            await page.getByLabel('Спосіб оплати').count(),
            'the payment dialog must not appear when there is nothing to choose',
        ).toBe(0);

        await waitForStatusChange(page, beforePayment);
        await page.unroute(/\/orders\/\d+\/status/);

        expect(await status(page)).toMatch(/Оплачен|Видано/i);
        expect(statusRequests, 'one payment, one status request').toHaveLength(1);

        await page.goto('/ledger/history');
        await page.waitForLoadState('networkidle');
        const journal = await page.locator('main').innerText();

        expect(
            journal,
            `The ledger must carry ${amount} for ${number} — the till and the orders must agree`,
        ).toContain(amount);
    });
});

// ─── Stale writes ───────────────────────────────────────────────────
//
// Deliberately not tested from here. Two tabs in one Playwright context share a
// session, so they also share the flash bag: the second tab renders the first
// tab's "Статус замовлення оновлено." and the assertion reads a success that
// belongs to someone else. The guarantee itself — a stale `version`, and a
// transition that already happened, both refused and neither adding a status
// history row — is pinned in tests/Feature/OrderWorkflowTest.php, where the two
// requests can be issued independently.
