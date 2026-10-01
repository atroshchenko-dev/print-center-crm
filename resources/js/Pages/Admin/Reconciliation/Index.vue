<script setup>
/**
 * Admin/Reconciliation/Index — Звірка внутрішніх замовлень
 *
 * Full-featured reconciliation page for operational internal orders.
 * Features: summary stats, filtering, service category grouping,
 * snapshot detail extraction, batch reconciliation, XLSX export.
 */
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import Pagination from '@/Components/Pagination.vue'
import { PAPER_GROUP } from '@/constants'
import { isGroupSelected, toggleGroup } from '@/composables/useBlockSelection'

const props = defineProps({
    orders: Object,
    filters: Object,
    summary: Object,
    isAdmin: Boolean,
    // orders.edit sits behind permission:orders + EnsureShiftIsOpen, while this
    // page needs neither. False means the pencil would bounce the user to the
    // shift-open form or 403, so it is not drawn at all.
    canEditOrders: Boolean,
})

// ─── Filters ────────────────────────────────────────

// Build month value from from/to props (YYYY-MM format)
function detectMonth(filters) {
    if (filters?.from) {
        return filters.from.substring(0, 7) // '2026-05-01' → '2026-05'
    }
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

// Generate last 12 months as options
const monthOptions = computed(() => {
    const options = []
    const now = new Date()
    const monthNames = [
        'Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень',
        'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень'
    ]
    for (let i = 0; i < 12; i++) {
        const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
        const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
        const label = `${monthNames[d.getMonth()]} ${d.getFullYear()}`
        options.push({ value, label })
    }
    return options
})

const selectedMonth = ref(detectMonth(props.filters))

const filterForm = useForm({
    from: props.filters?.from || '',
    to: props.filters?.to || '',
    cost_center: props.filters?.cost_center || '',
    authorized_person: props.filters?.authorized_person || '',
    reconciled: props.filters?.reconciled ?? '',
    request_received: props.filters?.request_received ?? '',
})

function monthToDateRange(monthStr) {
    const [y, m] = monthStr.split('-').map(Number)
    const from = `${y}-${String(m).padStart(2, '0')}-01`
    const lastDay = new Date(y, m, 0).getDate()
    const to = `${y}-${String(m).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`
    return { from, to }
}

function onMonthChange() {
    const { from, to } = monthToDateRange(selectedMonth.value)
    filterForm.from = from
    filterForm.to = to
    applyFilters()
}

function applyFilters() {
    // Ensure from/to are synced with month
    if (selectedMonth.value && !filterForm.from) {
        const { from, to } = monthToDateRange(selectedMonth.value)
        filterForm.from = from
        filterForm.to = to
    }
    filterForm.get(route('admin.reconciliation.index'), { preserveScroll: true })
}

function resetFilters() {
    const now = new Date()
    selectedMonth.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
    const { from, to } = monthToDateRange(selectedMonth.value)
    filterForm.from = from
    filterForm.to = to
    filterForm.cost_center = ''
    filterForm.authorized_person = ''
    filterForm.reconciled = ''
    filterForm.request_received = ''
    applyFilters()
}

// ─── Selection (Batch) ──────────────────────────────
const selectedIds = ref([])

// Scoped to the block whose header the checkbox sits in — owner's decision,
// 2026-07-31. It used to be one computed over the whole page, so ticking it in
// «Чорно-білий друк» also took «Ламінування» and lit up every other block's box
// at once. See useBlockSelection for the rule and the one caveat that stays:
// reconciliation applies to a whole order, and an order on two services shows
// in both blocks.
const blockSelected = group => isGroupSelected(group, selectedIds.value)

function toggleBlock(group, checked) {
    selectedIds.value = toggleGroup(group, checked, selectedIds.value)
}

function toggleSelection(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx > -1) selectedIds.value.splice(idx, 1)
    else selectedIds.value.push(id)
}

// ─── Actions ────────────────────────────────────────
function reconcile(orderId) {
    router.patch(route('admin.reconciliation.reconcile', orderId), {}, { preserveScroll: true })
}

function unreconcile(orderId) {
    router.delete(route('admin.reconciliation.unreconcile', orderId), { preserveScroll: true })
}

function reconcileBatch() {
    if (selectedIds.value.length === 0) return
    router.post(route('admin.reconciliation.batch'), {
        order_ids: selectedIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = [] },
    })
}

