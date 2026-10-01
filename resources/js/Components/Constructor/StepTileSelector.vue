<script setup>
/**
 * StepTileSelector — великі плитки для вибору категорії/параметра
 *
 * Використовується для Step 1 (Base Category) та Step 4 (Colorfulness).
 * Підтримує radio-режим (одиничний вибір).
 *
 * Автоматично групує опції паперу в ряди:
 *   Row 1: Звичайний (80, 160, 200 г/м²)
 *   Row 2: Пастельний (паст. рожевий, жовтий…)
 *   Row 3: Крейдований
 */
import { computed } from 'vue'
import { stockState, isSelectable, STOCK_TITLES } from '@/composables/useStockAvailability'

const props = defineProps({
    group:      { type: Object, required: true },       // ServiceParameterGroup
    options:    { type: Array,  required: true },        // filtered ServiceParameterOption[]
    selectedId: { type: [Number, null], default: null },
    size:       { type: String, default: 'large' },     // 'large' | 'small'
    stock:      { type: Object, default: () => ({}) },  // { inventoryItemId: { qty, min } }
    orderType:  { type: String, default: 'internal' },
    // Ids the parent has already decided are unsellable — the cascade lives in
    // ConstructorPanel, which is the only thing that knows parents from children.
    disabledIds: { type: Array, default: () => [] },
    // Backdated forms record what already happened, so today's shelf must not
    // decide what was orderable in July.
    enforceStock: { type: Boolean, default: true },
})

const emit = defineEmits(['select'])

// Strip cascading prefix — "А4: Папір 80 г/м²" → "Папір 80 г/м²"
function shortName(name) {
    const parts = name.split(': ')
    return parts[parts.length - 1]
}

// ─── Icon system ─────────────────────────────────────
// Returns { type: 'emoji'|'svg', value, color? }
function getIcon(name) {
    const sn = shortName(name).toLowerCase()

    // Sidedness icons (keep emoji)
    if (sn.includes('1+0')) return { type: 'emoji', value: '◐' }
    if (sn.includes('1+1')) return { type: 'emoji', value: '◑' }
    if (sn.includes('4+0')) return { type: 'dot', color: '#3B82F6' }
    if (sn.includes('4+4')) return { type: 'dot', color: '#8B5CF6' }

    // Format/category icons — big label + dimensions subtitle
    if (sn.includes('а4')) return { type: 'fmt', label: 'А4', sub: '210×297 мм' }
    if (sn.includes('а3')) return { type: 'fmt', label: 'А3', sub: '297×420 мм' }

    // Pastel papers → colored dot
    if (sn.includes('рожев'))    return { type: 'dot', color: '#F9A8D4' }  // pink-300
    if (sn.includes('жовт'))     return { type: 'dot', color: '#FCD34D' }  // amber-300
    if (sn.includes('зелен'))    return { type: 'dot', color: '#86EFAC' }  // green-300
    if (sn.includes('блакит'))   return { type: 'dot', color: '#93C5FD' }  // blue-300

    // Coated papers → glossy
    if (sn.includes('крейдован')) return { type: 'svg', value: 'coated' }

    // Designer cardboard
    if (sn.includes('стардрім'))  return { type: 'dot', color: '#C4B5FD' }
    if (sn.includes('мальмеро'))  return { type: 'dot', color: '#BAE6FD' }
    if (sn.includes('білий льон')) return { type: 'dot', color: '#F5F5F4' }
    if (sn.includes('жовтий льон')) return { type: 'dot', color: '#FDE68A' }

    // Plain papers by weight
    if (sn.includes('200'))      return { type: 'svg', value: 'heavy' }
    if (sn.includes('160'))      return { type: 'svg', value: 'medium' }
    if (sn.includes('80'))       return { type: 'svg', value: 'light' }

    return { type: 'svg', value: 'light' }
}

// ─── Stock level for an option ──────────────────────
// The vocabulary lives in useStockAvailability now. This component had the only
// complete copy of it — four others had never heard of 'convertible' — so the
// shared one was written from this one.
function stockLevel(option) {
    return stockState(option.inventory_item_id, props.stock)
}

/**
 * Sellable, which is narrower than visible: an option with no stock and nothing
 * to cut from stays on screen and greys out. Owner's decision 2026-08-04 — the
 * operator sells off the shelf.
 */
