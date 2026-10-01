<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { calendarDay } from '@/composables/useCalendarDay'

const props = defineProps({
    counterAnalytics: Array,
    counterTypeTotals: Array,
    shifts: Array,
    filters: Object,
})

const from = ref(props.filters?.from ?? '')
const to   = ref(props.filters?.to ?? '')

function applyFilter() {
    router.get(route('reports.counters'), { from: from.value, to: to.value }, { preserveScroll: true })
}

// Any row whose clicks belong to a counter type rather than to that machine.
const sharedCounterTypes = computed(() =>
    (props.counterAnalytics || []).some(i => i.clicks_are_this_machines === false)
)

function badgeClass(unaccounted) {
    const abs = Math.abs(unaccounted)
    if (abs === 0) return 'bg-green-100 text-green-700'
    if (abs <= 10) return 'bg-amber-100 text-amber-700'
    return 'bg-red-100 text-red-700'
}

function typeLabel(type) {
    const val = typeof type === 'object' ? type?.value ?? type : type
    const labels = { bw: 'Ч/Б', color: 'Колір', riso: 'Різограф' }
    return labels[val] ?? val
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-violet-400 to-purple-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Аналітика лічильників</h1>
                        <p class="page-header-subtitle">Порівняння фізичних та програмних кліків</p>
                    </div>
                </div>
                <a :href="route('reports.counters.export', { from: from, to: to })"
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

            <!-- Analytics table -->
            <div class="card" v-if="counterAnalytics?.length">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="pb-3 font-medium">Апарат</th>
                            <th scope="col" class="pb-3 font-medium">Тип</th>
                            <th scope="col" class="pb-3 font-medium text-right">Фізична різниця</th>
                            <th scope="col" class="pb-3 font-medium text-right">Програмні кліки</th>
                            <th scope="col" class="pb-3 font-medium text-right">Нерозраховано</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in counterAnalytics" :key="item.equipment_id"
                            class="border-b border-gray-50 last:border-0 table-row-hover">
                            <td class="py-3 font-medium text-gray-800">
                                {{ item.equipment_name }}
                                <span v-if="item.is_retired"
                                    class="badge bg-gray-100 text-gray-600 ml-1 font-normal">виведений з експлуатації</span>
                            </td>
                            <td class="py-3">
                                <span class="badge bg-gray-100 text-gray-600">{{ typeLabel(item.equipment_type) }}</span>
                            </td>
                            <td class="py-3 text-right font-mono">{{ item.physical_delta }}</td>
                            <td class="py-3 text-right font-mono">
                                {{ item.software_clicks }}
                                <span v-if="item.clicks_are_this_machines === false" class="text-amber-600 font-sans">*</span>
                            </td>
                            <td class="py-3 text-right">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                    :class="item.clicks_are_this_machines === false
                                        ? 'bg-gray-100 text-gray-600'
                                        : badgeClass(item.unaccounted)">
                                    {{ item.unaccounted > 0 ? '+' : '' }}{{ item.unaccounted }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!--
                    An order does not record which machine printed it, so the
                    software clicks are summed per counter type. With one machine
                    of a type that is the same thing; with two it is not, and each
                    row subtracts the type's whole total from its own delta — so
                    the greyed-out figure beside the asterisk is neither the
                    machine's balance nor the type's. The type's is below, and it
                    is the number this page exists to show (audit R22-1).
                -->
                <!--
                    And what the type's balance still cannot mean, which is a
                    question of time rather than of machines.

                    The clicks are summed over the orders of the period; the
                    counters move when the printing happens. Those are not the
                    same days: an order is entered when the work is handed over,
                    and July 2026 shows what that costs — two shifts carry 83%
                    of the month's booked clicks (the graduation run, 365
                    diplomas in one order) against 1,443 physical, while the
                    other 29 shifts show 11,801 physical against 2,856 booked
                    (audit R22-5).

                    Round 23 asked whether the printing moment could be
                    recovered from the status history and found there is none:
                    every internal order of July went from created to
                    completed_issued in 2–36 seconds, the diploma run in 27.
                    The system is never told when printing happened, so no
                    column and no join can fix this — only an operator action
                    nobody wants (audit R23-1). Hence a sentence, not a feature.
                -->
                <div v-if="counterTypeTotals?.length" class="mt-5 border-t border-gray-200 pt-4">
                    <p class="stat-label mb-2">Разом по типу</p>
                    <table class="w-full text-sm">
                        <thead class="sr-only">
                            <tr>
                                <th scope="col">Тип</th>
                                <th scope="col">Фізична різниця</th>
                                <th scope="col">Програмні кліки</th>
                                <th scope="col">Нерозраховано</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="total in counterTypeTotals" :key="total.equipment_type">
                                <td class="py-2 font-medium text-gray-800">
                                    {{ typeLabel(total.equipment_type) }}
                                    <span class="text-gray-500 font-normal">· апаратів: {{ total.machines }}</span>
                                </td>
                                <td class="py-2 text-right font-mono">{{ total.physical_delta }}</td>
                                <td class="py-2 text-right font-mono">{{ total.software_clicks }}</td>
                                <td class="py-2 text-right">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                        :class="badgeClass(total.unaccounted)">
                                        {{ total.unaccounted > 0 ? '+' : '' }}{{ total.unaccounted }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="counterTypeTotals?.length" class="mt-3 text-xs text-gray-600">
                    Кліки взято із замовлень цього періоду, а лічильники — з того, що
                    надруковано. Це різні дні: замовлення записують, коли роботу віддають,
                    і великий тираж може бути надрукований тижнями раніше. Тому
                    «Нерозраховано» варто читати на довгому вікні, а не за день чи тиждень —
                    і пам'ятати, що межа місяця розрізає такий тираж навпіл.
                </p>

                <p v-if="sharedCounterTypes" class="mt-3 text-xs text-amber-700">
                    <span class="font-semibold">*</span>
                    Кілька апаратів цього типу мають лічильник. Замовлення не запам'ятовує,
                    на якому саме друкували, тож програмні кліки в цьому рядку — сума по типу.
                    «Нерозраховано» в таких рядках не означає нічого: рахуйте по рядку
                    «Разом по типу» вище.
                </p>
            </div>

            <!-- Empty state -->
            <div v-else class="card empty-state">
                <div class="empty-state-icon">
                    <svg class="w-8 h-8 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <p class="empty-state-title">Немає даних за вибраний період</p>
                <p class="empty-state-hint">Спробуйте змінити дати фільтрації</p>
            </div>

            <!-- Shift readings detail -->
            <div v-if="shifts?.length" class="mt-6">
                <h2 class="text-lg font-semibold text-gray-700 mb-3">Деталі по змінах</h2>
                <div v-for="shift in shifts" :key="shift.id" class="card mb-3">
                    <div class="flex items-center justify-between mb-2 pb-2 border-b border-gray-100">
                        <!-- Number first — a date no longer identifies a shift. -->
                        <h3 class="font-semibold text-gray-800">Зміна #{{ shift.id }} · {{ calendarDay(shift.date) }}</h3>
                        <div class="text-xs text-gray-600">
                            BW: {{ shift.total_bw_clicks ?? 0 }} ·
                            Color: {{ shift.total_color_clicks ?? 0 }} ·
                            Riso: {{ shift.total_riso_clicks ?? 0 }}
                        </div>
                    </div>

                    <div v-if="!shift.counter_readings?.length" class="text-sm text-gray-600 py-2">
                        Немає показників
                    </div>

                    <div v-else class="space-y-1">
                        <div v-for="r in shift.counter_readings" :key="r.id"
                            class="flex justify-between items-center text-sm py-1 border-b border-gray-50 last:border-0">
                            <div>
                                <span class="text-gray-700">{{ r.equipment?.name ?? '—' }}</span>
                                <span class="text-xs text-gray-600 ml-2">
                                    {{ r.reading_type === 'morning' ? 'Ранок' : r.reading_type === 'evening' ? 'Вечір' : 'Коригування' }}
                                </span>
                            </div>
                            <span class="font-mono text-sm text-gray-800">{{ r.counter_value }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
