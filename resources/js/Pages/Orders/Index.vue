<script setup>
/**
 * Orders/Index — двострочний card-layout зі списком замовлень
 * Рядок 1: номер + сума + дії + заявка
 * Рядок 2: позиції + підписант/підрозділ + час
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import RequestBadge from '@/Components/RequestBadge.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, computed, watch, onUnmounted } from 'vue'
import { statusLabels, statusColors, isTerminal } from '@/composables/useOrderStatus'
import { serviceOptionLabels } from '@/composables/useConstructorOptions'

const props = defineProps({
    orders:    Object,
    carryover: Array,
    filters:   Object,
    shift:     Object,
    view:      { type: String, default: 'shift' },
})

const currentView = ref(props.view)

function switchView(view) {
    currentView.value = view
    router.get(route('orders.index'), view === 'month' ? monthParams() : {
        view,
        ...(searchQuery.value ? { search: searchQuery.value } : {}),
    }, {
        preserveState: false,
        preserveScroll: false,
    })
}

const processing = ref(new Set())

function changeStatus(order, newStatus) {
    if (processing.value.has(order.id)) return
    processing.value.add(order.id)
    router.patch(route('orders.status', order.id), {
        status: newStatus,
        version: order.version,
    }, {
        preserveScroll: true,
        onFinish: () => processing.value.delete(order.id),
    })
}

function toggleRequest(order) {
    if (processing.value.has('req-' + order.id)) return
    processing.value.add('req-' + order.id)
    router.patch(route('orders.request', order.id), {
        request_received: !order.request_received,
    }, {
        preserveScroll: true,
        onFinish: () => processing.value.delete('req-' + order.id),
    })
}

function getActions(order) {
    const isInternal = order.type === 'internal'
    switch (order.status) {
        case 'new':
            return isInternal
                ? [
                    { label: 'В роботу',  status: 'in_progress',      style: 'action-default' },
                    { label: '✓ Видано',     status: 'completed_issued', style: 'action-success' },
                  ]
                : [{ label: 'В роботу', status: 'in_progress', style: 'action-default' }]
        case 'in_progress':
            return isInternal
                ? [{ label: '✓ Видано', status: 'completed_issued', style: 'action-success' }]
                : [{ label: '☑ Готово',  status: 'ready', style: 'action-default' }]
        case 'ready':
            return isInternal
                ? [{ label: '✓ Видано', status: 'completed_issued', style: 'action-success' }]
                : []
        default:
            return []
    }
}

/** Build structured item details for rendering */
function itemDetails(order) {
    if (!order.items?.length) return []
    const isInternal = order.type === 'internal'
    return order.items.map(i => ({
        name: i.service_name ?? 'Послуга',
        quantity: i.quantity,
        opts: serviceOptionLabels(i.service_snapshot, { isInternal }),
        material: i.material_description,
        customerPaper: !!i.service_snapshot?.customer_paper,
    }))
}

/** Flat text summary for search matching */
function itemsSummary(order) {
    return itemDetails(order).map(d => {
        const opts = d.opts.length ? ` [${d.opts.join(' · ')}]` : ''
        const desc = d.material ? ` (${d.material})` : ''
        return `${d.name} × ${d.quantity}${opts}${desc}`
    }).join(' · ')
}

/** Get display amount for an order (considers at-cost) */
function orderAmount(order) {
    if (order.type === 'internal') return parseFloat(order.total_cost)
    if (order.is_at_cost) return parseFloat(order.total_cost)
    return parseFloat(order.total_commercial)
}

function orderAmountColor(order) {
    if (order.type === 'internal') return 'text-gray-600'
    if (order.is_at_cost) return 'text-amber-700'
    return 'text-gray-800'
}

// ─── Search & filters ───────────────────────────────
// The shift view holds the whole shift, so its search and chips filter
// client-side and the text can look inside item summaries. The month view
// holds at most one page of 50, so its search AND its chips must go to the
// server — filtering only what is loaded quietly hides the rest of the
// month (audit F-1, R3-5).
const searchQuery  = ref(props.filters?.search ?? '')
const filterStatus = ref(props.filters?.status_group ?? 'all')  // 'all' | 'active' | 'terminal'
const filterType   = ref(props.filters?.type ?? 'all')          // 'all' | 'internal' | 'commercial'

