import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DataTable from '@/Components/UI/DataTable.vue';

const columns = [
    { key: 'name', label: 'Назва', sortable: true },
    { key: 'qty', label: 'Кількість', sortable: true, align: 'right' },
    { key: 'status', label: 'Статус' },
];

const rows = [
    { id: 1, name: 'Папір А4', qty: 500, status: 'ok' },
    { id: 2, name: 'Тонер', qty: 10, status: 'low' },
    { id: 3, name: 'Ламінат', qty: 200, status: 'ok' },
];

describe('DataTable.vue', () => {
    // ─── Rendering ────────────────────────────────────

    it('renders column headers', () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        expect(wrapper.text()).toContain('Назва');
        expect(wrapper.text()).toContain('Кількість');
        expect(wrapper.text()).toContain('Статус');
    });

    it('renders all rows', () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        expect(wrapper.findAll('tbody tr')).toHaveLength(3);
    });

    it('renders cell values', () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        expect(wrapper.text()).toContain('Папір А4');
        expect(wrapper.text()).toContain('500');
        expect(wrapper.text()).toContain('Тонер');
    });

    it('shows dash for null values', () => {
        const rowsWithNull = [{ id: 1, name: 'Test', qty: null, status: null }];
        const wrapper = mount(DataTable, { props: { columns, rows: rowsWithNull } });
        expect(wrapper.text()).toContain('—');
    });

    // ─── Empty State ──────────────────────────────────

    it('shows empty message when no rows', () => {
        const wrapper = mount(DataTable, { props: { columns, rows: [] } });
        expect(wrapper.text()).toContain('Немає даних для відображення');
    });

    it('shows custom empty message', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows: [], emptyMessage: 'Порожньо' },
        });
        expect(wrapper.text()).toContain('Порожньо');
    });

    // ─── Sorting ──────────────────────────────────────

    it('sorts ascending on first click', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        const nameHeader = wrapper.findAll('th')[0];
        await nameHeader.trigger('click');

        const cells = wrapper.findAll('tbody td:first-child');
        expect(cells[0].text()).toBe('Ламінат');  // alphabetical first
        expect(cells[2].text()).toBe('Тонер');     // alphabetical last
    });

    it('sorts descending on second click', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        const nameHeader = wrapper.findAll('th')[0];
        await nameHeader.trigger('click'); // asc
        await nameHeader.trigger('click'); // desc

        const cells = wrapper.findAll('tbody td:first-child');
        expect(cells[0].text()).toBe('Тонер');
        expect(cells[2].text()).toBe('Ламінат');
    });

    it('sorts numbers correctly', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        const qtyHeader = wrapper.findAll('th')[1];
        await qtyHeader.trigger('click'); // asc by qty

        const cells = wrapper.findAll('tbody tr');
        // qty: 10, 200, 500
        expect(cells[0].text()).toContain('Тонер');
    });

    it('does not sort non-sortable columns', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        const statusHeader = wrapper.findAll('th')[2];
        await statusHeader.trigger('click');

        // Order should remain unchanged
        const cells = wrapper.findAll('tbody td:first-child');
        expect(cells[0].text()).toBe('Папір А4');
    });

    it('shows sort indicator arrow', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        const nameHeader = wrapper.findAll('th')[0];
        await nameHeader.trigger('click');
        expect(wrapper.find('.text-indigo-500').exists()).toBe(true);
    });

    // ─── Alignment ────────────────────────────────────

    it('applies right alignment to qty column', () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        const headerCells = wrapper.findAll('th');
        expect(headerCells[1].classes()).toContain('text-right');
    });

    // ─── Row Click ────────────────────────────────────

    it('emits row-click with row data', async () => {
        const wrapper = mount(DataTable, { props: { columns, rows } });
        const firstRow = wrapper.findAll('tbody tr')[0];
        await firstRow.trigger('click');
        expect(wrapper.emitted('row-click')).toHaveLength(1);
        expect(wrapper.emitted('row-click')[0][0]).toEqual(rows[0]);
    });
});
