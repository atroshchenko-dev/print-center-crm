import { describe, it, expect } from 'vitest';
import { sortSignatories } from '@/utils/sortSignatories';

/**
 * Список приходив у порядку таблиці, тобто по id. Абетка тут робиться
 * на фронті, бо `ext-intl` у PHP не гарантований, і спільного українського
 * collation, на яке можна було б покластися, немає.
 */
describe('sortSignatories', () => {
    const names = (list) => list.map(s => s.full_name);

    it('orders plain Ukrainian names', () => {
        const sorted = sortSignatories([
            { full_name: 'Ярова О.П.' },
            { full_name: 'Балдець Д.О.' },
            { full_name: 'Новаський І.М.' },
        ]);

        expect(names(sorted)).toEqual(['Балдець Д.О.', 'Новаський І.М.', 'Ярова О.П.']);
    });

    it('puts І, Ї, Є and Ґ where the Ukrainian alphabet puts them', () => {
        const sorted = sortSignatories([
            { full_name: 'Їжакевич А.А.' },
            { full_name: 'Ковальчук Б.Б.' },
            { full_name: 'Іщчук Л.В.' },
            { full_name: 'Ґалаган В.В.' },
            { full_name: 'Євтух Д.Д.' },
        ]);

        expect(names(sorted)).toEqual([
            'Ґалаган В.В.',
            'Євтух Д.Д.',
            'Іщчук Л.В.',
            'Їжакевич А.А.',
            'Ковальчук Б.Б.',
        ]);
    });

    it('does not mutate the list it was given', () => {
        const list = [{ full_name: 'Ткський Д.І.' }, { full_name: 'Балдець Д.О.' }];

        sortSignatories(list);

        expect(names(list)).toEqual(['Ткський Д.І.', 'Балдець Д.О.']);
    });

    it('survives an empty or missing list', () => {
        expect(sortSignatories([])).toEqual([]);
        expect(sortSignatories(undefined)).toEqual([]);
    });
});
