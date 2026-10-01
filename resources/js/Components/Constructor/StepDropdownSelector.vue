<script setup>
/**
 * StepDropdownSelector — dropdown для вибору параметра (Format, Material, Grammage)
 *
 * Стилізований select з label та placeholder.
 * Підтримує cascading — отримує вже відфільтрований список options.
 */
import { stockState, isSelectable } from '@/composables/useStockAvailability'

const props = defineProps({
    group:      { type: Object, required: true },
    options:    { type: Array,  required: true },
    selectedId: { type: [Number, null], default: null },
    stock:      { type: Object, default: () => ({}) },
    disabledIds: { type: Array, default: () => [] },
    enforceStock: { type: Boolean, default: true },
})

/** Sellable, not merely visible — owner's decision 2026-08-04. */
function isDisabled(option) {
    if (!option.is_active) return true
    if (!props.enforceStock) return false

    return props.disabledIds.includes(option.id) || !isSelectable(option.inventory_item_id, props.stock)
}

const emit = defineEmits(['select'])

function onSelect(event) {
    const val = event.target.value
    emit('select', val ? Number(val) : null)
}

function shortName(name) {
    const parts = name.split(': ')
    return parts[parts.length - 1]
}

// One vocabulary, shared. This copy had never heard of 'convertible'.
function stockLevel(option) {
    return stockState(option.inventory_item_id, props.stock)
}

function stockEmoji(option) {
    const level = stockLevel(option)
    if (level === 'ok') return '●'
    if (level === 'low') return '▲'
    if (level === 'convertible') return '✂'
    if (level === 'out') return '✕'
    return ''
}

import { computed } from 'vue'
const selectedOption = computed(() => props.options.find(o => o.id === props.selectedId))
const selectedStockLevel = computed(() => selectedOption.value ? stockLevel(selectedOption.value) : null)
</script>

<template>
    <div>
        <div class="flex items-center gap-2 mb-2">
            <h3 class="text-sm font-semibold text-gray-700">{{ group.name }}</h3>
            <span v-if="group.is_required" class="text-xs text-red-600">*</span>
        </div>

        <div class="relative">
            <select
                :id="`group-dropdown-${group.id}`"
                :value="selectedId"
                @change="onSelect"
                :class="[
                    'w-full appearance-none rounded-lg border-2 px-4 py-3 pr-10 text-sm font-medium',
                    'transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400',
                    selectedId
                        ? 'border-indigo-300 bg-indigo-50 text-indigo-800'
                        : 'border-gray-200 bg-white text-gray-700',
                ]"
            >
                <option :value="null" disabled>— Оберіть {{ group.name.toLowerCase() }} —</option>
                <option
                    v-for="option in options"
                    :key="option.id"
                    :value="option.id"
                    :disabled="isDisabled(option)"
                >
                    {{ stockEmoji(option) }} {{ shortName(option.name) }}
                    <template v-if="option.price_markup > 0"> (+{{ parseFloat(option.price_markup).toFixed(2) }} грн)</template>
                </option>
            </select>

            <!-- Stock dot next to dropdown -->
            <span v-if="selectedStockLevel"
                class="absolute inset-y-0 right-8 flex items-center"
            >
                <span class="w-2.5 h-2.5 rounded-full"
                    :class="{
                        'bg-green-400': selectedStockLevel === 'ok',
                        'bg-amber-400': selectedStockLevel === 'low' || selectedStockLevel === 'convertible',
                        'bg-red-400':   selectedStockLevel === 'out',
                    }"
                ></span>
            </span>

            <!-- Custom chevron -->
            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </div>
    </div>
</template>
