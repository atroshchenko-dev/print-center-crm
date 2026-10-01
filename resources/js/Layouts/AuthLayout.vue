<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const flash = computed(() => usePage().props.flash ?? {})

// The same safety net as AppLayout, and this layout needs it for a reason that
// is easy to miss: `Shift/Open.vue` lives here, not on AppLayout. It is the
// form that opens the working day — counter readings and the previous shift's
// cash — and a fix applied only to AppLayout would have left exactly that one
// silent (CLOSEOUT §1.9).
const validationErrors = computed(() =>
    Object.values(usePage().props.errors ?? {}).filter(m => typeof m === 'string' && m !== ''),
)
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-50 via-white to-gray-100">
        <div class="w-full max-w-sm">
            <!-- Flash -->
            <div v-if="flash.error" class="mb-4 px-4 py-3 rounded-lg bg-red-50 text-red-700 border border-red-200 text-sm">
                {{ flash.error }}
            </div>
            <div v-if="flash.success" class="mb-4 px-4 py-3 rounded-lg bg-green-50 text-green-700 border border-green-200 text-sm">
                {{ flash.success }}
            </div>

            <div v-if="validationErrors.length" role="alert" aria-live="assertive"
                class="mb-4 px-4 py-3 rounded-lg bg-red-50 text-red-800 border border-red-200 text-sm">
                <p class="font-semibold mb-1">Не збережено — форму відхилено:</p>
                <ul class="space-y-0.5">
                    <li v-for="(message, i) in validationErrors" :key="i" class="whitespace-pre-line">
                        {{ message }}
                    </li>
                </ul>
            </div>

            <div class="card shadow-xl border-0">
                <slot />
            </div>
        </div>
    </div>
</template>
