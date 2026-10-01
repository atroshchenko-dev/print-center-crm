<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { KYIV_TZ } from '@/constants'

const props = defineProps({
    signatoryGroups:   Array,
    signatories:       Array,
    departments:       Array,
    serviceCategories: Array,
})

// ─── Tabs ─────────────────────────────────────────────
const activeTab = ref('groups')

// Days in the current Kyiv month — the multiplier the server uses to turn the
// daily figure into the pool it actually enforces. Kyiv, not the browser's
// zone: between midnight and 03:00 they can name different months.
const daysInThisMonth = computed(() => {
    const kyivNow = new Date(new Date().toLocaleString('en-US', { timeZone: KYIV_TZ }))
    return new Date(kyivNow.getFullYear(), kyivNow.getMonth() + 1, 0).getDate()
})

// ─── Group form ──────────────────────────────────────
const showGroupForm = ref(false)
const editingGroupId = ref(null)

const groupForm = useForm({
    name:         '',
    daily_limit:  null,
    is_active:    true,
    category_ids: [],
})

function openCreateGroup() {
    groupForm.reset(); groupForm.is_active = true; groupForm.category_ids = []
    editingGroupId.value = null; showGroupForm.value = true
}
function openEditGroup(g) {
    groupForm.name         = g.name
    groupForm.daily_limit  = g.daily_limit
    groupForm.is_active    = g.is_active
    groupForm.category_ids = (g.categories || []).map(c => c.id)
    editingGroupId.value   = g.id; showGroupForm.value = true
}
function submitGroup() {
    const opts = { onSuccess: () => { showGroupForm.value = false } }
    editingGroupId.value
        ? groupForm.patch(route('admin.university.groups.update', editingGroupId.value), opts)
        : groupForm.post(route('admin.university.groups.store'), opts)
}
function deleteGroup(g) {
    if (confirm(`Деактивувати групу «${g.name}»?`))
        router.delete(route('admin.university.groups.destroy', g.id))
}

// ─── Signatory form ───────────────────────────────────
const showSignForm = ref(false)
const editingSignId = ref(null)

const signForm = useForm({
    full_name:          '',
    position:           '',
    email:              '',
    is_active:          true,
    signatory_group_id: null,
})

const activeGroups = computed(() =>
    (props.signatoryGroups || []).filter(g => g.is_active && !g.deleted_at)
)

function openCreateSign() {
    signForm.reset(); signForm.is_active = true; signForm.signatory_group_id = null; signForm.email = ''
    editingSignId.value = null; showSignForm.value = true
}
function openEditSign(s) {
    signForm.full_name          = s.full_name
    signForm.position           = s.position ?? ''
    signForm.email              = s.email ?? ''
    signForm.is_active          = s.is_active
    signForm.signatory_group_id = s.signatory_group_id
    editingSignId.value = s.id; showSignForm.value = true
}
function submitSign() {
    const opts = { onSuccess: () => { showSignForm.value = false } }
    editingSignId.value
        ? signForm.patch(route('admin.university.signatories.update', editingSignId.value), opts)
        : signForm.post(route('admin.university.signatories.store'), opts)
}
function deleteSign(s) {
    if (confirm(`Деактивувати «${s.full_name}»?`))
        router.delete(route('admin.university.signatories.destroy', s.id))
}

function groupName(groupId) {
    const g = (props.signatoryGroups || []).find(g => g.id === groupId)
    return g ? g.name : '—'
}

// ─── Department form ──────────────────────────────────
const showDeptForm = ref(false)
const editingDeptId = ref(null)

const deptForm = useForm({
    name:      '',
    type:      'department',
    is_active: true,
})

function openCreateDept() {
    deptForm.reset(); deptForm.type = 'department'; deptForm.is_active = true
    editingDeptId.value = null; showDeptForm.value = true
}
function openEditDept(d) {
    deptForm.name      = d.name
    deptForm.type      = d.type
    deptForm.is_active = d.is_active
    editingDeptId.value = d.id; showDeptForm.value = true
}
function submitDept() {
    const opts = { onSuccess: () => { showDeptForm.value = false } }
    editingDeptId.value
        ? deptForm.patch(route('admin.university.departments.update', editingDeptId.value), opts)
        : deptForm.post(route('admin.university.departments.store'), opts)
}
function deleteDept(d) {
    if (confirm(`Деактивувати «${d.name}»?`))
        router.delete(route('admin.university.departments.destroy', d.id))
}

