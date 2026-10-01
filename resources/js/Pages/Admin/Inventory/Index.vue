<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, useForm, router, Deferred } from '@inertiajs/vue3'
import ProcurementTab from '@/Components/Inventory/ProcurementTab.vue'
import InventoryItemRow from '@/Components/Inventory/InventoryItemRow.vue'
import { ref, computed, watch } from 'vue'
import { fmtQty } from '@/utils/fmtQty'
import { variantGroups } from '@/composables/useInventoryVariantGroups'

const props = defineProps({
    items: Array,
    parameterOptions: Array,
    categories: Array,
    procurement: Object,
})

// ─── Tab state ──────────────────────────────────────
const activeTab = ref('inventory')

// ─── Drag & Drop sorting (Inventory) ────────────────
const localItems = ref(JSON.parse(JSON.stringify(props.items || [])))
const dragState = ref({ draggedId: null, overId: null, categoryId: null })

// Paper subcategory display order
const PAPER_SUBCATEGORY_ORDER = ['Звичайний папір', 'Кольоровий', 'Крейдований', 'Диз. картон']
const PAPER_SUBCATEGORY_ICONS = {
    'Звичайний папір': '■',
    'Кольоровий': '◆',
    'Крейдований': '●',
    'Диз. картон': '✦',
}

const itemsByCategory = computed(() => {
    const map = {}
    for (const cat of props.categories) {
        map[cat.id] = localItems.value
            .filter(i => i.inventory_category_id === cat.id)
            .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    }
    return map
})

// Group items by subcategory within a category (used for Paper)
function itemsBySubcategory(categoryId) {
    const items = itemsByCategory.value[categoryId] || []
    const groups = {}
    for (const item of items) {
        const sub = item.subcategory || 'Інше'
        if (!groups[sub]) groups[sub] = []
        groups[sub].push(item)
    }
    // Sort by predefined order
    return PAPER_SUBCATEGORY_ORDER
        .filter(sub => groups[sub]?.length > 0)
        .map(sub => ({ name: sub, icon: PAPER_SUBCATEGORY_ICONS[sub] || '□', items: groups[sub] }))
        .concat(
            Object.keys(groups)
                .filter(sub => !PAPER_SUBCATEGORY_ORDER.includes(sub))
                .map(sub => ({ name: sub, icon: '□', items: groups[sub] }))
        )
}

// Detect if a category is the "Папір" category
function isPaperCategory(category) {
    return category.name === 'Папір'
}

// Категорії, де кольори однієї позиції читаються підсумком, а не списком:
// комплектна палітурка — це 8 розмірів × 4 кольори, канали з обкладинками —
// ще 20 рядків, і зайняті в них одиниці.
const GROUPED_CATEGORIES = [
    'Тверда палітурка (комплектні)',
    'Тверда палітурка (канали + обкладинки)',
]

function isGrouped(category) {
    return GROUPED_CATEGORIES.includes(category.name)
}

const groupsByCategory = computed(() => {
    const map = {}
    for (const cat of props.categories) {
        if (isGrouped(cat)) {
            map[cat.id] = variantGroups(itemsByCategory.value[cat.id] || [])
        }
    }
    return map
})

function groupKey(category, group) {
    return `${category.id}:${group.key}`
}

// ─── Що згорнуто на вкладці «Склад» ─────────────────
// Кожна дія — оприбуткування, правка, конвертація — перезавантажує сторінку,
// тож без памʼяті стан «згорнуто» не переживав би жодного кліку.
const COLLAPSE_KEY = 'crm.inventory.collapsed'

function loadCollapsed() {
    const empty = { cats: {}, subs: {}, groups: {} }
    try {
        const saved = JSON.parse(localStorage.getItem(COLLAPSE_KEY) || 'null')
        if (! saved || typeof saved !== 'object') {
            return empty
        }

        // Перелічені поіменно, щоб відмерлі відра (як `sizes` до групування за
        // назвою) не переписувались назад у сховище нескінченно.
        return { cats: saved.cats ?? {}, subs: saved.subs ?? {}, groups: saved.groups ?? {} }
    } catch {
        return empty
    }
}

const collapsed = ref(loadCollapsed())

watch(collapsed, (state) => {
    try {
        localStorage.setItem(COLLAPSE_KEY, JSON.stringify(state))
    } catch {
        // Приватний режим або переповнене сховище: стан просто не переживе перезавантаження.
    }
}, { deep: true })

// Категорії й підгрупи паперу за замовчуванням розгорнуті — як було досі.
function toggleCategory(name) {
    collapsed.value.cats[name] = !collapsed.value.cats[name]
}

function toggleSub(key) {
    collapsed.value.subs[key] = !collapsed.value.subs[key]
}

// Групи кольорів — навпаки, згорнуті, поки їх не відкрили: підсумок замість
// тридцяти двох рядків і є те, заради чого вони існують.
function groupIsCollapsed(key) {
    return collapsed.value.groups[key] !== false
}

function toggleGroup(key) {
    collapsed.value.groups[key] = !groupIsCollapsed(key)
}

function onDragStart(e, item) {
    dragState.value.draggedId = item.id
    dragState.value.categoryId = item.inventory_category_id
    e.dataTransfer.effectAllowed = 'move'
}

function onDragOver(e, item) {
    e.preventDefault()
    if (item.inventory_category_id !== dragState.value.categoryId) return
    dragState.value.overId = item.id
}

function onDrop(e, targetItem) {
    e.preventDefault()
    const { draggedId, categoryId } = dragState.value
    if (!draggedId || targetItem.inventory_category_id !== categoryId) return

    const catItems = itemsByCategory.value[categoryId]
    const fromIdx = catItems.findIndex(i => i.id === draggedId)
    const toIdx = catItems.findIndex(i => i.id === targetItem.id)
    if (fromIdx === -1 || toIdx === -1 || fromIdx === toIdx) return

    const [moved] = catItems.splice(fromIdx, 1)
    catItems.splice(toIdx, 0, moved)
    catItems.forEach((item, idx) => { item.sort_order = idx + 1 })

    router.post(route('admin.inventory.reorder'), {
        ids: catItems.map(i => i.id),
    }, { preserveScroll: true, preserveState: true })

    dragState.value = { draggedId: null, overId: null, categoryId: null }
}

function onDragEnd() {
    dragState.value = { draggedId: null, overId: null, categoryId: null }
}

// ─── Option Mapping ─────────────────────────────────
const initialOptions = JSON.parse(JSON.stringify(props.parameterOptions || []))
const optionsForm = useForm({
    options: initialOptions,
})

