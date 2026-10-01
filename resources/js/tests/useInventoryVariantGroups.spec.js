import { describe, it, expect } from 'vitest';
import { variantGroups, baseName, isBelowMin } from '@/composables/useInventoryVariantGroups';

/**
 * Групи кольорів на складі — підсумок, який мусить сходитись.
 *
 * Комплектна тверда палітурка займає на екрані 32 рядки (8 розмірів × 4
 * кольори), канали з обкладинками — ще 20, і зайняті в них одиниці: решта
 * стоїть на нулі й світиться червоним. Замість списку сторінка показує назву
 * без кольору і суму по ній — і саме тут можна помилитись тихо: підсумок,
 * який рахує не те, виглядає рівно так само, як підсумок, який рахує те.
 *
 * Ці тести тримають три речі: що ключ групи відрізає рівно колір, що сума
 * збігається з доданками, і що позиції не зникають — жодна група не має права
 * загубити рядок, бо під нею лежать окремі товари зі своїм AVCO і
 * оприбуткуванням.
 */

function item(attrs = {}) {
    return {
        id: 1,
        name: 'Палітурка комплектна 7мм (Синій)',
        unit: 'шт',
        current_quantity: '0',
        min_quantity: '5',
        avg_cost: '170.88',
        ...attrs,
    };
}

describe('baseName', () => {
    it('drops the colour and keeps the product', () => {
        expect(baseName('Палітурка комплектна 7мм (Синій)')).toBe('Палітурка комплектна 7мм');
        expect(baseName('Канал 10мм (Червоний)')).toBe('Канал 10мм');
    });

    /** Обкладинка твердої палітурки розміру не має — і однаково групується. */
    it('groups a product that carries no size at all', () => {
        expect(baseName('Обкладинка тверда (Синій)')).toBe('Обкладинка тверда');
    });

    it('leaves a name without a tail alone', () => {
        expect(baseName('Обкладинка прозора PVC А4, 150 мкм')).toBe('Обкладинка прозора PVC А4, 150 мкм');
        expect(baseName(null)).toBe('');
    });

    /** Дужки в середині назви — не варіант, а частина назви. */
    it('cuts only the tail, never the middle', () => {
        expect(baseName('Папір (крейдований) A4 300 г')).toBe('Папір (крейдований) A4 300 г');
    });

    /** Інакше ключем стала б порожня стрічка й такі позиції злиплися б. */
    it('keeps a name that is nothing but a tail', () => {
        expect(baseName('(Синій)')).toBe('(Синій)');
    });
});

describe('variantGroups', () => {
    const items = [
        item({ id: 1, name: 'Палітурка комплектна 3.5мм (Синій)', current_quantity: '30', avg_cost: '162.20' }),
        item({ id: 2, name: 'Палітурка комплектна 3.5мм (Червоний)' }),
        item({ id: 3, name: 'Палітурка комплектна 3.5мм (Сірий)' }),
        item({ id: 4, name: 'Палітурка комплектна 7мм (Синій)', current_quantity: '32', avg_cost: '165.46' }),
        item({ id: 5, name: 'Палітурка комплектна 7мм (Чорний)', current_quantity: '8', avg_cost: '170.88' }),
    ];

    it('collapses the colours of one product into one group', () => {
        const groups = variantGroups(items);

        expect(groups.map((g) => g.key)).toEqual(['Палітурка комплектна 3.5мм', 'Палітурка комплектна 7мм']);
        expect(groups[0].items.map((i) => i.id)).toEqual([1, 2, 3]);
        expect(groups[1].items.map((i) => i.id)).toEqual([4, 5]);
    });

    it('loses no position on the way into a group', () => {
        const inGroups = variantGroups(items).flatMap((g) => g.items);

        expect(inGroups).toHaveLength(items.length);
        expect(new Set(inGroups.map((i) => i.id))).toEqual(new Set([1, 2, 3, 4, 5]));
    });

    it('sums the quantity across colours — the number the page is opened for', () => {
        const groups = variantGroups(items);

        expect(groups[0].totalQuantity).toBe(30);
        expect(groups[1].totalQuantity).toBe(40);
    });

    /**
     * Собівартість групи — це AVCO того, що лежить на полиці, а не середнє
     * прайсу: порожні кольори несуть ціну минулої закупівлі й у середнє не
     * входять. 32 × 165.46 + 8 × 170.88 = 6660.76 на 40 штуках.
     */
    it('weights the cost by what is actually in stock', () => {
        expect(variantGroups(items)[1].avgCost).toBeCloseTo(166.544, 3);
    });

    it('shows no average when the whole group is empty', () => {
        const groups = variantGroups([
            item({ id: 6, name: 'Палітурка комплектна 21мм (Синій)' }),
            item({ id: 7, name: 'Палітурка комплектна 21мм (Чорний)' }),
        ]);

        expect(groups[0].totalQuantity).toBe(0);
        expect(groups[0].avgCost).toBeNull();
    });

    it('counts the colours that sit at or below their minimum', () => {
        const groups = variantGroups(items);

        expect(groups[0].belowMin).toBe(2);
        expect(groups[1].belowMin).toBe(0);
    });

    /** Один рядок групувати нема сенсу — до нього довелось би клікати. */
    it('does not fold a product that has a single position', () => {
        const groups = variantGroups([item({ id: 8, name: 'Пружина 6 мм', current_quantity: '108' })]);

        expect(groups[0].collapsible).toBe(false);
        expect(groups[0].items).toHaveLength(1);
    });

    it('keeps the order the owner dragged the rows into', () => {
        const groups = variantGroups([
            item({ id: 9, name: 'Палітурка комплектна 28мм (Синій)' }),
            item({ id: 10, name: 'Палітурка комплектна 3.5мм (Синій)' }),
            item({ id: 11, name: 'Палітурка комплектна 28мм (Чорний)' }),
        ]);

        expect(groups.map((g) => g.label)).toEqual(['28 мм', '3.5 мм']);
    });

    it('has nothing to group when the category is empty', () => {
        expect(variantGroups([])).toEqual([]);
        expect(variantGroups(undefined)).toEqual([]);
    });
});

