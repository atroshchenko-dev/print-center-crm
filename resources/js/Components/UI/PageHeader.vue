<script setup>
/**
 * UiPageHeader — Standardized page header with title, subtitle, icon, and action slots.
 * Replaces inconsistent <h1> + button patterns across pages.
 */
import Icon from '@/Components/Icon.vue'

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: '' },
})
</script>

<template>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div class="flex items-center gap-3 min-w-0">
            <div v-if="icon"
                 class="w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0">
                <Icon :name="icon" size="5" class="text-indigo-600" />
            </div>
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-800 truncate">{{ title }}</h1>
                <p v-if="subtitle" class="text-sm text-gray-600 mt-0.5">{{ subtitle }}</p>
                <slot name="subtitle" />
            </div>
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2 flex-shrink-0 flex-wrap">
            <slot name="actions" />
        </div>
    </div>
</template>
