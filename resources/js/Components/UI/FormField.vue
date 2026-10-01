<script setup>
/**
 * UiFormField — Label + Input + Error + Hint wrapper for Inertia useForm.
 * Provides consistent form field structure with validation feedback.
 */

const props = defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    required: { type: Boolean, default: false },
    hint: { type: String, default: '' },
    id: { type: String, default: null },
})
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700">
            {{ label }}
            <span v-if="required" class="text-red-600 ml-0.5" aria-label="обов'язкове поле">*</span>
        </label>

        <slot />

        <!-- Hint text -->
        <p v-if="hint && !error" class="text-xs text-gray-600 mt-1">{{ hint }}</p>

        <!-- Error message -->
        <p v-if="error" class="text-xs text-red-600 mt-1 flex items-center gap-1" role="alert">
            <svg class="w-3.5 h-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            {{ error }}
        </p>
    </div>
</template>
