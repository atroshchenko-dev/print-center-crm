import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import BrochureConstructor from '@/Components/Brochure/BrochureConstructor.vue';
import RisoCalculator from '@/Components/Riso/RisoCalculator.vue';

/**
 * One rule for "can this paper still be offered", across all three forms.
 *
 * `useStockAvailability.hasOfferableStock` was extracted in round 16 because
 * the constructor answered this question twice and the two copies disagreed
 * about zero. The owner decided it on 2026-07-31: **zero means "we are out of
 * these, order them anyway"** — only a deficit with nothing to convert from is
 * hidden. That decision is what made the four hard-binding sizes come back.
 *
 * The extraction stopped at the constructor. Two more copies stayed behind and
 * both broke the decision:
 *
 *   BrochureConstructor  hid at `qty <= 0` — a paper at exactly zero vanished
 *   RisoCalculator       hid at `qty <= 0` and ignored convertibility entirely
 *
 * So the same sheet at zero was offered with a red dot in one form and did not
 * exist in the other two. This spec pins all three to the same answer.
 */

const stock = {
    1: { qty: 120, min: 20, conv_from: null },  // plenty
    2: { qty: 0, min: 10, conv_from: null },    // exactly zero — offerable
    3: { qty: -5, min: 10, conv_from: null },   // deficit, nothing to cut from
    4: { qty: -5, min: 10, conv_from: 5 },      // deficit, but the source has stock
    5: { qty: 400, min: 50, conv_from: null },  // the source
};

const papers = [
    { id: 1, name: 'Папір А3 80г', avg_cost: 0.8, unit: 'арк' },
    { id: 2, name: 'Папір А3 160г зелений', avg_cost: 1.6, unit: 'арк' },
    { id: 3, name: 'Папір А3 300г крейдований', avg_cost: 3.0, unit: 'арк' },
    { id: 4, name: 'Папір А3 250г', avg_cost: 2.5, unit: 'арк' },
];

const service = { id: 1, name: 'Тестова послуга', service_type: 'riso' };

const tiers = [
    { id: 1, min_copies: 1, max_copies: 99, price_per_copy: 1.5 },
    { id: 2, min_copies: 100, max_copies: null, price_per_copy: 1.05 },
];

describe('a paper at zero is offered everywhere, and a hopeless deficit nowhere', () => {
    describe('RisoCalculator.vue', () => {
        const mountRiso = () =>
            mount(RisoCalculator, {
                props: { service, tiers, papers, paperCost: 0.8, stock },
            });

        // Riso strips the format from the label: «Папір А3 160г зелений» → «160г зелений».

        it('offers a paper with stock', () => {
            expect(mountRiso().text()).toContain('80г');
        });

        it('offers a paper at exactly zero — the owner said order it anyway', () => {
            expect(
                mountRiso().text(),
                'zero is "we are out, order it anyway", not "it does not exist"',
            ).toContain('160г зелений');
        });

        it('hides a deficit with nothing to cut from', () => {
            expect(mountRiso().text()).not.toContain('300г крейдований');
        });

        it('offers a deficit that can still be cut from a stocked source', () => {
            expect(
                mountRiso().text(),
                'the Riso copy ignored convertibility entirely',
            ).toContain('250г');
        });
    });

    describe('BrochureConstructor.vue', () => {
        // Default format is А4, which is printed on А3 sheets.
        const mountBrochure = () =>
            mount(BrochureConstructor, {
                props: { service: { ...service, service_type: 'brochure' }, papers, stock },
            });

        it('offers a paper with stock', () => {
            expect(mountBrochure().text()).toContain('А3 80г');
        });

        it('offers a paper at exactly zero — the owner said order it anyway', () => {
            expect(
                mountBrochure().text(),
                'zero is "we are out, order it anyway", not "it does not exist"',
            ).toContain('А3 160г зелений');
        });

        it('hides a deficit with nothing to cut from', () => {
            expect(mountBrochure().text()).not.toContain('А3 300г крейдований');
        });

        it('offers a deficit that can still be cut from a stocked source', () => {
            expect(mountBrochure().text()).toContain('А3 250г');
        });
    });
});
