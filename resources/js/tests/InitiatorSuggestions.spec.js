import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Create from '@/Pages/Orders/Create.vue';

/**
 * Поле «Ініціатор» пропонує лише імена обраного підрозділу — жодного
 * запасного списку «всіх відомих» поверх нього. Той список ріс би без
 * стелі, тож його прибрали: без центру витрат чи за центром без своїх
 * імен підказок немає, а вписати ім'я руками лишається можливим завжди.
 */

describe('Orders/Create — підказки ініціатора', () => {
    const departments = [
        { id: 1, name: 'Кафедра туризму', is_active: true },
        { id: 2, name: 'КЖУР', is_active: true },
    ];

    const mountPage = (props = {}) => mount(Create, {
        props: {
            services: [], categories: [], departments, signatories: [],
            riso_tiers: [], riso_papers: [], riso_paper_cost: 0,
            inventory_stock: {}, click_costs: { bw: 0, color: 0 },
            signatory_cost_centers: {},
            cost_center_initiators: { 1: ['Часта Ч.Ч.', 'Рідкісна Р.Р.'] },
            ...props,
        },
        global: {
            stubs: { Head: true, Link: true, AppLayout: { template: '<div><slot /></div>' } },
            mocks: { route: () => '/' },
        },
    });

    const optionValues = (wrapper) =>
        wrapper.find('#initiator-list').findAll('option').map(o => o.attributes('value'));

    it('offers nothing when no cost centre is chosen', () => {
        const wrapper = mountPage();

        expect(optionValues(wrapper)).toEqual([]);
    });

    it("offers exactly the centre's own names, most-used first", async () => {
        const wrapper = mountPage();

        wrapper.vm.costCenter = 'Кафедра туризму';
        await wrapper.vm.$nextTick();

        expect(optionValues(wrapper)).toEqual(['Часта Ч.Ч.', 'Рідкісна Р.Р.']);
    });

    it('offers nothing for a centre absent from the map', async () => {
        const wrapper = mountPage();

        wrapper.vm.costCenter = 'КЖУР';
        await wrapper.vm.$nextTick();

        expect(optionValues(wrapper)).toEqual([]);
    });

    it('keeps a typed name that is in no list', async () => {
        const wrapper = mountPage();
        const input = wrapper.find('input[aria-label="Ініціатор"]');

        await input.setValue('Нова Н.Н.');

        expect(wrapper.vm.initiator).toBe('Нова Н.Н.');
    });

    it('keeps a typed name that also happens to be a suggested one', async () => {
        const wrapper = mountPage();
        wrapper.vm.costCenter = 'Кафедра туризму';
        await wrapper.vm.$nextTick();
        const input = wrapper.find('input[aria-label="Ініціатор"]');

        await input.setValue('Часта Ч.Ч.');

        expect(wrapper.vm.initiator).toBe('Часта Ч.Ч.');
    });

    // Repeat-order path (`?repeat=`) copies the saved `cost_center` spelling
    // verbatim into `costCenter` — CostCenterSelect deliberately leaves an
    // unmatched saved spelling unfixed. The match against `departments` must
    // fold case and surrounding whitespace the way every backend path does
    // (CostCenterInitiator::remember(), the backfill, Department::remember()),
    // or a spelling that differs only that way silently loses the centre's
    // own initiators.
    it("matches the department despite case and whitespace differences in the saved spelling", async () => {
        const wrapper = mountPage();

        wrapper.vm.costCenter = '  кафедра ТУРИЗМУ  ';
        await wrapper.vm.$nextTick();

        expect(optionValues(wrapper)).toEqual(['Часта Ч.Ч.', 'Рідкісна Р.Р.']);
    });
});