describe('variantGroups labels', () => {
    /** Вісім разів прочитати «Палітурка комплектна» — марна робота для ока. */
    it('drops the opening words every group repeats', () => {
        const groups = variantGroups([
            item({ id: 1, name: 'Палітурка комплектна 3.5мм (Синій)' }),
            item({ id: 2, name: 'Палітурка комплектна 3.5мм (Чорний)' }),
            item({ id: 3, name: 'Палітурка комплектна 17.5мм (Синій)' }),
        ]);

        expect(groups.map((g) => g.label)).toEqual(['3.5 мм', '17.5 мм']);
    });

    /**
     * Канал і обкладинка спільного початку не мають — обидві лишаються
     * названими повністю, інакше «Обкладинка тверда» перетворилась би на
     * «тверда» поряд із голими міліметрами.
     */
    it('keeps both names when the groups share no opening', () => {
        const groups = variantGroups([
            item({ id: 1, name: 'Обкладинка тверда (Синій)', current_quantity: '9' }),
            item({ id: 2, name: 'Обкладинка тверда (Червоний)', current_quantity: '8' }),
            item({ id: 3, name: 'Канал 5мм (Синій)' }),
            item({ id: 4, name: 'Канал 5мм (Червоний)' }),
        ]);

        expect(groups.map((g) => g.label)).toEqual(['Обкладинка тверда', 'Канал 5 мм']);
        expect(groups[0].totalQuantity).toBe(17);
    });

    /** Остання назва — не привід лишити групу без підпису. */
    it('never eats the whole label', () => {
        const groups = variantGroups([
            item({ id: 1, name: 'Канал 5мм (Синій)' }),
            item({ id: 2, name: 'Канал 5мм (Червоний)' }),
            item({ id: 3, name: 'Канал 7мм (Синій)' }),
        ]);

        expect(groups.map((g) => g.label)).toEqual(['5 мм', '7 мм']);
    });

    it('leaves a lone group named in full', () => {
        const groups = variantGroups([
            item({ id: 1, name: 'Канал 5мм (Синій)' }),
            item({ id: 2, name: 'Канал 5мм (Червоний)' }),
        ]);

        expect(groups[0].label).toBe('Канал 5 мм');
    });
});

describe('isBelowMin', () => {
    /** Та сама умова, за якою рядок червоний: рівно мінімум — це вже мало. */
    it('treats exactly the minimum as below it', () => {
        expect(isBelowMin(item({ current_quantity: '5', min_quantity: '5' }))).toBe(true);
        expect(isBelowMin(item({ current_quantity: '6', min_quantity: '5' }))).toBe(false);
    });
});
