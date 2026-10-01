import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import OptionChip from '@/Components/Constructor/OptionChip.vue';

const baseOption = {
    id: 1,
    name: 'А4: Папір 80 г/м²',
    price_markup: 0,
    inventory_item_id: null,
};

describe('OptionChip.vue', () => {
    // ─── Rendering ────────────────────────────────────

    it('renders option short name (after colon)', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption },
        });
        expect(wrapper.text()).toContain('Папір 80 г/м²');
        expect(wrapper.text()).not.toContain('А4:');
    });

    it('renders full name if no colon', () => {
        const wrapper = mount(OptionChip, {
            props: { option: { ...baseOption, name: 'Ламінація' } },
        });
        expect(wrapper.text()).toContain('Ламінація');
    });

    // ─── Selected State ───────────────────────────────

    it('shows checkmark when selected', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, selected: true },
        });
        expect(wrapper.find('.bg-indigo-500').exists()).toBe(true);
    });

    it('hides checkmark when not selected', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, selected: false },
        });
        expect(wrapper.find('.bg-indigo-500').exists()).toBe(false);
    });

    it('applies selected border style', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, selected: true },
        });
        expect(wrapper.classes()).toContain('border-indigo-500');
    });

    it('applies unselected border style', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, selected: false },
        });
        expect(wrapper.classes()).toContain('border-gray-200');
    });

    // ─── aria-pressed ─────────────────────────────────

    it('sets aria-pressed=true when selected', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, selected: true },
        });
        expect(wrapper.attributes('aria-pressed')).toBe('true');
    });

    it('sets aria-pressed=false when not selected', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, selected: false },
        });
        expect(wrapper.attributes('aria-pressed')).toBe('false');
    });

    // ─── Disabled ─────────────────────────────────────

    it('is disabled when disabled prop is true', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, disabled: true },
        });
        expect(wrapper.attributes('disabled')).toBeDefined();
        expect(wrapper.classes()).toContain('opacity-40');
    });

    // ─── Price Markup ─────────────────────────────────

    it('shows price markup when > 0', () => {
        const wrapper = mount(OptionChip, {
            props: { option: { ...baseOption, price_markup: 1.5 } },
        });
        expect(wrapper.text()).toContain('+1.50 грн/шт');
    });

    it('hides price markup when 0', () => {
        const wrapper = mount(OptionChip, {
            props: { option: { ...baseOption, price_markup: 0 } },
        });
        expect(wrapper.text()).not.toContain('грн/шт');
    });

    // ─── Stock Level ──────────────────────────────────

    it('shows green dot when stock is ok', () => {
        const wrapper = mount(OptionChip, {
            props: {
                option: { ...baseOption, inventory_item_id: 10 },
                stock: { 10: { qty: 100, min: 20 } },
            },
        });
        expect(wrapper.find('.bg-green-400').exists()).toBe(true);
    });

    it('shows amber dot when stock is low', () => {
        const wrapper = mount(OptionChip, {
            props: {
                option: { ...baseOption, inventory_item_id: 10 },
                stock: { 10: { qty: 5, min: 20 } },
            },
        });
        expect(wrapper.find('.bg-amber-400').exists()).toBe(true);
    });

    it('shows red dot when stock is out', () => {
        const wrapper = mount(OptionChip, {
            props: {
                option: { ...baseOption, inventory_item_id: 10 },
                stock: { 10: { qty: 0, min: 20 } },
            },
        });
        expect(wrapper.find('.bg-red-400').exists()).toBe(true);
    });

    it('hides stock dot when no inventory_item_id', () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption, stock: {} },
        });
        expect(wrapper.find('.bg-green-400').exists()).toBe(false);
        expect(wrapper.find('.bg-amber-400').exists()).toBe(false);
        expect(wrapper.find('.bg-red-400').exists()).toBe(false);
    });

    // ─── Events ───────────────────────────────────────

    it('emits click with option data', async () => {
        const wrapper = mount(OptionChip, {
            props: { option: baseOption },
        });
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
        expect(wrapper.emitted('click')[0][0]).toEqual(baseOption);
    });
});
