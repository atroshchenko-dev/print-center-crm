<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
    services: Array,
    categories: Array,
    allCategories: Array,
    showTrashed: { type: Boolean, default: false },
})

// ─── Tab state ──────────────────────────────────────
const activeTab = ref('services')

// ═══════════════════════════════════════════════════
//  SERVICES TAB
// ═══════════════════════════════════════════════════
const showForm = ref(false)

// Same wording as the edit page, which stopped showing the raw enum in round 15.
// The list kept printing `constructor`/`diploma`/`brochure`/`riso` at the admin
// on a screen that is Ukrainian everywhere else.
const serviceTypeLabels = {
    constructor: 'Конструктор',
    static:      'Статична',
    riso:        'Тиражування',
    brochure:    'Брошура',
    diploma:     'Диплом',
}

const form = useForm({
    name:                  '',
    type:                  'constructor',
    service_category_id:   null,
    base_price_commercial: '',
    base_price_cost:       '',
    counter_type:          'bw',
    clicks_per_unit:       1,
    is_active:             true,
})

function openCreate() { form.reset(); showForm.value = true }

function onTypeChange() {
    if (form.type === 'constructor') {
        form.base_price_cost = 0
        form.counter_type = 'none'
        form.clicks_per_unit = 0
    }
}

// Editing from this modal is gone. It only ever edited the service card, which
// the edit page does too — alongside the parameter groups. Two routes to the
// same row is a trap when Service carries no version column: whoever saves last
// wins, silently. Creating still happens here.
function submit() {
    if (form.type === 'constructor') {
        form.base_price_commercial = form.base_price_commercial || 0
        form.base_price_cost = form.base_price_cost || 0
        form.counter_type = 'none'
        form.clicks_per_unit = 0
    }

    form.post(route('admin.services.store'), {
        onSuccess: () => { showForm.value = false }
    })
}

/**
 * What a service actually costs a customer.
 *
 * A constructor's base price is zero by design — the money is in its options —
 * so the column showed "0.00 грн" on every row and read as "everything is
 * free". Show the span the options actually cover instead.
 */
function priceLabel(svc) {
    const markups = (svc.parameter_groups ?? [])
        .flatMap(g => g.options ?? [])
        .map(o => parseFloat(o.price_markup))
        .filter(v => Number.isFinite(v) && v > 0)

    if (markups.length) {
        const min = Math.min(...markups)
        const max = Math.max(...markups)

        return min === max
            ? `${min.toFixed(2)} грн`
            : `${min.toFixed(2)}–${max.toFixed(2)} грн`
    }

    const base = parseFloat(svc.base_price_commercial)
    if (Number.isFinite(base) && base > 0) {
        return `${base.toFixed(2)} грн`
    }

    return '—'
}

function softDelete(svc) {
    if (confirm(`Деактивувати «${svc.name}»?`)) {
        router.delete(route('admin.services.destroy', svc.id))
    }
}

// ═══════════════════════════════════════════════════
//  CATEGORIES TAB
// ═══════════════════════════════════════════════════
const showCatForm = ref(false)
const catEditingId = ref(null)

const catForm = useForm({
    name: '',
    sort_order: 0,
    is_active: true,
    available_for: ['internal', 'commercial'],
})

function openCatCreate() {
    catForm.reset()
    catEditingId.value = null
    showCatForm.value = true
}

function openCatEdit(cat) {
    catForm.name = cat.name
    catForm.sort_order = cat.sort_order
    catForm.is_active = cat.is_active
    catForm.available_for = cat.available_for ?? ['internal', 'commercial']
    catEditingId.value = cat.id
    showCatForm.value = true
}

function toggleAvailability(type) {
    const idx = catForm.available_for.indexOf(type)
    if (idx >= 0) {
        if (catForm.available_for.length > 1) catForm.available_for.splice(idx, 1)
    } else {
        catForm.available_for.push(type)
    }
}

function submitCategory() {
    if (catEditingId.value) {
        catForm.patch(route('admin.service-categories.update', catEditingId.value), {
            onSuccess: () => { showCatForm.value = false }
        })
    } else {
        catForm.post(route('admin.service-categories.store'), {
            onSuccess: () => { showCatForm.value = false }
        })
    }
}

