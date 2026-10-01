<script setup>
/**
 * PostPressSection — секція пост-обробки (checkboxes)
 *
 * Показує список опцій з множинним вибором.
 * Підтримує disabled-стан для несумісних опцій.
 */
import { stockState, isSelectable, STOCK_TITLES } from '@/composables/useStockAvailability'

const props = defineProps({
    group:       { type: Object, required: true },       // ServiceParameterGroup
    options:     { type: Array,  required: true },       // filtered ServiceParameterOption[]
    selectedIds: { type: Array,  default: () => [] },
    stock:       { type: Object, default: () => ({}) },
    disabledIds: { type: Array, default: () => [] },
    enforceStock: { type: Boolean, default: true },
})

const emit = defineEmits(['toggle'])

function isSelected(optionId) {
    return props.selectedIds.includes(optionId)
}

// One vocabulary, shared. This copy had never heard of 'convertible'.
function stockLevel(option) {
    return stockState(option.inventory_item_id, props.stock)
}

/** Sellable, not merely visible — owner's decision 2026-08-04. */
function isDisabled(option) {
    if (!option.is_active) return true
    if (!props.enforceStock) return false

    return props.disabledIds.includes(option.id) || !isSelectable(option.inventory_item_id, props.stock)
}
</script>

<template>
    <div>
        <div class="flex items-center gap-2 mb-3">
            <h3 class="text-sm font-semibold text-gray-700">{{ group.name }}</h3>
            <span v-if="group.is_required" class="text-xs text-red-600">*</span>
            <span v-else class="text-xs text-gray-600">(опціонально)</span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            <label
                v-for="option in options"
                :key="option.id"
                :class="[
                    'relative flex items-center gap-3 px-4 py-3 rounded-xl border-2 text-sm font-medium cursor-pointer',
                    'transition-all duration-150',
                    isSelected(option.id)
                        ? 'border-indigo-500 bg-indigo-50 text-indigo-800 shadow-sm'
                        : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:bg-gray-50',
                    isDisabled(option) ? 'opacity-40 cursor-not-allowed' : '',
                ]"
                :title="isDisabled(option) && option.is_active ? STOCK_TITLES.out : ''"
            >
                <input
                    type="checkbox"
                    :checked="isSelected(option.id)"
                    :disabled="isDisabled(option)"
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 shrink-0"
                    @change="emit('toggle', option.id)"
                />

                <div class="flex-1 min-w-0">
                    <span class="block truncate">{{ option.name }}</span>
                    <span v-if="option.price_markup > 0"
                        class="text-xs"
                        :class="isSelected(option.id) ? 'text-indigo-500' : 'text-gray-600'">
                        +{{ parseFloat(option.price_markup).toFixed(2) }} грн
                    </span>
                </div>

                <!-- Stock level dot -->
                <span v-if="stockLevel(option)"
                    class="w-2.5 h-2.5 rounded-full shrink-0"
                    :class="{
                        'bg-green-400': stockLevel(option) === 'ok',
                        'bg-amber-400': stockLevel(option) === 'low' || stockLevel(option) === 'convertible',
                        'bg-red-400':   stockLevel(option) === 'out',
                    }"
                    :title="STOCK_TITLES[stockLevel(option)] ?? ''"
                ></span>
            </label>
        </div>
    </div>
</template>
