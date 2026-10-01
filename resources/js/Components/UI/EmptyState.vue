<script setup>
/**
 * UiEmptyState — Reusable empty state with icon, title, description, and action.
 * Replaces scattered inline empty-state divs across pages.
 */
import Icon from '@/Components/Icon.vue'

const props = defineProps({
    icon: { type: String, default: 'inbox' },
    title: { type: String, default: 'Немає даних' },
    description: { type: String, default: '' },
})
</script>

<template>
    <div class="flex flex-col items-center justify-center py-12 px-6 text-center">
        <div class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center mb-4">
            <Icon :name="icon" size="7" class="text-gray-600" />
        </div>
        <h3 class="text-base font-semibold text-gray-700 mb-1">
            <slot name="title">{{ title }}</slot>
        </h3>
        <p v-if="description || $slots.description" class="text-sm text-gray-600 max-w-sm">
            <slot name="description">{{ description }}</slot>
        </p>
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
