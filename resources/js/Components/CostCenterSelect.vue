<script setup>
import { computed, ref, watch } from 'vue'
import { findSimilarName } from '@/utils/similarName'

/**
 * Поле «Центр витрат».
 *
 * До цього це був вільний input із datalist на весь довідник: оператор гортав
 * близько півсотні підрозділів заради одного-двох релевантних, а будь-яка
 * одруківка ставала новим центром витрат через Department::remember().
 *
 * Тепер список звужений до центрів обраного підписанта — накопичених із
 * фактичних замовлень. Повний перелік лишається за кнопкою: підписант може
 * замовляти й для чужого підрозділу, і це не привід блокувати.
 */
const props = defineProps({
    modelValue:  { type: String, default: '' },
    departments: { type: Array,  default: () => [] },
    options:     { type: Array,  default: () => [] },
    signatoryId: { type: [Number, null], default: null },
})

const emit = defineEmits(['update:modelValue'])

const showAll = ref(false)
const creating = ref(false)

// Значення поля вводу нового центру. Не можна читати його з modelValue:
// компонент лише емітить update:modelValue, а чи прийде воно назад пропом
// у той самий тік — залежить від батька. Підказка на схожість мусить
// бачити щойно набране одразу, тож веде власний буфер.
//
// Синхронізації з modelValue немає навмисно, а не через недогляд: у
// creating-режим заходять лише через startCreating(), яка одразу обнуляє
// і draftName, і modelValue до ''. З creating-режиму виходять трьома
// шляхами: exitCreating() (кнопка «← Обрати зі списку» і зміна
// підписанта) обнуляє обидва так само; useSuggestion() — єдиний виняток,
// бо його сенс саме в тому, щоб залишити значення, підставивши
// підказку. Іншого шляху увімкнути creating.value немає, а поки він
// true — watch на options нічого не пише в modelValue, тож draftName і
// те, що бачить батько, розійтися не можуть.
const draftName = ref('')

// Чи значення в полі поставила автопідстановка. Зміна підписанта переставляє
// автоматичне значення, але не чіпає введене оператором руками — мовчки
// стерти набране було б гірше за зайвий клік.
const autoFilled = ref(false)

const visible = computed(() => {
    const narrowed = ! showAll.value && props.signatoryId && props.options.length > 0
    const base = narrowed ? props.options : props.departments

    if (props.modelValue === '' || base.some(d => d.name === props.modelValue)) {
        return base
    }

    // Центр, який уже стоїть у полі, лишається видимим завжди: інакше
    // редагування старого замовлення тихо підмінило б його підрозділ.
    //
    // Двох списків для цього не досить. `departments` несе лише активні
    // й невидалені рядки, тож у замовлення, оформленого на підрозділ, який
    // відтоді деактивували, збережене значення не збігається з жодною
    // <option> — селект показує порожньо, і оператор, зайшовши поправити
    // кількість, бачить незаповнене поле й обирає щось інше. Центр витрат
    // замовлення переїжджає, а з ним і те, чий ліміт його рахує. Вільний
    // <input>, який тут стояв до цього компонента, показував збережений
    // текст завжди — це його поведінка, повернута назад.
    const chosen = props.departments.find(d => d.name === props.modelValue)

    return [...base, chosen ?? { id: `saved:${props.modelValue}`, name: props.modelValue }]
})

const similar = computed(() => creating.value
    ? findSimilarName(draftName.value, props.departments.map(d => d.name))
    : null)

// Вихід із creating без вибору мусить забрати з собою недописане:
// modelValue у цей момент — те, що встиг набрати оператор і що
// typeName() уже відправив батьку як робоче значення поля. Просто
// скинути creating.value лишило б цей недописаний текст у батька —
// <select> показує порожньо, бо жодна option з ним не збігається, але
// відправиться саме він. Це та сама дірка, яку компонент існує, щоб
// закрити, лише не через одруківку, а через шлях назад зі створення.
// Спільна для обох виходів «без вибору» (кнопка «← Обрати зі списку» і
// зміна підписанта), щоб третій такий вихід не з'явився колись без
// цього скидання. useSuggestion() навмисно НЕ через цю функцію: там
// вихід із creating саме залишає значення — те, яке щойно підставили.
const exitCreating = () => {
    creating.value = false
    draftName.value = ''
    emit('update:modelValue', '')
}