function isDisabled(option) {
    if (!option.is_active) return true
    if (!props.enforceStock) return false

    return props.disabledIds.includes(option.id) || !isSelectable(option.inventory_item_id, props.stock)
}

function tileTitle(option) {
    if (props.enforceStock && props.disabledIds.includes(option.id)) {
        return 'Немає в наявності жодного варіанта цього розміру'
    }

    return STOCK_TITLES[stockLevel(option)] ?? ''
}

// ─── Row grouping for paper tiles ────────────────────
function getOptionCategory(option) {
    const sn = shortName(option.name).toLowerCase()
    if (sn.includes('паст.') || sn.includes('пастельн')) return 'pastel'
    if (sn.includes('крейдован')) return 'coated'
    if (sn.includes('дизайнерськ') || sn.includes('стардрім') || sn.includes('мальмеро') || sn.includes('льон')) return 'designer'
    return 'plain'
}

const groupedOptions = computed(() => {
    const categories = [
        { key: 'plain',    label: 'Звичайний',     items: [] },
        { key: 'pastel',   label: 'Пастельний',    items: [] },
        { key: 'coated',   label: 'Крейдований',   items: [] },
        { key: 'designer', label: 'Дизайнерський', items: [] },
    ]
    const catMap = Object.fromEntries(categories.map(c => [c.key, c]))

    for (const opt of props.options) {
        const cat = getOptionCategory(opt)
        ;(catMap[cat] || catMap.plain).items.push(opt)
    }

    const nonEmpty = categories.filter(c => c.items.length > 0)
    // If only one category — don't show labels (same as before)
    return nonEmpty.length <= 1
        ? [{ key: 'all', label: null, items: props.options }]
        : nonEmpty
})
</script>

