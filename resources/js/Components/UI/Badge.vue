<script setup>
/**
 * UiBadge — Status badge with color variants matching OrderStatus.
 * Supports optional pulsing dot indicator for "live" badges.
 */

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: v => ['default', 'success', 'warning', 'danger', 'info', 'neutral', 'brand'].includes(v),
    },
    size: { type: String, default: 'md', validator: v => ['sm', 'md'].includes(v) },
    dot: { type: Boolean, default: false },
    removable: { type: Boolean, default: false },
})

const emit = defineEmits(['remove'])

const colors = {
    default: 'bg-gray-100 text-gray-700',
    success: 'bg-green-100 text-green-800',
    warning: 'bg-amber-100 text-amber-800',
    danger:  'bg-red-100 text-red-800',
    info:    'bg-blue-100 text-blue-800',
    neutral: 'bg-gray-50 text-gray-600',
    brand:   'bg-brand-50 text-brand-700',
}

const dotColors = {
    default: 'bg-gray-500',
    success: 'bg-green-500',
    warning: 'bg-amber-500',
    danger:  'bg-red-500',
    info:    'bg-blue-500',
    neutral: 'bg-gray-400',
    brand:   'bg-brand-500',
}

const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-1 text-xs',
}
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-full font-medium', colors[variant], sizes[size]]">
        <!-- Optional pulsing dot -->
        <span v-if="dot"
            :class="['w-1.5 h-1.5 rounded-full animate-pulse-dot', dotColors[variant]]" />
        <slot />
        <!-- Optional remove button -->
        <button v-if="removable"
            type="button"
            class="ml-0.5 -mr-1 inline-flex items-center justify-center w-4 h-4 rounded-full
                   hover:bg-black/10 transition-colors focus:outline-none"
            @click.stop="emit('remove')"
            aria-label="Видалити">
            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </span>
</template>