// Through the reconciliation route, not the order one. `orders.request` sits
// behind EnsureShiftIsOpen and permission:orders; this page needs neither and
// is reached with permission:reports alone. Month-end reconciliation happens
// with the till shut, and the order route answered that by redirecting to the
// shift-open form. The shift-free twin was written for this page and called
// from nowhere — BackdatedOrders/Index.vue uses its own and always did.
function toggleRequest(orderId, currentValue) {
    router.patch(route('admin.reconciliation.toggle-request', orderId), {
        request_received: !currentValue,
    }, { preserveScroll: true })
}

// ─── Delete ─────────────────────────────────────────
const deleteTarget = ref(null)
const deleteReason = ref('')
const deleting = ref(false)

function showDeleteDialog(order) {
    deleteTarget.value = order
    deleteReason.value = ''
}

function cancelDelete() {
    deleteTarget.value = null
    deleteReason.value = ''
}

function confirmDelete() {
    if (!deleteTarget.value || !deleteReason.value.trim()) return
    deleting.value = true
    router.delete(route('admin.reconciliation.destroy', deleteTarget.value.id), {
        data: { reason: deleteReason.value.trim() },
        preserveScroll: true,
        onSuccess: () => { deleteTarget.value = null; deleteReason.value = ''; deleting.value = false },
        onError: () => { deleting.value = false },
    })
}

// ─── Close Month ────────────────────────────────────
const showCloseMonth = ref(false)
const closingMonth = ref(false)
const unreconciledCount = computed(() => {
    return props.summary.total - props.summary.reconciled
})

// Every filter the screen applies, for the same reason the export carries them:
// the count in the confirmation above the button is computed from the filtered
// summary, and this call used to send four of the six. Narrowing to "Заявка:
// отримана" showed "Буде звірено 3" and closed every unreconciled order of the
// month, paper request or not.
function closeMonth() {
    closingMonth.value = true
    router.post(route('admin.reconciliation.close-month'), {
        from: filterForm.from,
        to: filterForm.to,
        cost_center: filterForm.cost_center,
        authorized_person: filterForm.authorized_person,
        reconciled: filterForm.reconciled,
        request_received: filterForm.request_received,
    }, {
        preserveScroll: true,
        onSuccess: () => { showCloseMonth.value = false; closingMonth.value = false },
        onError: () => { closingMonth.value = false },
    })
}

