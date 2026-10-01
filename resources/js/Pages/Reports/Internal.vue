<script setup>
/**
 * Reports/Internal — внутрішній акт перевірки
 * Grouped by cost_center, with copy count, BW/Color clicks
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    data:    Object,   // { cost_center: { orders, total_cost, bw_clicks, color_clicks } }
    filters: Object,
    totals:  Object,
})

const from = ref(props.filters?.from ?? '')
const to   = ref(props.filters?.to ?? '')

function applyFilter() {
    router.get(route('reports.internal'), { from: from.value, to: to.value }, { preserveScroll: true })
}

const groups = Object.entries(props.data ?? {})
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-blue-500 to-indigo-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Внутрішній акт перевірки</h1>
                        <p class="page-header-subtitle">Звіт по внутрішніх замовленнях за період</p>
                    </div>
                </div>
                <a :href="route('reports.internal.export', { from: from, to: to })"
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

            <!-- Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div class="card flex items-center gap-4">
                    <div class="stat-icon bg-gradient-to-br from-indigo-400 to-blue-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Замовлень</p>
                        <p class="stat-value">{{ totals.orders }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-4">
                    <div class="stat-icon bg-gradient-to-br from-emerald-400 to-green-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Собівартість</p>
                        <p class="stat-value">{{ parseFloat(totals.cost).toFixed(2) }} <span class="text-sm text-gray-600 font-normal">грн</span></p>
                    </div>
                </div>
                <div class="card flex items-center gap-4">
                    <div class="stat-icon bg-gradient-to-br from-violet-400 to-purple-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Підрозділів</p>
                        <p class="stat-value">{{ groups.length }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-4">
                    <div class="stat-icon bg-gradient-to-br from-teal-400 to-cyan-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Економія</p>
                        <p class="stat-value text-teal-700">{{ (parseFloat(totals.commercial) - parseFloat(totals.cost)).toFixed(2) }} <span class="text-sm text-gray-600 font-normal">грн</span></p>
                    </div>
                </div>
            </div>


            <!-- Empty -->
            <div v-if="groups.length === 0" class="card empty-state">
                <div class="empty-state-icon">
                    <svg class="w-8 h-8 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                </div>
                <p class="empty-state-title">Немає даних за вибраний період</p>
                <p class="empty-state-hint">Спробуйте змінити дати фільтрації</p>
            </div>

            <div v-for="[center, group] in groups" :key="center" class="mb-6">
                <!-- Group header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between px-5 py-3 bg-indigo-50 rounded-t-xl border border-indigo-200 border-b-0 gap-2">
                    <h2 class="font-bold text-indigo-700 flex items-center gap-2">
                        <span class="text-lg">🏛️</span>
                        {{ center || 'Без підрозділу' }}
                    </h2>
                    <div class="flex gap-4 text-sm text-gray-600">
                        <span>⬛ ЧБ: <strong class="text-gray-800">{{ group.bw_clicks }}</strong></span>
                        <span>🌈 Кол: <strong class="text-gray-800">{{ group.color_clicks }}</strong></span>
                        <span class="font-bold text-indigo-700">{{ parseFloat(group.total_cost).toFixed(2) }} грн</span>
                        <span class="font-bold text-teal-700" title="Економія порівняно з комерційною ціною">🛡️ {{ (parseFloat(group.total_commercial) - parseFloat(group.total_cost)).toFixed(2) }} грн</span>
                    </div>
                </div>

                <!-- Orders table -->
                <div class="card !rounded-t-none overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="table-header">
                                <th scope="col">Номер</th>
                                <th scope="col">Підписант</th>
                                <th scope="col">Дата</th>
                                <th scope="col" class="text-right">Собівартість</th>
                                <th scope="col" class="text-right">Комерц. ціна</th>
                                <th scope="col" class="text-right">Економія</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="order in group.orders" :key="order.id" class="table-row-hover">
                                <td class="py-2 pr-4 font-mono text-indigo-600 text-xs">{{ order.order_number }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ order.authorized_person ?? '—' }}</td>
                                <td class="py-2 pr-4 text-gray-600 text-xs">{{ new Date(order.created_at).toLocaleDateString('uk-UA') }}</td>
                                <td class="py-2 pr-4 text-right font-mono text-gray-700">{{ parseFloat(order.total_cost).toFixed(2) }}</td>
                                <td class="py-2 pr-4 text-right font-mono text-gray-600">{{ parseFloat(order.total_commercial).toFixed(2) }}</td>
                                <td class="py-2 text-right font-mono font-semibold text-teal-700">{{ (parseFloat(order.total_commercial) - parseFloat(order.total_cost)).toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
