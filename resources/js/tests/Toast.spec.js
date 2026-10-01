import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref, nextTick } from 'vue';
import Toast from '@/Components/UI/Toast.vue';

// Create reactive flash data that Toast's watcher can observe
const flashSuccess = ref(null);
const flashError = ref(null);

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            flash: {
                get success() { return flashSuccess.value; },
                get error() { return flashError.value; },
            },
        },
    }),
}));

describe('Toast.vue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        flashSuccess.value = null;
        flashError.value = null;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    // ─── Hidden by default ────────────────────────────

    it('is hidden when no flash message', () => {
        const wrapper = mount(Toast);
        expect(wrapper.find('.fixed').exists()).toBe(false);
    });

    // ─── Success Toast ────────────────────────────────

    it('shows success toast when flash changes', async () => {
        const wrapper = mount(Toast);
        flashSuccess.value = 'Замовлення створено!';
        await nextTick();
        await nextTick();
        expect(wrapper.text()).toContain('Замовлення створено!');
    });

    it('applies green styling for success', async () => {
        const wrapper = mount(Toast);
        flashSuccess.value = 'Done';
        await nextTick();
        await nextTick();
        const toast = wrapper.find('.fixed');
        expect(toast.exists()).toBe(true);
        expect(toast.classes()).toContain('bg-green-50');
    });

    it('shows SVG check icon for success', async () => {
        const wrapper = mount(Toast);
        flashSuccess.value = 'OK';
        await nextTick();
        await nextTick();
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    // ─── Error Toast ──────────────────────────────────

    it('shows error toast', async () => {
        const wrapper = mount(Toast);
        flashError.value = 'Помилка!';
        await nextTick();
        await nextTick();
        expect(wrapper.text()).toContain('Помилка!');
    });

    it('applies red styling for error', async () => {
        const wrapper = mount(Toast);
        flashError.value = 'Error';
        await nextTick();
        await nextTick();
        const toast = wrapper.find('.fixed');
        expect(toast.exists()).toBe(true);
        expect(toast.classes()).toContain('bg-red-50');
    });

    it('shows SVG error icon for error', async () => {
        const wrapper = mount(Toast);
        flashError.value = 'Err';
        await nextTick();
        await nextTick();
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    // ─── Dismiss ──────────────────────────────────────

    it('has a dismiss button', async () => {
        const wrapper = mount(Toast);
        flashSuccess.value = 'Test';
        await nextTick();
        await nextTick();
        expect(wrapper.find('button').exists()).toBe(true);
    });

    it('auto-dismisses after timeout', async () => {
        const wrapper = mount(Toast);
        flashSuccess.value = 'Auto';
        await nextTick();
        await nextTick();
        expect(wrapper.find('.fixed').exists()).toBe(true);
        vi.advanceTimersByTime(5000);
        await nextTick();
        expect(wrapper.find('.fixed').exists()).toBe(false);
    });

    // ─── Error Priority ───────────────────────────────

    it('shows error when only error is set', async () => {
        const wrapper = mount(Toast);
        flashError.value = 'Bad';
        await nextTick();
        await nextTick();
        expect(wrapper.text()).toContain('Bad');
        expect(wrapper.find('.bg-red-50').exists()).toBe(true);
    });
});
