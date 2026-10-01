import { describe, it, expect } from 'vitest';
import { findRisoTier } from '@/composables/useRisoTier';

/**
 * Which tier prices a Riso run — audit R30-1 and R30-2.
 *
 * The rule lived twice, and the client's copy carried a comment saying it
 * matched the server's. It did not, and both fell into the same hole: any
 * quantity with no tier — including a **gap inside the ladder** — was priced
 * from the lowest-numbered row, which on this ladder is the dearest.
 *
 * Measured on the seeded ladder before the fix: deleting the 200–249 tier
 * moved a 220-sheet run from 0,1400 to 0,4100 per copy — 30,80 ₴ of printing
 * became 90,20 ₴.
 */
const ladder = [
    { min_qty: 50,  max_qty: 99,   cost_per_copy: '0.4100' },
    { min_qty: 100, max_qty: 149,  cost_per_copy: '0.2300' },
    { min_qty: 150, max_qty: 199,  cost_per_copy: '0.1700' },
    { min_qty: 200, max_qty: 249,  cost_per_copy: '0.1400' },
    { min_qty: 500, max_qty: null, cost_per_copy: '0.0900' },
];

describe('findRisoTier', () => {
    it('takes the step a run actually falls on', () => {
        expect(findRisoTier(ladder, 220).cost_per_copy).toBe('0.1400');
        expect(findRisoTier(ladder, 50).cost_per_copy).toBe('0.4100');
        expect(findRisoTier(ladder, 199).cost_per_copy).toBe('0.1700');
    });

    it('uses the open-ended step for anything above it', () => {
        expect(findRisoTier(ladder, 5000).cost_per_copy).toBe('0.0900');
    });

    /**
     * The hole. `250–499` is missing from this ladder, and the answer must be
     * «no price», not «the dearest price».
     */
    it('refuses a run that falls into a gap, even on a screen allowed to go below the ladder', () => {
        expect(findRisoTier(ladder, 300)).toBeNull();
        expect(findRisoTier(ladder, 300, true)).toBeNull();
    });

    /**
     * Below the ladder is the case somebody did decide: retro screens price a
     * ten-sheet run at the lowest tier. Fixing the hole must not take this away.
     */
    it('still prices a run below the ladder from the lowest step, when allowed', () => {
        expect(findRisoTier(ladder, 10, true).cost_per_copy).toBe('0.4100');
    });

    it('says nothing about a run below the ladder on an ordinary order screen', () => {
        expect(findRisoTier(ladder, 10)).toBeNull();
    });

    it('does not fall over on an empty ladder', () => {
        expect(findRisoTier([], 220)).toBeNull();
        expect(findRisoTier(undefined, 220, true)).toBeNull();
    });
});
