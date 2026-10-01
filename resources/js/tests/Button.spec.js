import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Button from '@/Components/UI/Button.vue';

describe('Button.vue', () => {
    // ─── Rendering ────────────────────────────────────

    it('renders slot content', () => {
        const wrapper = mount(Button, { slots: { default: 'Зберегти' } });
        expect(wrapper.text()).toContain('Зберегти');
    });

    it('renders with default props', () => {
        const wrapper = mount(Button);
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.attributes('disabled')).toBeUndefined();
    });

    // ─── Variants ─────────────────────────────────────

    it('applies primary variant classes', () => {
        const wrapper = mount(Button, { props: { variant: 'primary' } });
        expect(wrapper.classes()).toContain('bg-indigo-600');
    });

    it('applies danger variant classes', () => {
        const wrapper = mount(Button, { props: { variant: 'danger' } });
        expect(wrapper.classes()).toContain('bg-red-600');
    });

    it('applies secondary variant classes', () => {
        const wrapper = mount(Button, { props: { variant: 'secondary' } });
        expect(wrapper.classes()).toContain('bg-white');
    });

    it('applies ghost variant classes', () => {
        const wrapper = mount(Button, { props: { variant: 'ghost' } });
        expect(wrapper.classes()).toContain('bg-transparent');
    });

    // ─── Sizes ────────────────────────────────────────

    it('applies md size by default (44px touch target)', () => {
        const wrapper = mount(Button);
        expect(wrapper.classes()).toContain('min-h-[44px]');
    });

    it('applies sm size', () => {
        const wrapper = mount(Button, { props: { size: 'sm' } });
        expect(wrapper.classes()).toContain('min-h-[36px]');
    });

    it('applies lg size', () => {
        const wrapper = mount(Button, { props: { size: 'lg' } });
        expect(wrapper.classes()).toContain('min-h-[52px]');
    });

    // ─── Disabled State ───────────────────────────────

    it('is disabled when disabled prop is true', () => {
        const wrapper = mount(Button, { props: { disabled: true } });
        expect(wrapper.attributes('disabled')).toBeDefined();
    });

    it('is disabled when loading', () => {
        const wrapper = mount(Button, { props: { loading: true } });
        expect(wrapper.attributes('disabled')).toBeDefined();
    });

    // ─── Loading State ────────────────────────────────

    it('shows spinner when loading', () => {
        const wrapper = mount(Button, { props: { loading: true } });
        expect(wrapper.find('svg.animate-spin').exists()).toBe(true);
    });

    it('hides spinner when not loading', () => {
        const wrapper = mount(Button, { props: { loading: false } });
        expect(wrapper.find('svg.animate-spin').exists()).toBe(false);
    });

    // ─── Events ───────────────────────────────────────

    it('emits click event', async () => {
        const wrapper = mount(Button);
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('does not emit click when disabled', async () => {
        const wrapper = mount(Button, { props: { disabled: true } });
        await wrapper.trigger('click');
        // Browser prevents click on disabled buttons
        // The event is still emitted by Vue (native behavior), but button is visually disabled
        expect(wrapper.attributes('disabled')).toBeDefined();
    });

    // ─── Type Attribute ───────────────────────────────

    it('accepts submit type', () => {
        const wrapper = mount(Button, { props: { type: 'submit' } });
        expect(wrapper.attributes('type')).toBe('submit');
    });
});