// ─── Collapse state ──────────────────────────────
const collapsedMappingCats = ref({})

function toggleMappingCat(name) {
    collapsedMappingCats.value[name] = !collapsedMappingCats.value[name]
}

// Stats
const linkedOptions = computed(() => optionsForm.options.filter(o => o.inventory_item_id).length)

// Group ONLY linked options by service category → service
const optionsByCategory = computed(() => {
    const catMap = {}
    const opts = optionsForm.options.filter(o => o.inventory_item_id)
    for (const opt of opts) {
        const catName = opt.service_category_name || 'Інше'
        const catSort = opt.service_category_sort ?? 999
        const svcName = opt.service_name || 'Інше'
        if (!catMap[catName]) catMap[catName] = { sort: catSort, services: {} }
        if (!catMap[catName].services[svcName]) catMap[catName].services[svcName] = []
        catMap[catName].services[svcName].push(opt)
    }
    return Object.entries(catMap)
        .sort(([, a], [, b]) => a.sort - b.sort)
        .map(([catName, cat]) => ({
            name: catName,
            services: Object.entries(cat.services)
                .sort(([a], [b]) => a.localeCompare(b))
                .map(([svcName, items]) => ({
                    name: svcName,
                    items: items.sort((a, b) => {
                        if (a.group_name !== b.group_name) return (a.group_name || '').localeCompare(b.group_name || '')
                        return (a.name || '').localeCompare(b.name || '')
                    }),
                })),
        }))
})

function submitOptions() {
    optionsForm.post(route('admin.inventory.options'), {
        preserveScroll: true,
    })
}

// ─── CRUD Modal (Створення / Редагування) ───────────
const showingCrudModal = ref(false)
const crudForm = useForm({
    id: null,
    inventory_category_id: null,
    subcategory: null,
    name: '',
    unit: 'шт',
    min_quantity: 0,
    avg_cost: null,
    refill_cost: null,
    current_quantity: null,
    empty_quantity: null,
})

const crudCategoryIsPaper = computed(() => {
    const cat = props.categories.find(c => c.id === crudForm.inventory_category_id)
    return cat?.name === 'Папір'
})

const crudCategoryIsToner = computed(() => {
    const cat = props.categories.find(c => c.id === crudForm.inventory_category_id)
    return cat?.name === 'Витратні матеріали'
})

function openCreateModal() {
    crudForm.reset()
    crudForm.clearErrors()
    crudForm.id = null
    showingCrudModal.value = true
}

function openEditModal(item) {
    crudForm.reset()
    crudForm.clearErrors()
    crudForm.id = item.id
    crudForm.inventory_category_id = item.inventory_category_id
    crudForm.subcategory = item.subcategory
    crudForm.name = item.name
    crudForm.unit = item.unit
    crudForm.min_quantity = item.min_quantity
    crudForm.avg_cost = parseFloat(item.avg_cost) || null
    crudForm.refill_cost = parseFloat(item.refill_cost) || null
    crudForm.current_quantity = parseFloat(item.current_quantity) || 0
    crudForm.empty_quantity = parseFloat(item.empty_quantity) || 0
    showingCrudModal.value = true
}

function submitCrud() {
    if (crudForm.id) {
        crudForm.patch(route('admin.inventory.update', crudForm.id), {
            onSuccess: () => showingCrudModal.value = false,
        })
    } else {
        crudForm.post(route('admin.inventory.store'), {
            onSuccess: () => showingCrudModal.value = false,
        })
    }
}

// ─── Receipt Modal (Прибуткова накладна) ────────────
const showingReceiptModal = ref(false)
const selectedItemName = ref('')
const receiptForm = useForm({
    inventory_item_id: null,
    packs_quantity: 1,
    units_per_pack: 1,
    price_per_pack: 0,
    notes: '',
    auto_update_markup: true,
})

function openReceiptModal(item) {
    receiptForm.reset()
    receiptForm.clearErrors()
    receiptForm.inventory_item_id = item.id
    selectedItemName.value = item.name
    showingReceiptModal.value = true
}

function submitReceipt() {
    receiptForm.post(route('admin.inventory.receipt'), {
        onSuccess: () => showingReceiptModal.value = false,
    })
}

// ─── Conversion Modal (Конвертація матеріалу) ───────
const showingConvertModal = ref(false)
const convertForm = useForm({
    source_item_id: null,
    target_item_id: null,
    quantity: 1,
    ratio: 2,
    notes: '',
})

const convertSourceItem = computed(() => props.items.find(i => i.id === convertForm.source_item_id))
const convertTargetItem = computed(() => props.items.find(i => i.id === convertForm.target_item_id))
const convertResultQty = computed(() => (Number(convertForm.quantity) * Number(convertForm.ratio)).toFixed(2))

const paperCategoryId = computed(() => props.categories.find(c => c.name === 'Папір')?.id)
const paperItems = computed(() => props.items.filter(i => i.inventory_category_id === paperCategoryId.value))

function openConvertModal() {
    convertForm.reset()
    convertForm.clearErrors()
    convertForm.ratio = 2
    convertForm.quantity = 1
    showingConvertModal.value = true
}

function submitConvert() {
    convertForm.post(route('admin.inventory.convert'), {
        onSuccess: () => showingConvertModal.value = false,
    })
}

// ─── Toner tracking additions ───────────────────────
const tonerCategoryId = computed(() => props.categories.find(c => c.name === 'Витратні матеріали')?.id)
const tonerItems = computed(() => {
    if (!tonerCategoryId.value) return []
    return localItems.value.filter(i => i.inventory_category_id === tonerCategoryId.value)
})
const sortedTonerItems = computed(() => {
    return [...tonerItems.value].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
})

// Install Toner Modal
const showingInstallModal = ref(false)
const installForm = useForm({
    inventory_item_id: null,
    quantity: 1,
    notes: '',
})

function openInstallModal(item) {
    installForm.reset()
    installForm.clearErrors()
    installForm.inventory_item_id = item.id
    selectedItemName.value = item.name
    showingInstallModal.value = true
}

function submitInstall() {
    installForm.post(route('admin.inventory.install'), {
        onSuccess: () => showingInstallModal.value = false,
    })
}

// Refill Toner Modal
const showingRefillModal = ref(false)
const refillForm = useForm({
    inventory_item_id: null,
    quantity: 1,
    total_cost: 0,
    notes: '',
})