const typeLabels = { department: 'Підрозділ', project: 'Проект' }

// ─── Signatory cost centres ───────────────────────────
const expandedSignId = ref(null)
const addCentreForm = useForm({ department_id: null })

const toggleCentres = (id) => {
    expandedSignId.value = expandedSignId.value === id ? null : id
    addCentreForm.department_id = null
    addCentreForm.clearErrors()
}

const activeDepartments = computed(() =>
    (props.departments || []).filter(d => d.is_active && !d.deleted_at)
)

const addCentre = (s) => {
    if (! addCentreForm.department_id) return
    addCentreForm.post(route('admin.university.signatories.cost-centers.store', s.id), {
        preserveScroll: true,
        onSuccess: () => { addCentreForm.department_id = null },
    })
}

const removeCentre = (s, departmentId) => {
    router.delete(route('admin.university.signatories.cost-centers.destroy', [s.id, departmentId]), {
        preserveScroll: true,
    })
}

// ─── Cost centre initiators ───────────────────────────
const expandedDeptId = ref(null)
const addInitiatorForm = useForm({ initiator: '' })

const toggleInitiators = (id) => {
    expandedDeptId.value = expandedDeptId.value === id ? null : id
    addInitiatorForm.initiator = ''
    addInitiatorForm.clearErrors()
}

const addInitiator = (d) => {
    if (! addInitiatorForm.initiator.trim()) return
    addInitiatorForm.post(route('admin.university.departments.initiators.store', d.id), {
        preserveScroll: true,
        onSuccess: () => { addInitiatorForm.initiator = '' },
    })
}

