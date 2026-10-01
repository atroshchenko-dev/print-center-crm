<script setup>
/**
 * Admin/BackdatedOrders/Index — Список ретро-замовлень + Звірка
 *
 * Admin-only page for managing historical (backdated) internal orders.
 * Features: filtering, reconciliation (single + batch), XLSX export.
 * Detailed view: format, paper, print mode extracted from constructor snapshot.
 */
import { ref, computed } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import { PAPER_GROUP } from '@/constants'

const props = defineProps({
    orders: Object,
    filters: Object,
    summary: Object,
})

// ─── Filters ────────────────────────────────────────
const filterForm = useForm({
    from: props.filters?.from || '',
    to: props.filters?.to || '',
    cost_center: props.filters?.cost_center || '',
    authorized_person: props.filters?.authorized_person || '',
    reconciled: props.filters?.reconciled ?? '',
    request_received: props.filters?.request_received ?? '',
})

function applyFilters() {
    filterForm.get(route('admin.backdated-orders.index'), { preserveState: true })
}

function resetFilters() {
    filterForm.from = ''
    filterForm.to = ''
    filterForm.cost_center = ''
    filterForm.authorized_person = ''
    filterForm.reconciled = ''
    filterForm.request_received = ''
    applyFilters()
}

// ─── Selection (Batch) ──────────────────────────────
const selectedIds = ref([])
const allSelected = computed({
    get: () => props.orders.data.length > 0 && selectedIds.value.length === props.orders.data.filter(o => !o.is_reconciled).length,
    set: (val) => {
        selectedIds.value = val
            ? props.orders.data.filter(o => !o.is_reconciled).map(o => o.id)
            : []
    }
})

function toggleSelection(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx > -1) {
        selectedIds.value.splice(idx, 1)
    } else {
        selectedIds.value.push(id)
    }
}

// ─── Actions ────────────────────────────────────────
function reconcile(orderId) {
    router.patch(route('admin.backdated-orders.reconcile', orderId), {}, { preserveScroll: true })
}

function unreconcile(orderId) {
    router.delete(route('admin.backdated-orders.unreconcile', orderId), { preserveScroll: true })
}

function reconcileBatch() {
    if (selectedIds.value.length === 0) return
    router.post(route('admin.backdated-orders.reconcile-batch'), {
        order_ids: selectedIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = [] },
    })
}

function toggleRequest(orderId, currentValue) {
    router.patch(route('admin.backdated-orders.toggle-request', orderId), {
        request_received: !currentValue,
    }, { preserveScroll: true })
}

function deleteOrder(order) {
    if (!confirm(`Видалити ретро-замовлення ${order.order_number}? Цю дію неможливо скасувати.`)) return
    router.delete(route('admin.backdated-orders.destroy', order.id), { preserveScroll: true })
}

// ─── Export ─────────────────────────────────────────
const exportUrl = computed(() => {
    const params = new URLSearchParams()
    if (filterForm.from) params.set('from', filterForm.from)
    if (filterForm.to) params.set('to', filterForm.to)
    if (filterForm.reconciled !== '') params.set('reconciled', filterForm.reconciled)
    return route('admin.backdated-orders.export') + '?' + params.toString()
})

// ─── Type detection ─────────────────────────────────
function detectType(serviceName) {
    const n = (serviceName || '').toLowerCase()
    if (n.includes('друк') || n.includes('колір') || n.includes('чорно')) return 'print'
    if (n.includes('тираж') || n.includes('riso') || n.includes('різо'))  return 'riso'
    if (n.includes('скан'))  return 'scan'
    if (n.includes('ламін')) return 'lam'
    if (n.includes('паліт')) return 'bind'
    if (n.includes('брошур')) return 'brochure'
    if (n.includes('диплом')) return 'diploma'
    if (n.includes('візит')) return 'cards'
    return 'generic'
}

const categoryIcons = { print: '⎙', riso: '⊞', scan: '☷', lam: '▨', bind: '≡', brochure: '▣', diploma: '∴', cards: '◇', generic: '□' }

