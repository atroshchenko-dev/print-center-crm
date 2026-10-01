import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Button from '@/Components/UI/Button.vue';

// Stub Modal to always render its slot content (ignore show prop)
const ModalStub = {
    template: '<div class="modal-stub"><slot /><slot name="footer" /></div>',
    props: ['show', 'title', 'maxWidth'],
};

describe('ConfirmDialog.vue', () => {
    function factory(props = {}) {
        return mount(ConfirmDialog, {
            props: { show: true, ...props },
            global: {
                stubs: { Modal: ModalStub },
                components: { Button },
            },
        });
    }

    // ─── Rendering ────────────────────────────────────

    it('renders message when shown', () => {
        const wrapper = factory();
        expect(wrapper.text()).toContain('Ви впевнені?');
    });

    it('shows custom message', () => {
        const wrapper = factory({ message: 'Цю дію не можна скасувати.' });
        expect(wrapper.text()).toContain('Цю дію не можна скасувати.');
    });

    it('shows default button labels', () => {
        const wrapper = factory();
        expect(wrapper.text()).toContain('Підтвердити');
        expect(wrapper.text()).toContain('Скасувати');
    });

    it('shows custom button labels', () => {
        const wrapper = factory({ confirmLabel: 'Так, видалити', cancelLabel: 'Ні' });
        expect(wrapper.text()).toContain('Так, видалити');
        expect(wrapper.text()).toContain('Ні');
    });

    it('renders two buttons', () => {
        const wrapper = factory();
        const buttons = wrapper.findAllComponents(Button);
        expect(buttons).toHaveLength(2);
    });

    // ─── Events ───────────────────────────────────────

    it('emits confirm when confirm button clicked', async () => {
        const wrapper = factory();
        const buttons = wrapper.findAllComponents(Button);
        await buttons[1].trigger('click');
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('emits cancel when cancel button clicked', async () => {
        const wrapper = factory();
        const buttons = wrapper.findAllComponents(Button);
        await buttons[0].trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    // ─── Variant ──────────────────────────────────────

    it('passes default danger variant to confirm button', () => {
        const wrapper = factory();
        const buttons = wrapper.findAllComponents(Button);
        expect(buttons[1].props('variant')).toBe('danger');
    });

    it('passes custom variant to confirm button', () => {
        const wrapper = factory({ variant: 'primary' });
        const buttons = wrapper.findAllComponents(Button);
        expect(buttons[1].props('variant')).toBe('primary');
    });

    // ─── Loading ──────────────────────────────────────

    it('passes loading state to confirm button', () => {
        const wrapper = factory({ loading: true });
        const buttons = wrapper.findAllComponents(Button);
        expect(buttons[1].props('loading')).toBe(true);
    });

    it('cancel button is ghost variant', () => {
        const wrapper = factory();
        const buttons = wrapper.findAllComponents(Button);
        expect(buttons[0].props('variant')).toBe('ghost');
    });
});
