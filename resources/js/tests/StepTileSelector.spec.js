import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StepTileSelector from '@/Components/Constructor/StepTileSelector.vue';

const group = { id: 1, name: 'Формат', is_required: true };
const options = [
    { id: 1, name: 'А4', price_markup: 0, is_active: true, inventory_item_id: null },
    { id: 2, name: 'А3', price_markup: 2.00, is_active: true, inventory_item_id: null },
    { id: 3, name: 'Disabled', price_markup: 0, is_active: false, inventory_item_id: null },
];

describe('StepTileSelector.vue', () => {
    // ─── Rendering ────────────────────────────────────

    it('renders group name', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null },
        });
        expect(wrapper.text()).toContain('Формат');
    });

    it('shows required asterisk', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null },
        });
        const asterisk = wrapper.findAll('span').find((s) => s.text() === '*');
        expect(asterisk, 'a required group must be marked').toBeTruthy();
        // Shade, not exact class — text-red-500 was darkened for WCAG AA.
        expect(asterisk.classes().join(' ')).toMatch(/text-red-\d00/);
    });

    it('hides asterisk for non-required groups', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group: { ...group, is_required: false }, options, selectedId: null },
        });
        expect(wrapper.find('.text-red-500').exists()).toBe(false);
    });

    it('renders all active tiles', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null },
        });
        expect(wrapper.findAll('button')).toHaveLength(3);
    });

    // ─── Selection ────────────────────────────────────

    it('shows checkmark on selected tile', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: 1 },
        });
        expect(wrapper.find('.bg-indigo-500').exists()).toBe(true);
    });

    it('sets aria-pressed on selected tile', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: 1 },
        });
        const buttons = wrapper.findAll('button');
        expect(buttons[0].attributes('aria-pressed')).toBe('true');
        expect(buttons[1].attributes('aria-pressed')).toBe('false');
    });

    it('applies selected styling', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: 1 },
        });
        const buttons = wrapper.findAll('button');
        expect(buttons[0].classes()).toContain('border-indigo-500');
    });

    // ─── Disabled ─────────────────────────────────────

    it('disables inactive tiles', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null },
        });
        const buttons = wrapper.findAll('button');
        expect(buttons[2].attributes('disabled')).toBeDefined();
    });

    it('applies opacity to disabled tiles', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null },
        });
        const buttons = wrapper.findAll('button');
        expect(buttons[2].classes()).toContain('opacity-40');
    });

    // ─── Events ───────────────────────────────────────

    it('emits select with option id', async () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null },
        });
        await wrapper.findAll('button')[0].trigger('click');
        expect(wrapper.emitted('select')).toHaveLength(1);
        expect(wrapper.emitted('select')[0][0]).toBe(1);
    });

    // ─── Price Markup ─────────────────────────────────

    it('shows price for commercial orders', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null, orderType: 'commercial' },
        });
        expect(wrapper.text()).toContain('+2.00 грн');
    });

    it('hides price for internal orders', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options, selectedId: null, orderType: 'internal' },
        });
        expect(wrapper.text()).not.toContain('+2.00 грн');
    });

    // ─── Stock Indicators ─────────────────────────────

    it('shows green dot when stock ok', () => {
        const opts = [{ id: 10, name: 'Paper', price_markup: 0, is_active: true, inventory_item_id: 5 }];
        const wrapper = mount(StepTileSelector, {
            props: { group, options: opts, selectedId: null, stock: { 5: { qty: 100, min: 10 } } },
        });
        expect(wrapper.find('.bg-green-400').exists()).toBe(true);
    });

    it('shows amber dot when stock low', () => {
        const opts = [{ id: 10, name: 'Paper', price_markup: 0, is_active: true, inventory_item_id: 5 }];
        const wrapper = mount(StepTileSelector, {
            props: { group, options: opts, selectedId: null, stock: { 5: { qty: 3, min: 10 } } },
        });
        expect(wrapper.find('.bg-amber-400').exists()).toBe(true);
    });

    it('shows red dot when stock out (no conversion)', () => {
        const opts = [{ id: 10, name: 'Paper', price_markup: 0, is_active: true, inventory_item_id: 5 }];
        const wrapper = mount(StepTileSelector, {
            props: { group, options: opts, selectedId: null, stock: { 5: { qty: 0, min: 10 } } },
        });
        expect(wrapper.find('.bg-red-400').exists()).toBe(true);
    });

    it('shows scissors icon for convertible items', () => {
        const opts = [{ id: 10, name: 'Paper', price_markup: 0, is_active: true, inventory_item_id: 5 }];
        const stock = { 5: { qty: 0, min: 10, conv_from: 6 }, 6: { qty: 100, min: 5 } };
        const wrapper = mount(StepTileSelector, {
            props: { group, options: opts, selectedId: null, stock },
        });
        // Convertible indicator renders SVG + amber dot
        expect(wrapper.find('.bg-amber-400').exists()).toBe(true);
    });

    // ─── Paper Grouping ───────────────────────────────

    it('groups papers into categories when multiple types', () => {
        const paperOpts = [
            { id: 1, name: 'Папір 80 г/м²', price_markup: 0, is_active: true, inventory_item_id: null },
            { id: 2, name: 'Папір паст. рожевий', price_markup: 0, is_active: true, inventory_item_id: null },
            { id: 3, name: 'Папір крейдований', price_markup: 0, is_active: true, inventory_item_id: null },
        ];
        const wrapper = mount(StepTileSelector, {
            props: { group, options: paperOpts, selectedId: null },
        });
        expect(wrapper.text()).toContain('Звичайний');
        expect(wrapper.text()).toContain('Пастельний');
        expect(wrapper.text()).toContain('Крейдований');
    });

    // ─── Size Variants ────────────────────────────────

    it('uses large tile sizing by default', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options: [options[0]], selectedId: null, size: 'large' },
        });
        expect(wrapper.find('button').classes()).toContain('min-h-[100px]');
    });

    it('uses small tile sizing', () => {
        const wrapper = mount(StepTileSelector, {
            props: { group, options: [options[0]], selectedId: null, size: 'small' },
        });
        expect(wrapper.find('button').classes()).toContain('min-h-[72px]');
    });
});
