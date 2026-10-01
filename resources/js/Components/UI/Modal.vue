<script setup>
/**
 * UiModal — Reusable modal dialog with overlay, title, action slots.
 * Supports Escape key, focus trap, and scale entrance animation.
 */
import { watch, onMounted, onBeforeUnmount, ref, nextTick } from 'vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    maxWidth: { type: String, default: 'md', validator: v => ['sm', 'md', 'lg', 'xl', '2xl', 'full'].includes(v) },
    closeable: { type: Boolean, default: true },
})

const emit = defineEmits(['close'])
const panelRef = ref(null)

const widths = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    full: 'max-w-full mx-4',
}

// Close on Escape key
function onKeyDown(e) {
    if (e.key === 'Escape' && props.closeable) {
        emit('close')
    }
}

// Focus trap: cycle Tab within modal
function onFocusTrap(e) {
    if (e.key !== 'Tab' || !panelRef.value) return
    const focusable = panelRef.value.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    )
    if (focusable.length === 0) return
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
    }
}

// Auto-focus first focusable element when opened
watch(() => props.show, async (val) => {
    if (val) {
        document.addEventListener('keydown', onKeyDown)
        document.addEventListener('keydown', onFocusTrap)
        document.body.style.overflow = 'hidden'
        await nextTick()
        if (panelRef.value) {
            const first = panelRef.value.querySelector(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            )
            first?.focus()
        }
    } else {
        document.removeEventListener('keydown', onKeyDown)
        document.removeEventListener('keydown', onFocusTrap)
        document.body.style.overflow = ''
    }
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeyDown)
    document.removeEventListener('keydown', onFocusTrap)
    document.body.style.overflow = ''
})
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 role="dialog"
                 aria-modal="true"
                 :aria-label="title || undefined">
                <!-- Overlay -->
                <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"
                     @click="closeable && emit('close')" />

                <!-- Panel with scale transition -->
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                    appear
                >
                    <div v-if="show" ref="panelRef"
                         :class="['relative w-full bg-white', widths[maxWidth]]"
                         style="border-radius: var(--radius-xl); box-shadow: var(--shadow-xl);">
                        <!-- Header -->
                        <div v-if="title || $slots.header"
                             class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                            <slot name="header">
                                <h3 class="text-lg font-semibold text-gray-800">{{ title }}</h3>
                            </slot>
                            <button v-if="closeable"
                                class="min-h-[44px] min-w-[44px] flex items-center justify-center
                                       rounded-lg text-gray-600 hover:text-gray-600 hover:bg-gray-100 transition"
                                @click="emit('close')"
                                aria-label="Закрити"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="px-6 py-4">
                            <slot />
                        </div>

                        <!-- Footer -->
                        <div v-if="$slots.footer" class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-100">
                            <slot name="footer" />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
