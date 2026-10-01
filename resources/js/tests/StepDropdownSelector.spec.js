import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StepDropdownSelector from '@/Components/Constructor/StepDropdownSelector.vue';

const group = { id: 1, name: 'Заповнення', is_required: true };
const options = [
    { id: 10, name: 'А4: 100% заповнення', price_markup: 1.50, is_active: true, inventory_item_id: null },
    { id: 11, name: 'А4: 50% заповнення', price_markup: 0, is_active: true, inventory_item_id: null },
    { id: 12, name: 'А4: Disabled fill', price_markup: 0, is_active: false, inventory_item_id: null },
];

describe('StepDropdownSelector.vue', () => {
    // ─── Rendering ────────────────────────────────────

    it('renders group name', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        expect(wrapper.text()).toContain('Заповнення');
    });

    it('shows required asterisk', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        const asterisk = wrapper.findAll('span').find((s) => s.text() === '*');
        expect(asterisk, 'a required group must be marked').toBeTruthy();
        // Shade, not exact class — text-red-500 was darkened for WCAG AA.
        expect(asterisk.classes().join(' ')).toMatch(/text-red-\d00/);
    });

    it('hides asterisk for non-required group', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group: { ...group, is_required: false }, options, selectedId: null },
        });
        expect(wrapper.find('.text-red-500').exists()).toBe(false);
    });

    it('renders select with all options + placeholder', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        const selectOptions = wrapper.findAll('option');
        expect(selectOptions).toHaveLength(4); // placeholder + 3
    });

    it('placeholder says "Оберіть" + group name', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        const placeholder = wrapper.find('option[disabled]');
        expect(placeholder.text()).toContain('Оберіть');
        expect(placeholder.text()).toContain('заповнення');
    });

    // ─── Selection ────────────────────────────────────

    it('applies selected styling', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: 10 },
        });
        expect(wrapper.find('select').classes()).toContain('border-indigo-300');
    });

    it('applies unselected styling when null', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        expect(wrapper.find('select').classes()).toContain('border-gray-200');
    });

    // ─── Events ───────────────────────────────────────

    it('emits select with option id on change', async () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        await wrapper.find('select').setValue(10);
        expect(wrapper.emitted('select')).toBeTruthy();
        expect(wrapper.emitted('select')[0][0]).toBe(10);
    });

    // ─── Disabled Options ─────────────────────────────

    it('marks inactive options as disabled', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        const opts = wrapper.findAll('option');
        const disabledOpt = opts.find(o => o.text().includes('Disabled fill'));
        expect(disabledOpt.attributes('disabled')).toBeDefined();
    });

    // ─── Short Names ──────────────────────────────────

    it('strips cascading prefix in option labels', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        expect(wrapper.text()).toContain('100% заповнення');
        expect(wrapper.text()).not.toContain('А4: 100% заповнення');
    });

    // ─── Stock Indicators ─────────────────────────────

    it('shows ● for in-stock options', () => {
        const opts = [{ id: 20, name: 'Test', price_markup: 0, is_active: true, inventory_item_id: 3 }];
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options: opts, selectedId: null, stock: { 3: { qty: 50, min: 5 } } },
        });
        expect(wrapper.text()).toContain('●');
    });

    it('shows ▲ for low-stock options', () => {
        const opts = [{ id: 20, name: 'Test', price_markup: 0, is_active: true, inventory_item_id: 3 }];
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options: opts, selectedId: null, stock: { 3: { qty: 2, min: 10 } } },
        });
        expect(wrapper.text()).toContain('▲');
    });

    it('shows ✕ for out-of-stock options', () => {
        const opts = [{ id: 20, name: 'Test', price_markup: 0, is_active: true, inventory_item_id: 3 }];
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options: opts, selectedId: null, stock: { 3: { qty: 0, min: 10 } } },
        });
        expect(wrapper.text()).toContain('✕');
    });

    it('shows stock dot next to select when option selected', () => {
        const opts = [{ id: 20, name: 'Test', price_markup: 0, is_active: true, inventory_item_id: 3 }];
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options: opts, selectedId: 20, stock: { 3: { qty: 50, min: 5 } } },
        });
        expect(wrapper.find('.bg-green-400').exists()).toBe(true);
    });

    // ─── Price Markup ─────────────────────────────────

    it('shows price markup in option text', () => {
        const wrapper = mount(StepDropdownSelector, {
            props: { group, options, selectedId: null },
        });
        expect(wrapper.text()).toContain('+1.50 грн');
    });
});
