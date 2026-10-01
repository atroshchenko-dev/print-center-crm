import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RequestBadge from '@/Components/RequestBadge.vue';
import Icon from '@/Components/Icon.vue';

/**
 * Один бейдж на обидві копії списку: основну й «Незавершені з попередніх днів».
 * Доки він був продубльований, будь-яка правка мала шанс поїхати лише в одну.
 *
 * Колір відповідає на «чи є підтвердження», іконка — на «де його шукати».
 */
describe('RequestBadge.vue', () => {
    const mountWith = (order) => mount(RequestBadge, { props: { order } });

    it('shows the envelope when the letter confirmed the request', () => {
        const wrapper = mountWith({ request_received: true, has_email_approval: true });

        expect(wrapper.findComponent(Icon).props('name')).toBe('mail');
        expect(wrapper.attributes('title')).toContain('листом');
    });

    it('shows the sheet when the paper request arrived', () => {
        const wrapper = mountWith({ request_received: true, has_email_approval: false });

        expect(wrapper.findComponent(Icon).props('name')).toBe('file-text');
    });

    it('shows no icon when nothing arrived', () => {
        const wrapper = mountWith({ request_received: false, has_email_approval: false });

        expect(wrapper.findComponent(Icon).exists()).toBe(false);
        expect(wrapper.text()).toBe('Заявка');
    });

    it('does not emit toggle for a letter-approved request', async () => {
        const wrapper = mountWith({ request_received: true, has_email_approval: true });

        await wrapper.trigger('click');

        expect(wrapper.emitted('toggle')).toBeUndefined();
        expect(wrapper.attributes('disabled')).toBeDefined();
    });

    it('emits toggle for a paper request', async () => {
        const wrapper = mountWith({ request_received: true, has_email_approval: false });

        await wrapper.trigger('click');

        expect(wrapper.emitted('toggle')).toHaveLength(1);
    });

    it('emits toggle when no request has arrived yet', async () => {
        const wrapper = mountWith({ request_received: false, has_email_approval: false });

        await wrapper.trigger('click');

        expect(wrapper.emitted('toggle')).toHaveLength(1);
    });
});