// ─── Export ─────────────────────────────────────────
//
// Every filter the form above applies. It used to carry three of them, so an
// accountant who narrowed the screen to one cost centre downloaded a file
// holding every cost centre — and nothing in the file said so. The workbook
// could take the cost centre and the signatory all along; no caller passed
// them.
const exportUrl = computed(() => {
    const params = new URLSearchParams()
    if (filterForm.from) params.set('from', filterForm.from)
    if (filterForm.to) params.set('to', filterForm.to)
    if (filterForm.reconciled !== '') params.set('reconciled', filterForm.reconciled)
    if (filterForm.request_received !== '') params.set('request_received', filterForm.request_received)
    if (filterForm.cost_center) params.set('cost_center', filterForm.cost_center)
    if (filterForm.authorized_person) params.set('authorized_person', filterForm.authorized_person)
    return route('admin.reconciliation.export') + '?' + params.toString()
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

const categoryIcons = { print: '🖨', riso: '📠', scan: '📷', lam: '🔲', bind: '📒', brochure: '📖', diploma: '🎓', cards: '💳', generic: '📄' }
const categoryColors = {
    print: { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-700', badge: 'bg-blue-100 text-blue-600' },
    riso: { bg: 'bg-violet-50', border: 'border-violet-200', text: 'text-violet-700', badge: 'bg-violet-100 text-violet-600' },
    scan: { bg: 'bg-cyan-50', border: 'border-cyan-200', text: 'text-cyan-700', badge: 'bg-cyan-100 text-cyan-600' },
    lam: { bg: 'bg-amber-50', border: 'border-amber-200', text: 'text-amber-700', badge: 'bg-amber-100 text-amber-700' },
    bind: { bg: 'bg-emerald-50', border: 'border-emerald-200', text: 'text-emerald-700', badge: 'bg-emerald-100 text-emerald-700' },
    brochure: { bg: 'bg-rose-50', border: 'border-rose-200', text: 'text-rose-700', badge: 'bg-rose-100 text-rose-600' },
    diploma: { bg: 'bg-indigo-50', border: 'border-indigo-200', text: 'text-indigo-700', badge: 'bg-indigo-100 text-indigo-600' },
    cards: { bg: 'bg-pink-50', border: 'border-pink-200', text: 'text-pink-700', badge: 'bg-pink-100 text-pink-600' },
    generic: { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700', badge: 'bg-gray-100 text-gray-600' },
}
const reconcilePercent = computed(() => summary.total > 0 ? Math.round((summary.reconciled / summary.total) * 100) : 0)
const { summary } = props

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
        case 'scan': return [{ key: 'format', label: 'Формат' }]
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
        if (type === 'riso') {
            const rp = ss.riso_params || {}
            return { ...base, format: rp.format || '—', paper: rp.paper_name || '—', sides: (rp.sides || 1) === 2 ? '1+1' : '1+0', sheetsA3: rp.sheets_a3 || '—', risoClicks: item.riso_clicks || '' }
        }
        if (type === 'scan') {
            let format = '—'
            for (const e of ss.constructor_snapshot || []) { if (e.group_name === 'Формат') { format = e.option_name; break } }
            return { ...base, format }
        }
        if (type === 'lam') {
            let format = '—', film = '—'
            for (const e of ss.constructor_snapshot || []) {
                if (e.group_name === 'Формат') format = e.option_name
                else if (e.group_name !== 'Формат' && e.option_name) film = e.option_name.replace(paperRegex, '')
            }
            return { ...base, format, film }
        }
        if (type === 'bind') {
            const bindType = item.service_name?.includes('пружин') ? 'Пружина' : 'Тверда'
            let detail = '—'
            for (const e of ss.constructor_snapshot || []) { if (e.group_name !== 'Формат' && e.option_name) detail = e.option_name }
            return { ...base, bindType, detail }
        }
        if (type === 'brochure') {
            const bp = ss.brochure_params || {}
            const cover = bp.cover || {}
            const block = bp.block || {}
            return { ...base, format: bp.format || '—', coverInfo: `${cover.paper_name || '?'} ${cover.print_mode || ''}`, blockInfo: `${block.total_sheets || 0} арк. ${block.paper_name || ''}` }
        }
        if (type === 'diploma') {
            const dp = ss.diploma_params || {}
            let suppQty = 0
            for (const s of dp.supplements || []) suppQty += (s.qty || 0)
            const copies = dp.copies || {}
            return { ...base, diplomaQty: dp.diplomas?.qty || 0, suppQty: suppQty || '', academicQty: dp.academic_records?.qty || 0, diplomaCopiesQty: copies.diploma_copies?.qty || '', suppCopiesQty: copies.supplement_copies?.qty || '' }
        }
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
            name: key, type,
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

function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('uk-UA')
}
</script>

<template>
    <AppLayout>
        <div class="max-w-[1400px]">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-md">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Звірка внутрішніх замовлень</h1>
                        <p class="text-sm text-gray-600 mt-0.5">Поточні внутрішні замовлення · звірка з паперовими заявками</p>
                    </div>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="card flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-600 font-medium uppercase tracking-wide">Замовлень</p>
                        <p class="text-2xl font-bold text-gray-800 tabular-nums">{{ summary.total }}</p>
                    </div>
                </div>
                <div class="card">
                    <div class="flex items-center gap-4 mb-3">
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-400 to-green-600 flex items-center justify-center shadow-sm">
                            <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-xs text-gray-600 font-medium uppercase tracking-wide">Звірено</p>
                            <p class="text-2xl font-bold text-gray-800 tabular-nums">
                                {{ summary.reconciled }}<span class="text-sm text-gray-600 font-normal"> / {{ summary.total }}</span>
                                <span class="ml-1.5 text-xs font-semibold px-1.5 py-0.5 rounded-full" :class="reconcilePercent === 100 ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">{{ reconcilePercent }}%</span>
                            </p>
                        </div>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700 ease-out" :class="reconcilePercent === 100 ? 'bg-green-500' : 'bg-amber-500'" :style="{ width: reconcilePercent + '%' }"></div>
                    </div>
                </div>
                <div class="card flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-400 to-blue-600 flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-600 font-medium uppercase tracking-wide">Собівартість</p>
                        <p class="text-2xl font-bold text-gray-800 tabular-nums">{{ summary.total_cost.toFixed(2) }} <span class="text-sm text-gray-600 font-normal">грн</span></p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <form @submit.prevent="applyFilters" class="card mb-6">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Місяць</label>
                        <select aria-label="Місяць" v-model="selectedMonth" @change="onMonthChange" class="input">
                            <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
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
                            <option value="paper">Отримана (папір)</option>
                            <option value="email">Отримана (лист)</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex gap-2 items-center">
                    <button type="submit" class="btn-primary text-sm">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Застосувати
                    </button>
                    <button type="button" @click="resetFilters" class="btn-ghost text-sm !px-3 !py-2">Скинути</button>
                    <div class="ml-auto flex items-center gap-2">
                        <button v-if="unreconciledCount > 0" type="button" @click="showCloseMonth = true"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white hover:bg-indigo-700 text-sm font-semibold rounded-lg transition shadow-sm">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            Закрити місяць
                        </button>
                        <a :href="exportUrl" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-700 text-white hover:bg-emerald-800 text-sm font-semibold rounded-lg transition shadow-sm">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            XLSX
                        </a>
                    </div>
                </div>
            </form>

            <!-- Close Month Confirm -->
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="showCloseMonth" class="mb-4 px-5 py-4 bg-indigo-50 rounded-xl border border-indigo-200 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-indigo-800">Закрити місяць?</p>
                            <p class="text-xs text-indigo-600 mt-0.5">Буде звірено <strong>{{ unreconciledCount }}</strong> незвірених замовлень за обраний період.</p>
                        </div>
                        <button @click="closeMonth" :disabled="closingMonth"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition disabled:opacity-50">
                            {{ closingMonth ? 'Закриваю...' : 'Підтвердити' }}
                        </button>
                        <button @click="showCloseMonth = false" class="text-xs text-indigo-600 hover:text-indigo-800 transition">Скасувати</button>
                    </div>
                </div>
            </Transition>

            <!-- Batch action -->
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="selectedIds.length > 0" class="mb-4 flex items-center gap-3 px-5 py-3 bg-indigo-50 rounded-xl border border-indigo-200 shadow-sm sticky top-0 z-10">
                    <div class="w-7 h-7 rounded-lg bg-indigo-100 flex items-center justify-center">
                        <svg class="w-4 h-4 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    </div>
                    <span class="text-sm text-indigo-700 font-semibold">Обрано: {{ selectedIds.length }}</span>
                    <button @click="reconcileBatch" class="btn-primary text-sm ml-2">
                        Звірити обрані
                    </button>
                    <button @click="selectedIds = []" class="text-xs text-indigo-600 hover:text-indigo-800 ml-1 transition">Скинути</button>
                </div>
            </Transition>

            <!-- Grouped by service category -->
            <div v-for="group in groupedOrders" :key="group.name" class="mb-8">
                <!-- Group Header -->
                <div class="flex items-center justify-between px-5 py-3 rounded-t-xl border border-b-0 transition-colors"
                     :class="[categoryColors[group.type]?.bg || 'bg-gray-50', categoryColors[group.type]?.border || 'border-gray-200']">
                    <div class="flex items-center gap-2.5">
                        <span class="text-lg">{{ group.icon }}</span>
                        <span class="text-sm font-bold" :class="categoryColors[group.type]?.text || 'text-gray-700'">{{ group.name }}</span>
                        <span class="text-xs font-medium px-2.5 py-0.5 rounded-full" :class="categoryColors[group.type]?.badge || 'bg-gray-100 text-gray-600'">{{ group.count }} зам.</span>
                    </div>
                    <span class="text-sm font-bold tabular-nums" :class="categoryColors[group.type]?.text || 'text-gray-600'">{{ group.totalCost.toFixed(2) }} <span class="font-normal text-xs opacity-70">грн</span></span>
                </div>

                <!-- Table -->
                <div class="card !rounded-t-none overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-600 text-xs">
                                <th scope="col" class="pb-2 pr-2 w-8">
                                    <input type="checkbox"
                                        :aria-label="`Обрати всі: ${group.name}`"
                                        :checked="blockSelected(group)"
                                        @change="toggleBlock(group, $event.target.checked)" />
                                </th>
                                <th scope="col" class="pb-2 pr-2">Номер</th>
                                <th scope="col" class="pb-2 pr-2">Дата</th>
                                <th scope="col" class="pb-2 pr-2">Підписант</th>
                                <th scope="col" class="pb-2 pr-2">Центр витрат</th>
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
                            <template v-for="(order, orderIdx) in group.orders" :key="order.id">
                                <tr v-for="(item, itemIdx) in extractAllItems(order, group.type, group.name)" :key="order.id + '-' + itemIdx"
                                    class="transition-colors duration-150 hover:bg-gray-50/80"
                                    :class="[
                                        itemIdx === extractAllItems(order, group.type, group.name).length - 1 ? 'border-b border-gray-200' : 'border-b border-gray-50',
                                        order.is_reconciled && itemIdx === 0 ? 'bg-green-50/30' : '',
                                    ]">
                                    <!-- Order-level cells (only first item row) -->
                                    <td v-if="itemIdx === 0" class="py-2 pr-2" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <input v-if="!order.is_reconciled" type="checkbox"
                                            :checked="selectedIds.includes(order.id)"
                                            @change="toggleSelection(order.id)" />
                                        <span v-else class="text-green-700 text-xs">✓</span>
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 font-mono text-xs font-medium text-gray-700 whitespace-nowrap align-middle" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ order.order_number }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-gray-600 whitespace-nowrap text-xs align-middle" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ formatDate(order.created_at) }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-gray-700 max-w-[130px] truncate align-middle" :title="order.authorized_person" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ order.authorized_person || '—' }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-xs text-gray-600 max-w-[140px] truncate align-middle" :title="order.cost_center" :rowspan="extractAllItems(order, group.type, group.name).length">
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
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-right font-mono text-xs font-medium text-gray-800 align-middle" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        {{ itemsCost(order, group.name).toFixed(2) }}
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-center align-middle" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <!-- Конверт — підпис живе тільки в системі, аркуш — заявка
                                             лежить у теці. Погоджене листом не знімається: сервер
                                             відмовляє так само (ReconciliationController::toggleRequest). -->
                                        <button @click="toggleRequest(order.id, order.request_received)"
                                            class="w-7 h-7 rounded-lg inline-flex items-center justify-center transition-all duration-150"
                                            :disabled="order.has_email_approval"
                                            :class="order.request_received
                                                ? (order.has_email_approval
                                                    ? 'bg-green-100 text-green-700 cursor-default'
                                                    : 'bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-600')
                                                : 'bg-gray-100 text-gray-600 hover:bg-green-100 hover:text-green-700'"
                                            :title="order.has_email_approval
                                                ? 'Погоджено листом — позначку зняти не можна'
                                                : (order.request_received ? 'Зняти позначку' : 'Позначити отриманою')">
                                            <Icon v-if="order.request_received" :name="order.has_email_approval ? 'mail' : 'file-text'" size="3" />
                                            <svg v-else class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        </button>
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 pr-2 text-center align-middle" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <span v-if="order.is_reconciled" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                            Звірено
                                        </span>
                                        <span v-else class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                                            Очікує
                                        </span>
                                    </td>
                                    <td v-if="itemIdx === 0" class="py-2 align-middle whitespace-nowrap" :rowspan="extractAllItems(order, group.type, group.name).length">
                                        <div class="flex items-center gap-1">
                                            <button v-if="!order.is_reconciled" @click="reconcile(order.id)"
                                                class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg bg-green-50 text-green-700 hover:bg-green-100 font-semibold transition-colors border border-green-200">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                                Звірити
                                            </button>
                                            <button v-else @click="unreconcile(order.id)"
                                                class="text-xs text-gray-600 hover:text-red-600 font-medium transition">Скасувати</button>
                                            <a v-if="canEditOrders && (order.status === 'new' || order.status === 'in_progress')"
                                                :href="route('orders.edit', order.id)"
                                                class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 font-medium transition-colors border border-blue-200"
                                                title="Редагувати">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </a>
                                            <button v-if="isAdmin" @click="showDeleteDialog(order)"
                                                class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 font-medium transition-colors border border-red-200"
                                                title="Видалити">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="orders.data.length === 0" class="card py-16 text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gray-100 flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <p class="text-gray-600 font-medium">Замовлень не знайдено</p>
                <p class="text-sm text-gray-600 mt-1">Спробуйте змінити параметри фільтрації</p>
            </div>

            <Pagination :links="orders.links" />
        </div>
    </AppLayout>

    <!-- Delete Confirmation Dialog -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="cancelDelete">
                <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">Видалити замовлення?</h3>
                            <p class="text-sm text-gray-600">{{ deleteTarget.order_number }}</p>
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">
                        Замовлення буде видалено (soft delete). Якщо воно було завершено — інвентар та ліміти будуть реверсовані.
                    </p>
                    <label class="block text-xs text-gray-600 mb-1 font-medium">Причина видалення <span class="text-red-600">*</span></label>
                    <textarea aria-label="Причина видалення" v-model="deleteReason" rows="2" class="input w-full text-sm" placeholder="Вкажіть причину..."></textarea>
                    <div class="mt-4 flex justify-end gap-2">
                        <button @click="cancelDelete" class="btn-ghost text-sm !px-4 !py-2">Скасувати</button>
                        <button @click="confirmDelete" :disabled="!deleteReason.trim() || deleting"
                            class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition disabled:opacity-50">
                            {{ deleting ? 'Видаляю...' : 'Видалити' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
