import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import InventoryIndex from '@/Pages/Admin/Inventory/Index.vue';

/**
 * Кожен блок складу згортається, а тверда палітурка ще й підсумовується.
 *
 * Сторінка складу відкривається на повний список: чотири підгрупи паперу,
 * пружини, обкладинки і 32 рядки комплектної палітурки, з яких зайняті
 * одиниці. Власник попросив дві речі — щоб згорталось усе, а не лише папір, і
 * щоб по комплектній було видно кількість на розмір, незалежно від кольору.
 *
 * `useInventoryVariantGroups` тримає арифметику; ці тести тримають екран: що
 * кнопка справді ховає таблицю, що згорнута група показує суму замість
 * кольорів, і що після перезавантаження сторінки (а її перезавантажує кожне
 * оприбуткування) згорнуте лишається згорнутим.
 */

const categories = [
    { id: 1, name: 'Пружини' },
    { id: 2, name: 'Тверда палітурка (комплектні)' },
    { id: 3, name: 'Тверда палітурка (канали + обкладинки)' },
];

const item = (attrs) => ({
    unit: 'шт',
    min_quantity: '5',
    avg_cost: '170.88',
    current_quantity: '0',
    subcategory: null,
    sort_order: attrs.id,
    ...attrs,
});

const items = [
    item({ id: 1, inventory_category_id: 1, name: 'Пружина 6 мм', current_quantity: '108', avg_cost: '1.50', min_quantity: '50' }),
    item({ id: 2, inventory_category_id: 2, name: 'Палітурка комплектна 7мм (Синій)', current_quantity: '32', avg_cost: '165.46' }),
    item({ id: 3, inventory_category_id: 2, name: 'Палітурка комплектна 7мм (Червоний)' }),
    item({ id: 4, inventory_category_id: 2, name: 'Палітурка комплектна 7мм (Чорний)', current_quantity: '8' }),
    item({ id: 5, inventory_category_id: 2, name: 'Палітурка комплектна 7мм (Сірий)' }),
    item({ id: 6, inventory_category_id: 3, name: 'Обкладинка тверда (Синій)', current_quantity: '9' }),
    item({ id: 7, inventory_category_id: 3, name: 'Обкладинка тверда (Червоний)', current_quantity: '8' }),
    item({ id: 8, inventory_category_id: 3, name: 'Канал 5мм (Синій)' }),
    item({ id: 9, inventory_category_id: 3, name: 'Канал 5мм (Червоний)' }),
];

function mountPage() {
    return mount(InventoryIndex, {
        props: {
            items,
            parameterOptions: [],
            categories,
            procurement: null,
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                Deferred: true,
                Teleport: true,
            },
        },
    });
}

/** Шапка блока: кнопка категорії або рядок-підсумок розміру з цією назвою. */
function header(wrapper, label) {
    const found = wrapper.findAll('button, tr').filter((el) => el.text().includes(label));
    expect(found.length, `немає кнопки «${label}»`).toBeGreaterThan(0);

    return found[0];
}

describe('Inventory blocks collapse', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('gives every category a header that hides its table', async () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Пружина 6 мм');

        await header(wrapper, 'Пружини').trigger('click');

        expect(wrapper.text()).not.toContain('Пружина 6 мм');
    });

    it('opens the category again on a second click', async () => {
        const wrapper = mountPage();

        await header(wrapper, 'Пружини').trigger('click');
        await header(wrapper, 'Пружини').trigger('click');

        expect(wrapper.text()).toContain('Пружина 6 мм');
    });

    /**
     * Те, заради чого все затівалось: 40 штук сьомого розміру одним рядком
     * замість чотирьох кольорів, два з яких порожні.
     */
    it('shows the hard-binding size as one summed row, colours folded away', () => {
        const text = mountPage().text();

        expect(text).toContain('7 мм');
        expect(text).toContain('40');
        expect(text).not.toContain('Палітурка комплектна 7мм (Синій)');
    });

    it('says how many colours of the size sit at or below the minimum', () => {
        expect(mountPage().text()).toContain('2 нижче мін.');
    });

    it('unfolds the colours on click, each with its own row', async () => {
        const wrapper = mountPage();

        await header(wrapper, '7 мм').trigger('click');

        expect(wrapper.text()).toContain('Палітурка комплектна 7мм (Синій)');
        expect(wrapper.text()).toContain('Палітурка комплектна 7мм (Червоний)');
        expect(wrapper.text()).toContain('Палітурка комплектна 7мм (Чорний)');
        expect(wrapper.text()).toContain('Палітурка комплектна 7мм (Сірий)');
    });

    /**
     * Оприбуткування перезавантажує сторінку, тож без памʼяті стан «згорнуто»
     * не пережив би жодної дії — і кнопка була б марною.
     */
    it('remembers what was collapsed across a page reload', async () => {
        const first = mountPage();
        await header(first, 'Пружини').trigger('click');
        first.unmount();

        const second = mountPage();

        expect(second.text()).not.toContain('Пружина 6 мм');
    });

    it('remembers an unfolded size too', async () => {
        const first = mountPage();
        await header(first, '7 мм').trigger('click');
        first.unmount();

        const second = mountPage();

        expect(second.text()).toContain('Палітурка комплектна 7мм (Синій)');
    });

    /**
     * Канали й обкладинки — та сама категорія, дві різні осі: канал має
     * розмір, обкладинка не має жодного, і обидві однаково згортаються за
     * назвою без кольору.
     */
    it('folds the channels and their covers by name, each with its own sum', () => {
        const text = mountPage().text();

        expect(text).toContain('Обкладинка тверда');
        expect(text).toContain('17');
        expect(text).toContain('Канал 5 мм');
        expect(text).not.toContain('Обкладинка тверда (Синій)');
        expect(text).not.toContain('Канал 5мм (Червоний)');
    });

    it('unfolds a cover group into its colours', async () => {
        const wrapper = mountPage();

        await header(wrapper, 'Обкладинка тверда').trigger('click');

        expect(wrapper.text()).toContain('Обкладинка тверда (Синій)');
        expect(wrapper.text()).toContain('Обкладинка тверда (Червоний)');
    });

    /** Пошкоджене сховище — не привід не намалювати сторінку. */
    it('draws the page when the remembered state is garbage', () => {
        localStorage.setItem('crm.inventory.collapsed', '{не json');

        expect(mountPage().text()).toContain('Пружина 6 мм');
    });
});
