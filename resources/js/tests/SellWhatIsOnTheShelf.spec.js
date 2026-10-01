import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StepTileSelector from '@/Components/Constructor/StepTileSelector.vue';
import ConstructorPanel from '@/Components/Constructor/ConstructorPanel.vue';
import RisoCalculator from '@/Components/Riso/RisoCalculator.vue';

/**
 * «Продаємо те, що на полиці» — owner's decision, 2026-08-04.
 *
 * It narrows the decision of 2026-07-31 («zero means order it anyway») to its
 * tail: the option stays on screen, and stops being orderable. The exception is
 * cutting — A3 in the building is A4 in the building.
 *
 * Two things this must never do, both measured on production before it was
 * written and both pinned below:
 *
 *  - it must not HIDE anything. Eleven hard-binding sizes have every colour at
 *    zero; if the rule were folded into `hasOfferableStock`, the forward check
 *    in ConstructorPanel would drop those sizes from the form without a word.
 *    That is R16-2, rebuilt by hand two months after it was fixed.
 *  - it must not reach the backdated forms. They record what already happened,
 *    and July's paper is often August's empty shelf.
 */

const stock = {
    1: { qty: 120, min: 20 },                 // plenty
    2: { qty: 0, min: 5 },                    // zero, nothing to cut from
    3: { qty: 0, min: 5, conv_from: 4 },      // zero, but A3 is in the building
    4: { qty: 1125, min: 50 },                // the A3
};

const options = [
    { id: 11, name: 'Папір 80 г/м²', price_markup: 0, is_active: true, inventory_item_id: 1 },
    { id: 12, name: 'Папір 300 г/м² (крейдований)', price_markup: 0, is_active: true, inventory_item_id: 2 },
    { id: 13, name: 'Папір 160 г/м² (паст. рожевий)', price_markup: 0, is_active: true, inventory_item_id: 3 },
];

const group = { id: 1, name: 'Тип паперу', is_required: true };

function tiles(props = {}) {
    return mount(StepTileSelector, {
        props: { group, options, selectedId: null, stock, ...props },
    });
}

describe('StepTileSelector — sellable is narrower than visible', () => {
    it('keeps every option on screen, whatever the shelf says', () => {
        expect(tiles().findAll('button')).toHaveLength(3);
    });

    // By text, not by index: the tiles are regrouped into plain / pastel /
    // coated rows, so position on screen is not position in the array.
    const tile = (wrapper, text) =>
        wrapper.findAll('button').find(b => b.text().includes(text));

    it('sells what is in stock', () => {
        expect(tile(tiles(), '80 г/м²').attributes('disabled')).toBeUndefined();
    });

    it('refuses zero with nothing to cut from', () => {
        const button = tile(tiles(), 'крейдований');

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toContain('обрати не можна');
    });

    it('sells zero that can be cut from a stocked A3', () => {
        const button = tile(tiles(), 'паст. рожевий');

        expect(button.attributes('disabled')).toBeUndefined();
        expect(button.attributes('title')).toContain('розрізку');
    });

    /** The backdated carve-out: today's shelf must not edit July. */
    it('sells everything when stock is not being enforced', () => {
        const buttons = tiles({ enforceStock: false }).findAll('button');

        expect(buttons.every(b => b.attributes('disabled') === undefined)).toBe(true);
    });

    /** The cascade arrives as a prop, because only the panel knows parents. */
    it('greys out an option the panel has ruled unsellable', () => {
        const button = tiles({ disabledIds: [11] }).findAll('button')[0];

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toContain('жодного варіанта');
    });
});

/**
 * The parent cascade, on the shape production actually has: a size that carries
 * no stock of its own, and colours that do.
 */
describe('ConstructorPanel — a size whose every colour is empty', () => {
    const service = {
        id: 1,
        name: 'Палітурка тверда',
        parameter_groups: [
            {
                id: 10,
                name: 'Розмір',
                ui_type: 'radio',
                ui_style: 'tiles_large',
                is_required: true,
                options: [
                    { id: 101, name: '3.5 мм', is_active: true, inventory_item_id: null, price_markup: 0 },
                    { id: 102, name: '13 мм', is_active: true, inventory_item_id: null, price_markup: 0 },
                ],
            },
            {
                id: 20,
                name: 'Колір',
                ui_type: 'radio',
                ui_style: 'tiles_small',
                is_required: true,
                options: [
                    {
                        id: 201, name: '3.5 мм: Синій', is_active: true, price_markup: 0,
                        inventory_item_id: 2, depends_on: { group_id: 10, option_ids: [101] },
                    },
                    {
                        id: 202, name: '13 мм: Синій', is_active: true, price_markup: 0,
                        inventory_item_id: 1, depends_on: { group_id: 10, option_ids: [102] },
                    },
                ],
            },
        ],
    };

    const panel = (props = {}) =>
        mount(ConstructorPanel, { props: { service, stock, ...props } });

    it('leaves the empty size on screen — hiding it is R16-2', () => {
        expect(panel().text()).toContain('3.5 мм');
    });

    it('rules the empty size unsellable', () => {
        expect(panel().vm.unsellableOptionIds(service.parameter_groups[0])).toEqual([101]);
    });

    it('leaves a size alone while one of its colours is in stock', () => {
        expect(panel().vm.unsellableOptionIds(service.parameter_groups[0])).not.toContain(102);
    });

    it('rules nothing unsellable on a backdated form', () => {
        expect(
            panel({ enforceStock: false }).vm.unsellableOptionIds(service.parameter_groups[0]),
        ).toEqual([]);
    });
});

describe('RisoCalculator — the paper it pre-selects', () => {
    const papers = [
        { id: 2, name: 'Папір А3 300 г/м² (крейдований)', avg_cost: 3.0, unit: 'арк' },
        { id: 1, name: 'Папір А3 80 г/м²', avg_cost: 0.8, unit: 'арк' },
    ];

    const tiers = [{ id: 1, min_qty: 1, max_qty: null, cost_per_copy: 1.05 }];

    it('never lands on a paper the operator may not sell', () => {
        const wrapper = mount(RisoCalculator, {
            props: { service: { id: 1, name: 'Різо' }, tiers, papers, paperCost: 0.8, stock },
        });

        expect(wrapper.vm.selectedPaperId).toBe(1);
    });

    /** Only the empty paper exists: selling forms select nothing at all. */
    it('selects nothing rather than something unsellable', () => {
        const wrapper = mount(RisoCalculator, {
            props: {
                service: { id: 1, name: 'Різо' }, tiers,
                papers: [papers[0]], paperCost: 0.8, stock,
            },
        });

        expect(wrapper.vm.selectedPaperId).toBeNull();
    });

    it('still pre-selects it when recording the past', () => {
        const wrapper = mount(RisoCalculator, {
            props: {
                service: { id: 1, name: 'Різо' }, tiers,
                papers: [papers[0]], paperCost: 0.8, stock, enforceStock: false,
            },
        });

        expect(wrapper.vm.selectedPaperId).toBe(2);
    });
});
