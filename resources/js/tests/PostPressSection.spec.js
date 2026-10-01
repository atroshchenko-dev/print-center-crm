import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PostPressSection from '@/Components/Constructor/PostPressSection.vue';

const group = { id: 1, name: 'Пост-обробка', is_required: false };
const options = [
    { id: 10, name: 'Ламінація', price_markup: 2.50, is_active: true, inventory_item_id: null },
    { id: 11, name: 'Скріплення', price_markup: 0, is_active: true, inventory_item_id: null },
    { id: 12, name: 'Фальцювання', price_markup: 1.00, is_active: false, inventory_item_id: null },
];

describe('PostPressSection.vue', () => {
    // ─── Rendering ────────────────────────────────────

    it('renders group name', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [] },
        });
        expect(wrapper.text()).toContain('Пост-обробка');
    });

    it('shows (опціонально) for non-required groups', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [] },
        });
        expect(wrapper.text()).toContain('(опціонально)');
    });

    it('hides (опціонально) for required groups', () => {
        const wrapper = mount(PostPressSection, {
            props: { group: { ...group, is_required: true }, options, selectedIds: [] },
        });
        expect(wrapper.text()).not.toContain('(опціонально)');
    });

    it('renders all options', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [] },
        });
        expect(wrapper.findAll('label')).toHaveLength(3);
    });

    // ─── Selection ────────────────────────────────────

    it('checks selected options', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [10] },
        });
        const checkboxes = wrapper.findAll('input[type="checkbox"]');
        expect(checkboxes[0].element.checked).toBe(true);
        expect(checkboxes[1].element.checked).toBe(false);
    });

    it('applies selected styling', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [10] },
        });
        const labels = wrapper.findAll('label');
        expect(labels[0].classes()).toContain('border-indigo-500');
        expect(labels[1].classes()).toContain('border-gray-200');
    });

    // ─── Disabled ─────────────────────────────────────

    it('disables inactive options', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [] },
        });
        const checkboxes = wrapper.findAll('input[type="checkbox"]');
        expect(checkboxes[2].element.disabled).toBe(true); // Фальцювання is_active=false
    });

    it('applies opacity to inactive options', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [] },
        });
        const labels = wrapper.findAll('label');
        expect(labels[2].classes()).toContain('opacity-40');
    });

    // ─── Price Markup ─────────────────────────────────

    it('shows price markup for options with price > 0', () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [] },
        });
        expect(wrapper.text()).toContain('+2.50 грн');
        expect(wrapper.text()).toContain('+1.00 грн');
    });

    // ─── Events ───────────────────────────────────────

    it('emits toggle with option id on change', async () => {
        const wrapper = mount(PostPressSection, {
            props: { group, options, selectedIds: [] },
        });
        const checkboxes = wrapper.findAll('input[type="checkbox"]');
        await checkboxes[0].setValue(true);
        expect(wrapper.emitted('toggle')).toHaveLength(1);
        expect(wrapper.emitted('toggle')[0][0]).toBe(10);
    });

    // ─── Stock Levels ─────────────────────────────────

    it('shows stock dots when inventory is linked', () => {
        const optionsWithStock = [
            { id: 20, name: 'Ламінат', price_markup: 0, is_active: true, inventory_item_id: 5 },
        ];
        const wrapper = mount(PostPressSection, {
            props: {
                group,
                options: optionsWithStock,
                selectedIds: [],
                stock: { 5: { qty: 100, min: 10 } },
            },
        });
        expect(wrapper.find('.bg-green-400').exists()).toBe(true);
    });
});
