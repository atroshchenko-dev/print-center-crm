import { describe, it, expect } from 'vitest';
import { hasOfferableStock, isSelectable, stockState } from '@/composables/useStockAvailability';

/**
 * The rule that decides whether an option is still offered on its own stock.
 *
 * It existed twice inside ConstructorPanel and the copies disagreed about zero,
 * which is how four of the eight hard-binding sizes became unorderable on
 * production while the public price list advertised all eight. The point of
 * these tests is not the arithmetic — it is that there is now one rule to test.
 */
describe('hasOfferableStock', () => {
    const stock = {
        10: { qty: 12 },                    // in stock
        20: { qty: 0 },                     // out of stock, not a deficit
        30: { qty: -4 },                    // deficit, nothing to convert from
        40: { qty: -4, conv_from: 50 },     // deficit, source has stock
        50: { qty: 100 },
        60: { qty: -4, conv_from: 70 },     // deficit, source is empty too
        70: { qty: 0 },
    };

    it('offers an option with stock', () => {
        expect(hasOfferableStock({ inventory_item_id: 10 }, stock)).toBe(true);
    });

    /**
     * The decision this whole change rests on (owner, 2026-07-31): zero means
     * "we are out of these, order them anyway", and the screen says so with a
     * red dot. A cover at zero must stay offerable — and, just as importantly,
     * must still count as a reason to keep its parent size on the form.
     */
    it('offers an option at zero — that is a red dot, not a disappearance', () => {
        expect(hasOfferableStock({ inventory_item_id: 20 }, stock)).toBe(true);
    });

    it('hides a deficit with nothing to convert from', () => {
        expect(hasOfferableStock({ inventory_item_id: 30 }, stock)).toBe(false);
    });

    it('offers a deficit that can still be converted into', () => {
        expect(hasOfferableStock({ inventory_item_id: 40 }, stock)).toBe(true);
    });

    it('hides a deficit whose convertible source is empty as well', () => {
        expect(hasOfferableStock({ inventory_item_id: 60 }, stock)).toBe(false);
    });

    /** An option that consumes nothing from the warehouse is never gated by it. */
    it('offers an option with no inventory behind it', () => {
        expect(hasOfferableStock({ inventory_item_id: null }, stock)).toBe(true);
        expect(hasOfferableStock({}, stock)).toBe(true);
    });

    /**
     * A missing entry is not evidence of absence: the map is built from the
     * items the page happened to load, and treating "unknown" as "gone" would
     * empty the form on any page that ships a partial map.
     */
    it('offers an option the stock map says nothing about', () => {
        expect(hasOfferableStock({ inventory_item_id: 999 }, stock)).toBe(true);
        expect(hasOfferableStock({ inventory_item_id: 10 }, null)).toBe(true);
    });

    /**
     * The production case, in miniature: a size carries no stock of its own and
     * is kept on the form as long as *any* of its colours is offerable. All four
     * colours at zero used to remove the size; now zero keeps it.
     */
    it('keeps a parent whose children are all at zero', () => {
        const colours = [{ inventory_item_id: 20 }, { inventory_item_id: 70 }];
        expect(colours.some(c => hasOfferableStock(c, stock))).toBe(true);
    });

    it('drops a parent whose children are all in deficit', () => {
        const colours = [{ inventory_item_id: 30 }, { inventory_item_id: 60 }];
        expect(colours.some(c => hasOfferableStock(c, stock))).toBe(false);
    });
});

/**
 * Selling is a narrower question than showing — owner's decision 2026-08-04.
 *
 * An operator sells off the shelf. Zero with nothing to cut from cannot go on an
 * order, even though it stays on screen. Measured before the rule was written:
 * on production it takes 53 constructor options out of the sellable set, and
 * eleven hard-binding sizes lose every colour they have.
 */
describe('isSelectable', () => {
    const stock = {
        10: { qty: 12 },                    // in stock
        20: { qty: 0 },                     // zero, nothing to cut from
        30: { qty: -4 },                    // deficit
        40: { qty: 0, conv_from: 50 },      // zero, but A3 is in the building
        50: { qty: 1125 },
        60: { qty: 0, conv_from: 70 },      // zero, and so is its source
        70: { qty: 0 },
    };

    it('sells what is on the shelf', () => {
        expect(isSelectable(10, stock)).toBe(true);
    });

    it('refuses zero with nothing to cut from', () => {
        expect(isSelectable(20, stock)).toBe(false);
    });

    it('refuses a deficit', () => {
        expect(isSelectable(30, stock)).toBe(false);
    });

    /** The exception the operator asked for by name: A3 in the building is A4 in the building. */
    it('sells zero that can be cut from a stocked source', () => {
        expect(isSelectable(40, stock)).toBe(true);
    });

    it('refuses zero whose source is empty too', () => {
        expect(isSelectable(60, stock)).toBe(false);
    });

    it('never blocks an option with no inventory behind it', () => {
        expect(isSelectable(null, stock)).toBe(true);
        expect(isSelectable(999, stock)).toBe(true);
        expect(isSelectable(10, null)).toBe(true);
    });

    /**
     * The line this rule must not cross. Visibility stays with
     * `hasOfferableStock`, which still says yes at zero — otherwise the forward
     * check in ConstructorPanel would hide the eleven sizes instead of greying
     * them, and that is R16-2 built again by hand.
     */
    it('is stricter than visibility, and visibility is unchanged', () => {
        expect(isSelectable(20, stock)).toBe(false);
        expect(hasOfferableStock({ inventory_item_id: 20 }, stock)).toBe(true);
    });
});

/**
 * One vocabulary for the indicator. Six components had their own, and four of
 * them had never heard of «convertible» — so a paper backed by 1125 sheets of A3
 * showed a plain «немає» in four places and the truth in two.
 */
describe('stockState', () => {
    const stock = {
        10: { qty: 120, min: 20 },
        11: { qty: 12, min: 20 },           // at or under the minimum
        12: { qty: 20, min: 20 },           // exactly on it
        20: { qty: 0, min: 5 },
        40: { qty: 0, min: 5, conv_from: 50 },
        50: { qty: 1125, min: 50 },
        60: { qty: 0, min: 5, conv_from: 70 },
        70: { qty: 0, min: 5 },
    };

    it('reads plenty as ok', () => {
        expect(stockState(10, stock)).toBe('ok');
    });

    it('reads at-or-under the minimum as low', () => {
        expect(stockState(11, stock)).toBe('low');
        expect(stockState(12, stock)).toBe('low');
    });

    it('reads zero with a stocked source as convertible', () => {
        expect(stockState(40, stock)).toBe('convertible');
    });

    it('reads zero with an empty source as out', () => {
        expect(stockState(60, stock)).toBe('out');
        expect(stockState(20, stock)).toBe('out');
    });

    it('says nothing about what has no inventory behind it', () => {
        expect(stockState(null, stock)).toBeNull();
        expect(stockState(999, stock)).toBeNull();
    });

    /** Whatever is sellable must never read as «out», and vice versa. */
    it('agrees with isSelectable on every item in the map', () => {
        for (const id of Object.keys(stock).map(Number)) {
            expect(stockState(id, stock) === 'out').toBe(!isSelectable(id, stock));
        }
    });
});
