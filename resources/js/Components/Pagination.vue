<script setup>
/**
 * Pagination — reusable Laravel pagination component.
 *
 * Replaces v-html="link.label" with safe text rendering.
 * Handles «/» and »/» arrows via computed label sanitization.
 */
import { router } from '@inertiajs/vue3'

const props = defineProps({
    links: { type: Array, required: true },
})

function goPage(url) {
    if (url) router.visit(url, { preserveScroll: true })
}

/**
 * Decode HTML entities from Laravel pagination labels.
 * e.g. "&laquo; Previous" → "« Previous", "Next &raquo;" → "Next »"
 */
function decodeLabel(html) {
    return html
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g, '&')
}
</script>

<template>
    <div v-if="links?.length > 3" class="flex justify-center gap-1 mt-4">
        <button
            v-for="link in links"
            :key="link.label"
            :disabled="!link.url"
            :class="[
                'px-3 py-1.5 rounded text-sm transition',
                link.active
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50',
                !link.url ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer',
            ]"
            @click="goPage(link.url)"
        >
            {{ decodeLabel(link.label) }}
        </button>
    </div>
</template>
