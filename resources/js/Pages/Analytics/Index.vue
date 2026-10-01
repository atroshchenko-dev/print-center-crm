<script setup>
/**
 * Analytics/Index — Dashboard з аналітикою
 * Графіки: замовлення/день, топ послуги, оператори
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { router } from '@inertiajs/vue3'
import { ref, computed, onMounted } from 'vue'

const props = defineProps({
    filters:        Object,
    ordersPerDay:   Array,
    topServices:    Array,
    revenueByType:  Array,
    operatorStats:  Array,
    summary:        Object,
})

const from = ref(props.filters?.from ?? '')
const to   = ref(props.filters?.to ?? '')

function applyFilter() {
    router.get(route('analytics'), { from: from.value, to: to.value }, { preserveScroll: true })
}

// ─── Chart rendering with Canvas ────────────────────

const chartCanvas = ref(null)
const barCanvas   = ref(null)

function drawLineChart() {
    const canvas = chartCanvas.value
    if (!canvas || !props.ordersPerDay?.length) return

    const ctx = canvas.getContext('2d')
    const data = props.ordersPerDay
    const w = canvas.width = canvas.parentElement.offsetWidth
    const h = canvas.height = 200
    const pad = { top: 20, right: 20, bottom: 30, left: 40 }

    const maxVal = Math.max(...data.map(d => d.count), 1)
    const xStep = (w - pad.left - pad.right) / Math.max(data.length - 1, 1)
    const yScale = (h - pad.top - pad.bottom) / maxVal

    ctx.clearRect(0, 0, w, h)

    // Grid lines
    ctx.strokeStyle = '#f0f0f0'
    ctx.lineWidth = 1
    for (let i = 0; i <= 4; i++) {
        const y = pad.top + (h - pad.top - pad.bottom) * i / 4
        ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(w - pad.right, y); ctx.stroke()
    }

    // Line
    ctx.strokeStyle = '#6366f1'
    ctx.lineWidth = 2
    ctx.beginPath()
    data.forEach((d, i) => {
        const x = pad.left + i * xStep
        const y = h - pad.bottom - d.count * yScale
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y)
    })
    ctx.stroke()

    // Dots
    ctx.fillStyle = '#6366f1'
    data.forEach((d, i) => {
        const x = pad.left + i * xStep
        const y = h - pad.bottom - d.count * yScale
        ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill()
    })

    // X labels (show every Nth)
    ctx.fillStyle = '#9ca3af'
    ctx.font = '10px sans-serif'
    ctx.textAlign = 'center'
    const step = Math.max(1, Math.floor(data.length / 8))
    data.forEach((d, i) => {
        if (i % step === 0) {
            const x = pad.left + i * xStep
            ctx.fillText(d.date.slice(5), x, h - 8) // MM-DD
        }
    })

    // Y labels
    ctx.textAlign = 'right'
    for (let i = 0; i <= 4; i++) {
        const val = Math.round(maxVal * (4 - i) / 4)
        const y = pad.top + (h - pad.top - pad.bottom) * i / 4
        ctx.fillText(String(val), pad.left - 5, y + 4)
    }
}

function drawBarChart() {
    const canvas = barCanvas.value
    if (!canvas || !props.topServices?.length) return

    const ctx = canvas.getContext('2d')
    const data = props.topServices.slice(0, 8)
    const w = canvas.width = canvas.parentElement.offsetWidth
    const h = canvas.height = 200
    const pad = { top: 10, right: 10, bottom: 60, left: 40 }

    const maxVal = Math.max(...data.map(d => Number(d.total_qty)), 1)
    const barW = (w - pad.left - pad.right) / data.length * 0.7
    const gap  = (w - pad.left - pad.right) / data.length * 0.3

    ctx.clearRect(0, 0, w, h)

    const colors = ['#6366f1', '#8b5cf6', '#a78bfa', '#c4b5fd', '#818cf8', '#6366f1', '#8b5cf6', '#a78bfa']

    data.forEach((d, i) => {
        const x = pad.left + i * (barW + gap) + gap / 2
        const barH = (Number(d.total_qty) / maxVal) * (h - pad.top - pad.bottom)
        const y = h - pad.bottom - barH

        // Bar
        ctx.fillStyle = colors[i % colors.length]
        ctx.beginPath()
        ctx.roundRect(x, y, barW, barH, [4, 4, 0, 0])
        ctx.fill()

        // Value on top
        ctx.fillStyle = '#374151'
        ctx.font = 'bold 11px sans-serif'
        ctx.textAlign = 'center'
        ctx.fillText(String(d.total_qty), x + barW / 2, y - 4)

        // Label
        ctx.fillStyle = '#6b7280'
        ctx.font = '9px sans-serif'
        ctx.save()
        ctx.translate(x + barW / 2, h - pad.bottom + 8)
        ctx.rotate(Math.PI / 4)
        ctx.textAlign = 'left'
        const label = d.service_name.length > 15 ? d.service_name.slice(0, 14) + '…' : d.service_name
        ctx.fillText(label, 0, 0)
        ctx.restore()
    })
}

onMounted(() => {
    drawLineChart()
    drawBarChart()
})
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="page-header">
                <div class="page-header-icon bg-gradient-to-br from-indigo-400 to-violet-600">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                </div>
                <div>
                    <h1 class="page-header-title">Аналітика</h1>
                    <p class="page-header-subtitle">Статистика замовлень, доходів та операторів</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-6 flex flex-col sm:flex-row items-start sm:items-end gap-3">
                <div>
                    <label class="stat-label block mb-1">Від</label>
                    <input aria-label="Від" v-model="from" type="date" class="input w-full sm:w-36" />
                </div>
                <div>
                    <label class="stat-label block mb-1">До</label>
                    <input aria-label="До" v-model="to" type="date" class="input w-full sm:w-36" />
                </div>
                <button @click="applyFilter" class="btn-primary w-full sm:w-auto">Оновити</button>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-indigo-400 to-blue-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Замовлень</p>
                        <p class="stat-value">{{ summary.total_orders }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-emerald-400 to-green-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Собівартість</p>
                        <p class="stat-value">{{ summary.total_cost?.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-violet-400 to-purple-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Комерція</p>
                        <p class="stat-value">{{ summary.total_commercial?.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-amber-400 to-orange-500">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Сер./день</p>
                        <p class="stat-value">{{ summary.avg_per_day }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-red-400 to-rose-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Скасовано</p>
                        <p class="stat-value text-red-600">{{ summary.cancelled }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-green-400 to-emerald-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Прибуток COM</p>
                        <p class="stat-value text-green-700">{{ summary.profit?.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-teal-400 to-cyan-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div>
                        <p class="stat-label">Економія INT</p>
                        <p class="stat-value text-teal-700">{{ summary.savings?.toFixed(0) }} <span class="text-sm text-gray-600 font-normal">₴</span></p>
                    </div>
                </div>
            </div>

            <!-- Charts row -->
            <div class="grid md:grid-cols-2 gap-6 mb-6">
                <!-- Orders per day -->
                <div class="card">
                    <h3 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg bg-gradient-to-br from-indigo-400 to-blue-500 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        </div>
                        Замовлення по днях
                    </h3>
                    <div v-if="ordersPerDay?.length">
                        <canvas ref="chartCanvas"></canvas>
                    </div>
                    <p v-else class="text-sm text-gray-600 text-center py-8">Немає даних</p>
                </div>

                <!-- Top services -->
                <div class="card">
                    <h3 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg bg-gradient-to-br from-violet-400 to-purple-500 flex items-center justify-center">
                            <svg class="w-3.5 h-3.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        </div>
                        Топ послуги
                    </h3>
                    <div v-if="topServices?.length">
                        <canvas ref="barCanvas"></canvas>
                    </div>
                    <p v-else class="text-sm text-gray-600 text-center py-8">Немає даних</p>
                </div>
            </div>

            <!-- Tables row -->
            <div class="grid md:grid-cols-2 gap-6">
                <!-- Revenue by type -->
                <div class="card p-0 overflow-hidden">
                    <h3 class="font-semibold text-gray-700 px-4 pt-4 pb-2">Виручка по типах</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs text-gray-600 uppercase">
                                <th scope="col" class="px-4 py-2 text-left">Тип</th>
                                <th scope="col" class="px-4 py-2 text-right">К-ть</th>
                                <th scope="col" class="px-4 py-2 text-right">Собівартість</th>
                                <th scope="col" class="px-4 py-2 text-right">Комерція</th>
                                <th scope="col" class="px-4 py-2 text-right">Прибуток / Економія</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="row in revenueByType" :key="row.type">
                                <td class="px-4 py-2 font-medium">
                                    {{ row.type === 'internal' ? 'Внутрішній' : 'Комерційний' }}
                                </td>
                                <td class="px-4 py-2 text-right">{{ row.count }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ Number(row.total_cost).toFixed(0) }} ₴</td>
                                <td class="px-4 py-2 text-right font-mono">{{ Number(row.total_commercial).toFixed(0) }} ₴</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold"
                                    :class="row.type === 'commercial' ? 'text-green-700' : 'text-teal-700'">
                                    {{ (Number(row.total_commercial) - Number(row.total_cost)).toFixed(0) }} ₴
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Operator stats -->
                <div class="card p-0 overflow-hidden">
                    <h3 class="font-semibold text-gray-700 px-4 pt-4 pb-2">Оператори</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs text-gray-600 uppercase">
                                <th scope="col" class="px-4 py-2 text-left">Ім'я</th>
                                <th scope="col" class="px-4 py-2 text-right">Замовлень</th>
                                <th scope="col" class="px-4 py-2 text-right">Собівартість</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="op in operatorStats" :key="op.name">
                                <td class="px-4 py-2 font-medium">{{ op.name }}</td>
                                <td class="px-4 py-2 text-right">{{ op.order_count }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ Number(op.total_cost).toFixed(0) }} ₴</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
