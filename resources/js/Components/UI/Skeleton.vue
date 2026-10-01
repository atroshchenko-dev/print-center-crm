<script setup>
/**
 * UiSkeleton — Shimmer loading placeholder.
 * Use for progressive loading states in tables, cards, and forms.
 */
const props = defineProps({
    variant: { type: String, default: 'rect', validator: v => ['text', 'circle', 'rect'].includes(v) },
    width: { type: String, default: '100%' },
    height: { type: String, default: '1rem' },
    count: { type: Number, default: 1 },
})
</script>

<template>
    <div v-for="i in count" :key="i"
         :class="[
             'bg-gray-200 animate-shimmer',
             variant === 'circle' ? 'rounded-full' : 'rounded',
             i < count ? 'mb-2' : '',
         ]"
         :style="{
             width: variant === 'circle' ? height : width,
             height: height,
         }"
         role="presentation"
         aria-hidden="true"
    />
</template>
