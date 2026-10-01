<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import axios from 'axios'

const props = defineProps({ categories: Array })

const showForm = ref(false)
const editingId = ref(null)

const form = useForm({
    name: '',
    sort_order: 0,
    is_active: true,
    available_for: ['internal', 'commercial'],
})

function openCreate() {
    form.reset()
    editingId.value = null
    showForm.value = true
}

function openEdit(cat) {
    form.name = cat.name
    form.sort_order = cat.sort_order
    form.is_active = cat.is_active
    form.available_for = cat.available_for ?? ['internal', 'commercial']
    editingId.value = cat.id
    showForm.value = true
}

function toggleAvailability(type) {
    const idx = form.available_for.indexOf(type)
    if (idx >= 0) {
        // Don't allow removing the last one
        if (form.available_for.length > 1) {
            form.available_for.splice(idx, 1)
        }
    } else {
        form.available_for.push(type)
    }
}

function submit() {
    if (editingId.value) {
        form.patch(route('admin.service-categories.update', editingId.value), {
            onSuccess: () => { showForm.value = false }
        })
    } else {
        form.post(route('admin.service-categories.store'), {
            onSuccess: () => { showForm.value = false }
        })
    }
}

function deleteCategory(cat) {
    if (confirm(`Деактивувати категорію «${cat.name}»? Всі послуги залишаться, але категорія зникне з каси.`)) {
        router.delete(route('admin.service-categories.destroy', cat.id))
    }
}

// ─── Drag & Drop ────────────────────────────────────
const localCategories = ref([...props.categories])
const dragIdx = ref(null)
const dragOverIdx = ref(null)
const saving = ref(false)

// Sync from props when inertia reloads
import { watch } from 'vue'
watch(() => props.categories, (v) => { localCategories.value = [...v] })

function onDragStart(idx) {
    dragIdx.value = idx
}

function onDragOver(e, idx) {
    e.preventDefault()
    if (dragOverIdx.value !== idx) dragOverIdx.value = idx
}

function onDragLeave() {
    dragOverIdx.value = null
}

function onDrop(e, toIdx) {
    e.preventDefault()
    const fromIdx = dragIdx.value
    if (fromIdx === null || fromIdx === toIdx) {
        dragIdx.value = null
        dragOverIdx.value = null
        return
    }
    const items = [...localCategories.value]
    const [moved] = items.splice(fromIdx, 1)
    items.splice(toIdx, 0, moved)
    localCategories.value = items
    dragIdx.value = null
    dragOverIdx.value = null
    saveOrder(items)
}

function onDragEnd() {
    dragIdx.value = null
    dragOverIdx.value = null
}

// Move via arrow buttons (mobile-friendly alternative)
function moveUp(idx) {
    if (idx === 0) return
    const items = [...localCategories.value]
    ;[items[idx - 1], items[idx]] = [items[idx], items[idx - 1]]
    localCategories.value = items
    saveOrder(items)
}

function moveDown(idx) {
    if (idx >= localCategories.value.length - 1) return
    const items = [...localCategories.value]
    ;[items[idx], items[idx + 1]] = [items[idx + 1], items[idx]]
    localCategories.value = items
    saveOrder(items)
}

async function saveOrder(items) {
    saving.value = true
    try {
        const order = items.map((cat, i) => ({ id: cat.id, sort_order: (i + 1) * 10 }))
        await axios.post(route('admin.service-categories.reorder'), { order })
        // Update local sort_order for display
        items.forEach((cat, i) => { cat.sort_order = (i + 1) * 10 })
    } catch (err) {
        console.error('Reorder failed:', err)
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-teal-400 to-cyan-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Категорії послуг</h1>
                        <p class="page-header-subtitle">Перетягніть рядки для зміни порядку</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="saving" class="text-xs text-indigo-500 animate-pulse">Зберігаю…</span>
                    <button @click="openCreate" class="btn-primary w-full sm:w-auto">+ Створити категорію</button>
                </div>
            </div>

            <!-- Form modal -->
            <Teleport to="body">
                <div v-if="showForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
                        <h2 class="text-lg font-bold mb-4">
                            {{ editingId ? 'Редагувати' : 'Нова' }} категорія
                        </h2>
                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Назва категорії</label>
                                <input aria-label="Назва категорії" v-model="form.name" class="input" placeholder="Назва *" required />
                            </div>

                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Порядок сортування</label>
                                <input aria-label="Порядок сортування" v-model="form.sort_order" type="number" class="input" placeholder="0" />
                                <span class="text-xs text-gray-600">Менше число = вище у списку</span>
                            </div>

                            <!-- Availability flags -->
                            <div>
                                <label class="text-xs text-gray-600 mb-2 block">Доступна для типів замовлень</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300"
                                            :checked="form.available_for.includes('internal')"
                                            @change="toggleAvailability('internal')"
                                        />
                                        <span class="text-gray-700">Внутрішні</span>
                                    </label>
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300"
                                            :checked="form.available_for.includes('commercial')"
                                            @change="toggleAvailability('commercial')"
                                        />
                                        <span class="text-gray-700">Комерційні</span>
                                    </label>
                                </div>
                                <p v-if="form.available_for.length === 0" class="text-xs text-red-600 mt-1">
                                    Оберіть хоча б один тип
                                </p>
                            </div>

                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300" />
                                <span class="text-gray-700">Активна (відображається на касі)</span>
                            </label>

                            <div class="flex gap-3 pt-4 border-t border-gray-100">
                                <button type="button" class="btn-ghost flex-1 justify-center border border-gray-200"
                                    @click="showForm = false">Скасувати</button>
                                <button type="submit" class="btn-primary flex-1 justify-center"
                                    :disabled="form.processing || form.available_for.length === 0">Зберегти</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- Table -->
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
                        <tr v-for="(cat, idx) in localCategories" :key="cat.id"
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
                                    <!-- Grip dots -->
                                    <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="currentColor">
                                        <circle cx="8" cy="8" r="1.5"/><circle cx="16" cy="8" r="1.5"/>
                                        <circle cx="8" cy="12" r="1.5"/><circle cx="16" cy="12" r="1.5"/>
                                        <circle cx="8" cy="16" r="1.5"/><circle cx="16" cy="16" r="1.5"/>
                                    </svg>
                                    <button type="button" @click.stop="moveDown(idx)" :disabled="idx >= localCategories.length - 1"
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
                                    <button @click="openEdit(cat)" class="text-indigo-600 font-medium hover:text-indigo-800 transition">
                                        Редагувати
                                    </button>
                                    <button @click="deleteCategory(cat)" class="text-red-600 font-medium hover:text-red-700 transition">
                                        Видалити
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="localCategories.length === 0">
                            <td colspan="6" class="px-6 py-8 text-center text-gray-600">
                                Немає жодної категорії. Створіть першу.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