<template>
    <div>
        <div class="flex items-center gap-2 mb-3">
            <h3 class="text-sm font-semibold text-gray-700">{{ group.name }}</h3>
            <span v-if="group.is_required" class="text-xs text-red-600">*</span>
        </div>

        <div v-for="(cat, ci) in groupedOptions" :key="cat.key" :class="{ 'mt-3': ci > 0 }">
            <!-- Row label (only if multiple categories) -->
            <div v-if="cat.label" class="flex items-center gap-2 mb-2" :class="{ 'mt-1': ci > 0 }">
                <span class="text-xs font-medium text-gray-600 uppercase tracking-wide">{{ cat.label }}</span>
                <div class="flex-1 border-t border-gray-100"></div>
            </div>

            <div :class="[
                'grid gap-3',
                size === 'large' ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-2 sm:grid-cols-4',
            ]">
                <button
                    v-for="option in cat.items"
                    :key="option.id"
                    type="button"
                    :disabled="isDisabled(option)"
                    :title="tileTitle(option)"
                    :aria-pressed="selectedId === option.id"
                    :class="[
                        'relative flex flex-col items-center justify-center rounded-xl border-2 transition-all duration-200',
                        'focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-400',
                        size === 'large' ? 'px-4 py-5 min-h-[100px]' : 'px-3 py-3 min-h-[72px]',
                        selectedId === option.id
                            ? 'border-indigo-500 bg-indigo-50 text-indigo-800 shadow-md ring-1 ring-indigo-200'
                            : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:bg-gray-50 hover:shadow-sm',
                        isDisabled(option) ? 'opacity-40 cursor-not-allowed grayscale' : 'cursor-pointer',
                    ]"
                    @click="emit('select', option.id)"
                >
                    <!-- Icon -->
                    <span :class="size === 'large' ? 'mb-2' : 'mb-1'" class="flex items-center justify-center">
                        <!-- Format (big label А4/А3, subtitle = dimensions) -->
                        <span v-if="getIcon(option.name).type === 'fmt'"
                            class="font-bold text-current"
                            :class="size === 'large' ? 'text-3xl' : 'text-xl'"
                        >{{ getIcon(option.name).label }}</span>

                        <!-- Emoji (sidedness) -->
                        <span v-else-if="getIcon(option.name).type === 'emoji'"
                            :class="size === 'large' ? 'text-3xl' : 'text-xl'"
                        >{{ getIcon(option.name).value }}</span>

                        <!-- Colored dot (pastel) -->
                        <span v-else-if="getIcon(option.name).type === 'dot'"
                            class="inline-block rounded-full"
                            :class="size === 'large' ? 'w-8 h-8' : 'w-6 h-6'"
                            :style="{ backgroundColor: getIcon(option.name).color, boxShadow: '0 0 0 3px ' + getIcon(option.name).color + '33' }"
                        ></span>

                        <!-- SVG icons (paper weight, coated) -->
                        <svg v-else-if="getIcon(option.name).value === 'light'"
                            :class="size === 'large' ? 'w-8 h-8' : 'w-6 h-6'"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round" />
                            <line x1="9" y1="13" x2="15" y2="13" stroke-linecap="round" opacity="0.4" />
                        </svg>

                        <svg v-else-if="getIcon(option.name).value === 'medium'"
                            :class="size === 'large' ? 'w-8 h-8' : 'w-6 h-6'"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round" />
                            <line x1="9" y1="12" x2="15" y2="12" stroke-linecap="round" opacity="0.5" />
                            <line x1="9" y1="15" x2="15" y2="15" stroke-linecap="round" opacity="0.5" />
                        </svg>

                        <svg v-else-if="getIcon(option.name).value === 'heavy'"
                            :class="size === 'large' ? 'w-8 h-8' : 'w-6 h-6'"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round" />
                            <line x1="9" y1="11" x2="15" y2="11" stroke-linecap="round" stroke-width="2" opacity="0.6" />
                            <line x1="9" y1="14.5" x2="15" y2="14.5" stroke-linecap="round" stroke-width="2" opacity="0.6" />
                            <line x1="9" y1="18" x2="13" y2="18" stroke-linecap="round" stroke-width="2" opacity="0.6" />
                        </svg>

                        <svg v-else-if="getIcon(option.name).value === 'coated'"
                            :class="size === 'large' ? 'w-8 h-8' : 'w-6 h-6'"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M9 11l2 2 4-4" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.7" />
                            <path d="M17 16l-1-1 1-1" stroke="#a5b4fc" stroke-width="1" stroke-linecap="round" opacity="0.5" />
                        </svg>

                        <!-- Fallback -->
                        <svg v-else :class="size === 'large' ? 'w-8 h-8' : 'w-6 h-6'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round" /><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round" /><line x1="9" y1="13" x2="15" y2="13" stroke-linecap="round" opacity="0.4" /></svg>
                    </span>

                    <!-- Name (hidden for format tiles — icon already shows А4/А3) -->
                    <span v-if="getIcon(option.name).type !== 'fmt'" :class="[
                        'text-center font-medium leading-tight',
                        size === 'large' ? 'text-sm' : 'text-xs',
                    ]">
                        {{ shortName(option.name) }}
                    </span>

                    <!-- Dimensions subtitle (format tiles only) -->
                    <span v-if="getIcon(option.name).type === 'fmt'"
                        class="mt-0.5 font-mono text-gray-600"
                        :class="size === 'large' ? 'text-[10px]' : 'text-[9px]'"
                    >{{ getIcon(option.name).sub }}</span>

                    <!-- Price markup (only for commercial orders) -->
                    <span v-if="option.price_markup > 0 && orderType !== 'internal'"
                        class="mt-1 text-xs"
                        :class="selectedId === option.id ? 'text-indigo-500' : 'text-gray-600'">
                        +{{ parseFloat(option.price_markup).toFixed(2) }} грн
                    </span>

                    <!-- Selected check -->
                    <div v-if="selectedId === option.id"
                        class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-indigo-500 flex items-center justify-center">
                        <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                    </div>

                    <!-- Stock level indicator -->
                    <span v-if="stockLevel(option) && stockLevel(option) !== 'convertible'"
                        class="absolute bottom-1.5 right-1.5 w-2.5 h-2.5 rounded-full ring-2 ring-white"
                        :class="{
                            'bg-green-400': stockLevel(option) === 'ok',
                            'bg-amber-400': stockLevel(option) === 'low',
                            'bg-red-400':   stockLevel(option) === 'out',
                        }"
                        :title="tileTitle(option)"
                    ></span>
                    <!-- Convertible indicator: scissors + amber dot (available via A3 cutting) -->
                    <span v-else-if="stockLevel(option) === 'convertible'"
                        class="absolute bottom-0.5 right-0.5 flex items-center gap-0.5"
                        title="Немає на складі. Доступно через розрізку А3"
                    >
                        <svg class="w-3.5 h-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 ring-2 ring-white"></span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>