function openRefillModal(item) {
    refillForm.reset()
    refillForm.clearErrors()
    refillForm.inventory_item_id = item.id
    const qty = item.empty_quantity > 0 ? parseFloat(item.empty_quantity) : 1
    refillForm.quantity = qty
    refillForm.total_cost = qty * (parseFloat(item.refill_cost) || 0)
    selectedItemName.value = item.name
    showingRefillModal.value = true
}

watch(() => refillForm.quantity, (newQty) => {
    const item = props.items.find(i => i.id === refillForm.inventory_item_id)
    if (item && item.refill_cost) {
        refillForm.total_cost = Number(newQty) * parseFloat(item.refill_cost)
    }
})

function submitRefill() {
    refillForm.post(route('admin.inventory.refill'), {
        onSuccess: () => showingRefillModal.value = false,
    })
}

// Adjust Empty Modal
const showingAdjustEmptyModal = ref(false)
const adjustEmptyForm = useForm({
    inventory_item_id: null,
    quantity_change: 1,
    notes: '',
})

function openAdjustEmptyModal(item) {
    adjustEmptyForm.reset()
    adjustEmptyForm.clearErrors()
    adjustEmptyForm.inventory_item_id = item.id
    selectedItemName.value = item.name
    showingAdjustEmptyModal.value = true
}

function submitAdjustEmpty() {
    adjustEmptyForm.post(route('admin.inventory.adjust_empty'), {
        onSuccess: () => showingAdjustEmptyModal.value = false,
    })
}
</script>

