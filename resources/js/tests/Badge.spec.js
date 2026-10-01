import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Badge from '@/Components/UI/Badge.vue';

describe('Badge.vue', () => {
    it('renders slot content', () => {
        const wrapper = mount(Badge, { slots: { default: 'Оплачено' } });
        expect(wrapper.text()).toBe('Оплачено');
    });

    it('applies default variant', () => {
        const wrapper = mount(Badge);
        expect(wrapper.classes()).toContain('bg-gray-100');
    });

    it('applies success variant', () => {
        const wrapper = mount(Badge, { props: { variant: 'success' } });
        expect(wrapper.classes()).toContain('bg-green-100');
    });

    it('applies danger variant', () => {
        const wrapper = mount(Badge, { props: { variant: 'danger' } });
        expect(wrapper.classes()).toContain('bg-red-100');
    });

    it('applies warning variant', () => {
        const wrapper = mount(Badge, { props: { variant: 'warning' } });
        expect(wrapper.classes()).toContain('bg-amber-100');
    });

    it('applies info variant', () => {
        const wrapper = mount(Badge, { props: { variant: 'info' } });
        expect(wrapper.classes()).toContain('bg-blue-100');
    });

    it('applies sm size', () => {
        const wrapper = mount(Badge, { props: { size: 'sm' } });
        expect(wrapper.classes()).toContain('text-xs');
    });

    it('renders as span element', () => {
        const wrapper = mount(Badge);
        expect(wrapper.element.tagName).toBe('SPAN');
    });

    it('has rounded-full class', () => {
        const wrapper = mount(Badge);
        expect(wrapper.classes()).toContain('rounded-full');
    });
});