function getCategoryColumns(type) {
    switch (type) {
        case 'print': return [
            { key: 'format', label: 'Формат' },
            { key: 'paper', label: 'Папір' },
            { key: 'printMode', label: 'Режим' },
        ]
        case 'riso': return [
            { key: 'format', label: 'Формат' },
            { key: 'paper', label: 'Папір' },
            { key: 'sides', label: 'Режим' },
            { key: 'sheetsA3', label: 'Арк. А3', align: 'center' },
        ]
        case 'scan': return [
            { key: 'format', label: 'Формат' },
        ]
        case 'lam': return [
            { key: 'format', label: 'Формат' },
            { key: 'film', label: 'Плівка' },
        ]
        case 'bind': return [
            { key: 'bindType', label: 'Тип' },
            { key: 'detail', label: 'Деталі' },
        ]
        case 'brochure': return [
            { key: 'format', label: 'Формат' },
            { key: 'coverInfo', label: 'Обкладинка' },
            { key: 'blockInfo', label: 'Блок' },
        ]
        case 'diploma': return [
            { key: 'diplomaQty', label: 'Дипл.', align: 'center' },
            { key: 'suppQty', label: 'Додатки', align: 'center' },
            { key: 'academicQty', label: 'Акад.', align: 'center' },
            { key: 'diplomaCopiesQty', label: 'Копії дипл.', align: 'center' },
            { key: 'suppCopiesQty', label: 'Копії дод.', align: 'center' },
        ]
        case 'cards': return [
            { key: 'cardFormat', label: 'Формат' },
            { key: 'paper', label: 'Папір' },
            { key: 'printMode', label: 'Режим' },
            { key: 'lamination', label: 'Ламінація' },
        ]
        default: return [
            { key: 'format', label: 'Формат' },
            { key: 'detail', label: 'Деталі' },
        ]
    }
}

// ─── Snapshot detail extraction ─────────────────────
const paperRegex = /^А[34]:\s*/

// Only the items of this group's service. The page used to group an order by
// its *first* item and then draw every item it had under that group's columns,
// so a lamination line inside a printing order was rendered with Формат/Папір/
// Режим and came out as three dashes. An order now appears under each service
// it actually bought, and each block draws its own items — the same shape the
// XLSX takes, so the screen and the file agree category by category.
function extractAllItems(order, type, serviceName = null) {
    if (!order.items?.length) return []

    const items = serviceName === null
        ? order.items
        : order.items.filter(i => i.service_name === serviceName)

    return items.map(item => {
        const ss = item.service_snapshot || {}
        const base = {
            service: item.service_name || '—',
            qty: item.quantity || 0,
            unitCost: parseFloat(item.unit_price_cost || 0).toFixed(4),
            cost: parseFloat(item.total_price_cost || 0).toFixed(2),
            note: item.material_description || null,
        }

        // Print (BW / Color)
        if (type === 'print') {
            let format = '—', paper = '—', printMode = '—'
            if (ss.customer_paper) paper = 'Замовника'
            for (const e of ss.constructor_snapshot || []) {
                if (e.group_name === 'Формат') format = e.option_name
                else if (e.group_name === PAPER_GROUP && !ss.customer_paper) paper = e.option_name.replace(paperRegex, '')
                else if (e.group_name === 'Сторонність') { const p = e.option_name.split(': '); printMode = p[p.length - 1] }
            }
            return { ...base, format, paper, printMode, bwClicks: item.bw_clicks || '', colorClicks: item.color_clicks || '' }
        }

        // Riso
        if (type === 'riso') {
            const rp = ss.riso_params || {}
            return { ...base, format: rp.format || '—', paper: rp.paper_name || '—', sides: (rp.sides || 1) === 2 ? '1+1' : '1+0', sheetsA3: rp.sheets_a3 || '—', risoClicks: item.riso_clicks || '' }
        }

        // Scan
        if (type === 'scan') {
            let format = '—'
            for (const e of ss.constructor_snapshot || []) { if (e.group_name === 'Формат') { format = e.option_name; break } }
            return { ...base, format }
        }

        // Lamination
        if (type === 'lam') {
            let format = '—', film = '—'
            for (const e of ss.constructor_snapshot || []) {
                if (e.group_name === 'Формат') format = e.option_name
                else if (e.group_name !== 'Формат' && e.option_name) film = e.option_name.replace(paperRegex, '')
            }
            return { ...base, format, film }
        }

        // Binding
        if (type === 'bind') {
            const bindType = item.service_name?.includes('пружин') ? 'Пружина' : 'Тверда'
            let detail = '—'
            for (const e of ss.constructor_snapshot || []) { if (e.group_name !== 'Формат' && e.option_name) detail = e.option_name }
            return { ...base, bindType, detail }
        }

        // Brochure
        if (type === 'brochure') {
            const bp = ss.brochure_params || {}
            const cover = bp.cover || {}
            const block = bp.block || {}
            const coverInfo = `${cover.paper_name || '?'} ${cover.print_mode || ''}`
            const blockInfo = `${block.total_sheets || 0} арк. ${block.paper_name || ''}`
            return { ...base, format: bp.format || '—', coverInfo, blockInfo }
        }

        // Diploma
        if (type === 'diploma') {
            const dp = ss.diploma_params || {}
            let suppQty = 0
            for (const s of dp.supplements || []) suppQty += (s.qty || 0)
            const copies = dp.copies || {}
            return {
                ...base,
                diplomaQty: dp.diplomas?.qty || 0,
                suppQty: suppQty || '',
                academicQty: dp.academic_records?.qty || 0,
                diplomaCopiesQty: copies.diploma_copies?.qty || '',
                suppCopiesQty: copies.supplement_copies?.qty || '',
            }
        }

        // Business Cards
        if (type === 'cards') {
            let cardFormat = '—', paper = '—', printMode = '—', lamination = '—'
            if (ss.customer_paper) paper = 'Замовника'
            for (const e of ss.constructor_snapshot || []) {
                if (e.group_name === 'Формат') cardFormat = e.option_name
                else if (e.group_name === PAPER_GROUP && !ss.customer_paper) paper = e.option_name
                else if (e.group_name === 'Сторонність') { const p = e.option_name.split(': '); printMode = p[p.length - 1] }
                else if (e.group_name === 'Ламінація') lamination = e.option_name
            }
            return { ...base, cardFormat, paper, printMode, lamination, colorClicks: item.color_clicks || '' }
        }

        // Generic
        let format = '—', detail = '—'
        for (const e of ss.constructor_snapshot || []) {
            if (e.group_name === 'Формат') format = e.option_name
            else if (e.option_name) detail = e.option_name
        }
        return { ...base, format, detail }
    })
}

