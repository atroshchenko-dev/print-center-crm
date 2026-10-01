import { describe, it, expect } from 'vitest';
import { selectableIn, isGroupSelected, toggleGroup } from '@/composables/useBlockSelection';

/**
 * «Обрати все» on the reconciliation screen, scoped to its own service block —
 * owner's decision, 2026-07-31, on a question deliberately left alone four
 * rounds running because both behaviours were defensible.
 *
 * The old version was a single computed over the whole page while the checkbox
 * was drawn inside every block's header: ticking it in «Друк» selected
 * «Ламінування» too and lit up every other block's box at the same time.
 */
describe('useBlockSelection', () => {
    const print = {
        name: 'Чорно-білий друк',
        orders: [
            { id: 1, is_reconciled: false },
            { id: 2, is_reconciled: false },
            { id: 3, is_reconciled: true },   // already done — not the checkbox's to take
        ],
    };

    const lamination = {
        name: 'Ламінування',
        orders: [
            { id: 10, is_reconciled: false },
            { id: 11, is_reconciled: false },
        ],
    };

    it('owns only the orders of its own block that are still open', () => {
        expect(selectableIn(print)).toEqual([1, 2]);
        expect(selectableIn(lamination)).toEqual([10, 11]);
    });

    /** The defect, as a test: ticking one block must not reach into another. */
    it('leaves the other block alone', () => {
        const selected = toggleGroup(print, true, []);

        expect(selected).toEqual([1, 2]);
        expect(isGroupSelected(print, selected)).toBe(true);
        expect(isGroupSelected(lamination, selected)).toBe(false);
    });

    it('unticking removes only its own block', () => {
        const both = toggleGroup(lamination, true, toggleGroup(print, true, []));
        expect(both).toEqual([1, 2, 10, 11]);

        const afterUntick = toggleGroup(print, false, both);

        expect(afterUntick).toEqual([10, 11]);
        expect(isGroupSelected(lamination, afterUntick)).toBe(true);
    });

    it('does not duplicate what is already selected', () => {
        const selected = toggleGroup(print, true, [1]);
        expect(selected).toEqual([1, 2]);
    });

    it('is not "all selected" until every open order of the block is in', () => {
        expect(isGroupSelected(print, [1])).toBe(false);
        expect(isGroupSelected(print, [1, 2])).toBe(true);
    });

    /**
     * A ticked box over nothing to tick reads as a promise that the batch button
     * will do something.
     */
    it('an empty block is not "all selected"', () => {
        const empty = { name: 'Порожньо', orders: [] };
        const done = { name: 'Все звірено', orders: [{ id: 9, is_reconciled: true }] };

        expect(isGroupSelected(empty, [])).toBe(false);
        expect(isGroupSelected(done, [9])).toBe(false);
    });

    /**
     * Not a bug and not designable away: reconciliation applies to a whole
     * order, the page groups by service, so an order on print *and* lamination
     * sits in both blocks. Selecting either takes it. Reconciling half an order
     * is not a thing the ledger has.
     */
    it('an order in two blocks is taken by either of them', () => {
        const shared = { id: 42, is_reconciled: false };
        const a = { name: 'Друк', orders: [shared] };
        const b = { name: 'Ламінування', orders: [shared] };

        const selected = toggleGroup(a, true, []);

        expect(selected).toEqual([42]);
        expect(isGroupSelected(b, selected)).toBe(true);
    });
});