function deleteCategory(cat) {
    if (confirm(`Деактивувати категорію «${cat.name}»?`)) {
        router.delete(route('admin.service-categories.destroy', cat.id))
    }
}

// ─── Drag & Drop for Categories ─────────────────────
const localCats = ref([...(props.allCategories || [])])
const dragIdx = ref(null)
const dragOverIdx = ref(null)
const saving = ref(false)

watch(() => props.allCategories, (v) => { localCats.value = [...(v || [])] })

function onDragStart(idx) { dragIdx.value = idx }
function onDragOver(e, idx) { e.preventDefault(); if (dragOverIdx.value !== idx) dragOverIdx.value = idx }
function onDragLeave() { dragOverIdx.value = null }
function onDragEnd() { dragIdx.value = null; dragOverIdx.value = null }

function onDrop(e, toIdx) {
    e.preventDefault()
    const fromIdx = dragIdx.value
    if (fromIdx === null || fromIdx === toIdx) { dragIdx.value = null; dragOverIdx.value = null; return }
    const items = [...localCats.value]
    const [moved] = items.splice(fromIdx, 1)
    items.splice(toIdx, 0, moved)
    localCats.value = items
    dragIdx.value = null
    dragOverIdx.value = null
    saveOrder(items)
}

function moveUp(idx) {
    if (idx === 0) return
    const items = [...localCats.value]
    ;[items[idx - 1], items[idx]] = [items[idx], items[idx - 1]]
    localCats.value = items
    saveOrder(items)
}
function moveDown(idx) {
    if (idx >= localCats.value.length - 1) return
    const items = [...localCats.value]
    ;[items[idx], items[idx + 1]] = [items[idx + 1], items[idx]]
    localCats.value = items
    saveOrder(items)
}