// ─── Grouping by service category ───────────────────
//
// An order lands in every category it has work in, not only the one its first
// item happened to be. The subtotal counts that category's items, so the
// blocks add up to the same money the XLSX reports per sheet.
const groupedOrders = computed(() => {
    const groups = {}
    for (const order of props.orders.data) {
        const services = order.items?.length
            ? [...new Set(order.items.map(i => i.service_name))]
            : ['Інше']
        for (const serviceName of services) {
            if (!groups[serviceName]) groups[serviceName] = []
            groups[serviceName].push(order)
        }
    }
    return Object.keys(groups).sort().map(key => {
        const type = detectType(key)
        return {
            name: key,
            type,
            columns: getCategoryColumns(type),
            orders: groups[key],
            totalCost: groups[key].reduce((sum, o) => sum + itemsCost(o, key), 0),
            count: groups[key].length,
            icon: categoryIcons[type] || '□',
        }
    })
})

/** What this order spent on one service — the figure its block shows. */
function itemsCost(order, serviceName) {
    return (order.items ?? [])
        .filter(i => i.service_name === serviceName)
        .reduce((sum, i) => sum + parseFloat(i.total_price_cost || 0), 0)
}

// ─── Helpers ────────────────────────────────────────
function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('uk-UA')
}
</script>

