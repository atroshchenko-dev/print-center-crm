<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { calendarDay } from '@/composables/useCalendarDay'

const props = defineProps({
    shift: Object,
    cash_calculated: Number,
})

const form = useForm({})

function submit() {
    form.post(route('shifts.close'))
}
</script>

<template>
    <AppLayout>
        <div class="max-w-lg mx-auto">
            <div class="page-header">
                <div class="page-header-icon bg-gradient-to-br from-amber-400 to-orange-500">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </div>
                <div>
                    <h1 class="page-header-title">Закриття зміни</h1>
                    <p class="page-header-subtitle">Підтвердження завершення робочої зміни</p>
                </div>
            </div>

            <div class="card text-center py-8 space-y-4">
                <div class="text-5xl"><svg class="w-12 h-12 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></div>
                <p class="text-gray-600">
                    Ви збираєтесь закрити зміну за <strong>{{ calendarDay(shift.date) }}</strong>.
                </p>

                <!-- Cash balance info -->
                <div class="px-4 py-3 rounded-lg bg-indigo-50 border border-indigo-200 text-sm text-indigo-800">
                    Розрахунковий залишок каси:
                    <strong class="ml-1">{{ cash_calculated.toFixed(2) }} грн</strong>
                </div>

                <p class="text-sm text-gray-600">
                    Звірка каси буде проведена вранці при відкритті наступної зміни.
                </p>
            </div>

            <form @submit.prevent="submit" class="mt-6">
                <button
                    type="submit"
                    class="btn-danger w-full justify-center py-3 text-base"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Закриваю зміну…' : 'Закрити зміну' }}
                </button>
            </form>
        </div>
    </AppLayout>
</template>
