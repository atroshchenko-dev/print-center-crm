import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Modal from '@/Components/UI/Modal.vue';

describe('Modal.vue', () => {
    // ─── Visibility ───────────────────────────────────

    it('does not render when show is false', () => {
        const wrapper = mount(Modal, {
            props: { show: false },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.find('.fixed').exists()).toBe(false);
    });

    it('renders when show is true', () => {
        const wrapper = mount(Modal, {
            props: { show: true },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.find('.fixed').exists()).toBe(true);
    });

    // ─── Title ────────────────────────────────────────

    it('renders title when provided', () => {
        const wrapper = mount(Modal, {
            props: { show: true, title: 'Підтвердження' },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.text()).toContain('Підтвердження');
    });

    // ─── Slots ────────────────────────────────────────

    it('renders default slot content', () => {
        const wrapper = mount(Modal, {
            props: { show: true, title: 'Test' },
            slots: { default: '<p>Тіло модалки</p>' },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.text()).toContain('Тіло модалки');
    });

    it('renders footer slot', () => {
        const wrapper = mount(Modal, {
            props: { show: true },
            slots: { footer: '<button>OK</button>' },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.text()).toContain('OK');
    });

    // ─── Width Variants ───────────────────────────────

    it('applies md width by default', () => {
        const wrapper = mount(Modal, {
            props: { show: true },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.find('.max-w-md').exists()).toBe(true);
    });

    it('applies lg width', () => {
        const wrapper = mount(Modal, {
            props: { show: true, maxWidth: 'lg' },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.find('.max-w-lg').exists()).toBe(true);
    });

    // ─── Close Events ─────────────────────────────────

    it('emits close when overlay clicked', async () => {
        const wrapper = mount(Modal, {
            props: { show: true },
            global: { stubs: { Teleport: true } },
        });
        await wrapper.find('.bg-black\\/50').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('emits close when X button clicked', async () => {
        const wrapper = mount(Modal, {
            props: { show: true, title: 'Test' },
            global: { stubs: { Teleport: true } },
        });
        // X button has min-h-[44px] class
        const closeBtn = wrapper.find('button.min-h-\\[44px\\]');
        await closeBtn.trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    // ─── Accessibility ────────────────────────────────

    it('close button has 44px touch target', () => {
        const wrapper = mount(Modal, {
            props: { show: true, title: 'Test' },
            global: { stubs: { Teleport: true } },
        });
        const closeBtn = wrapper.find('button');
        expect(closeBtn.classes()).toContain('min-h-[44px]');
        expect(closeBtn.classes()).toContain('min-w-[44px]');
    });
});
