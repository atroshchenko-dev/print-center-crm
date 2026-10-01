<script setup>
/**
 * UiButton — Unified button component with variants and touch-friendly sizing.
 * TZ §А.4: minimum touch target 44x44px.
 */

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: v => ['primary', 'secondary', 'danger', 'ghost', 'success', 'warning'].includes(v),
    },
    size: { type: String, default: 'md', validator: v => ['sm', 'md', 'lg'].includes(v) },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    type: { type: String, default: 'button' },
})

const emit = defineEmits(['click'])

const classes = {
    primary:   'bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-indigo-500',
    secondary: 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus:ring-indigo-500',
    danger:    'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
    ghost:     'bg-transparent text-gray-600 hover:bg-gray-100 focus:ring-gray-400',
    success:   'bg-green-600 text-white hover:bg-green-700 focus:ring-green-500',
    warning:   'bg-amber-500 text-white hover:bg-amber-600 focus:ring-amber-400',
}

const sizes = {
    sm: 'min-h-[36px] px-3 py-1.5 text-sm',
    md: 'min-h-[44px] px-4 py-2 text-sm',
    lg: 'min-h-[52px] px-6 py-3 text-base',
}
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center gap-2 font-medium',
            'focus:outline-none focus:ring-2 focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'active:scale-[0.97]',
            classes[variant],
            sizes[size],
        ]"
        :style="{
            borderRadius: 'var(--radius-md)',
            transition: 'all var(--duration-fast) var(--easing-default)',
        }"
        @click="emit('click', $event)"
    >
        <!-- Loading spinner -->
        <svg v-if="loading" class="animate-spin -ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <!-- Icon slot (before text) -->
        <slot name="icon" />
        <!-- Default slot (text) -->
        <slot />
    </button>
</template>