<template>
    <Head title="Склад" />

    <AppLayout>
        <div class="max-w-7xl">
        <!-- Header with gradient icon + tabs -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="page-header !mb-0">
                <div class="page-header-icon bg-gradient-to-br from-emerald-400 to-teal-600">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                </div>
                <div>
                    <h1 class="page-header-title">Склад</h1>
                    <p class="page-header-subtitle">Управління запасами та відповідності опціям</p>
                </div>
            </div>
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
                    <button @click="activeTab = 'inventory'"
                        :class="[
                            'px-4 py-2 text-sm font-semibold rounded-md transition',
                            activeTab === 'inventory'
                                ? 'bg-white text-indigo-700 shadow-sm'
                                : 'text-gray-600 hover:text-gray-800'
                        ]">
                        Склад
                    </button>
                    <button @click="activeTab = 'toners'"
                        :class="[
                            'px-4 py-2 text-sm font-semibold rounded-md transition',
                            activeTab === 'toners'
                                ? 'bg-white text-indigo-700 shadow-sm'
                                : 'text-gray-600 hover:text-gray-800'
                        ]">
                        Заправка тонерів
                    </button>
                    <button @click="activeTab = 'procurement'"
                        :class="[
                            'px-4 py-2 text-sm font-semibold rounded-md transition',
                            activeTab === 'procurement'
                                ? 'bg-white text-indigo-700 shadow-sm'
                                : 'text-gray-600 hover:text-gray-800'
                        ]">
                        Закупівлі
                    </button>
                    <button @click="activeTab = 'mapping'"
                        :class="[
                            'px-4 py-2 text-sm font-semibold rounded-md transition',
                            activeTab === 'mapping'
                                ? 'bg-white text-indigo-700 shadow-sm'
                                : 'text-gray-600 hover:text-gray-800'
                        ]">
                        Відповідність
                    </button>
                </div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center justify-end gap-2 mb-4">
            <template v-if="activeTab === 'inventory'">
                <button @click="openConvertModal" class="btn-secondary flex items-center gap-1.5 w-full sm:w-auto justify-center">
                    ✂ Конвертація
                </button>
                <button @click="openCreateModal" class="btn-primary w-full sm:w-auto justify-center">
                    + Додати товар
                </button>
            </template>
            <template v-else-if="activeTab === 'mapping'">
                <button @click="submitOptions" class="btn-primary bg-indigo-600 hover:bg-indigo-700 shadow-indigo-200/50 text-sm w-full sm:w-auto justify-center" :disabled="optionsForm.processing">
                    {{ optionsForm.processing ? 'Збереження...' : 'Зберегти зв\'язки' }}
                </button>
            </template>
        </div>

        <!-- ═══════════ INVENTORY TAB ═══════════ -->
        <template v-if="activeTab === 'inventory'">
            <template v-for="category in categories" :key="category.id">
                <template v-if="category.name !== 'Витратні матеріали' && itemsByCategory[category.id]?.length > 0">
                    <!-- Category header: collapsible -->
                    <button @click="toggleCategory(category.name)"
                        class="flex items-center gap-2 w-full text-left mt-8 mb-4 px-2 py-2.5 rounded-lg hover:bg-gray-50 transition group">
                        <svg :class="{ 'rotate-90': !collapsed.cats[category.name] }"
                             class="w-4 h-4 text-gray-600 transition-transform flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                        <h2 class="text-xl font-bold text-gray-700 group-hover:text-indigo-600 transition">
                            {{ category.name }}
                        </h2>
                        <span class="text-sm text-gray-600 font-normal">{{ itemsByCategory[category.id].length }}</span>
                    </button>

                    <template v-if="!collapsed.cats[category.name]">

                    <!-- Paper category: grouped by subcategory -->
                    <template v-if="isPaperCategory(category)">
                        <div v-for="subgroup in itemsBySubcategory(category.id)" :key="subgroup.name" class="mb-4">
                            <!-- Subcategory header -->
                            <button @click="toggleSub(subgroup.name)"
                                class="flex items-center gap-2 w-full text-left mb-2 px-2 py-2.5 rounded-lg hover:bg-gray-50 transition group">
                                <svg :class="{ 'rotate-90': !collapsed.subs[subgroup.name] }"
                                     class="w-3.5 h-3.5 text-gray-600 transition-transform flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                </svg>
                                <span class="text-base">{{ subgroup.icon }}</span>
                                <h3 class="text-sm font-semibold text-gray-600 group-hover:text-indigo-600 transition">
                                    {{ subgroup.name }}
                                </h3>
                                <span class="text-xs text-gray-600 font-normal">{{ subgroup.items.length }}</span>
                            </button>

                            <!-- Items table -->
                            <div v-if="!collapsed.subs[subgroup.name]" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden ml-2">
                                <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="w-10 px-2 py-3"></th>
                                            <th scope="col" class="px-6 py-3 text-left font-semibold text-gray-600 uppercase tracking-widest">Назва</th>
                                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest ml-auto">Поточний залишок</th>
                                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Середня собівартість (AVCO)</th>
                                            <th scope="col" class="px-6 py-3 text-center font-semibold text-gray-600 uppercase tracking-widest">Мін. залишок</th>
                                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Дії</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <InventoryItemRow v-for="item in subgroup.items" :key="item.id"
                                            :item="item"
                                            :drag-state="dragState"
                                            @dragstart="onDragStart($event, item)"
                                            @dragover="onDragOver($event, item)"
                                            @drop="onDrop($event, item)"
                                            @dragend="onDragEnd"
                                            @receipt="openReceiptModal(item)"
                                            @edit="openEditModal(item)" />
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Тверда палітурка: кольори згорнуті в назву, у шапці — сума по ній -->
                    <template v-else-if="isGrouped(category)">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
                        <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="w-10 px-2 py-3"></th>
                                    <th scope="col" class="px-6 py-3 text-left font-semibold text-gray-600 uppercase tracking-widest">Назва</th>
                                    <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest ml-auto">Поточний залишок</th>
                                    <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Середня собівартість (AVCO)</th>
                                    <th scope="col" class="px-6 py-3 text-center font-semibold text-gray-600 uppercase tracking-widest">Мін. залишок</th>
                                    <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Дії</th>
                                </tr>
                            </thead>
                            <tbody v-for="group in groupsByCategory[category.id]" :key="group.key"
                                class="divide-y divide-gray-200 border-t border-gray-200">
                                <!-- Назва без кольору: сума по всіх кольорах -->
                                <tr v-if="group.collapsible"
                                    @click="toggleGroup(groupKey(category, group))"
                                    class="bg-gray-50 hover:bg-gray-100 transition-colors cursor-pointer">
                                    <td class="px-2 py-3 text-center">
                                        <svg :class="{ 'rotate-90': !groupIsCollapsed(groupKey(category, group)) }"
                                             class="w-3.5 h-3.5 text-gray-600 transition-transform inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap border-r border-gray-100">
                                        <span class="font-semibold text-gray-800">{{ group.label }}</span>
                                        <span class="text-gray-600 ml-2">{{ group.items.length }} кол.</span>
                                        <span v-if="group.belowMin" class="ml-2 text-xs font-semibold text-red-700">
                                            {{ group.belowMin }} нижче мін.
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right border-r border-gray-100">
                                        <span class="text-lg font-bold" :class="group.totalQuantity > 0 ? 'text-green-700' : 'text-red-700'">
                                            {{ fmtQty(group.totalQuantity) }}
                                        </span>
                                        <span class="text-gray-600 ml-1">{{ group.unit }}.</span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right font-mono text-gray-700 border-r border-gray-100">
                                        {{ group.avgCost === null ? '—' : `${group.avgCost.toFixed(2)} ₴` }}
                                    </td>
                                    <td class="px-6 py-3"></td>
                                    <td class="px-6 py-3"></td>
                                </tr>
                                <template v-if="!group.collapsible || !groupIsCollapsed(groupKey(category, group))">
                                    <InventoryItemRow v-for="item in group.items" :key="item.id"
                                        :item="item"
                                        :drag-state="dragState"
                                        :nested="group.collapsible"
                                        @dragstart="onDragStart($event, item)"
                                        @dragover="onDragOver($event, item)"
                                        @drop="onDrop($event, item)"
                                        @dragend="onDragEnd"
                                        @receipt="openReceiptModal(item)"
                                        @edit="openEditModal(item)" />
                                </template>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    </template>

                    <!-- Non-paper categories: flat table -->
                    <template v-else>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
                        <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="w-10 px-2 py-3"></th>
                                    <th scope="col" class="px-6 py-3 text-left font-semibold text-gray-600 uppercase tracking-widest">Назва</th>
                                    <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest ml-auto">Поточний залишок</th>
                                    <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Середня собівартість (AVCO)</th>
                                    <th scope="col" class="px-6 py-3 text-center font-semibold text-gray-600 uppercase tracking-widest">Мін. залишок</th>
                                    <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Дії</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <InventoryItemRow v-for="item in itemsByCategory[category.id]" :key="item.id"
                                    :item="item"
                                    :drag-state="dragState"
                                    @dragstart="onDragStart($event, item)"
                                    @dragover="onDragOver($event, item)"
                                    @drop="onDrop($event, item)"
                                    @dragend="onDragEnd"
                                    @receipt="openReceiptModal(item)"
                                    @edit="openEditModal(item)" />
                            </tbody>
                        </table>
                        </div>
                    </div>
                    </template>

                    </template>
                </template>
            </template>

            <div v-if="!items.length" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6 p-12 text-center text-gray-600">
                Склад порожній. Додайте перший товар.
            </div>
        </template>

        <!-- ═══════════ TONERS TAB ═══════════ -->
        <template v-if="activeTab === 'toners'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6 mt-8">
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800">Облік та заправка тонерів</h2>
                        <p class="text-xs text-gray-600">Управління повними картриджами, порожніми гільзами та процесом заправки</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="w-10 px-2 py-3"></th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Назва</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold text-gray-600 uppercase tracking-wider">Заправлені</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold text-gray-600 uppercase tracking-wider">Порожні</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold text-gray-600 uppercase tracking-wider">Всього</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold text-gray-600 uppercase tracking-wider">Собівартість (AVCO)</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold text-gray-600 uppercase tracking-wider">Дії</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="item in sortedTonerItems" :key="item.id"
                                draggable="true"
                                @dragstart="onDragStart($event, item)"
                                @dragover="onDragOver($event, item)"
                                @drop="onDrop($event, item)"
                                @dragend="onDragEnd"
                                class="hover:bg-gray-50 transition-colors cursor-grab active:cursor-grabbing"
                                :class="{
                                    'opacity-40': dragState.draggedId === item.id,
                                    'border-t-2 border-indigo-400': dragState.overId === item.id && dragState.draggedId !== item.id,
                                }">
                                <td class="px-2 py-4 text-center text-gray-600 select-none">
                                    <span class="text-lg text-gray-600 cursor-grab">⠿</span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap font-medium text-gray-900 border-r border-gray-100">
                                    {{ item.name }}
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right border-r border-gray-100">
                                    <span class="text-lg font-bold" :class="Number(item.current_quantity) <= Number(item.min_quantity) ? 'text-amber-700' : 'text-green-700'">
                                        {{ fmtQty(item.current_quantity) }}
                                    </span>
                                    <span class="text-gray-600 ml-1">{{ item.unit }}.</span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right border-r border-gray-100">
                                    <span class="text-lg font-bold" :class="Number(item.empty_quantity) > 0 ? 'text-amber-700' : 'text-gray-600'">
                                        {{ fmtQty(item.empty_quantity) }}
                                    </span>
                                    <span class="text-gray-600 ml-1">{{ item.unit }}.</span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right font-semibold text-gray-700 bg-gray-50/50 border-r border-gray-100">
                                    {{ fmtQty(Number(item.current_quantity) + Number(item.empty_quantity)) }} {{ item.unit }}.
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right font-mono text-gray-700 bg-gray-50 border-r border-gray-100">
                                    {{ parseFloat(item.avg_cost).toFixed(2) }} ₴
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right space-x-3">
                                    <button @click="openInstallModal(item)" class="text-blue-600 hover:text-blue-900 font-medium" :disabled="Number(item.current_quantity) <= 0" title="Встановити картридж в апарат">
                                        Встановити
                                    </button>
                                    <button @click="openRefillModal(item)" class="text-emerald-700 hover:text-emerald-900 font-medium" :disabled="Number(item.empty_quantity) <= 0" title="Заправити порожні гільзи">
                                        Заправити
                                    </button>
                                    <button @click="openEditModal(item)" class="text-indigo-600 hover:text-indigo-900 font-medium" title="Редагувати параметри та залишки">
                                        Редагувати
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="!tonerItems.length" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6 p-12 text-center text-gray-600">
                Витратні матеріали не знайдені.
            </div>
        </template>

        <!-- ═══════════ PROCUREMENT TAB ═══════════ -->
        <template v-if="activeTab === 'procurement'">
            <Deferred data="procurement">
                <template #fallback>
                    <div class="mt-8 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div v-for="n in 3" :key="n" class="h-20 bg-white rounded-xl border border-gray-200 shadow-sm animate-pulse"></div>
                        </div>
                        <div class="h-64 bg-white rounded-xl border border-gray-200 shadow-sm animate-pulse"></div>
                    </div>
                </template>
                <ProcurementTab :procurement="procurement" @receipt="openReceiptModal" @refill="openRefillModal" />
            </Deferred>
        </template>

        <!-- ═══════════ MAPPING TAB ═══════════ -->
        <template v-if="activeTab === 'mapping'">
            <p class="text-sm text-gray-600 mb-4">
                Прив'язано <strong class="text-indigo-600">{{ linkedOptions }}</strong> опцій до складських товарів. Нові зв'язки додаються через «Параметри» конкретної послуги.
            </p>

            <template v-if="optionsByCategory.length > 0">
                <div v-for="(cat, ci) in optionsByCategory" :key="cat.name" :class="{ 'mt-6': ci > 0 }">
                    <!-- Category heading (collapsible) -->
                    <button @click="toggleMappingCat(cat.name)" class="flex items-center gap-2 w-full text-left mb-3 px-1 group">
                        <svg :class="{ 'rotate-90': !collapsedMappingCats[cat.name] }" class="w-4 h-4 text-gray-600 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                        <h2 class="text-lg font-bold text-gray-700 group-hover:text-indigo-600 transition">
                            {{ cat.name }}
                        </h2>
                        <span class="text-xs text-gray-600 font-normal">
                            {{ cat.services.reduce((s, svc) => s + svc.items.length, 0) }} опцій
                        </span>
                    </button>

                    <!-- Service sub-groups -->
                    <template v-if="!collapsedMappingCats[cat.name]">
                        <div v-for="(svc, si) in cat.services" :key="svc.name" :class="{ 'mt-3': si > 0 }">
                            <h3 class="text-sm font-semibold text-indigo-600 mb-2 px-1 flex items-center gap-2">
                                {{ svc.name }}
                                <span class="text-xs text-gray-600 font-normal">{{ svc.items.length }}</span>
                            </h3>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-3">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 border-b border-gray-100">
                                        <tr>
                                            <th scope="col" class="px-6 py-2.5 text-left font-semibold text-gray-600 uppercase tracking-widest text-xs w-1/3">Група / Опція</th>
                                            <th scope="col" class="px-6 py-2.5 text-left font-semibold text-gray-600 uppercase tracking-widest text-xs w-1/4">Товар на складі</th>
                                            <th scope="col" class="px-6 py-2.5 text-left font-semibold text-gray-600 uppercase tracking-widest text-xs">К-сть списання</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="opt in svc.items" :key="opt.id"
                                            class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-2.5 border-r border-gray-50">
                                                <div class="font-medium text-gray-900 text-sm">{{ opt.name }}</div>
                                                <div class="text-xs text-gray-600">{{ opt.group_name }}</div>
                                            </td>
                                            <td class="px-6 py-2.5 border-r border-gray-50">
                                                <select aria-label="Матеріал зі складу" v-model="opt.inventory_item_id" class="input py-1 text-sm h-auto bg-white">
                                                    <option :value="null">— Не списувати —</option>
                                                    <option v-for="item in items" :key="item.id" :value="item.id">
                                                        {{ item.name }} ({{ item.unit }})
                                                    </option>
                                                </select>
                                            </td>
                                            <td class="px-6 py-2.5">
                                                <input aria-label="Витрата на одиницю" v-model="opt.inventory_qty" type="number" step="0.0001" min="0"
                                                    class="input py-1 text-sm h-auto bg-white max-w-[120px]"
                                                    :disabled="!opt.inventory_item_id" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <div v-if="linkedOptions === 0" class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center text-gray-600">
                Немає прив'язаних опцій. Додайте зв'язки через «Параметри» послуги.
            </div>
        </template>

        <!-- CRUD Modal -->
        <div v-if="showingCrudModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <form @submit.prevent="submitCrud">
                    <div class="px-6 py-5 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-900">
                            {{ crudForm.id ? 'Редагувати товар' : 'Новий товар' }}
                        </h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Категорія складового товару *</label>
                            <select aria-label="Категорія складового товару" v-model="crudForm.inventory_category_id" class="input" required>
                                <option :value="null" disabled>— Оберіть категорію —</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                            <p v-if="crudForm.errors.inventory_category_id" class="text-xs text-red-600 mt-1">{{ crudForm.errors.inventory_category_id }}</p>
                        </div>
                        <div v-if="crudCategoryIsPaper">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Підкатегорія паперу</label>
                            <select aria-label="Підкатегорія паперу" v-model="crudForm.subcategory" class="input">
                                <option :value="null">— Без підкатегорії —</option>
                                <option value="Звичайний папір">Звичайний папір</option>
                                <option value="Кольоровий">Кольоровий</option>
                                <option value="Крейдований">Крейдований</option>
                                <option value="Диз. картон">Диз. картон</option>
                            </select>
                            <p v-if="crudForm.errors.subcategory" class="text-xs text-red-600 mt-1">{{ crudForm.errors.subcategory }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Назва *</label>
                            <input aria-label="Назва" v-model="crudForm.name" type="text" class="input" required />
                            <p v-if="crudForm.errors.name" class="text-xs text-red-600 mt-1">{{ crudForm.errors.name }}</p>
                        </div>
                        <div v-if="!crudCategoryIsToner" class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Одиниця виміру *</label>
                                <select aria-label="Одиниця виміру" v-model="crudForm.unit" class="input" required>
                                    <option value="шт">шт (штуки, аркуші)</option>
                                    <option value="м">м (метри погонні)</option>
                                    <option value="м2">м² (кв. метри)</option>
                                    <option value="упаковки">упаковки</option>
                                    <option value="мл">мл (мілілітри)</option>
                                </select>
                                <p v-if="crudForm.errors.unit" class="text-xs text-red-600 mt-1">{{ crudForm.errors.unit }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Мін. залишок (для тригеру)</label>
                                <input aria-label="Мін. залишок (для тригеру)" v-model="crudForm.min_quantity" type="number" step="1" class="input" required />
                                <p v-if="crudForm.errors.min_quantity" class="text-xs text-red-600 mt-1">{{ crudForm.errors.min_quantity }}</p>
                            </div>
                        </div>

                        <!-- Special fields for Toner Refilling/Consumables -->
                        <div v-if="crudCategoryIsToner" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Мін. залишок (критичний поріг)</label>
                                <input aria-label="Мін. залишок (критичний поріг)" v-model="crudForm.min_quantity" type="number" step="1" class="input" required />
                                <p v-if="crudForm.errors.min_quantity" class="text-xs text-red-600 mt-1">{{ crudForm.errors.min_quantity }}</p>
                            </div>
                            <!--
                                Stock and AVCO only when editing. store() writes
                                current_quantity = 0 and avg_cost = 0 over whatever
                                arrives — a new item's stock comes in through
                                Оприбуткування and its cost from AVCO — so on create
                                these two boxes took a required number and threw it
                                away under "Товар успішно додано на склад." The manual
                                cost field below has carried `crudForm.id &&` for this
                                reason since it was written; the toner block never got
                                the same guard.
                            -->
                            <div class="grid grid-cols-2 gap-4">
                                <div v-if="crudForm.id">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Заправлені тонери (шт)</label>
                                    <input aria-label="Заправлені тонери (шт)" v-model="crudForm.current_quantity" type="number" step="1" min="0" class="input" required />
                                    <p v-if="crudForm.errors.current_quantity" class="text-xs text-red-600 mt-1">{{ crudForm.errors.current_quantity }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Порожні гільзи (шт)</label>
                                    <input aria-label="Порожні гільзи (шт)" v-model="crudForm.empty_quantity" type="number" step="1" min="0" class="input" required />
                                    <p v-if="crudForm.errors.empty_quantity" class="text-xs text-red-600 mt-1">{{ crudForm.errors.empty_quantity }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div v-if="crudForm.id">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Собівартість тонера (AVCO), грн</label>
                                    <input aria-label="Собівартість тонера (AVCO), грн" v-model="crudForm.avg_cost" type="number" step="0.01" min="0" class="input font-mono" placeholder="0.00" />
                                    <p v-if="crudForm.errors.avg_cost" class="text-xs text-red-600 mt-1">{{ crudForm.errors.avg_cost }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Вартість заправки тонера, грн</label>
                                    <input aria-label="Вартість заправки тонера, грн" v-model="crudForm.refill_cost" type="number" step="0.01" min="0" class="input font-mono" placeholder="0.00" />
                                    <p v-if="crudForm.errors.refill_cost" class="text-xs text-red-600 mt-1">{{ crudForm.errors.refill_cost }}</p>
                                </div>
                            </div>
                            <p v-if="!crudForm.id" class="text-xs text-gray-600">
                                Заправлені тонери та собівартість з'являться після першого
                                оприбуткування — новий товар створюється з нульовим залишком.
                            </p>
                        </div>

                        <div v-if="crudForm.id && !crudCategoryIsToner">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Собівартість (ручна), грн</label>
                            <input aria-label="Собівартість (ручна), грн" v-model="crudForm.avg_cost" type="number" step="0.0001" min="0" class="input font-mono" placeholder="0.0000" />
                            <p v-if="crudForm.errors.avg_cost" class="text-xs text-red-600 mt-1">{{ crudForm.errors.avg_cost }}</p>
                            <p class="text-xs text-gray-600 mt-1">Буде автоматично перерахована при наступному оприбуткуванні (AVCO)</p>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
                        <button type="button" @click="showingCrudModal = false" class="btn-secondary">Скасувати</button>
                        <button type="submit" class="btn-primary" :disabled="crudForm.processing">Зберегти</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Receipt Modal -->
        <div v-if="showingReceiptModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200">
                <form @submit.prevent="submitReceipt">
                    <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-green-50 to-white">
                        <h3 class="text-lg font-bold text-green-900 flex items-center gap-2">
                            Прибуткова накладна ({{ selectedItemName }})
                        </h3>
                    </div>

                    <div class="p-6 space-y-4 bg-white">
                        <div class="p-4 bg-blue-50 text-blue-800 text-sm rounded-xl border border-blue-100 leading-relaxed shadow-sm">
                            <strong class="font-bold flex items-center gap-1">Конвертація одиниць</strong>
                            Якщо ви купили <strong>10 пачок</strong> паперу, а в одній пачці <strong>500 аркушів</strong>:
                            вкажіть "К-сть пачок: 10", "Одиниць у пачці: 500". На склад ляже <strong>5000 аркушів</strong>.
                        </div>

                        <div class="grid grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">К-сть пачок / коробок</label>
                                <input aria-label="К-сть пачок / коробок" v-model="receiptForm.packs_quantity" type="number" step="0.01" class="input text-lg font-bold text-gray-900 bg-gray-50" required />
                                <p v-if="receiptForm.errors.packs_quantity" class="text-xs text-red-600 mt-1">{{ receiptForm.errors.packs_quantity }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Одиниць в одній пачці</label>
                                <input aria-label="Одиниць в одній пачці" v-model="receiptForm.units_per_pack" type="number" step="1" class="input text-lg font-bold text-gray-900 bg-gray-50" required />
                                <p v-if="receiptForm.errors.units_per_pack" class="text-xs text-red-600 mt-1">{{ receiptForm.errors.units_per_pack }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Закупівельна ціна за ОДНУ ПАЧКУ (грн)</label>
                            <input aria-label="Закупівельна ціна за ОДНУ ПАЧКУ (грн)" v-model="receiptForm.price_per_pack" type="number" step="0.01" class="input text-lg font-bold text-gray-900" required />
                            <p v-if="receiptForm.errors.price_per_pack" class="text-xs text-red-600 mt-1">{{ receiptForm.errors.price_per_pack }}</p>
                        </div>

                        <div class="mt-4 p-4 border-2 border-dashed border-gray-200 rounded-xl bg-gray-50 flex justify-between items-center">
                            <div class="text-sm text-gray-600">
                                Разом на склад ляже:<br/>
                                Собівартість 1 одиниці:
                            </div>
                            <div class="text-right text-base font-bold text-gray-900 font-mono">
                                {{ fmtQty(receiptForm.packs_quantity * receiptForm.units_per_pack) }}<br/>
                                <span class="text-indigo-600">
                                    {{ receiptForm.price_per_pack > 0 ? (receiptForm.price_per_pack / receiptForm.units_per_pack).toFixed(4) : '0.0000' }} грн
                                </span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Примітки (Номер накладної, Постачальник)</label>
                            <input aria-label="Примітки (Номер накладної, Постачальник)" v-model="receiptForm.notes" type="text" class="input bg-gray-50" placeholder="Опціонально..." />
                            <p v-if="receiptForm.errors.notes" class="text-xs text-red-600 mt-1">{{ receiptForm.errors.notes }}</p>
                        </div>

                        <label class="flex items-start gap-3 p-3 border border-gray-200 rounded-xl hover:bg-gray-50 cursor-pointer transition">
                            <input v-model="receiptForm.auto_update_markup" type="checkbox" class="mt-1 w-5 h-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600" />
                            <span class="text-sm text-gray-700 leading-snug">
                                <strong class="block text-gray-900">Автоматично оновлювати маржинальність (cost) у конструкторі</strong>
                                Усі опції (папір, пружини), які прив'язані до цього товару, отримають нову собівартість (cost) для калькулятора.
                            </span>
                        </label>
                    </div>

                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 rounded-b-2xl">
                        <button type="button" @click="showingReceiptModal = false" class="btn-secondary">Скасувати</button>
                        <button type="submit" class="btn-primary" :disabled="receiptForm.processing">
                            {{ receiptForm.processing ? 'Зберігаю...' : 'Оформити прихід' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Conversion Modal -->
        <div v-if="showingConvertModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200">
                <form @submit.prevent="submitConvert">
                    <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-white">
                        <h3 class="text-lg font-bold text-amber-900 flex items-center gap-2">
                            <span>✂</span> Розрізка паперу
                        </h3>
                        <p class="text-sm text-amber-700/80 mt-1">Наприклад, розрізка А3 → А4 (коефіцієнт 2)</p>
                    </div>

                    <div class="p-6 space-y-4 bg-white">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Джерело (списати з)</label>
                            <select aria-label="Джерело (списати з)" v-model="convertForm.source_item_id" class="input" required>
                                <option :value="null" disabled>— Оберіть товар —</option>
                                <option v-for="item in paperItems" :key="item.id" :value="item.id" :disabled="item.id === convertForm.target_item_id">
                                    {{ item.name }} (залишок: {{ fmtQty(item.current_quantity) }} {{ item.unit }})
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Кількість для конвертації</label>
                            <input aria-label="Кількість для конвертації" v-model="convertForm.quantity" type="number" step="0.01" min="0.01" class="input text-lg font-bold text-gray-900 bg-gray-50" required />
                            <p v-if="convertSourceItem" class="text-xs text-gray-600 mt-1">
                                Залишок: <strong>{{ fmtQty(convertSourceItem.current_quantity) }}</strong> {{ convertSourceItem.unit }}
                                <span v-if="Number(convertForm.quantity) > Number(convertSourceItem.current_quantity)" class="text-red-600 font-semibold ml-2">⚠ Перевищує залишок!</span>
                            </p>
                        </div>

                        <hr class="border-dashed border-gray-300" />

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Ціль (прибуткувати на)</label>
                            <select aria-label="Ціль (прибуткувати на)" v-model="convertForm.target_item_id" class="input" required>
                                <option :value="null" disabled>— Оберіть товар —</option>
                                <option v-for="item in paperItems" :key="item.id" :value="item.id" :disabled="item.id === convertForm.source_item_id">
                                    {{ item.name }} (залишок: {{ fmtQty(item.current_quantity) }} {{ item.unit }})
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Коефіцієнт конвертації</label>
                            <input aria-label="Коефіцієнт конвертації" v-model="convertForm.ratio" type="number" step="0.01" min="0.01" class="input text-lg font-bold text-gray-900" required />
                            <p class="text-xs text-gray-600 mt-1">Для А3→А4: <strong>2</strong> (1 аркуш А3 = 2 аркуші А4)</p>
                        </div>

                        <div class="p-4 border-2 border-dashed border-amber-200 rounded-xl bg-amber-50/50 flex justify-between items-center">
                            <div class="text-sm text-gray-600">
                                Спишеться:<br/>
                                Додасться:
                            </div>
                            <div class="text-right text-base font-bold text-gray-900 font-mono">
                                <span class="text-red-600">−{{ Number(convertForm.quantity).toFixed(2) }}</span>
                                {{ convertSourceItem?.unit || '' }}<br/>
                                <span class="text-green-700">+{{ convertResultQty }}</span>
                                {{ convertTargetItem?.unit || '' }}
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Примітка</label>
                            <input aria-label="Примітка" v-model="convertForm.notes" type="text" class="input bg-gray-50" placeholder="Розрізка для замовлення..." />
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 rounded-b-2xl">
                        <button type="button" @click="showingConvertModal = false" class="btn-secondary">Скасувати</button>
                        <button type="submit" class="btn-primary bg-amber-600 hover:bg-amber-700" :disabled="convertForm.processing || !convertForm.source_item_id || !convertForm.target_item_id">
                            {{ convertForm.processing ? 'Обробка...' : '✂ Конвертувати' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Install Toner Modal -->
        <div v-if="showingInstallModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200">
                <form @submit.prevent="submitInstall">
                    <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-white">
                        <h3 class="text-lg font-bold text-blue-900 flex items-center gap-2">
                            <span>💻</span> Встановлення у пристрій ({{ selectedItemName }})
                        </h3>
                        <p class="text-xs text-blue-700/80 mt-1">Перенесення картриджа зі складу в роботу (зменшує заправлені, збільшує порожні на складі)</p>
                    </div>

                    <div class="p-6 space-y-4 bg-white">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Кількість для встановлення (шт)</label>
                            <input aria-label="Кількість для встановлення (шт)" v-model="installForm.quantity" type="number" step="1" min="1" class="input text-lg font-bold text-gray-900 bg-gray-50" required />
                            <!-- «Недостатньо на складі: є N, спроба встановити M» — the one
                                 refusal this form can actually meet (R29-2). -->
                            <p v-if="installForm.errors.quantity" class="text-sm text-red-600 mt-1">{{ installForm.errors.quantity }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Примітка / В який саме пристрій</label>
                            <input aria-label="Примітка / В який саме пристрій" v-model="installForm.notes" type="text" class="input bg-gray-50" placeholder="Наприклад: Встановлено в Develop ineo+ 220" />
                            <p v-if="installForm.errors.notes" class="text-sm text-red-600 mt-1">{{ installForm.errors.notes }}</p>
                        </div>

                        <p v-if="installForm.errors.inventory_item_id" class="text-sm text-red-600">{{ installForm.errors.inventory_item_id }}</p>
                    </div>

                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 rounded-b-2xl">
                        <button type="button" @click="showingInstallModal = false" class="btn-secondary">Скасувати</button>
                        <button type="submit" class="btn-primary bg-blue-600 hover:bg-blue-700" :disabled="installForm.processing">
                            {{ installForm.processing ? 'Обробка...' : 'Встановити' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Refill Toner Modal -->
        <div v-if="showingRefillModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200">
                <form @submit.prevent="submitRefill">
                    <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-white">
                        <h3 class="text-lg font-bold text-emerald-900 flex items-center gap-2">
                            <span>🔋</span> Оформлення заправки ({{ selectedItemName }})
                        </h3>
                        <p class="text-xs text-emerald-700/80 mt-1">Оприбуткування заправлених картриджів з гільз (зменшує порожні, збільшує заправлені)</p>
                    </div>

                    <div class="p-6 space-y-4 bg-white">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Кількість заправлених (шт)</label>
                                <input aria-label="Кількість заправлених (шт)" v-model="refillForm.quantity" type="number" step="1" min="1" class="input text-lg font-bold text-gray-900 bg-gray-50" required />
                                <!-- «Недостатньо порожніх картриджів: є N, спроба заправити M» —
                                     the refusal that actually happens here (R29-2). -->
                                <p v-if="refillForm.errors.quantity" class="text-sm text-red-600 mt-1">{{ refillForm.errors.quantity }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Вартість заправки разом (грн)</label>
                                <input aria-label="Вартість заправки разом (грн)" v-model="refillForm.total_cost" type="number" step="0.01" min="0" class="input text-lg font-bold text-gray-900 bg-gray-50" required />
                                <p v-if="refillForm.errors.total_cost" class="text-sm text-red-600 mt-1">{{ refillForm.errors.total_cost }}</p>
                            </div>
                        </div>

                        <div class="mt-4 p-4 border-2 border-dashed border-gray-200 rounded-xl bg-gray-50 flex justify-between items-center">
                            <div class="text-sm text-gray-600">
                                Собівартість 1 заправки:
                            </div>
                            <div class="text-right text-base font-bold text-emerald-700 font-mono">
                                {{ refillForm.quantity > 0 && refillForm.total_cost > 0 ? (refillForm.total_cost / refillForm.quantity).toFixed(2) : '0.00' }} грн
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Примітка</label>
                            <input aria-label="Примітка" v-model="refillForm.notes" type="text" class="input bg-gray-50" placeholder="Наприклад: Заправка в сервісному центрі Тріо" />
                            <p v-if="refillForm.errors.notes" class="text-sm text-red-600 mt-1">{{ refillForm.errors.notes }}</p>
                        </div>

                        <p v-if="refillForm.errors.inventory_item_id" class="text-sm text-red-600">{{ refillForm.errors.inventory_item_id }}</p>
                    </div>

                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 rounded-b-2xl">
                        <button type="button" @click="showingRefillModal = false" class="btn-secondary">Скасувати</button>
                        <button type="submit" class="btn-primary bg-emerald-700 hover:bg-emerald-800" :disabled="refillForm.processing">
                            {{ refillForm.processing ? 'Обробка...' : 'Оформити заправку' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Adjust Empty Modal -->
        <div v-if="showingAdjustEmptyModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200">
                <form @submit.prevent="submitAdjustEmpty">
                    <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-slate-50 to-white">
                        <h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                            <span>⚙</span> Коригування порожніх гільз ({{ selectedItemName }})
                        </h3>
                        <p class="text-xs text-slate-700/80 mt-1">Зміна кількості порожніх гільз вручну (списання браку, купівля гільз тощо)</p>
                    </div>

                    <div class="p-6 space-y-4 bg-white">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Зміна кількості (позитивне або негативне значення)</label>
                            <input aria-label="Зміна кількості (позитивне або негативне значення)" v-model="adjustEmptyForm.quantity_change" type="number" step="1" class="input text-lg font-bold text-gray-900 bg-gray-50" required />
                            <!-- «Кількість порожніх не може бути меншою за 0 (залишилось би: −4)» —
                                 the only refusal reachable here, and it names the arithmetic (R29-2). -->
                            <p v-if="adjustEmptyForm.errors.quantity_change" class="text-sm text-red-600 mt-1">{{ adjustEmptyForm.errors.quantity_change }}</p>
                            <p class="text-xs text-gray-600 mt-1">Введіть наприклад <strong>2</strong> щоб додати порожні гільзи, або <strong>-1</strong> щоб списати пошкоджену гільзу.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Примітка</label>
                            <input aria-label="Примітка" v-model="adjustEmptyForm.notes" type="text" class="input bg-gray-50" placeholder="Причина зміни..." required />
                            <p v-if="adjustEmptyForm.errors.notes" class="text-sm text-red-600 mt-1">{{ adjustEmptyForm.errors.notes }}</p>
                        </div>

                        <p v-if="adjustEmptyForm.errors.inventory_item_id" class="text-sm text-red-600">{{ adjustEmptyForm.errors.inventory_item_id }}</p>
                    </div>

                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3 rounded-b-2xl">
                        <button type="button" @click="showingAdjustEmptyModal = false" class="btn-secondary">Скасувати</button>
                        <button type="submit" class="btn-primary bg-slate-700 hover:bg-slate-800" :disabled="adjustEmptyForm.processing">
                            {{ adjustEmptyForm.processing ? 'Обробка...' : 'Підтвердити' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
        </div>
    </AppLayout>
</template>