function monthParams() {
    return {
        view: 'month',
        ...(searchQuery.value ? { search: searchQuery.value } : {}),
        ...(filterStatus.value !== 'all' ? { status_group: filterStatus.value } : {}),
        ...(filterType.value !== 'all' ? { type: filterType.value } : {}),
    }
}

function reloadMonth() {
    router.get(route('orders.index'), monthParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

let searchTimer = null
watch(searchQuery, () => {
    if (currentView.value !== 'month') return
    clearTimeout(searchTimer)
    searchTimer = setTimeout(reloadMonth, 300)
})

// Chips are one click, not typing — no debounce needed.
watch([filterStatus, filterType], () => {
    if (currentView.value !== 'month') return
    clearTimeout(searchTimer)
    reloadMonth()
})

// The timer must not outlive the page: typing and navigating away within
// the debounce window used to fire router.get() after unmount, yanking the
// user back to the index from wherever they had just landed.
onUnmounted(() => clearTimeout(searchTimer))

function matchesSearch(order) {
    // In month view the server already ran the search and the chips over
    // the whole month — re-filtering the page here could only hide results.
    if (currentView.value === 'month') return true
    // Type filter
    if (filterType.value !== 'all' && order.type !== filterType.value) return false
    // Status filter
    if (filterStatus.value === 'active' && isTerminal(order.status)) return false
    if (filterStatus.value === 'terminal' && !isTerminal(order.status)) return false
    // Text search
    const q = searchQuery.value.toLowerCase().trim()
    if (!q) return true
    return (
        order.order_number?.toLowerCase().includes(q) ||
        order.authorized_person?.toLowerCase().includes(q) ||
        order.cost_center?.toLowerCase().includes(q) ||
        order.initiator?.toLowerCase().includes(q) ||
        itemsSummary(order).toLowerCase().includes(q)
    )
}

const filteredOrders = computed(() =>
    props.orders.data.filter(matchesSearch)
)
const filteredCarryover = computed(() =>
    (props.carryover || []).filter(matchesSearch)
)

// ─── Bulk selection ────────────────────────────────
const selectedIds = ref(new Set())
const batchProcessing = ref(false)

function toggleSelect(orderId) {
    const s = new Set(selectedIds.value)
    s.has(orderId) ? s.delete(orderId) : s.add(orderId)
    selectedIds.value = s
}

const selectableOrders = computed(() =>
    filteredOrders.value.filter(o => !isTerminal(o.status))
)

function selectAll() {
    selectedIds.value = new Set(selectableOrders.value.map(o => o.id))
}

function clearSelection() {
    selectedIds.value = new Set()
}

function batchStatus(status) {
    if (selectedIds.value.size === 0 || batchProcessing.value) return
    batchProcessing.value = true
    router.patch(route('orders.batch-status'), {
        order_ids: [...selectedIds.value],
        status,
    }, {
        preserveScroll: true,
        onFinish: () => {
            batchProcessing.value = false
            selectedIds.value = new Set()
        },
    })
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-4">
                    <div class="page-header-icon bg-gradient-to-br from-indigo-500 to-blue-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">
                            {{ currentView === 'month' ? 'Замовлення за місяць' : 'Замовлення зміни' }}
                        </h1>
                        <div class="flex items-center bg-gray-100 rounded-lg p-0.5 mt-1">
                            <button
                                type="button"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-150"
                                :class="currentView === 'shift'
                                    ? 'bg-white text-indigo-700 shadow-sm'
                                    : 'text-gray-600 hover:text-gray-800'"
                                @click="switchView('shift')"
                            >Зміна</button>
                            <button
                                type="button"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-150"
                                :class="currentView === 'month'
                                    ? 'bg-white text-indigo-700 shadow-sm'
                                    : 'text-gray-600 hover:text-gray-800'"
                                @click="switchView('month')"
                            >Місяць</button>
                        </div>
                    </div>
                </div>
                <Link :href="route('orders.create')" class="btn-primary w-full sm:w-auto text-center">
                    + Нове замовлення
                </Link>
            </div>

            <!-- Search + Filters -->
            <div class="mb-4 space-y-3">
                <input aria-label="Пошук за номером, підписантом, підрозділом"
                    v-model="searchQuery"
                    type="search"
                    placeholder="Пошук за номером, підписантом, підрозділом…"
                    class="w-full text-sm px-4 py-2.5 border border-gray-200 rounded-xl bg-white focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100 outline-none transition placeholder-gray-600"
                />
                <div class="flex items-center gap-4 text-xs">
                    <!-- Status filter -->
                    <div class="flex items-center gap-1">
                        <button v-for="opt in [
                            { value: 'all', label: 'Всі' },
                            { value: 'active', label: 'Активні' },
                            { value: 'terminal', label: 'Завершені' },
                        ]" :key="opt.value"
                            type="button"
                            class="px-2.5 py-1 rounded-full font-medium transition-all duration-150"
                            :class="filterStatus === opt.value
                                ? 'bg-indigo-100 text-indigo-700'
                                : 'text-gray-600 hover:text-gray-600 hover:bg-gray-100'"
                            @click="filterStatus = opt.value"
                        >{{ opt.label }}</button>
                    </div>
                    <div class="h-4 w-px bg-gray-200"></div>
                    <!-- Type filter -->
                    <div class="flex items-center gap-1">
                        <button v-for="opt in [
                            { value: 'all', label: 'Всі' },
                            { value: 'internal', label: 'INT' },
                            { value: 'commercial', label: 'COM' },
                        ]" :key="opt.value"
                            type="button"
                            class="px-2.5 py-1 rounded-full font-medium transition-all duration-150"
                            :class="filterType === opt.value
                                ? 'bg-indigo-100 text-indigo-700'
                                : 'text-gray-600 hover:text-gray-600 hover:bg-gray-100'"
                            @click="filterType = opt.value"
                        >{{ opt.label }}</button>
                    </div>
                </div>
            </div>

            <!-- Current shift orders -->
            <div v-if="filteredOrders.length === 0" class="card text-center text-gray-600 py-10">
                {{ (searchQuery || filterStatus !== 'all' || filterType !== 'all')
                    ? 'Нічого не знайдено'
                    : (currentView === 'month' ? 'Замовлень за цей місяць ще немає' : 'Замовлень у цій зміні ще немає') }}
            </div>

            <div v-else class="space-y-2">
                <div v-for="order in filteredOrders" :key="order.id"
                    class="card p-0 overflow-hidden hover:shadow-md transition-shadow duration-150"
                    :class="{ 'opacity-60': isTerminal(order.status) }">

                    <!-- Row 1: Number + Amount + Actions + Request -->
                    <div class="flex items-center gap-3 px-5 pt-4 pb-2">
                        <!-- Bulk checkbox -->
                        <input
                            v-if="!isTerminal(order.status)"
                            type="checkbox"
                            :checked="selectedIds.has(order.id)"
                            @change="toggleSelect(order.id)"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-200 flex-shrink-0"
                        />
                        <div v-else class="w-4 flex-shrink-0"></div>

                        <!-- Order number (link) -->
                        <Link :href="route('orders.show', order.id)"
                            class="font-mono text-base font-bold text-indigo-700 hover:underline min-w-[140px]">
                            {{ order.order_number }}
                        </Link>
                        <Link v-if="['new', 'in_progress'].includes(order.status)"
                            :href="route('orders.edit', order.id)"
                            class="text-gray-600 hover:text-blue-600 transition text-sm"
                            title="Редагувати">✎</Link>

                        <!-- Amount -->
                        <span class="text-lg font-bold tabular-nums ml-1"
                            :class="orderAmountColor(order)">
                            {{ orderAmount(order).toFixed(2) }}
                            <span class="text-xs font-normal text-gray-600">грн</span>
                            <span v-if="order.is_at_cost" class="text-[10px] font-normal text-amber-700">соб.</span>
                        </span>

                        <div class="flex-1"></div>

                        <!-- Actions / Status -->
                        <template v-if="!isTerminal(order.status)">
                            <span v-for="(part, pi) in statusLabels[order.status].split('/')" :key="pi"
                                :class="statusColors[order.status]">
                                {{ part.trim() }}
                            </span>
                            <div class="flex items-center gap-1.5">
                                <button
                                    v-for="action in getActions(order)" :key="action.status"
                                    @click="changeStatus(order, action.status)"
                                    :disabled="processing.has(order.id)"
                                    :class="action.style"
                                >
                                    {{ action.label }}
                                </button>
                                <Link v-if="order.status === 'ready' && order.type === 'commercial'"
                                    :href="route('orders.show', order.id)"
                                    class="action-default">
                                    Оплата →
                                </Link>
                            </div>
                        </template>
                        <template v-else>
                            <span v-for="(part, pi) in statusLabels[order.status].split('/')" :key="pi"
                                :class="statusColors[order.status]">
                                {{ part.trim() }}
                            </span>
                        </template>

                        <!-- Request toggle (internal only) -->
                        <RequestBadge v-if="order.type === 'internal'"
                            :order="order"
                            :disabled="processing.has('req-' + order.id)"
                            @toggle="toggleRequest(order)" />
                    </div>

                    <!-- Row 2: Items detail + meta -->
                    <div class="px-5 pb-3 text-xs">
                        <div class="flex items-start gap-3">
                            <!-- Items list (each on own line) -->
                            <div class="flex-1 min-w-0 space-y-0.5">
                                <div v-if="!order.items?.length" class="text-gray-600">Без позицій</div>
                                <div v-for="(d, idx) in itemDetails(order)" :key="idx" class="flex items-baseline gap-1.5 min-w-0">
                                    <span class="text-gray-600 font-medium shrink-0">{{ d.name }} × {{ d.quantity }}</span>
                                    <span v-for="(opt, oi) in d.opts" :key="oi"
                                        class="inline-block px-1 py-px rounded bg-gray-100 text-gray-600 text-[10px] leading-tight shrink-0">
                                        {{ opt }}
                                    </span>
                                    <span v-if="d.material" class="text-indigo-600 italic truncate">{{ d.material }}</span>
                                    <span v-if="d.customerPaper"
                                        class="inline-block px-1 py-px rounded bg-amber-100 text-amber-700 text-[10px] leading-tight shrink-0">
                                        Папір замовника
                                    </span>
                                </div>
                            </div>

                            <!-- Authorized person / Cost center (internal) -->
                            <span v-if="order.authorized_person" class="text-gray-600 shrink-0">
                                {{ order.authorized_person }}
                            </span>
                            <span v-if="order.cost_center"
                                class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 shrink-0">
                                {{ order.cost_center }}
                            </span>

                            <!-- Date/Time -->
                            <span class="text-gray-600 tabular-nums shrink-0">
                                <template v-if="currentView === 'month'">
                                    {{ new Date(order.created_at).toLocaleDateString('uk-UA', { day: '2-digit', month: '2-digit' }) }}
                                </template>
                                <template v-else>
                                    {{ new Date(order.created_at).toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' }) }}
                                </template>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- The month is paginated at 50 on the server; every page of it
                 must be reachable from the screen (audit F-1). Hidden by the
                 component itself while everything fits on one page. -->
            <Pagination :links="orders.links" />

            <!-- Carryover: incomplete orders from previous shifts -->
            <template v-if="filteredCarryover && filteredCarryover.length > 0">
                <div class="flex items-center gap-3 mt-8 mb-3">
                    <div class="h-px flex-1 bg-gray-200"></div>
                    <span class="text-sm font-semibold text-amber-700 whitespace-nowrap">
                        Незавершені з попередніх днів ({{ filteredCarryover.length }})
                    </span>
                    <div class="h-px flex-1 bg-gray-200"></div>
                </div>

                <div class="space-y-2">
                    <div v-for="order in filteredCarryover" :key="order.id"
                        class="card p-0 overflow-hidden border-amber-200 hover:shadow-md transition-shadow duration-150">

                        <!-- Row 1 -->
                        <div class="flex items-center gap-3 px-5 pt-4 pb-2">
                            <Link :href="route('orders.show', order.id)"
                                class="font-mono text-base font-bold text-indigo-700 hover:underline min-w-[140px]">
                                {{ order.order_number }}
                            </Link>
                            <span class="text-lg font-bold tabular-nums ml-1"
                                :class="orderAmountColor(order)">
                                {{ orderAmount(order).toFixed(2) }}
                                <span class="text-xs font-normal text-gray-600">грн</span>
                                <span v-if="order.is_at_cost" class="text-[10px] font-normal text-amber-700">соб.</span>
                            </span>
                            <div class="flex-1"></div>
                            <span v-for="(part, pi) in statusLabels[order.status].split('/')" :key="pi"
                                :class="statusColors[order.status]">
                                {{ part.trim() }}
                            </span>
                            <div class="flex items-center gap-1.5">
                                <button
                                    v-for="action in getActions(order)" :key="action.status"
                                    @click="changeStatus(order, action.status)"
                                    :disabled="processing.has(order.id)"
                                    :class="action.style"
                                >
                                    {{ action.label }}
                                </button>
                                <Link v-if="order.status === 'ready' && order.type === 'commercial'"
                                    :href="route('orders.show', order.id)"
                                    class="action-default">
                                    Оплата →
                                </Link>
                            </div>
                            <RequestBadge v-if="order.type === 'internal'"
                                :order="order"
                                :disabled="processing.has('req-' + order.id)"
                                @toggle="toggleRequest(order)" />
                        </div>

                        <!-- Row 2 -->
                        <div class="px-5 pb-3 text-xs">
                            <div class="flex items-start gap-3">
                                <div class="flex-1 min-w-0 space-y-0.5">
                                    <div v-if="!order.items?.length" class="text-gray-600">Без позицій</div>
                                    <div v-for="(d, idx) in itemDetails(order)" :key="idx" class="flex items-baseline gap-1.5 min-w-0">
                                        <span class="text-gray-600 font-medium shrink-0">{{ d.name }} × {{ d.quantity }}</span>
                                        <span v-for="(opt, oi) in d.opts" :key="oi"
                                            class="inline-block px-1 py-px rounded bg-gray-100 text-gray-600 text-[10px] leading-tight shrink-0">
                                            {{ opt }}
                                        </span>
                                        <span v-if="d.material" class="text-indigo-600 italic truncate">{{ d.material }}</span>
                                    </div>
                                </div>
                                <span v-if="order.authorized_person" class="text-gray-600 shrink-0">
                                    {{ order.authorized_person }}
                                </span>
                                <span v-if="order.cost_center"
                                    class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 shrink-0">
                                    {{ order.cost_center }}
                                </span>
                                <span class="text-amber-700 tabular-nums shrink-0">
                                    {{ new Date(order.created_at).toLocaleDateString('uk-UA', { day: '2-digit', month: '2-digit' }) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Bulk action bar (floating) -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-full opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-full opacity-0"
            >
                <div v-if="selectedIds.size > 0"
                    class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50
                           bg-white/95 backdrop-blur-sm border border-gray-200 shadow-xl
                           rounded-2xl px-5 py-3 flex items-center gap-4">
                    <span class="text-sm font-semibold text-gray-700">
                        Обрано: {{ selectedIds.size }}
                    </span>

                    <div class="h-5 w-px bg-gray-200"></div>

                    <button type="button" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                        @click="selectAll">
                        Обрати всі ({{ selectableOrders.length }})
                    </button>
                    <button type="button" class="text-xs text-gray-600 hover:text-gray-600 font-medium"
                        @click="clearSelection">
                        Зняти
                    </button>

                    <div class="h-5 w-px bg-gray-200"></div>

                    <button type="button" class="action-default"
                        :disabled="batchProcessing"
                        @click="batchStatus('in_progress')">
                        В роботу
                    </button>
                    <button type="button" class="action-success"
                        :disabled="batchProcessing"
                        @click="batchStatus('completed_issued')">
                        ✓ Видано
                    </button>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.action-default {
    @apply inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold
           bg-white border border-gray-300 text-gray-600
           hover:border-blue-400 hover:text-blue-700 hover:bg-blue-50
           transition-all duration-150 disabled:opacity-50 cursor-pointer;
}
.action-success {
    @apply inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold
           bg-white border border-green-400 text-green-700
           hover:bg-green-50 hover:border-green-500
           transition-all duration-150 disabled:opacity-50 cursor-pointer;
}
</style>