const removeInitiator = (d, initiatorId) => {
    router.delete(route('admin.university.departments.initiators.destroy', [d.id, initiatorId]), {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-purple-400 to-indigo-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 10 3 12 0v-5"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Довідник «Університет»</h1>
                        <p class="page-header-subtitle">Групи, уповноважені особи та центри витрат</p>
                    </div>
                </div>
                <button
                    v-if="activeTab === 'groups'"
                    @click="openCreateGroup"
                    class="btn-primary w-full sm:w-auto"
                >+ Додати групу</button>
                <button
                    v-else-if="activeTab === 'signatories'"
                    @click="openCreateSign"
                    class="btn-primary w-full sm:w-auto"
                >+ Додати особу</button>
                <button
                    v-else
                    @click="openCreateDept"
                    class="btn-primary w-full sm:w-auto"
                >+ Додати центр витрат</button>
            </div>

            <!-- Tabs -->
            <div class="flex border-b border-gray-200 mb-4">
                <button
                    :class="[
                        'px-6 py-2.5 text-sm font-semibold border-b-2 transition',
                        activeTab === 'groups'
                            ? 'text-indigo-700 border-indigo-600'
                            : 'text-gray-600 border-transparent hover:text-gray-700'
                    ]"
                    @click="activeTab = 'groups'"
                >Групи доступу</button>
                <button
                    :class="[
                        'px-6 py-2.5 text-sm font-semibold border-b-2 transition',
                        activeTab === 'signatories'
                            ? 'text-indigo-700 border-indigo-600'
                            : 'text-gray-600 border-transparent hover:text-gray-700'
                    ]"
                    @click="activeTab = 'signatories'"
                >Уповноважені особи</button>
                <button
                    :class="[
                        'px-6 py-2.5 text-sm font-semibold border-b-2 transition',
                        activeTab === 'departments'
                            ? 'text-indigo-700 border-indigo-600'
                            : 'text-gray-600 border-transparent hover:text-gray-700'
                    ]"
                    @click="activeTab = 'departments'"
                >Центри витрат</button>
            </div>

            <!-- ══════════ Groups Tab ══════════ -->
            <div v-if="activeTab === 'groups'" class="card p-0 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3">Назва групи</th>
                            <th scope="col" class="px-4 py-3">Категорії послуг</th>
                            <th scope="col" class="px-4 py-3">Денний ліміт</th>
                            <th scope="col" class="px-4 py-3">Осіб</th>
                            <th scope="col" class="px-4 py-3">Статус</th>
                            <th scope="col" class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="g in signatoryGroups" :key="g.id"
                            :class="{ 'opacity-50': g.deleted_at }"
                            class="table-row-hover">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ g.name }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="cat in (g.categories || [])" :key="cat.id"
                                        class="inline-block px-2 py-0.5 text-xs rounded-full bg-indigo-50 text-indigo-700">
                                        {{ cat.name }}
                                    </span>
                                    <span v-if="!g.categories?.length" class="text-xs text-gray-600">—</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <!--
                                    Both numbers, because the daily one is an
                                    input now and the monthly one is the rule the
                                    server applies: the pool may be spent in a
                                    single day.
                                -->
                                <template v-if="g.daily_limit">
                                    <span class="text-sm font-medium text-amber-700">
                                        {{ g.daily_limit }} коп./день
                                    </span>
                                    <span class="block text-xs text-gray-600">
                                        {{ g.monthly_limit }} на місяць
                                    </span>
                                </template>
                                <span v-else class="text-xs text-gray-600">Без ліміту</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-700">
                                    {{ g.signatories_count ?? 0 }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="g.is_active && !g.deleted_at
                                    ? 'badge bg-green-100 text-green-700'
                                    : 'badge bg-red-100 text-red-600'">
                                    {{ g.deleted_at ? 'Видалено' : g.is_active ? 'Активна' : 'Неактивна' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="!g.deleted_at" class="flex gap-2">
                                    <button @click="openEditGroup(g)" class="text-xs text-indigo-600 hover:underline">Ред.</button>
                                    <button @click="deleteGroup(g)" class="text-xs text-red-600 hover:text-red-800">Видалити</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="signatoryGroups.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-600">
                                Немає груп. Додайте першу.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ══════════ Signatories Tab ══════════ -->
            <div v-if="activeTab === 'signatories'" class="card p-0 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3">ПІБ</th>
                            <th scope="col" class="px-4 py-3">Посада</th>
                            <th scope="col" class="px-4 py-3">Email</th>
                            <th scope="col" class="px-4 py-3">Група</th>
                            <th scope="col" class="px-4 py-3">Статус</th>
                            <th scope="col" class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <template v-for="s in signatories" :key="s.id">
                        <tr :class="{ 'opacity-50': s.deleted_at }"
                            class="table-row-hover">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ s.full_name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ s.position ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span v-if="s.email" class="text-xs text-blue-600">{{ s.email }}</span>
                                <span v-else class="text-xs text-gray-600">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="s.group" class="inline-block px-2.5 py-1 text-xs rounded-full bg-violet-50 text-violet-700 font-medium">
                                    {{ s.group.name }}
                                </span>
                                <span v-else class="text-xs text-gray-600">Без групи</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="s.is_active && !s.deleted_at
                                    ? 'badge bg-green-100 text-green-700'
                                    : 'badge bg-red-100 text-red-600'">
                                    {{ s.deleted_at ? 'Видалено' : s.is_active ? 'Активний' : 'Неактивний' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button type="button" class="btn btn-ghost text-xs"
                                    @click="toggleCentres(s.id)">
                                    Центри витрат ({{ (s.cost_centers || []).length }})
                                </button>
                                <div v-if="!s.deleted_at" class="flex gap-2">
                                    <button @click="openEditSign(s)" class="text-xs text-indigo-600 hover:underline">Ред.</button>
                                    <button @click="deleteSign(s)" class="text-xs text-red-600 hover:text-red-800">Видалити</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expandedSignId === s.id" class="bg-gray-50">
                            <td colspan="99" class="px-4 py-3">
                                <p class="text-xs text-gray-600 mb-2">
                                    Центри витрат накопичуються з оформлених замовлень.
                                    Видалена пара повернеться, якщо замовлення повториться.
                                </p>
                                <ul class="space-y-1 mb-3">
                                    <li v-for="c in (s.cost_centers || [])" :key="c.id"
                                        class="flex items-center gap-2 text-sm">
                                        <span class="flex-1">{{ c.name }}</span>
                                        <span class="text-xs text-gray-500">
                                            {{ c.orders_count }} замовл.<template v-if="c.last_used_at">, востаннє {{ c.last_used_at }}</template>
                                        </span>
                                        <button type="button" class="btn btn-ghost text-xs"
                                            @click="removeCentre(s, c.department_id)">✕</button>
                                    </li>
                                    <li v-if="(s.cost_centers || []).length === 0" class="text-sm text-gray-500">
                                        Жодного центру витрат ще не назбиралось.
                                    </li>
                                </ul>
                                <div class="flex items-center gap-2">
                                    <select v-model="addCentreForm.department_id" class="input text-sm" aria-label="Додати центр витрат">
                                        <option :value="null" disabled>— Оберіть центр витрат —</option>
                                        <option v-for="d in activeDepartments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                    </select>
                                    <button type="button" class="btn btn-primary text-xs" @click="addCentre(s)">Додати</button>
                                </div>
                                <p v-if="addCentreForm.errors.department_id" class="text-red-600 text-xs mt-1">{{ addCentreForm.errors.department_id }}</p>
                            </td>
                        </tr>
                        </template>
                        <tr v-if="signatories.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-gray-600">
                                Немає уповноважених осіб. Додайте першу.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ══════════ Departments Tab ══════════ -->
            <div v-if="activeTab === 'departments'" class="card p-0 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3">Назва</th>
                            <th scope="col" class="px-4 py-3">Тип</th>
                            <th scope="col" class="px-4 py-3">Статус</th>
                            <th scope="col" class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <template v-for="d in departments" :key="d.id">
                        <tr :class="{ 'opacity-50': d.deleted_at }"
                            class="table-row-hover">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ d.name }}</td>
                            <td class="px-4 py-3">{{ typeLabels[d.type] }}</td>
                            <td class="px-4 py-3">
                                <span :class="d.is_active && !d.deleted_at
                                    ? 'badge bg-green-100 text-green-700'
                                    : 'badge bg-red-100 text-red-600'">
                                    {{ d.deleted_at ? 'Видалено' : d.is_active ? 'Активний' : 'Неактивний' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button type="button" class="btn btn-ghost text-xs"
                                    @click="toggleInitiators(d.id)">
                                    Ініціатори ({{ (d.initiators || []).length }})
                                </button>
                                <div v-if="!d.deleted_at" class="flex gap-2">
                                    <button @click="openEditDept(d)" class="text-xs text-indigo-600 hover:underline">Ред.</button>
                                    <button @click="deleteDept(d)" class="text-xs text-red-600 hover:text-red-800">Видалити</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expandedDeptId === d.id" class="bg-gray-50">
                            <td colspan="99" class="px-4 py-3">
                                <p class="text-xs text-gray-600 mb-2">
                                    Імена накопичуються з оформлених замовлень.
                                    Видалене повернеться, якщо замовлення повториться.
                                </p>
                                <ul class="space-y-1 mb-3">
                                    <li v-for="i in (d.initiators || [])" :key="i.id"
                                        class="flex items-center gap-2 text-sm">
                                        <span class="flex-1">{{ i.name }}</span>
                                        <span class="text-xs text-gray-500">
                                            {{ i.orders_count }} замовл.<template v-if="i.last_used_at">, востаннє {{ i.last_used_at }}</template>
                                        </span>
                                        <button type="button" class="btn btn-ghost text-xs"
                                            @click="removeInitiator(d, i.id)">✕</button>
                                    </li>
                                    <li v-if="(d.initiators || []).length === 0" class="text-sm text-gray-500">
                                        Жодного ініціатора ще не назбиралось.
                                    </li>
                                </ul>
                                <div class="flex items-center gap-2">
                                    <input v-model="addInitiatorForm.initiator" class="input text-sm"
                                        aria-label="Додати ініціатора" placeholder="Прізвище та ініціали" />
                                    <button type="button" class="btn btn-primary text-xs" @click="addInitiator(d)">Додати</button>
                                </div>
                                <p v-if="addInitiatorForm.errors.initiator" class="text-red-600 text-xs mt-1">
                                    {{ addInitiatorForm.errors.initiator }}
                                </p>
                            </td>
                        </tr>
                        </template>
                        <tr v-if="departments.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-gray-600">
                                Немає центрів витрат. Додайте перший.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ══════════ Group Modal ══════════ -->
            <Teleport to="body">
                <div v-if="showGroupForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                        <h2 class="text-lg font-bold mb-4">
                            {{ editingGroupId ? 'Редагувати групу' : 'Нова група доступу' }}
                        </h2>
                        <form @submit.prevent="submitGroup" class="space-y-3">
                            <div>
                                <input aria-label="Назва групи" v-model="groupForm.name" class="input" placeholder="Назва групи *" required />
                                <p v-if="groupForm.errors.name" class="text-red-600 text-xs mt-1">{{ groupForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Денний ліміт копій (0 або порожньо = без ліміту)</label>
                                <input aria-label="Денний ліміт копій (0 або порожньо = без ліміту)" v-model.number="groupForm.daily_limit" type="number" min="0" class="input" placeholder="Без ліміту" />
                                <p class="text-xs text-gray-600 mt-1">
                                    Витрачається як місячний пул: денна цифра × кількість днів у
                                    місяці. Група на 10 копій/день може надрукувати всі
                                    {{ 10 * daysInThisMonth }} за один день. Перевищення не блокує
                                    збереження — воно потрапляє в журнал аудиту й показується
                                    оператору.
                                </p>
                            </div>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input v-model="groupForm.is_active" type="checkbox" class="rounded" /> Активна
                            </label>
                            <div>
                                <label class="text-xs text-gray-600 mb-2 block">Дозволені категорії послуг</label>
                                <div class="max-h-48 overflow-y-auto border rounded-lg p-2 space-y-1 bg-gray-50">
                                    <label v-for="cat in serviceCategories" :key="cat.id"
                                        class="flex items-center gap-2 text-sm cursor-pointer px-2 py-1.5 rounded hover:bg-white transition">
                                        <input type="checkbox" :value="cat.id"
                                            v-model="groupForm.category_ids"
                                            class="rounded border-gray-300" />
                                        {{ cat.name }}
                                    </label>
                                    <p v-if="serviceCategories.length === 0" class="text-xs text-gray-600 text-center py-2">
                                        Немає активних категорій
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="button" class="btn-ghost flex-1 justify-center border border-gray-200"
                                    @click="showGroupForm = false">Скасувати</button>
                                <button type="submit" class="btn-primary flex-1 justify-center"
                                    :disabled="groupForm.processing">Зберегти</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- ══════════ Signatory Modal ══════════ -->
            <Teleport to="body">
                <div v-if="showSignForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                        <h2 class="text-lg font-bold mb-4">
                            {{ editingSignId ? 'Редагувати особу' : 'Нова уповноважена особа' }}
                        </h2>
                        <form @submit.prevent="submitSign" class="space-y-3">
                            <div>
                                <input aria-label="ПІБ" v-model="signForm.full_name" class="input" placeholder="ПІБ *" required />
                                <p v-if="signForm.errors.full_name" class="text-red-600 text-xs mt-1">{{ signForm.errors.full_name }}</p>
                            </div>
                            <input aria-label="Посада (необов'язково)" v-model="signForm.position" class="input" placeholder="Посада (необов'язково)" />
                            <div>
                                <input aria-label="Email (для сповіщень)" v-model="signForm.email" type="email" class="input" placeholder="Email (для сповіщень)" />
                                <p v-if="signForm.errors.email" class="text-red-600 text-xs mt-1">{{ signForm.errors.email }}</p>
                            </div>
                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Група доступу</label>
                                <select aria-label="Група доступу" v-model="signForm.signatory_group_id" class="input">
                                    <option :value="null">— Без групи —</option>
                                    <option v-for="g in activeGroups" :key="g.id" :value="g.id">
                                        {{ g.name }}
                                    </option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input v-model="signForm.is_active" type="checkbox" class="rounded" /> Активний
                            </label>
                            <div class="flex gap-3 pt-2">
                                <button type="button" class="btn-ghost flex-1 justify-center border border-gray-200"
                                    @click="showSignForm = false">Скасувати</button>
                                <button type="submit" class="btn-primary flex-1 justify-center"
                                    :disabled="signForm.processing">Зберегти</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- ══════════ Department Modal ══════════ -->
            <Teleport to="body">
                <div v-if="showDeptForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                        <h2 class="text-lg font-bold mb-4">
                            {{ editingDeptId ? 'Редагувати центр витрат' : 'Новий центр витрат' }}
                        </h2>
                        <form @submit.prevent="submitDept" class="space-y-3">
                            <div>
                                <input aria-label="Назва" v-model="deptForm.name" class="input" placeholder="Назва *" required />
                                <p v-if="deptForm.errors.name" class="text-red-600 text-xs mt-1">{{ deptForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Тип</label>
                                <select aria-label="Тип" v-model="deptForm.type" class="input">
                                    <option value="department">Підрозділ (кафедра/відділ)</option>
                                    <option value="project">Проект</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input v-model="deptForm.is_active" type="checkbox" class="rounded" /> Активний
                            </label>
                            <div class="flex gap-3 pt-2">
                                <button type="button" class="btn-ghost flex-1 justify-center border border-gray-200"
                                    @click="showDeptForm = false">Скасувати</button>
                                <button type="submit" class="btn-primary flex-1 justify-center"
                                    :disabled="deptForm.processing">Зберегти</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
