<script setup>
/**
 * UiToast — Flash message display for Inertia.js flash data.
 * Reads $page.props.flash.success / flash.error / flash.warning automatically.
 * Uses aria-live for screen reader accessibility.
 */
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const visible = ref(false)
const timer = ref(null)
const progress = ref(100)
let progressTimer = null

const DISMISS_MS = 5000
const TICK_MS = 50

const flash = computed(() => ({
    success: page.props.flash?.success ?? null,
    error:   page.props.flash?.error ?? null,
    warning: page.props.flash?.warning ?? null,
}))

const message = computed(() => flash.value.success || flash.value.warning || flash.value.error)
const type = computed(() => {
    if (flash.value.error) return 'error'
    if (flash.value.warning) return 'warning'
    return 'success'
})

const colors = {
    success: 'bg-green-50 border-green-200 text-green-800',
    error:   'bg-red-50 border-red-200 text-red-800',
    warning: 'bg-amber-50 border-amber-200 text-amber-800',
}

const progressColors = {
    success: 'bg-green-400',
    error:   'bg-red-400',
    warning: 'bg-amber-400',
}

const iconPaths = {
    success: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    error:   'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
    warning: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
}

function startProgress() {
    progress.value = 100
    clearInterval(progressTimer)
    progressTimer = setInterval(() => {
        progress.value -= (TICK_MS / DISMISS_MS) * 100
        if (progress.value <= 0) {
            clearInterval(progressTimer)
        }
    }, TICK_MS)
}

watch(message, (val) => {
    if (val) {
        visible.value = true
        progress.value = 100
        clearTimeout(timer.value)
        clearInterval(progressTimer)
        startProgress()
        timer.value = setTimeout(() => { visible.value = false }, DISMISS_MS)
    }
})

function dismiss() {
    visible.value = false
    clearTimeout(timer.value)
    clearInterval(progressTimer)
}

// Pause countdown on hover
function onMouseEnter() {
    clearTimeout(timer.value)
    clearInterval(progressTimer)
}

function onMouseLeave() {
    if (!visible.value) return
    startProgress()
    const remaining = (progress.value / 100) * DISMISS_MS
    timer.value = setTimeout(() => { visible.value = false }, remaining)
}
</script>

<template>
    <Transition
        enter-active-class="transition ease-out duration-300"
        enter-from-class="opacity-0 translate-y-2 scale-95"
        enter-to-class="opacity-100 translate-y-0 scale-100"
        leave-active-class="transition ease-in duration-200"
        leave-from-class="opacity-100 translate-y-0 scale-100"
        leave-to-class="opacity-0 translate-y-2 scale-95"
    >
        <div
            v-if="visible && message"
            :class="['fixed bottom-6 right-6 z-50 flex flex-col max-w-md border overflow-hidden', colors[type]]"
            style="border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);"
            role="alert"
            aria-live="polite"
            @mouseenter="onMouseEnter"
            @mouseleave="onMouseLeave"
        >
            <div class="flex items-center gap-3 px-5 py-3">
                <!-- SVG Icon -->
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="iconPaths[type]" />
                </svg>
                <span class="text-sm font-medium flex-1">{{ message }}</span>
                <button
                    class="ml-auto min-h-[44px] min-w-[44px] flex items-center justify-center -mr-2
                           rounded-lg hover:bg-black/5 transition"
                    @click="dismiss"
                    aria-label="Закрити сповіщення"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <!-- Progress bar -->
            <div class="h-0.5 w-full bg-black/5">
                <div
                    :class="['h-full', progressColors[type]]"
                    :style="{ width: progress + '%', transition: `width ${TICK_MS}ms linear` }"
                />
            </div>
        </div>
    </Transition>
</template>
