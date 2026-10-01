import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CostCenterSelect from '@/Components/CostCenterSelect.vue';

/**
 * Поле показувало весь довідник — близько півсотні підрозділів, з яких для
 * конкретного підписанта релевантні один-два. Тут закріплено, що звуження
 * не позбавляє виходу: повний список за кнопкою, новий центр — окремим кроком.
 */
describe('CostCenterSelect.vue', () => {
    const all = [
        { id: 1, name: 'Департамент реклами' },
        { id: 2, name: 'Кафедра туризму' },
        { id: 3, name: 'КЖУР' },
        { id: 4, name: 'ДКДЗ' },
    ];

    const mountWith = (props = {}) => mount(CostCenterSelect, {
        props: { modelValue: '', departments: all, options: [], signatoryId: null, ...props },
    });

    it('offers the whole book while no signatory is chosen', () => {
        const wrapper = mountWith();

        expect(wrapper.findAll('option[data-centre]')).toHaveLength(4);
    });

    /**
     * Форма створення: підписанта обирають уже після mount, тож поява центрів
     * — справжня зміна options, і саме на неї підстановка й розрахована.
     */
    it('fills the field when the operator picks a signatory with exactly one centre', async () => {
        const wrapper = mountWith();

        await wrapper.setProps({ signatoryId: 7, options: [{ id: 1, name: 'Департамент реклами' }] });

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['Департамент реклами']);
    });

    /**
     * Форма редагування: options приходять заповненими вже на першому рендері,
     * і підставляти там нічого не можна. Внутрішнє замовлення зі збереженим
     * порожнім центром витрат оператор відкриває поправити позицію — і воно
     * не має після збереження нести центр, якого йому ніхто не ставив: саме за
     * ним рахуються ліміти підрозділу.
     */
    it('leaves an empty saved value empty on first render, when options arrive already filled', async () => {
        const wrapper = mountWith({ signatoryId: 7, options: [{ id: 1, name: 'Департамент реклами' }] });
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('narrows the list without filling it when there are several', async () => {
        const wrapper = mountWith({
            signatoryId: 7,
            options: [{ id: 1, name: 'Департамент реклами' }, { id: 3, name: 'КЖУР' }],
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('option[data-centre]')).toHaveLength(2);
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('shows the whole book on demand', async () => {
        const wrapper = mountWith({
            signatoryId: 7,
            options: [{ id: 1, name: 'Департамент реклами' }, { id: 3, name: 'КЖУР' }],
        });

        await wrapper.find('[data-testid="show-all"]').trigger('click');

        expect(wrapper.findAll('option[data-centre]')).toHaveLength(4);
    });

    it('keeps a centre the current signatory never used, so editing does not silently swap it', () => {
        const wrapper = mountWith({
            modelValue: 'ДКДЗ',
            signatoryId: 7,
            options: [{ id: 1, name: 'Департамент реклами' }],
        });

        expect(wrapper.find('select').element.value).toBe('ДКДЗ');
    });

    /**
     * `departments` несе лише активні й невидалені рядки. Замовлення,
     * оформлене на підрозділ, який відтоді деактивували, не збігається
     * з жодною <option> — селект показував порожньо, і оператор, зайшовши
     * поправити кількість, обирав інший центр, бо поле виглядало незаповненим.
     */
    it('shows a saved centre whose department is in neither list, so the field is not blank', () => {
        const wrapper = mountWith({
            modelValue: 'Кафедра ІМЗД',
            signatoryId: 7,
            options: [{ id: 1, name: 'Департамент реклами' }],
        });

        expect(wrapper.find('select').element.value).toBe('Кафедра ІМЗД');
    });

    it('shows such a centre with no signatory chosen either, where the whole book is the list', () => {
        const wrapper = mountWith({ modelValue: 'Кафедра ІМЗД' });

        expect(wrapper.find('select').element.value).toBe('Кафедра ІМЗД');
    });

    it('warns about a name that looks like an existing one', async () => {
        const wrapper = mountWith();

        await wrapper.find('[data-testid="new-centre"]').trigger('click');
        await wrapper.find('input[type="text"]').setValue('Департамерт реклами');

        expect(wrapper.text()).toContain('Департамент реклами');
    });

    it('does not warn about a genuinely new name', async () => {
        const wrapper = mountWith();

        await wrapper.find('[data-testid="new-centre"]').trigger('click');
        await wrapper.find('input[type="text"]').setValue('Відділ аспірантури');

        expect(wrapper.text()).not.toContain('Можливо');
    });

    /**
     * "Показати всі" й режим створення належать конкретному підписанту в
     * конкретній сесії редагування. Без скидання зміна підписанта лишає
     * розкритим повний довідник поверх щойно звужених рядків іншої людини.
     */
    it('resets the show-all and creating state when the signatory changes', async () => {
        const wrapper = mountWith({
            signatoryId: 7,
            options: [{ id: 1, name: 'Департамент реклами' }, { id: 3, name: 'КЖУР' }],
        });

        await wrapper.find('[data-testid="show-all"]').trigger('click');
        expect(wrapper.findAll('option[data-centre]')).toHaveLength(4);

        await wrapper.setProps({ signatoryId: 9, options: [{ id: 2, name: 'Кафедра туризму' }] });

        expect(wrapper.findAll('option[data-centre]')).toHaveLength(1);
    });

    /**
     * Скидання creating при зміні підписанта мусить забрати з собою й
     * недописане: typeName() відправляє кожну натиснуту клавішу батьку як
     * робоче значення поля, тож саме лише скидання прапорця лишило б цей
     * недописаний текст у батька — <select> виглядає порожнім, бо жодна
     * option з ним не збігається, але відправиться саме він.
     */
    it('drops an unconfirmed new-centre name instead of leaving it for the parent to submit, when the signatory changes mid-typing', async () => {
        const wrapper = mountWith({
            signatoryId: 7,
            options: [{ id: 1, name: 'Департамент реклами' }, { id: 3, name: 'КЖУР' }],
        });

        await wrapper.find('[data-testid="new-centre"]').trigger('click');
        await wrapper.find('input[type="text"]').setValue('Відділ аспі');

        // Реальна форма віддзеркалює кожен emit назад у проп (v-model);
        // відтворюємо цей цикл явно, щоб тест бачив те саме, що бачив би батько.
        await wrapper.setProps({
            modelValue: wrapper.emitted('update:modelValue').at(-1)[0],
            signatoryId: 9,
            options: [{ id: 2, name: 'Кафедра туризму' }, { id: 4, name: 'ДКДЗ' }],
        });

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['']);
    });

    /**
     * Та сама дірка, інші двері: «← Обрати зі списку» — кнопка саме для
     * «я передумав», і вона так само мусить забрати з собою недописане,
     * а не лишити його батьку як робоче значення поля.
     */
    it('drops an unconfirmed new-centre name instead of leaving it for the parent to submit, when the operator backs out to the list', async () => {
        const wrapper = mountWith();

        await wrapper.find('[data-testid="new-centre"]').trigger('click');
        await wrapper.find('input[type="text"]').setValue('Департаме');

        await wrapper.find('[data-testid="back-to-list"]').trigger('click');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['']);
    });
});
