<script setup>
/**
 * Admin/Services/Form — Редагування послуги
 *
 * EDIT ONLY. `service` is dereferenced below during setup, so this page cannot
 * render without one — there is deliberately no GET route that opens it empty.
 * New services are created by the modal on Admin/Services/Index.
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { PAPER_GROUP } from '@/constants'

const props = defineProps({
    service:        Object,
    categories:     Array,
    inventoryItems: Array,  // { id, name, avg_cost, unit }
    materials:      Array,  // { id, name, counter_type, click_cost }
})

const serviceTypeLabels = {
    constructor: 'Конструктор (з параметрами)',
    static:      'Статична (фіксована ціна)',
    riso:        'Тиражування (ризограф)',
}

// ─── Base service form ────────────────────────────────────
//
// `type` is deliberately absent. UpdateServiceRequest omits it — the type
// cannot change after creation, because historical orders were priced by it —
// so a `type` in this payload was dropped by validated() and never reached the
// service. The form still offered a dropdown, rebuilt itself around the choice
// and reported "Послугу оновлено": three signals that something had changed,
// over a server that had refused. The type is shown, not edited.
const baseForm = useForm({
    name:                  props.service.name,
    service_category_id:   props.service.service_category_id,
    base_price_commercial: props.service.base_price_commercial,
    base_price_cost:       props.service.base_price_cost,
    counter_type:          props.service.counter_type ?? 'bw',
    clicks_per_unit:       props.service.clicks_per_unit ?? 0,
    is_active:             props.service.is_active,
})

function saveBase() {
    baseForm.patch(route('admin.services.update', props.service.id))
}

// ─── Add / Edit Group ─────────────────────────────────────
//
// Editing was missing, the same way option editing was before R3-18 and for
// worse consequences. The form could only add and delete, so correcting a group
// name meant deleting the group and building it again — a new id, every option
// under it gone, and every depends_on aimed at those options orphaned. And the
// name is not decoration: ServiceParameterGroup::PAPER keys the customer-paper
// rule on the exact string 'Тип паперу'.
//
// The update endpoint existed and was routed all along. Nothing called it.
const showAddGroup  = ref(false)
const editingGroupId = ref(null)   // null while adding, group id while editing

// `ui_style` decides how ConstructorPanel draws the group, and until round 15
// this form did not send it: every group added through the admin stayed on the
// column default `chips` for good, and changing it meant SQL. The column, the
// reader and the validation rule were all in place — only the control was
// missing.
const uiStyleLabels = {
    chips:       'Чіпи (компактно)',
    tiles_large: 'Великі плитки',
    tiles_small: 'Малі плитки',
    dropdown:    'Випадайка',
}

const groupForm = useForm({ name: '', ui_type: 'radio', ui_style: 'chips', is_required: true, sort_order: 0 })

function openAddGroup() {
    editingGroupId.value = null
    groupForm.reset()
    showAddGroup.value = true
}

function openEditGroup(group) {
    editingGroupId.value  = group.id
    groupForm.name        = group.name
    groupForm.ui_type     = group.ui_type ?? 'radio'
    groupForm.ui_style    = group.ui_style ?? 'chips'
    groupForm.is_required = group.is_required
    groupForm.sort_order  = group.sort_order ?? 0
    showAddGroup.value    = true
}

function closeGroupForm() {
    showAddGroup.value   = false
    editingGroupId.value = null
    groupForm.reset()
}

// Renaming this one group turns off the "папір замовника" rule, because the
// rule is keyed on the literal name and there is no flag column. Until
// this round the rename was impossible, so the trap was unreachable; now it is
// reachable and has to be visible. The server still logs a warning if the rule
// stops matching.
const renamingThePaperGroup = computed(() =>
    editingGroupId.value !== null
    && props.service.parameter_groups?.some(g => g.id === editingGroupId.value && g.name === PAPER_GROUP)
    && groupForm.name !== PAPER_GROUP
)

function saveGroup() {
    // depends_on is deliberately not on this form, so it is not submitted and
    // the server leaves it alone — the same contract as the option form below.
    if (editingGroupId.value) {
        groupForm.patch(route('admin.service-groups.update', editingGroupId.value), {
            preserveScroll: true,
            onSuccess: closeGroupForm,
        })
        return
    }

    groupForm.post(route('admin.service-groups.store', props.service.id), {
        onSuccess: closeGroupForm,
    })
}

function deleteGroup(group) {
    if (confirm(
        `Видалити групу «${group.name}» та всі її опції?\n\n`
        + 'Щоб змінити назву, порядок або тип — натисніть «Ред.». Видаляти й '
        + 'створювати заново не треба, і це ламає опції, які залежать від цієї групи.'
    )) {
        router.delete(route('admin.service-groups.destroy', group.id))
    }
}

// ─── Add / Edit Option ────────────────────────────────────
//
// Editing used to be missing entirely: the form could only add and delete, so
// changing a price meant deleting the option and creating it again. That mints
// a new id, and options in other groups point at ids through depends_on — the
// lamination films hang off a format option, the hard-binding colours hang off
// a size. Re-creating a parent silently orphans every child.
//
// The update endpoint existed and was routed all along. Nothing called it.
const addingOptionFor = ref(null)   // group id whose form is open
const editingOptionId = ref(null)   // null while adding, option id while editing

const optionForm = useForm({
    name:               '',
    price_markup:       0,
    inventory_item_id:  null,
    inventory_qty:      1,
    counter_type:       'bw',
    clicks_per_unit:    1,
    is_active:          true,
})

function openAddOption(group) {
    addingOptionFor.value = group.id
    editingOptionId.value = null
    optionForm.reset()
    optionForm.counter_type   = 'bw'
    optionForm.clicks_per_unit = 1
    optionForm.inventory_qty  = 1
    optionForm.is_active      = true
}

function openEditOption(group, option) {
    addingOptionFor.value = group.id
    editingOptionId.value = option.id

    optionForm.name              = option.name
    optionForm.price_markup      = option.price_markup
    optionForm.inventory_item_id = option.inventory_item_id
    optionForm.inventory_qty     = option.inventory_qty ?? 1
    optionForm.counter_type      = option.counter_type ?? 'none'
    optionForm.clicks_per_unit   = option.clicks_per_unit ?? 0
    optionForm.is_active         = option.is_active
}

function closeOptionForm() {
    addingOptionFor.value = null
    editingOptionId.value = null
    optionForm.reset()
}

function saveOption(group) {
    // depends_on is deliberately not in this form, so it is not submitted and
    // the server leaves it alone.
    if (editingOptionId.value) {
        optionForm.patch(route('admin.service-options.update', editingOptionId.value), {
            preserveScroll: true,
            onSuccess: closeOptionForm,
        })
        return
    }

    optionForm.post(route('admin.service-options.store', group.id), {
        preserveScroll: true,
        onSuccess: closeOptionForm,
    })
}

function deleteOption(option) {
    if (confirm(
        `Видалити опцію «${option.name}»?\n\n`
        + 'Щоб змінити ціну, натисніть «Ред.» — видаляти й створювати заново '
        + 'не треба, і це може зламати опції, які залежать від цієї.'
    )) {
        router.delete(route('admin.service-options.destroy', option.id))
    }
}

// ─── Cost preview (live calculation in UI) ───────────────
function previewCost(invItemId, invQty, counterType, clicks) {
    let consumables = 0
    let amortization = 0

    if (invItemId && invQty > 0) {
        const item = props.inventoryItems.find(i => i.id === invItemId)
        if (item) consumables = parseFloat(item.avg_cost) * parseFloat(invQty)
    }

    if (counterType && counterType !== 'none' && clicks > 0) {
        const mat = props.materials.find(m => m.counter_type === counterType)
        if (mat) amortization = parseFloat(mat.click_cost) * parseInt(clicks)
    }

    return {
        consumables:  consumables.toFixed(4),
        amortization: amortization.toFixed(4),
        total:        (consumables + amortization).toFixed(4),
    }
}

const optionCostPreview = computed(() =>
    previewCost(
        optionForm.inventory_item_id,
        optionForm.inventory_qty,
        optionForm.counter_type,
        optionForm.clicks_per_unit,
    )
)
</script>

<template>
    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-6">

            <!-- Header -->
            <div class="flex items-center gap-3">
                <a :href="route('admin.services.index')"
                   class="text-gray-600 hover:text-gray-800 text-sm">← Назад до послуг</a>
            </div>
            <h1 class="page-header-title">Редагування: {{ service.name }}</h1>

            <!-- ─── Base service info ─────────────────────────────── -->
            <div class="card p-6">
                <h2 class="text-base font-semibold mb-4 text-gray-700">Основні параметри</h2>
                <form @submit.prevent="saveBase" class="space-y-4">
                    <div>
                        <input aria-label="Назва послуги" v-model="baseForm.name" class="input" placeholder="Назва послуги *" required />
                        <p v-if="baseForm.errors.name" class="text-xs text-red-600 mt-1">{{ baseForm.errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-gray-600 mb-1 block">Категорія</label>
                            <select aria-label="Категорія" v-model="baseForm.service_category_id" class="input">
                                <option :value="null">— Без категорії (Інше) —</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                            <p v-if="baseForm.errors.service_category_id" class="text-xs text-red-600 mt-1">{{ baseForm.errors.service_category_id }}</p>
                        </div>
                        <div>
                            <label class="text-xs text-gray-600 mb-1 block">Тип</label>
                            <!--
                                Read-only on purpose. UpdateServiceRequest omits `type` so the
                                type cannot change after creation — historical orders were priced
                                by it. The dropdown that used to stand here changed the form in
                                front of the admin, saved with "Послугу оновлено", and left the
                                type exactly as it was.
                            -->
                            <div aria-label="Тип" class="input bg-gray-50 text-gray-700 cursor-not-allowed">
                                {{ serviceTypeLabels[service.type] ?? service.type }}
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Тип не змінюється після створення послуги.</p>
                        </div>
                    </div>

                    <!-- Static: counter info -->
                    <div v-if="service.type === 'static'" class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs text-gray-600 mb-1 block">Комерційна ціна (грн)</label>
                            <input aria-label="Комерційна ціна (грн)" v-model="baseForm.base_price_commercial" type="number" step="0.01" min="0" class="input" />
                            <p v-if="baseForm.errors.base_price_commercial" class="text-xs text-red-600 mt-1">{{ baseForm.errors.base_price_commercial }}</p>
                        </div>
                        <div>
                            <label class="text-xs text-gray-600 mb-1 block">Собівартість (грн)</label>
                            <input aria-label="Собівартість (грн)" v-model="baseForm.base_price_cost" type="number" step="0.01" min="0" class="input" />
                            <p v-if="baseForm.errors.base_price_cost" class="text-xs text-red-600 mt-1">{{ baseForm.errors.base_price_cost }}</p>
                        </div>
                        <div>
                            <label class="text-xs text-gray-600 mb-1 block">Лічильник</label>
                            <select aria-label="Лічильник" v-model="baseForm.counter_type" class="input">
                                <option value="bw">Ч/Б</option>
                                <option value="color">Колір</option>
                                <option value="riso">Ризограф</option>
                                <option value="none">Без лічильника</option>
                            </select>
                            <p v-if="baseForm.errors.counter_type" class="text-xs text-red-600 mt-1">{{ baseForm.errors.counter_type }}</p>
                            <p v-if="baseForm.errors.clicks_per_unit" class="text-xs text-red-600 mt-1">{{ baseForm.errors.clicks_per_unit }}</p>
                        </div>
                    </div>

                    <!-- Constructor: info only (pricing comes from options) -->
                    <div v-else-if="service.type === 'constructor'"
                         class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-700">
                        Ціна та собівартість Конструктора формуються опціями. Налаштуйте групи параметрів нижче.
                    </div>

                    <!-- Riso: no price fields needed -->
                    <div v-else-if="service.type === 'riso'" class="bg-indigo-50 border border-indigo-200 rounded-lg px-4 py-3 text-sm text-indigo-700">
                        Ціноутворення для ризографа визначається тарифною сіткою.
                        <a :href="route('admin.riso-pricing.index')" class="underline font-medium">Налаштувати тарифи →</a>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="baseForm.is_active" type="checkbox" class="rounded" /> Активна
                    </label>

                    <button type="submit" class="btn-primary" :disabled="baseForm.processing">Зберегти зміни</button>
                </form>
            </div>

            <!-- ─── Parameter Groups (Constructor) ──────────────────── -->
            <div v-if="service.type === 'constructor'" class="card p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-700">Групи параметрів</h2>
                        <p class="text-xs text-gray-600 mt-0.5">Собівартість кожної опції = розхідники зі складу + амортизація принтера</p>
                    </div>
                    <button @click="openAddGroup" class="btn-primary text-sm">+ Додати групу</button>
                </div>

                <!-- Add / edit group form -->
                <div v-if="showAddGroup" class="bg-gray-50 rounded-xl p-4 space-y-3 border border-gray-200">
                    <p class="text-sm font-medium text-gray-700">
                        {{ editingGroupId ? 'Редагування групи параметрів' : 'Нова група параметрів' }}
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <input aria-label="Назва групи (напр. «Сторонність»)" v-model="groupForm.name" class="input" placeholder="Назва групи (напр. «Сторонність»)" />
                            <p v-if="groupForm.errors.name" class="text-xs text-red-600 mt-1">{{ groupForm.errors.name }}</p>
                        </div>
                        <div>
                            <select aria-label="Тип відображення групи" v-model="groupForm.ui_type" class="input">
                                <option value="radio">Один вибір (Radio)</option>
                                <option value="checkbox">Множинний (Checkbox)</option>
                            </select>
                            <p v-if="groupForm.errors.ui_type" class="text-xs text-red-600 mt-1">{{ groupForm.errors.ui_type }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-gray-600 mb-1 block">Вигляд у конструкторі</label>
                        <select aria-label="Вигляд у конструкторі" v-model="groupForm.ui_style" class="input">
                            <option v-for="(label, value) in uiStyleLabels" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <p v-if="groupForm.errors.ui_style" class="text-xs text-red-600 mt-1">{{ groupForm.errors.ui_style }}</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="groupForm.is_required" type="checkbox" class="rounded" /> Обов'язкова
                        </label>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-600">Порядок:</span>
                            <input aria-label="Порядок сортування" v-model="groupForm.sort_order" type="number" min="0" class="input w-20 text-sm" />
                        </div>
                    </div>
                    <p v-if="groupForm.errors.sort_order" class="text-xs text-red-600">{{ groupForm.errors.sort_order }}</p>
                    <p v-if="groupForm.errors.is_required" class="text-xs text-red-600">{{ groupForm.errors.is_required }}</p>
                    <p v-if="renamingThePaperGroup" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        Увага: правило «папір замовника» знаходить цю групу за назвою «{{ PAPER_GROUP }}».
                        Після перейменування воно перестане обнуляти папір у замовленнях.
                    </p>
                    <div class="flex gap-2">
                        <button @click="saveGroup" class="btn-primary text-sm" :disabled="groupForm.processing">
                            {{ editingGroupId ? 'Зберегти зміни' : 'Зберегти групу' }}
                        </button>
                        <button @click="closeGroupForm" class="btn-ghost text-sm">Скасувати</button>
                    </div>
                </div>

                <!-- Existing groups -->
                <div v-if="service.parameter_groups && service.parameter_groups.length" class="space-y-4">
                    <div v-for="group in service.parameter_groups" :key="group.id"
                         class="border border-gray-200 rounded-xl overflow-hidden">

                        <!-- Group header -->
                        <div class="flex items-center justify-between bg-gray-50 px-4 py-2">
                            <div>
                                <span class="font-medium text-sm text-gray-800">{{ group.name }}</span>
                                <span class="ml-2 text-xs text-gray-600">
                                    {{ group.ui_type === 'radio' ? 'Один вибір' : 'Множинний' }}
                                    · {{ uiStyleLabels[group.ui_style] ?? group.ui_style ?? 'Чіпи (компактно)' }}
                                    {{ group.is_required ? '· Обов\'язкова' : '' }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="openAddOption(group)" class="text-xs text-indigo-600 hover:underline font-medium">+ Додати опцію</button>
                                <button @click="openEditGroup(group)" class="text-xs text-gray-600 hover:text-gray-800 hover:underline">Ред.</button>
                                <button @click="deleteGroup(group)" class="text-xs text-red-600 hover:text-red-800">Видалити групу</button>
                            </div>
                        </div>

                        <!-- Add option form -->
                        <div v-if="addingOptionFor === group.id" class="bg-indigo-50 px-4 py-4 space-y-3 border-b border-indigo-100">
                            <p class="text-xs font-semibold text-indigo-700 uppercase tracking-wide">
                                {{ editingOptionId ? 'Редагування опції' : 'Нова опція' }}
                            </p>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-gray-600 mb-1 block">Назва опції *</label>
                                    <input aria-label="Назва опції" v-model="optionForm.name" class="input text-sm" placeholder="напр. Одностороннє" />
                                    <p v-if="optionForm.errors.name" class="text-xs text-red-600 mt-1">{{ optionForm.errors.name }}</p>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-600 mb-1 block">Ціна для клієнта (+грн)</label>
                                    <input aria-label="Ціна для клієнта (+грн)" v-model="optionForm.price_markup" type="number" step="0.01" min="0" class="input text-sm" />
                                    <p v-if="optionForm.errors.price_markup" class="text-xs text-red-600 mt-1">{{ optionForm.errors.price_markup }}</p>
                                </div>
                            </div>

                            <!-- Cost components -->
                            <div class="border border-indigo-200 rounded-lg p-3 space-y-3 bg-white">
                                <p class="text-xs font-semibold text-indigo-600">Компоненти собівартості</p>

                                <!-- Consumables -->
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-600 mb-1 block">Розхідник зі складу</label>
                                        <select aria-label="Розхідник зі складу" v-model="optionForm.inventory_item_id" class="input text-sm">
                                            <option :value="null">— Без розхідника —</option>
                                            <option v-for="item in inventoryItems" :key="item.id" :value="item.id">
                                                {{ item.name }} ({{ parseFloat(item.avg_cost).toFixed(2) }} грн/{{ item.unit }})
                                            </option>
                                        </select>
                                        <p v-if="optionForm.errors.inventory_item_id" class="text-xs text-red-600 mt-1">{{ optionForm.errors.inventory_item_id }}</p>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-600 mb-1 block">Кількість на 1 од. послуги</label>
                                        <input aria-label="Кількість на 1 од. послуги" v-model="optionForm.inventory_qty" type="number" step="0.01" min="0" class="input text-sm" />
                                        <p v-if="optionForm.errors.inventory_qty" class="text-xs text-red-600 mt-1">{{ optionForm.errors.inventory_qty }}</p>
                                    </div>
                                </div>

                                <!-- Amortization -->
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-600 mb-1 block">Тип принтера (амортизація)</label>
                                        <select aria-label="Тип принтера (амортизація)" v-model="optionForm.counter_type" class="input text-sm">
                                            <option value="none">— Без принтера —</option>
                                            <option value="bw">Ч/Б принтер</option>
                                            <option value="color">Кольоровий принтер</option>
                                            <option value="riso">Ризограф</option>
                                        </select>
                                        <p v-if="optionForm.counter_type !== 'none'" class="text-xs text-gray-600 mt-1">
                                            Ставка: {{ parseFloat(materials.find(m => m.counter_type === optionForm.counter_type)?.click_cost ?? 0).toFixed(2) }} грн/клік
                                        </p>
                                        <p v-if="optionForm.errors.counter_type" class="text-xs text-red-600 mt-1">{{ optionForm.errors.counter_type }}</p>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-600 mb-1 block">Кількість кліків на 1 одиницю</label>
                                        <input aria-label="Кількість кліків на 1 одиницю" v-model="optionForm.clicks_per_unit" type="number" min="0" class="input text-sm"
                                               :disabled="optionForm.counter_type === 'none'" />
                                        <p v-if="optionForm.errors.clicks_per_unit" class="text-xs text-red-600 mt-1">{{ optionForm.errors.clicks_per_unit }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Cost preview -->
                            <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                                <p class="text-xs font-semibold text-green-700 mb-1">Авто-розрахунок собівартості</p>
                                <div class="text-xs text-gray-600 space-y-0.5">
                                    <div>Розхідники: <strong>{{ optionCostPreview.consumables }} грн</strong></div>
                                    <div>Амортизація: <strong>{{ optionCostPreview.amortization }} грн</strong></div>
                                    <div class="border-t border-green-200 pt-1 mt-1 text-sm font-bold text-green-800">
                                        Разом собівартість: {{ optionCostPreview.total }} грн
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <button @click="saveOption(group)" class="btn-primary text-xs" :disabled="optionForm.processing">
                                    {{ editingOptionId ? 'Зберегти зміни' : 'Зберегти опцію' }}
                                </button>
                                <button @click="closeOptionForm" class="btn-ghost text-xs">Скасувати</button>
                            </div>
                        </div>

                        <!-- Options list -->
                        <div v-if="group.options && group.options.length" class="divide-y divide-gray-100">
                            <div v-for="opt in group.options" :key="opt.id"
                                 class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-medium text-gray-800">{{ opt.name }}</span>
                                    <span class="text-xs text-indigo-600 font-mono bg-indigo-50 px-2 py-0.5 rounded">
                                        +{{ parseFloat(opt.price_markup).toFixed(2) }} грн ціна
                                    </span>
                                    <span class="text-xs text-gray-600 bg-gray-100 px-2 py-0.5 rounded">
                                        собів. {{ parseFloat(opt.cost_markup).toFixed(2) }} грн
                                    </span>
                                    <span v-if="opt.inventory_item" class="text-xs text-amber-700 bg-amber-50 px-2 py-0.5 rounded">
                                        {{ opt.inventory_item.name }} × {{ opt.inventory_qty }}
                                    </span>
                                    <span v-if="opt.clicks_per_unit > 0" class="text-xs text-blue-500">
                                        {{ opt.clicks_per_unit }} кл. {{ opt.counter_type }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button @click="openEditOption(group, opt)"
                                            class="text-xs text-indigo-600 hover:underline">
                                        Ред.
                                    </button>
                                    <button @click="deleteOption(opt)"
                                            :aria-label="`Видалити опцію ${opt.name}`"
                                            class="text-xs text-red-600 hover:text-red-800"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-4 py-3 text-xs text-gray-600 italic">
                            Опцій ще немає — натисніть "+ Додати опцію"
                        </div>
                    </div>
                </div>
                <div v-else class="text-sm text-gray-600 italic text-center py-4">
                    Груп параметрів ще немає. Натисніть "+ Додати групу".
                </div>
            </div>

            <div v-else-if="service.type === 'riso'" class="card p-4 text-sm text-indigo-600 text-center">
                Послуга ризографа використовує тарифну сітку.
                <a :href="route('admin.riso-pricing.index')" class="underline font-medium">Налаштувати тарифи →</a>
            </div>

            <div v-else class="card p-4 text-sm text-gray-600 text-center">
                Групи параметрів доступні лише для послуг типу <strong>Конструктор</strong>. Змініть тип вище та збережіть.
            </div>

        </div>
    </AppLayout>
</template>
