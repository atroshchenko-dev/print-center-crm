<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    orders:  Array,
    filters: Object,
    totals:  Object,
})

const from = ref(props.filters?.from ?? '')
const to   = ref(props.filters?.to ?? '')

function applyFilter() {
    router.get(route('reports.commercial'), { from: from.value, to: to.value }, { preserveScroll: true })
}

const cashOrders = computed(() => props.orders?.filter(o => o.payment_method === 'cash') ?? [])
const cardOrders = computed(() => props.orders?.filter(o => o.payment_method === 'card') ?? [])
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-amber-400 to-orange-500">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Комерційний звіт</h1>
                        <p class="page-header-subtitle">Дохід від комерційних замовлень за період</p>
                    </div>
                </div>
                <a :href="route('reports.commercial.export', { from: from, to: to })"
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

            <!-- Summary cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-amber-400 to-orange-500">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Замовлень</p>
                        <p class="stat-value">{{ totals.count }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-emerald-400 to-green-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Дохід</p>
                        <p class="stat-value">{{ parseFloat(totals.commercial).toFixed(2) }} <span class="text-sm text-gray-600 font-normal">грн</span></p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-green-400 to-emerald-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Готівка</p>
                        <p class="stat-value">{{ totals.cash_count }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-blue-400 to-indigo-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Картка</p>
                        <p class="stat-value">{{ totals.card_count }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-rose-400 to-red-500">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Собівартість</p>
                        <p class="stat-value text-rose-600">{{ parseFloat(totals.cost).toFixed(2) }} <span class="text-sm text-gray-600 font-normal">грн</span></p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-green-400 to-emerald-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Прибуток</p>
                        <p class="stat-value text-green-700">{{ parseFloat(totals.profit).toFixed(2) }} <span class="text-sm text-gray-600 font-normal">грн</span></p>
                    </div>
                </div>
            </div>

            <!-- Orders table -->
            <div class="card p-0 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3">Номер</th>
                            <th scope="col" class="px-4 py-3">Дата</th>
                            <th scope="col" class="px-4 py-3">Оплата</th>
                            <th scope="col" class="px-4 py-3">Виконавець</th>
                            <th scope="col" class="px-4 py-3 text-right">Сума</th>
                            <th scope="col" class="px-4 py-3 text-right">Собів.</th>
                            <th scope="col" class="px-4 py-3 text-right">Прибуток</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-if="!orders?.length">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-600">Немає даних</td>
                        </tr>
                        <tr v-for="order in orders" :key="order.id" class="table-row-hover">
                            <td class="px-4 py-2.5 font-mono text-indigo-600 text-xs">{{ order.order_number }}</td>
                            <td class="px-4 py-2.5 text-gray-600 text-xs">{{ new Date(order.created_at).toLocaleDateString('uk-UA') }}</td>
                            <td class="px-4 py-2.5">
                                <span v-if="order.payment_method === 'cash'" class="badge bg-green-100 text-green-700">Готівка</span>
                                <span v-else-if="order.payment_method === 'card'" class="badge bg-blue-100 text-blue-700">Картка</span>
                                <span v-else class="text-gray-600 text-xs">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-gray-600">{{ order.user?.name }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold text-gray-800">
                                {{ parseFloat(order.total_commercial).toFixed(2) }} грн
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-gray-600">
                                {{ parseFloat(order.total_cost).toFixed(2) }}
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold text-green-700">
                                {{ (parseFloat(order.total_commercial) - parseFloat(order.total_cost)).toFixed(2) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="orders?.length">
                        <tr class="bg-gray-50 border-t font-semibold">
                            <td colspan="4" class="px-4 py-3 text-right text-gray-600">Всього:</td>
                            <td class="px-4 py-3 text-right text-gray-800 font-bold">
                                {{ parseFloat(totals.commercial).toFixed(2) }} грн
                            </td>
                            <td class="px-4 py-3 text-right text-gray-600">
                                {{ parseFloat(totals.cost).toFixed(2) }}
                            </td>
                            <td class="px-4 py-3 text-right text-green-700 font-bold">
                                {{ parseFloat(totals.profit).toFixed(2) }} грн
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