<template>
    <AppLayout>
        <div class="max-w-[1200px]">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-amber-400 to-yellow-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Ретро-замовлення</h1>
                        <p class="page-header-subtitle">Список внутрішніх замовлень за минулі періоди</p>
                    </div>
                </div>
                <Link :href="route('admin.backdated-orders.create')"
                    class="btn-primary w-full sm:w-auto inline-flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Нове ретро-замовлення
                </Link>
            </div>

            <!-- Summary Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="card flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-700"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
                    <div>
                        <p class="text-xs text-gray-600">Всього замовлень</p>
                        <p class="text-lg font-bold text-gray-800">{{ summary.total }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center text-green-700"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>
                    <div>
                        <p class="text-xs text-gray-600">Звірено</p>
                        <p class="text-lg font-bold text-gray-800">
                            {{ summary.reconciled }}
                            <span class="text-xs text-gray-600 font-normal">/ {{ summary.total }}</span>
                        </p>
                    </div>
                </div>
                <div class="card flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-600"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                    <div>
                        <p class="text-xs text-gray-600">Загальна собівартість</p>
                        <p class="text-lg font-bold text-gray-800">{{ summary.total_cost.toFixed(2) }} <span class="text-xs text-gray-600 font-normal">грн</span></p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <form @submit.prevent="applyFilters" class="card mb-6">
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Від</label>
                        <input aria-label="Від" v-model="filterForm.from" type="date" class="input" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">До</label>
                        <input aria-label="До" v-model="filterForm.to" type="date" class="input" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Підписант</label>
                        <input aria-label="Підписант" v-model="filterForm.authorized_person" type="text" class="input" placeholder="Всі" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Центр витрат</label>
                        <input aria-label="Центр витрат" v-model="filterForm.cost_center" type="text" class="input" placeholder="Всі" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Звірка</label>
                        <select aria-label="Звірка" v-model="filterForm.reconciled" class="input">
                            <option value="">Всі</option>
                            <option value="0">Не звірені</option>
                            <option value="1">Звірені</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Заявка</label>
                        <select aria-label="Заявка" v-model="filterForm.request_received" class="input">
                            <option value="">Всі</option>
                            <option value="0">Не отримана</option>
                            <option value="1">Отримана</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3 flex gap-2 items-center">
                    <button type="submit" class="btn-primary text-sm">Застосувати</button>
                    <button type="button" @click="resetFilters" class="text-sm text-gray-600 hover:text-gray-600 transition">Скинути</button>
                    <div class="ml-auto">
                        <a :href="exportUrl" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-50 text-green-700 hover:bg-green-100 text-sm font-medium rounded-lg transition">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Вивантажити XLSX
                        </a>
                    </div>
                </div>
            </form>

            <!-- Batch action -->
            <div v-if="selectedIds.length > 0" class="mb-4 flex items-center gap-3 px-4 py-2.5 bg-indigo-50 rounded-lg border border-indigo-200">
                <span class="text-sm text-indigo-700 font-medium">Обрано: {{ selectedIds.length }}</span>
                <button @click="reconcileBatch" class="btn-primary text-sm">
                    Звірити обрані
                </button>
            </div>

            <!-- Grouped by service category -->
            <div v-for="group in groupedOrders" :key="group.name" class="mb-6">
                <!-- Group Header -->
                <div class="flex items-center justify-between px-4 py-2.5 bg-gray-50 rounded-t-xl border border-gray-200 border-b-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-700">{{ group.icon }} {{ group.name }}</span>
                        <span class="text-xs text-gray-600 bg-white px-2 py-0.5 rounded-full border border-gray-200">{{ group.count }} зам.</span>
                    </div>
                    <span class="text-sm font-medium text-gray-600">{{ group.totalCost.toFixed(2) }} грн</span>
                </div>

                <!-- Table -->
                <div class="card !rounded-t-none overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-600 text-xs">
                                <th scope="col" class="pb-2 pr-2 w-8">
                                    <input type="checkbox" v-model="allSelected" />
                                </th>
                                <th scope="col" class="pb-2 pr-2">Номер</th>
                                <th scope="col" class="pb-2 pr-2">Дата</th>
                                <th scope="col" class="pb-2 pr-2">Підписант</th>
                                <th scope="col" class="pb-2 pr-2">Центр витрат</th>
                                <!-- Dynamic category-specific columns -->
                                <th scope="col" v-for="col in group.columns" :key="col.key" class="pb-2 pr-2"
                                    :class="col.align === 'center' ? 'text-center' : ''">{{ col.label }}</th>
                                <th scope="col" class="pb-2 pr-2 text-center">Кіл-ть</th>
                                <th scope="col" class="pb-2 pr-2 text-right">За од.</th>
                                <th scope="col" class="pb-2 pr-2 text-right">Собів.</th>
                                <th scope="col" class="pb-2 pr-2 text-center">Заявка</th>
                                <th scope="col" class="pb-2 pr-2 text-center">Звірка</th>
                                <th scope="col" class="pb-2">Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="order in group.orders" :key="order.id">
                                <tr v-for="(item, itemIdx) in extractAllItems(order, group.type, group.name)" :key="order.id + '-' + itemIdx"
                                    class="transition-colors"
                                    :class="itemIdx === extractAllItems(order, group.type, group.name).length - 1 ? 'border-b border-gray-200' : 'border-b border-gray-50'">
                                    <!-- Order-level cells (only first item row) -->
                                    <td v-if="itemIdx === 0" class="py-2 pr-2" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <input v-if="!order.is_reconciled" type="checkbox"
                                            :checked="selectedIds.includes(order.id)"
                                            @change="toggleSelection(order.id)" />
                                        <span v-else class="text-green-700 text-xs">✓</span>
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 font-mono text-xs font-medium text-gray-700 whitespace-nowrap align-top" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ order.order_number }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-gray-600 whitespace-nowrap text-xs align-top" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ formatDate(order.created_at) }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-gray-700 max-w-[130px] truncate align-top" :title="order.authorized_person" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ order.authorized_person || '—' }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-xs text-gray-600 max-w-[140px] truncate align-top" :title="order.cost_center" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ order.cost_center || '—' }}
                                    </td>
                                    <!-- Dynamic category-specific cells -->
                                    <td v-for="col in group.columns" :key="col.key" class="py-1.5 pr-2 text-xs"
                                        :class="[col.align === 'center' ? 'text-center' : '', col.key === 'format' ? 'font-medium text-gray-700' : 'text-gray-600']">
                                        {{ item[col.key] ?? '—' }}
                                        <span v-if="col.key === group.columns[group.columns.length - 1]?.key && item.note"
                                            class="text-amber-700 ml-1" :title="item.note"><svg class="w-3 h-3 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></span>
                                    </td>
                                    <td class="py-1.5 pr-2 text-center text-xs font-medium text-gray-700">{{ item.qty }}</td>
                                    <td class="py-1.5 pr-2 text-right font-mono text-xs text-gray-600">{{ item.unitCost }}</td>
                                    <!-- Order-level cells (only first item row) -->
                                    <!-- This category's share of the order, not the whole order:
                                         an order split across two services is drawn in both blocks,
                                         and its full total repeated in each would count twice. -->
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-right font-mono text-xs font-medium text-gray-800 align-top" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ itemsCost(order, group.name).toFixed(2) }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-center align-top" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <button @click="toggleRequest(order.id, order.request_received)"
                                            class="text-xs font-medium"
                                            :class="order.request_received ? 'text-green-700 hover:text-red-600' : 'text-gray-600 hover:text-green-700'"
                                            :title="order.request_received ? 'Зняти позначку' : 'Позначити отриманою'">
                                            {{ order.request_received ? '✓' : '—' }}
                                        </button>
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-center align-top" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <span v-if="order.is_reconciled" class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Звірено</span>
                                        <span v-else class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">Очікує</span>
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 align-top" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <div class="flex flex-col gap-1">
                                            <button v-if="!order.is_reconciled" @click="reconcile(order.id)"
                                                class="text-xs text-green-700 hover:text-green-800 font-medium transition">Звірити</button>
                                            <button v-else @click="unreconcile(order.id)"
                                                class="text-xs text-gray-600 hover:text-red-600 font-medium transition">Скасувати</button>
                                            <a :href="route('admin.backdated-orders.edit', order.id)"
                                                class="text-xs text-blue-600 hover:text-blue-800 font-medium transition"
                                                @click.prevent="router.get(route('admin.backdated-orders.edit', order.id))">Редагувати</a>
                                            <button @click="deleteOrder(order)"
                                                class="text-xs text-red-600 hover:text-red-800 font-medium transition text-left">Видалити</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="orders.data.length === 0" class="card py-12 text-center text-gray-600">
                Ретро-замовлень не знайдено
            </div>

            <Pagination :links="orders.links" />

            <!-- Info -->
            <div class="mt-4 text-xs text-amber-700 bg-amber-50 rounded-lg px-4 py-3 border border-amber-200">
                Ретро-замовлення не впливають на касу, склад та лічильники. Вони ізольовані від операційних звітів та дашборду.
            </div>
        </div>
    </AppLayout>
</template>
