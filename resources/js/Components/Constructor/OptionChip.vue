<script setup>
/**
 * OptionChip component
 *
 * Large clickable tile/chip for parameter selection in Constructor UI.
 * Supports radio (single select) and checkbox (multi select) behavior.
 */
import { computed } from 'vue'
import { stockState, isSelectable, STOCK_TITLES } from '@/composables/useStockAvailability'

const props = defineProps({
    option:    { type: Object, required: true },   // ServiceParameterOption
    selected:  { type: Boolean, default: false },
    disabled:  { type: Boolean, default: false },
    stock:     { type: Object, default: () => ({}) },
    enforceStock: { type: Boolean, default: true },
})

// Sellable, not merely visible — owner's decision 2026-08-04.
const unsellable = computed(() =>
    props.enforceStock && !isSelectable(props.option.inventory_item_id, props.stock)
)

const emit = defineEmits(['click'])

function shortName(name) {
    const parts = name.split(': ')
    return parts[parts.length - 1]
}

// One vocabulary, shared. This copy did not know 'convertible', so a paper with
// 1125 sheets of A3 behind it read as a plain «немає» here.
function stockLevel() {
    return stockState(props.option.inventory_item_id, props.stock)
}
</script>

<template>
    <button
        type="button"
        :disabled="disabled || unsellable"
        :title="unsellable ? STOCK_TITLES.out : ''"
        :aria-pressed="selected"
        :class="[
            'relative flex flex-col items-start px-4 py-3 rounded-xl border-2 text-sm font-medium',
            'transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-400',
            selected
                ? 'border-indigo-500 bg-indigo-50 text-indigo-800 shadow-sm'
                : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:bg-gray-50',
            disabled || unsellable ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer',
        ]"
        @click="emit('click', option)"
    >
        <!-- Selected indicator -->
        <div v-if="selected"
            class="absolute top-2 right-2 w-4 h-4 rounded-full bg-indigo-500 flex items-center justify-center">
            <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
            </svg>
        </div>

        <!-- Stock level dot -->
        <span v-if="stockLevel()"
            class="absolute bottom-2 right-2 w-2.5 h-2.5 rounded-full ring-2 ring-white"
            :class="{
                'bg-green-400': stockLevel() === 'ok',
                'bg-amber-400': stockLevel() === 'low' || stockLevel() === 'convertible',
                'bg-red-400':   stockLevel() === 'out',
            }"
            :title="STOCK_TITLES[stockLevel()] ?? ''"
        ></span>

        <span>{{ shortName(option.name) }}</span>

        <!-- Price markup label (if > 0) -->
        <span v-if="option.price_markup > 0"
            class="mt-1 text-xs"
            :class="selected ? 'text-indigo-500' : 'text-gray-600'">
            +{{ parseFloat(option.price_markup).toFixed(2) }} грн/шт
        </span>
    </button>
</template>
