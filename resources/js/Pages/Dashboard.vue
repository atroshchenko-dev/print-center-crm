<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { Link, usePage } from '@inertiajs/vue3'
import { computed, onMounted, ref, shallowRef } from 'vue'

const props = defineProps({
    shift: Object,
    chartData: Object,
})

const page = usePage()
const isAdmin = computed(() => page.props.auth?.user?.role === 'admin')

// The quick-action cards below point at routes behind permission:orders and
// permission:ledger. The dashboard itself is only auth-gated — it is where
// every user lands after login — so without this an accountant holding just
// `reports` saw three inviting cards that each answered 403. The sidebar has
// always checked; the dashboard simply never did.
function can(module) {
    return page.props.auth?.user?.permissions?.includes(module) ?? false
}

// Chart instances
const ordersCanvas = ref(null)
const revenueCanvas = ref(null)
const ordersChart = shallowRef(null)
const revenueChart = shallowRef(null)

onMounted(async () => {
    if (!props.chartData || !isAdmin.value) return

    const { Chart, registerables } = await import('chart.js')
    Chart.register(...registerables)

    const brandBlue = '#1D4289'
    const brandTeal = '#0C426F'
    const gray200 = '#e5e7eb'

    // Orders Chart (stacked bar)
    if (ordersCanvas.value) {
        ordersChart.value = new Chart(ordersCanvas.value, {
            type: 'bar',
            data: {
                labels: props.chartData.labels,
                datasets: [
                    {
                        label: 'Внутрішні',
                        data: props.chartData.internalCounts,
                        backgroundColor: brandBlue + 'cc',
                        borderRadius: 4,
                    },
                    {
                        label: 'Комерційні',
                        data: props.chartData.commercialCounts,
                        backgroundColor: '#f59e0b' + 'cc',
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10 } },
                    tooltip: { mode: 'index', intersect: false, padding: 10, cornerRadius: 8 },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: gray200 } },
                },
            },
        })
    }

    // Revenue Chart (line)
    if (revenueCanvas.value) {
        revenueChart.value = new Chart(revenueCanvas.value, {
            type: 'line',
            data: {
                labels: props.chartData.labels,
                datasets: [
                    {
                        label: 'Собівартість INT (₴)',
                        data: props.chartData.internalCost,
                        borderColor: brandBlue,
                        backgroundColor: brandBlue + '20',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                    },
                    {
                        label: 'Дохід COM (₴)',
                        data: props.chartData.commercialRevenue,
                        borderColor: '#f59e0b',
                        backgroundColor: '#f59e0b20',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10 } },
                    tooltip: {
                        mode: 'index', intersect: false, padding: 10, cornerRadius: 8,
                        callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)} ₴` },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: gray200 }, ticks: { callback: v => v + ' ₴' } },
                },
            },
        })
    }
})
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <!-- Header -->
            <div class="page-header">
                <div class="page-header-icon bg-gradient-to-br from-indigo-500 to-purple-600">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <div>
                    <h1 class="page-header-title">Дашборд</h1>
                    <p class="page-header-subtitle">Огляд поточної зміни · оперативна аналітика</p>
                </div>
            </div>

            <!-- Shift open card -->
            <div class="card flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6">
                <div class="flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-green-400 to-emerald-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Поточна зміна</p>
                        <p class="text-lg font-semibold text-green-700 mt-0.5">
                            Відкрита · {{ new Date().toLocaleDateString('uk-UA') }}
                        </p>
                        <p class="text-xs text-gray-600 mt-0.5">
                            Відкрив: {{ shift?.opener?.name ?? '—' }}
                        </p>
                    </div>
                </div>
                <Link :href="route('shifts.close.form')" class="btn-ghost border border-gray-200 w-full sm:w-auto text-center">
                    Закрити зміну
                </Link>
            </div>

            <!-- Quick actions -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                <Link v-if="can('orders')" :href="route('orders.create')" class="card hover:shadow-md transition-all duration-200 group">
                    <div class="stat-icon bg-gradient-to-br from-indigo-400 to-blue-600 mb-3">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    </div>
                    <h2 class="font-semibold text-gray-800 group-hover:text-indigo-600 transition-colors">Нове замовлення</h2>
                    <p class="text-sm text-gray-600 mt-1">Внутрішнє або комерційне</p>
                </Link>

                <Link v-if="can('orders')" :href="route('orders.index')" class="card hover:shadow-md transition-all duration-200 group">
                    <div class="stat-icon bg-gradient-to-br from-amber-400 to-orange-500 mb-3">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <h2 class="font-semibold text-gray-800 group-hover:text-indigo-600 transition-colors">Замовлення зміни</h2>
                    <p class="text-sm text-gray-600 mt-1">Всі замовлення поточної зміни</p>
                </Link>

                <Link v-if="can('ledger')" :href="route('ledger.history')" class="card hover:shadow-md transition-all duration-200 group">
                    <div class="stat-icon bg-gradient-to-br from-emerald-400 to-green-600 mb-3">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <h2 class="font-semibold text-gray-800 group-hover:text-indigo-600 transition-colors">Каса</h2>
                    <p class="text-sm text-gray-600 mt-1">Баланс та видача готівки</p>
                </Link>
            </div>

            <!-- Admin Charts Section -->
            <template v-if="chartData && isAdmin">
                <!-- Internal (INT) section -->
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs">🏢</span>
                        Внутрішні (INT) — собівартість
                    </h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-blue-400 to-indigo-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Сьогодні</p>
                                <p class="stat-value text-[#1D4289]">{{ chartData.todayInt }}</p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-indigo-400 to-blue-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Собів. / тиждень</p>
                                <p class="stat-value text-[#1D4289]">{{ chartData.weekIntCost.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-blue-400 to-indigo-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Зам. / місяць</p>
                                <p class="stat-value text-[#1D4289]">{{ chartData.monthInt }}</p>
                                <p v-if="chartData.lastMonthInt" class="text-xs mt-0.5"
                                   :class="chartData.monthInt >= chartData.lastMonthInt ? 'text-green-700' : 'text-red-600'">
                                    {{ chartData.monthInt >= chartData.lastMonthInt ? '▲' : '▼' }}
                                    мин.: {{ chartData.lastMonthInt }}
                                </p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-indigo-400 to-blue-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Собів. / місяць</p>
                                <p class="stat-value text-[#1D4289]">{{ chartData.monthIntCost?.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                                <p v-if="chartData.lastMonthIntCost" class="text-xs mt-0.5"
                                   :class="chartData.monthIntCost <= chartData.lastMonthIntCost ? 'text-green-700' : 'text-red-600'">
                                    {{ chartData.monthIntCost <= chartData.lastMonthIntCost ? '▼' : '▲' }}
                                    мин.: {{ chartData.lastMonthIntCost?.toFixed(0) }} ₴
                                </p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-teal-400 to-cyan-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Економія / тижд.</p>
                                <p class="stat-value text-teal-700">{{ (chartData.weekIntCommercial - chartData.weekIntCost).toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-teal-400 to-cyan-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Економія / місяць</p>
                                <p class="stat-value text-teal-700">{{ (chartData.monthIntCommercial - chartData.monthIntCost).toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                                <p v-if="chartData.lastMonthIntCommercial" class="text-xs mt-0.5"
                                   :class="(chartData.monthIntCommercial - chartData.monthIntCost) >= (chartData.lastMonthIntCommercial - chartData.lastMonthIntCost) ? 'text-green-700' : 'text-red-600'">
                                    {{ (chartData.monthIntCommercial - chartData.monthIntCost) >= (chartData.lastMonthIntCommercial - chartData.lastMonthIntCost) ? '▲' : '▼' }}
                                    мин.: {{ (chartData.lastMonthIntCommercial - chartData.lastMonthIntCost).toFixed(0) }} ₴
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Commercial (COM) section -->
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white text-xs">💰</span>
                        Комерційні (COM) — дохід
                    </h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-amber-400 to-orange-500">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Сьогодні</p>
                                <p class="stat-value text-amber-700">{{ chartData.todayCom }}</p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-emerald-400 to-green-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Дохід / тиждень</p>
                                <p class="stat-value text-green-700">{{ chartData.weekComRev.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-rose-400 to-red-500">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Собів. / тиждень</p>
                                <p class="stat-value text-rose-600">{{ chartData.weekComCost.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-amber-400 to-orange-500">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Зам. / місяць</p>
                                <p class="stat-value text-amber-700">{{ chartData.monthCom }}</p>
                                <p v-if="chartData.lastMonthCom" class="text-xs mt-0.5"
                                   :class="chartData.monthCom >= chartData.lastMonthCom ? 'text-green-700' : 'text-red-600'">
                                    {{ chartData.monthCom >= chartData.lastMonthCom ? '▲' : '▼' }}
                                    мин.: {{ chartData.lastMonthCom }}
                                </p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3">
                            <div class="stat-icon bg-gradient-to-br from-emerald-400 to-green-600">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Дохід / місяць</p>
                                <p class="stat-value text-green-700">{{ chartData.monthComRev?.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                                <p v-if="chartData.lastMonthComRev" class="text-xs mt-0.5"
                                   :class="chartData.monthComRev >= chartData.lastMonthComRev ? 'text-green-700' : 'text-red-600'">
                                    {{ chartData.monthComRev >= chartData.lastMonthComRev ? '▲' : '▼' }}
                                    мин.: {{ chartData.lastMonthComRev?.toFixed(0) }} ₴
                                </p>
                            </div>
                        </div>
                        <div class="card flex items-center gap-3 col-span-2 sm:col-span-1">
                            <div class="stat-icon" :class="(chartData.monthComRev - chartData.monthComCost) >= 0 ? 'bg-gradient-to-br from-green-400 to-emerald-600' : 'bg-gradient-to-br from-red-400 to-rose-600'">
                                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            </div>
                            <div>
                                <p class="stat-label">Маржа / місяць</p>
                                <p class="stat-value"
                                   :class="(chartData.monthComRev - chartData.monthComCost) >= 0 ? 'text-green-700' : 'text-red-600'">
                                    {{ (chartData.monthComRev - chartData.monthComCost).toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span>
                                </p>
                                <p v-if="chartData.monthComRev > 0" class="text-xs text-gray-600 mt-0.5">
                                    {{ ((chartData.monthComRev - chartData.monthComCost) / chartData.monthComRev * 100).toFixed(0) }}%
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts grid -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <div class="card">
                        <h3 class="text-sm font-semibold text-gray-600 mb-4 flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg bg-gradient-to-br from-blue-400 to-indigo-500 flex items-center justify-center">
                                <svg class="w-3.5 h-3.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            </div>
                            Замовлення (14 днів)
                        </h3>
                        <div class="h-64">
                            <canvas ref="ordersCanvas"></canvas>
                        </div>
                    </div>
                    <div class="card">
                        <h3 class="text-sm font-semibold text-gray-600 mb-4 flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg bg-gradient-to-br from-emerald-400 to-green-500 flex items-center justify-center">
                                <svg class="w-3.5 h-3.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                            </div>
                            Фінанси (14 днів)
                        </h3>
                        <div class="h-64">
                            <canvas ref="revenueCanvas"></canvas>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
