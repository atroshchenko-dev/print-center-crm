<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { calendarDay } from '@/composables/useCalendarDay'

const props = defineProps({
    shifts:  Array,
    filters: Object,
    totals:  Object,
})

const from = ref(props.filters?.from ?? '')
const to   = ref(props.filters?.to ?? '')

function applyFilter() {
    router.get(route('reports.cash-flow'), { from: from.value, to: to.value }, { preserveScroll: true })
}

const txTypeLabels = {
    payment_cash: 'Оплата готівкою',
    payment_card: 'Оплата карткою',
    withdrawal:   'Видача',
    reversal:     'Сторно',
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-emerald-400 to-green-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Рух коштів</h1>
                        <p class="page-header-subtitle">Надходження та видача за період</p>
                    </div>
                </div>
                <a :href="route('reports.cash-flow.export', { from: from, to: to })"
                   class="btn-ghost border border-gray-200 text-sm w-full sm:w-auto text-center">
                    Експорт XLSX
                </a>
            </div>

            <!-- Filters -->
            <div class="card mb-6 flex flex-col sm:flex-row items-start sm:items-end gap-3">
                <div>
                    <label class="stat-label block mb-1">Від</label>
                    <input aria-label="Від" v-model="from" type="date" class="input w-full sm:w-40" />
                </div>
                <div>
                    <label class="stat-label block mb-1">До</label>
                    <input aria-label="До" v-model="to" type="date" class="input w-full sm:w-40" />
                </div>
                <button @click="applyFilter" class="btn-primary w-full sm:w-auto">Застосувати</button>
            </div>

            <!-- Totals -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="card flex items-center gap-4">
                    <div class="stat-icon bg-gradient-to-br from-green-400 to-emerald-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Готівка надх.</p>
                        <p class="stat-value text-green-700">+{{ parseFloat(totals.cash_in).toFixed(2) }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-4">
                    <div class="stat-icon bg-gradient-to-br from-blue-400 to-indigo-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Картка надх.</p>
                        <p class="stat-value text-blue-600">+{{ parseFloat(totals.card_in).toFixed(2) }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-4">
                    <div class="stat-icon bg-gradient-to-br from-red-400 to-rose-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Видача</p>
                        <p class="stat-value text-red-600">-{{ parseFloat(totals.out).toFixed(2) }}</p>
                    </div>
                </div>
            </div>

            <!-- Per shift -->
            <div v-for="shift in shifts" :key="shift.id" class="card mb-4">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-gray-100">
                    <div>
                        <!-- The number, not just the date: a day can hold more
                             than one shift, and two rows labelled with the same
                             date are two rows nobody can tell apart. -->
                        <h2 class="font-semibold text-gray-800">
                            Зміна #{{ shift.id }} · {{ calendarDay(shift.date) }}
                        </h2>
                        <p class="text-xs text-gray-600">
                            Відкрив: {{ shift.opener?.name }}
                            · Старт каси: {{ parseFloat(shift.cash_start).toFixed(2) }} грн
                        </p>
                    </div>
                    <div class="text-right">
                        <p v-if="shift.cash_actual !== null" class="text-sm font-semibold text-gray-800">
                            Факт: {{ parseFloat(shift.cash_actual).toFixed(2) }} грн
                        </p>
                        <span :class="shift.status === 'open' ? 'badge bg-green-100 text-green-700' : 'badge bg-gray-100 text-gray-600'">
                            {{ shift.status }}
                        </span>
                    </div>
                </div>

                <div v-if="!shift.ledger_transactions?.length" class="text-sm text-gray-600 py-2">
                    Немає транзакцій
                </div>

                <div v-else class="space-y-1">
                    <div v-for="tx in shift.ledger_transactions" :key="tx.id"
                        class="flex justify-between items-center text-sm py-1 border-b border-gray-50 last:border-0">
                        <div>
                            <span class="text-gray-600">{{ txTypeLabels[tx.type] ?? tx.type }}</span>
                            <span class="text-xs text-gray-600 ml-2">{{ tx.comment }}</span>
                        </div>
                        <span class="font-mono text-sm font-medium"
                            :class="tx.amount >= 0 ? 'text-green-700' : 'text-red-600'">
                            {{ tx.amount >= 0 ? '+' : '' }}{{ parseFloat(tx.amount).toFixed(2) }} грн
                        </span>
                    </div>
                </div>
            </div>

            <div v-if="!shifts?.length" class="card text-center text-gray-600 py-12">
                Немає даних за вибраний період
            </div>
        </div>
    </AppLayout>
</template>