async function saveOrder(items) {
    saving.value = true
    try {
        const order = items.map((cat, i) => ({ id: cat.id, sort_order: (i + 1) * 10 }))
        await axios.post(route('admin.service-categories.reorder'), { order })
        items.forEach((cat, i) => { cat.sort_order = (i + 1) * 10 })
    } catch (err) { console.error('Reorder failed:', err) }
    finally { saving.value = false }
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <!-- Header with tabs -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-4">
                    <div class="page-header-icon bg-gradient-to-br from-blue-500 to-indigo-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    </div>
                    <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
                        <button @click="activeTab = 'services'"
                            :class="[
                                'px-4 py-2 text-sm font-semibold rounded-md transition',
                                activeTab === 'services'
                                    ? 'bg-white text-indigo-700 shadow-sm'
                                    : 'text-gray-600 hover:text-gray-800'
                            ]">
                            Послуги
                        </button>
                        <button @click="activeTab = 'categories'"
                            :class="[
                                'px-4 py-2 text-sm font-semibold rounded-md transition',
                                activeTab === 'categories'
                                    ? 'bg-white text-indigo-700 shadow-sm'
                                    : 'text-gray-600 hover:text-gray-800'
                            ]">
                            Категорії
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <span v-if="saving" class="text-xs text-indigo-500 animate-pulse">Зберігаю…</span>
                    <button v-if="activeTab === 'services'" @click="openCreate" class="btn-primary w-full sm:w-auto">+ Додати послугу</button>
                    <button v-else @click="openCatCreate" class="btn-primary w-full sm:w-auto">+ Створити категорію</button>
                </div>
            </div>

            <!-- ═══════════ SERVICES TAB ═══════════ -->
            <template v-if="activeTab === 'services'">
                <div class="mb-2">
                    <button @click="router.get(route('admin.services.index', showTrashed ? {} : { trashed: 1 }))"
                        class="text-xs text-gray-600 hover:text-indigo-600">
                        {{ showTrashed ? 'Сховати видалені' : 'Показати видалені' }}
                    </button>
                </div>

                <!-- Service Form Modal -->
                <Teleport to="body">
                    <div v-if="showForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
                            <h2 class="text-lg font-bold mb-4">
                                Нова послуга
                            </h2>
                            <form @submit.prevent="submit" class="space-y-3">
                                <div>
                                    <input aria-label="Назва" v-model="form.name" class="input" placeholder="Назва *" required />
                                    <p v-if="form.errors.name" class="text-xs text-red-600 mt-1">{{ form.errors.name }}</p>
                                </div>

                                <div>
                                    <label class="text-xs text-gray-600 mb-1 block">Категорія</label>
                                    <select aria-label="Категорія" v-model="form.service_category_id" class="input">
                                        <option :value="null">— Без категорії (Інше) —</option>
                                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                            {{ cat.name }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.service_category_id" class="text-xs text-red-600 mt-1">{{ form.errors.service_category_id }}</p>
                                </div>

                                <div>
                                    <label class="text-xs text-gray-600 mb-1 block">Тип послуги</label>
                                    <select aria-label="Тип послуги" v-model="form.type" class="input" @change="onTypeChange">
                                        <option value="constructor">Конструктор — ціна складається з обраних опцій</option>
                                        <option value="static">Статична — фіксована ціна</option>
                                    </select>
                                    <p v-if="form.errors.type" class="text-xs text-red-600 mt-1">{{ form.errors.type }}</p>
                                </div>

                                <template v-if="form.type === 'static'">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs text-gray-600 mb-1 block">Комерційна ціна (грн)</label>
                                            <input aria-label="Комерційна ціна (грн)" v-model="form.base_price_commercial" type="number" step="0.01" min="0" class="input" required />
                                            <p v-if="form.errors.base_price_commercial" class="text-xs text-red-600 mt-1">{{ form.errors.base_price_commercial }}</p>
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-600 mb-1 block">Собівартість (грн)</label>
                                            <input aria-label="Собівартість (грн)" v-model="form.base_price_cost" type="number" step="0.01" min="0" class="input" required />
                                            <p v-if="form.errors.base_price_cost" class="text-xs text-red-600 mt-1">{{ form.errors.base_price_cost }}</p>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs text-gray-600 mb-1 block">Лічильник</label>
                                            <select aria-label="Лічильник" v-model="form.counter_type" class="input">
                                                <option value="bw">Ч/Б</option>
                                                <option value="color">Кольоровий</option>
                                                <option value="riso">Ризограф</option>
                                                <option value="none">Без лічильника</option>
                                            </select>
                                            <p v-if="form.errors.counter_type" class="text-xs text-red-600 mt-1">{{ form.errors.counter_type }}</p>
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-600 mb-1 block">Кліків/шт</label>
                                            <input aria-label="Кліків/шт" v-model="form.clicks_per_unit" type="number" min="0" class="input" />
                                            <p v-if="form.errors.clicks_per_unit" class="text-xs text-red-600 mt-1">{{ form.errors.clicks_per_unit }}</p>
                                        </div>
                                    </div>
                                </template>

                                <template v-else>
                                    <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-700">
                                        Ціна та собівартість Конструктора формуються опціями.
                                        Налаштуйте <strong>параметри й опції</strong> після збереження послуги.
                                    </div>
                                </template>

                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input v-model="form.is_active" type="checkbox" class="rounded" />
                                    Активна
                                </label>

                                <div class="flex gap-3 pt-2">
                                    <button type="button" class="btn-ghost flex-1 justify-center border border-gray-200"
                                        @click="showForm = false">Скасувати</button>
                                    <button type="submit" class="btn-primary flex-1 justify-center"
                                        :disabled="form.processing">Зберегти</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </Teleport>

                <!-- Services Table -->
                <div class="card p-0 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                                <th scope="col" class="px-4 py-3">Послуга</th>
                                <th scope="col" class="px-4 py-3">Категорія</th>
                                <th scope="col" class="px-4 py-3">Тип</th>
                                <th scope="col" class="px-4 py-3">Ціна</th>
                                <th scope="col" class="px-4 py-3">Лічильник</th>
                                <th scope="col" class="px-4 py-3">Статус</th>
                                <th scope="col" class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="svc in services" :key="svc.id"
                                :class="{ 'opacity-50': svc.deleted_at }"
                                class="table-row-hover">
                                <td class="px-4 py-3 font-medium text-gray-800">{{ svc.name }}</td>
                                <td class="px-4 py-3 text-gray-600">
                                    <span v-if="svc.category" class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded text-xs border border-indigo-100">
                                        {{ svc.category.name }}
                                    </span>
                                    <span v-else class="text-gray-600 text-xs">—</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ serviceTypeLabels[svc.type] ?? svc.type }}</td>
                                <td class="px-4 py-3 font-mono">{{ priceLabel(svc) }}</td>
                                <td class="px-4 py-3 text-gray-600 uppercase text-xs">{{ svc.counter_type }}</td>
                                <td class="px-4 py-3">
                                    <span :class="svc.is_active && !svc.deleted_at
                                        ? 'badge bg-green-100 text-green-700'
                                        : 'badge bg-red-100 text-red-600'">
                                        {{ svc.deleted_at ? 'Видалено' : svc.is_active ? 'Активна' : 'Неактивна' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2" v-if="!svc.deleted_at">
                                        <!--
                                            Two different destinations. The link opens the full
                                            page — card fields plus parameter groups and options.
                                            The button opens a modal with the card fields only,
                                            a subset of the same thing. Both used to read "Ред."
                                            on non-constructor rows, so there was no way to tell
                                            which was which.
                                        -->
                                        <a :href="route('admin.services.edit-page', svc.id)"
                                           class="text-xs text-indigo-600 hover:underline">
                                            {{ svc.type === 'constructor' ? 'Параметри' : 'Редагувати' }}
                                        </a>
                                        <button @click="softDelete(svc)" class="text-xs text-red-600 hover:text-red-800">
                                            Видалити
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ═══════════ CATEGORIES TAB ═══════════ -->
            <template v-if="activeTab === 'categories'">
                <!-- Category Form Modal -->
                <Teleport to="body">
                    <div v-if="showCatForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
                            <h2 class="text-lg font-bold mb-4">
                                {{ catEditingId ? 'Редагувати' : 'Нова' }} категорія
                            </h2>
                            <form @submit.prevent="submitCategory" class="space-y-4">
                                <div>
                                    <label class="text-xs text-gray-600 mb-1 block">Назва категорії</label>
                                    <input aria-label="Назва категорії" v-model="catForm.name" class="input" placeholder="Назва *" required />
                                    <p v-if="catForm.errors.name" class="text-xs text-red-600 mt-1">{{ catForm.errors.name }}</p>
                                </div>

                                <div>
                                    <label class="text-xs text-gray-600 mb-1 block">Порядок сортування</label>
                                    <input aria-label="Порядок сортування" v-model="catForm.sort_order" type="number" class="input" placeholder="0" />
                                    <p v-if="catForm.errors.sort_order" class="text-xs text-red-600 mt-1">{{ catForm.errors.sort_order }}</p>
                                    <span class="text-xs text-gray-600">Менше число = вище у списку</span>
                                </div>

                                <div>
                                    <label class="text-xs text-gray-600 mb-2 block">Доступна для типів замовлень</label>
                                    <div class="flex gap-4">
                                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                                            <input type="checkbox" class="rounded border-gray-300"
                                                :checked="catForm.available_for.includes('internal')"
                                                @change="toggleAvailability('internal')" />
                                            <span class="text-gray-700">Внутрішні</span>
                                        </label>
                                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                                            <input type="checkbox" class="rounded border-gray-300"
                                                :checked="catForm.available_for.includes('commercial')"
                                                @change="toggleAvailability('commercial')" />
                                            <span class="text-gray-700">Комерційні</span>
                                        </label>
                                    </div>
                                    <p v-if="catForm.errors.available_for" class="text-xs text-red-600 mt-1">{{ catForm.errors.available_for }}</p>
                                </div>

                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input v-model="catForm.is_active" type="checkbox" class="rounded border-gray-300" />
                                    <span class="text-gray-700">Активна (відображається на касі)</span>
                                </label>
                                <p v-if="catForm.errors.is_active" class="text-xs text-red-600">{{ catForm.errors.is_active }}</p>

                                <div class="flex gap-3 pt-4 border-t border-gray-100">
                                    <button type="button" class="btn-ghost flex-1 justify-center border border-gray-200"
                                        @click="showCatForm = false">Скасувати</button>
                                    <button type="submit" class="btn-primary flex-1 justify-center"
                                        :disabled="catForm.processing || catForm.available_for.length === 0">Зберегти</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </Teleport>

                <p class="text-xs text-gray-600 mb-3">Перетягніть рядки або використайте стрілки ↕ для зміни порядку</p>

                <!-- Categories Table -->
                <div class="card p-0 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                                <th scope="col" class="px-3 py-3 w-10"></th>
                                <th scope="col" class="px-3 py-3 w-16">Порядок</th>
                                <th scope="col" class="px-6 py-3">Назва</th>
                                <th scope="col" class="px-6 py-3">Доступність</th>
                                <th scope="col" class="px-6 py-3">Статус</th>
                                <th scope="col" class="px-6 py-3 text-right">Дії</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(cat, idx) in localCats" :key="cat.id"
                                :class="[
                                    { 'opacity-50 grayscale bg-gray-50': cat.deleted_at || !cat.is_active },
                                    dragIdx === idx ? 'opacity-30 bg-indigo-50' : '',
                                    dragOverIdx === idx ? 'border-t-2 border-indigo-400' : '',
                                ]"
                                class="hover:bg-gray-50 transition cursor-grab active:cursor-grabbing"
                                draggable="true"
                                @dragstart="onDragStart(idx)"
                                @dragover="onDragOver($event, idx)"
                                @dragleave="onDragLeave"
                                @drop="onDrop($event, idx)"
                                @dragend="onDragEnd"
                            >
                                <!-- Drag handle + arrows -->
                                <td class="px-3 py-4 text-gray-600">
                                    <div class="flex flex-col items-center gap-0.5">
                                        <button type="button" @click.stop="moveUp(idx)" :disabled="idx === 0"
                                            class="text-gray-600 hover:text-indigo-600 disabled:opacity-20 disabled:cursor-not-allowed transition p-0.5">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        </button>
                                        <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="currentColor">
                                            <circle cx="8" cy="8" r="1.5"/><circle cx="16" cy="8" r="1.5"/>
                                            <circle cx="8" cy="12" r="1.5"/><circle cx="16" cy="12" r="1.5"/>
                                            <circle cx="8" cy="16" r="1.5"/><circle cx="16" cy="16" r="1.5"/>
                                        </svg>
                                        <button type="button" @click.stop="moveDown(idx)" :disabled="idx >= localCats.length - 1"
                                            class="text-gray-600 hover:text-indigo-600 disabled:opacity-20 disabled:cursor-not-allowed transition p-0.5">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-4 font-mono text-gray-600 text-center">{{ cat.sort_order }}</td>
                                <td class="px-6 py-4 font-medium text-gray-900">{{ cat.name }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex gap-1">
                                        <span v-if="cat.available_for?.includes('internal')"
                                            class="badge bg-blue-100 text-blue-700">Внутр.</span>
                                        <span v-if="cat.available_for?.includes('commercial')"
                                            class="badge bg-amber-100 text-amber-700">Комерц.</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span :class="cat.is_active && !cat.deleted_at
                                        ? 'badge bg-green-100 text-green-700'
                                        : 'badge bg-gray-100 text-gray-600'">
                                        {{ cat.deleted_at ? 'Видалена' : cat.is_active ? 'Активна' : 'Неактивна' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex justify-end gap-3" v-if="!cat.deleted_at">
                                        <button @click="openCatEdit(cat)" class="text-indigo-600 font-medium hover:text-indigo-800 transition">
                                            Редагувати
                                        </button>
                                        <button @click="deleteCategory(cat)" class="text-red-600 font-medium hover:text-red-700 transition">
                                            Видалити
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="localCats.length === 0">
                                <td colspan="6" class="px-6 py-8 text-center text-gray-600">
                                    Немає жодної категорії. Створіть першу.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
