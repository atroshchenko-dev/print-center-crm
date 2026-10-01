import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import OrdersIndex from '@/Pages/Orders/Index.vue';
import Pagination from '@/Components/Pagination.vue';

/**
 * The month view must reach every order of the month (audit F-1, 2026-08-09).
 *
 * The backend paginates at 50 and searches the whole month; the page rendered
 * neither pagination controls nor sent the search to the server, so an
 * operator saw the newest 50 and a search box that quietly looked only at
 * them. The rule: what the server filtered is what the screen shows, and
 * every page of it is reachable.
 */

const paginatorLinks = () => [
    { url: null, label: '&laquo; Previous', active: false },
    { url: '/orders?view=month&page=1', label: '1', active: true },
    { url: '/orders?view=month&page=2', label: '2', active: false },
    { url: '/orders?view=month&page=2', label: 'Next &raquo;', active: false },
];

const makeOrder = (id) => ({
    id,
    order_number: `INT-000${id}`,
    type: 'internal',
    status: 'new',
    total_cost: '10.00',
    request_received: false,
    version: 1,
    created_at: '2026-08-01T10:00:00Z',
    items: [],
});

function mountPage(overrides = {}) {
    return mount(OrdersIndex, {
        props: {
            orders: { data: [makeOrder(1)], links: paginatorLinks() },
            carryover: [],
            filters: {},
            shift: null,
            view: 'month',
            ...overrides,
        },
        global: {
            mocks: {
                route: (name) => `/${name}`,
            },
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                Teleport: true,
            },
        },
    });
}

describe('Orders month view reaches every order', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        router.get.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the pagination controls when the month has more pages', () => {
        const wrapper = mountPage();

        const pagination = wrapper.findComponent(Pagination);
        expect(pagination.exists(), 'the paginated month must offer its pages').toBe(true);
        expect(pagination.props('links')).toHaveLength(4);
    });

    it('sends the search to the server instead of filtering the loaded page', async () => {
        const wrapper = mountPage();

        await wrapper.find('input[type="search"]').setValue('Кафедра');
        vi.advanceTimersByTime(400);

        expect(router.get).toHaveBeenCalledWith(
            '/orders.index',
            expect.objectContaining({ view: 'month', search: 'Кафедра' }),
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('does not hide the server results with the client text filter in month view', async () => {
        const wrapper = mountPage();

        // The term matches nothing on the loaded page client-side; in month
        // view the server is the judge, so the row must stay until it answers.
        await wrapper.find('input[type="search"]').setValue('Кафедра');

        expect(wrapper.text()).toContain('INT-0001');
    });

    it('shows the term the server filtered by', () => {
        const wrapper = mountPage({ filters: { search: 'Кафедра' } });

        expect(wrapper.find('input[type="search"]').element.value).toBe('Кафедра');
    });

    it('a navigation within the debounce window cancels the pending search', async () => {
        // The timer used to outlive the component: typing and
        // leaving within 300 ms fired router.get() after unmount, yanking the
        // user back to the index from wherever they had just landed.
        const wrapper = mountPage();

        await wrapper.find('input[type="search"]').setValue('Кафедра');
        wrapper.unmount();
        vi.advanceTimersByTime(400);

        expect(router.get).not.toHaveBeenCalled();
    });

    it('sends a status chip to the server in month view', async () => {
        // The month is paginated: a chip filtering only the loaded page says
        // «Нічого не знайдено» while the matches sit on page two.
        const wrapper = mountPage();

        const chip = wrapper.findAll('button').find((b) => b.text() === 'Активні');
        await chip.trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/orders.index',
            expect.objectContaining({ view: 'month', status_group: 'active' }),
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('does not hide the loaded page with a client chip in month view', async () => {
        const wrapper = mountPage({
            orders: {
                data: [{ ...makeOrder(1), status: 'completed_issued' }],
                links: paginatorLinks(),
            },
        });

        const chip = wrapper.findAll('button').find((b) => b.text() === 'Активні');
        await chip.trigger('click');

        // The row is terminal and the chip says active — but in month view
        // the server is the judge, so the row stays until it answers.
        expect(wrapper.text()).toContain('INT-0001');
    });

    it('shows the chips the server filtered by', () => {
        const wrapper = mountPage({ filters: { status_group: 'terminal', type: 'commercial' } });

        const statusChip = wrapper.findAll('button').find((b) => b.text() === 'Завершені');
        const typeChip = wrapper.findAll('button').find((b) => b.text() === 'COM');

        expect(statusChip.classes()).toContain('bg-indigo-100');
        expect(typeChip.classes()).toContain('bg-indigo-100');
    });

    it('carries the search term across a view switch', async () => {
        const wrapper = mountPage({ view: 'shift' });

        await wrapper.find('input[type="search"]').setValue('ДРУК');
        const monthButton = wrapper.findAll('button').find((b) => b.text() === 'Місяць');
        await monthButton.trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/orders.index',
            expect.objectContaining({ view: 'month', search: 'ДРУК' }),
            expect.anything(),
        );
    });
});
