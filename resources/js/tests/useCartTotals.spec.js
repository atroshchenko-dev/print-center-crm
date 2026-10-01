import { describe, it, expect } from 'vitest';
import { commercialTotal, commercialUnknown } from '@/composables/useCartTotals';

/**
 * The cart's commercial total — audit R32-1 and R32-2.
 *
 * Two constructors stated a commercial price they could not know:
 * `RisoCalculator` sent the cost (the server multiplies it by
 * `riso_commercial_markup`, 2,0 by default) and `BrochureConstructor` sent
 * zero (the server applies 2×). The page receives neither multiplier, so
 * neither number was computed — both were invented.
 *
 * And `?? 0` in the cart turned the second one into a silent zero: an item
 * worth nothing looks exactly like an item priced at nothing.
 *
 * The rule is round 30's, applied one layer up: **a missing price is shown as
 * missing, not as a number.**
 */
describe('commercialTotal', () => {
    it('adds up what it knows', () => {
        const cart = [
            { pricing: { total_price_commercial: 120.5 } },
            { pricing: { total_price_commercial: 79.5 } },
        ];

        expect(commercialTotal(cart)).toEqual({ total: 200, incomplete: false });
    });

    it('says the total is incomplete rather than counting an unknown as zero', () => {
        const cart = [
            { pricing: { total_price_commercial: 120 } },
            { pricing: { total_price_commercial: null } },   // Різо або брошура
        ];

        const { total, incomplete } = commercialTotal(cart);

        expect(total).toBe(120);
        expect(incomplete).toBe(true);
    });

    /**
     * A genuine zero is a price, and it is not the same thing as no price.
     * The diploma constructor sends zero on purpose — the server prices
     * diplomas at zero commercially — and it must keep summing as zero.
     */
    it('keeps a real zero as a real zero', () => {
        const cart = [{ pricing: { total_price_commercial: 0 } }];

        expect(commercialTotal(cart)).toEqual({ total: 0, incomplete: false });
    });

    it('treats a missing pricing block as unknown, not as zero', () => {
        expect(commercialTotal([{}])).toEqual({ total: 0, incomplete: true });
        expect(commercialTotal([{ pricing: {} }])).toEqual({ total: 0, incomplete: true });
    });

    it('does not fall over on an empty cart', () => {
        expect(commercialTotal([])).toEqual({ total: 0, incomplete: false });
        expect(commercialTotal(undefined)).toEqual({ total: 0, incomplete: false });
    });
});

describe('commercialUnknown', () => {
    it('separates «no price» from «zero»', () => {
        expect(commercialUnknown({ pricing: { total_price_commercial: null } })).toBe(true);
        expect(commercialUnknown({ pricing: {} })).toBe(true);
        expect(commercialUnknown({})).toBe(true);

        expect(commercialUnknown({ pricing: { total_price_commercial: 0 } })).toBe(false);
        expect(commercialUnknown({ pricing: { total_price_commercial: 12.3 } })).toBe(false);
    });
});
