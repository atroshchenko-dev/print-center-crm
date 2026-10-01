import { describe, it, expect } from 'vitest';
import { risoOptionLabels } from '@/composables/useConstructorOptions';

/**
 * The chips under a Riso line on the order card.
 *
 * They exist to explain the money, and until round 16 they left out the one
 * figure the money depends on. A3 sheets are rounded up per original, so
 * the same 198 copies cost one thing as 2 originals × 99 and another as
 * 1 original × 198 — 100 sheets at the 100–149 rate against 99 at the 50–99 one.
 * The card showed the quantity and the total and nothing in between.
 */
describe('risoOptionLabels', () => {
    it('names the originals, because they decide the sheet count', () => {
        const labels = risoOptionLabels({
            format: 'A4',
            originals: 2,
            sides: 1,
            paper_name: 'Папір А3 80 г/м²',
        });

        expect(labels).toEqual(['A4', '2 ориг.', '1 стор.', 'Папір А3 80 г/м²']);
    });

    /**
     * Snapshots written before round 14 have no `originals` key. Reading it as 1
     * is right for pricing — that is what those runs were — but printing «1
     * ориг.» on the card would state something the snapshot never recorded.
     */
    it('says nothing about originals when the snapshot did not record them', () => {
        const labels = risoOptionLabels({ format: 'A3', sides: 2, paper_name: 'Папір А3 80 г/м²' });

        expect(labels).toEqual(['A3', '2 стор.', 'Папір А3 80 г/м²']);
    });

    it('drops the placeholder paper name', () => {
        expect(risoOptionLabels({ format: 'A4', paper_name: 'Невідомо' })).toEqual(['A4']);
    });

    it('returns nothing for a missing snapshot', () => {
        expect(risoOptionLabels(null)).toEqual([]);
        expect(risoOptionLabels(undefined)).toEqual([]);
    });
});