// «Показати всі» й режим створення — стан саме цього підписанта в цій
// сесії редагування. Коли оператор перемикає підписанта на іншого, старий
// вибір нічого не означає: лишений розкритим повний список ховає щойно
// звужені кілька рядків за тими самими півсотнею, а лишений відкритим
// інпут нового центру пропонує "підставити" назву, схожу на список
// попереднього підписанта.
watch(() => props.signatoryId, () => {
    showAll.value = false

    if (creating.value) {
        exitCreating()
    }
})

// Автопідстановка — реакція на те, що оператор щойно обрав підписанта, тому
// вона й починається з того, що список центрів **змінився**. Навмисно без
// `immediate`: на першому рендері форми редагування options приходять уже
// заповненими, і immediate-прохід записав би центр у замовлення, яке його
// не мало — внутрішнє замовлення зі збереженим порожнім cost_center, чий
// підписант має рівно один активний центр. Оператор відкриває таке
// замовлення поправити позицію, зберігає, і воно вже несе центр витрат,
// якого ніхто не ставив, — а саме за ним рахуються ліміти підрозділу.
// На формі створення підписанта обирають після mount, тож там зміна
// options справжня і підстановка працює як і працювала.
watch(() => props.options, (options) => {
    if (creating.value) return

    if (options.length === 1 && (props.modelValue === '' || autoFilled.value)) {
        autoFilled.value = true
        emit('update:modelValue', options[0].name)
        return
    }

    // Кілька центрів і значення стояло автоматично — його поставив попередній
    // підписант, тож воно більше не відповідає ні на що.
    if (options.length !== 1 && autoFilled.value) {
        autoFilled.value = false
        emit('update:modelValue', '')
    }
})

const choose = (event) => {
    autoFilled.value = false
    emit('update:modelValue', event.target.value)
}

const startCreating = () => {
    creating.value = true
    autoFilled.value = false
    draftName.value = ''
    emit('update:modelValue', '')
}

const typeName = (event) => {
    autoFilled.value = false
    draftName.value = event.target.value
    emit('update:modelValue', event.target.value)
}

const useSuggestion = () => {
    creating.value = false
    draftName.value = similar.value
    emit('update:modelValue', similar.value)
}
</script>

<template>
    <div>
        <label class="text-xs text-gray-600 mb-1 block">Центр витрат</label>

        <template v-if="creating">
            <input type="text" class="input mb-1 text-sm" aria-label="Новий центр витрат"
                placeholder="Назва нового підрозділу / проєкту"
                :value="draftName" @input="typeName" />
            <p v-if="similar" class="text-amber-700 text-xs mb-2">
                Можливо, ви мали на увазі «{{ similar }}»?
                <button type="button" class="underline" @click="useSuggestion">Підставити</button>
            </p>
            <button type="button" data-testid="back-to-list" class="text-xs text-gray-600 underline mb-2"
                @click="exitCreating">← Обрати зі списку</button>
        </template>

        <template v-else>
            <select class="input mb-1 text-sm" aria-label="Центр витрат"
                :value="modelValue" @change="choose">
                <option value="">— Оберіть —</option>
                <option v-for="d in visible" :key="d.id" :value="d.name" data-centre>{{ d.name }}</option>
            </select>

            <div class="flex gap-3 mb-2">
                <button v-if="! showAll && signatoryId && options.length" type="button"
                    data-testid="show-all" class="text-xs text-gray-600 underline"
                    @click="showAll = true">Показати всі</button>
                <button type="button" data-testid="new-centre" class="text-xs text-gray-600 underline"
                    @click="startCreating">+ Новий центр витрат</button>
            </div>
        </template>
    </div>
</template>
