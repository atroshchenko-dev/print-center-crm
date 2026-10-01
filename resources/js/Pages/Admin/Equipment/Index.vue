<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({ equipment: Array })

const showForm = ref(false)
const editingId = ref(null)

const form = useForm({
    name:            '',
    serial_number:   '',
    type:            'bw',
    initial_counter: 0,
    has_counter:     true,
    is_active:       true,
})

const typeLabels = { bw: 'ЧБ', color: 'Кольоровий', riso: 'Ризограф' }

function openCreate() { form.reset(); form.type = 'bw'; form.initial_counter = 0; form.has_counter = true; editingId.value = null; showForm.value = true }
function openEdit(eq) {
    form.name = eq.name; form.serial_number = eq.serial_number ?? ''
    form.type = eq.type; form.is_active = eq.is_active
    form.initial_counter = eq.initial_counter ?? 0
    form.has_counter = eq.has_counter ?? true
    editingId.value = eq.id; showForm.value = true
}
function submit() {
    const options = { onSuccess: () => { showForm.value = false } }
    editingId.value
        ? form.patch(route('admin.equipment.update', editingId.value), options)
        : form.post(route('admin.equipment.store'), options)
}
function softDelete(eq) {
    if (confirm(`Деактивувати «${eq.name}»?`))
        router.delete(route('admin.equipment.destroy', eq.id))
}
// No forceDelete(): admin.equipment.force-destroy has never existed on the
// server, so the button only threw a Ziggy error into the console and did
// nothing. It could not be built either — the project forbids forceDelete() on
// any model, and counter readings reference equipment for the whole audit
// trail. Deactivating is the whole story.

// ─── Counter Adjustment ─────────────────────────────
const showAdjust = ref(false)
const adjustForm = useForm({
    equipment_id:  null,
    counter_value: 0,
    reason:        '',
})
const adjustEquipmentName = ref('')

function openAdjust(eq) {
    adjustForm.equipment_id = eq.id
    adjustForm.counter_value = 0
    adjustForm.reason = ''
    adjustEquipmentName.value = eq.name
    showAdjust.value = true
}
function submitAdjust() {
    adjustForm.post(route('admin.equipment.adjust'), {
        onSuccess: () => { showAdjust.value = false },
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-cyan-400 to-teal-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Апарати</h1>
                        <p class="page-header-subtitle">Управління обладнанням та лічильниками</p>
                    </div>
                </div>
                <button @click="openCreate" class="btn-primary w-full sm:w-auto">+ Додати апарат</button>
            </div>

            <Teleport to="body">
                <div v-if="showForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                        <h2 class="text-lg font-bold mb-4">
                            {{ editingId ? 'Редагувати апарат' : 'Новий апарат' }}
                        </h2>
                        <form @submit.prevent="submit" class="space-y-3">
                            <div>
                                <input aria-label="Модель" v-model="form.name" class="input" placeholder="Модель *" required />
                                <p v-if="form.errors.name" class="text-xs text-red-600 mt-1">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <input aria-label="Серійний номер" v-model="form.serial_number" class="input" placeholder="Серійний номер" />
                                <p v-if="form.errors.serial_number" class="text-xs text-red-600 mt-1">{{ form.errors.serial_number }}</p>
                            </div>
                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Тип лічильника</label>
                                <select aria-label="Тип лічильника" v-model="form.type" class="input">
                                    <option value="bw">ЧБ</option>
                                    <option value="color">Кольоровий</option>
                                    <option value="riso">Ризограф</option>
                                </select>
                                <p v-if="form.errors.type" class="text-xs text-red-600 mt-1">{{ form.errors.type }}</p>
                            </div>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input v-model="form.has_counter" type="checkbox" class="rounded" /> Має лічильник
                            </label>
                            <div v-if="form.has_counter">
                                <label class="text-xs text-gray-600 mb-1 block">Стартовий показник лічильника</label>
                                <input aria-label="Стартовий показник лічильника" v-model="form.initial_counter" type="number" min="0" class="input" required />
                                <p v-if="form.errors.initial_counter" class="text-xs text-red-600 mt-1">{{ form.errors.initial_counter }}</p>
                            </div>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="rounded" /> Активний
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

            <!-- Counter Adjustment Modal -->
            <Teleport to="body">
                <div v-if="showAdjust" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                        <h2 class="text-lg font-bold mb-1">Коригування лічильника</h2>
                        <p class="text-sm text-gray-600 mb-4">{{ adjustEquipmentName }}</p>
                        <form @submit.prevent="submitAdjust" class="space-y-3">
                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Новий показник лічильника *</label>
                                <input aria-label="Новий показник лічильника" v-model="adjustForm.counter_value" type="number" min="0" class="input" required />
                            </div>
                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Причина коригування *</label>
                                <textarea aria-label="Причина коригування" v-model="adjustForm.reason" class="input" rows="2"
                                    placeholder="Після ТО, заміна вузла..." required></textarea>
                                <p v-if="adjustForm.errors.reason" class="text-red-600 text-xs mt-1">{{ adjustForm.errors.reason }}</p>
                            </div>
                            <div class="flex gap-3 pt-2">
                                <button type="button" class="btn-ghost flex-1 justify-center border border-gray-200"
                                    @click="showAdjust = false">Скасувати</button>
                                <button type="submit" class="btn-primary flex-1 justify-center"
                                    :disabled="adjustForm.processing">Зберегти</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <div class="card p-0 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3">Модель</th>
                            <th scope="col" class="px-4 py-3">Серійний номер</th>
                            <th scope="col" class="px-4 py-3">Тип</th>
                            <th scope="col" class="px-4 py-3">Лічильник</th>
                            <th scope="col" class="px-4 py-3">Старт. пробіг</th>
                            <th scope="col" class="px-4 py-3">Статус</th>
                            <th scope="col" class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="eq in equipment" :key="eq.id"
                            :class="{ 'opacity-50': eq.deleted_at }"
                            class="table-row-hover">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ eq.name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ eq.serial_number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ typeLabels[eq.type] }}</td>
                            <td class="px-4 py-3">
                                <span :class="eq.has_counter ? 'text-green-700' : 'text-gray-600'">{{ eq.has_counter ? 'Так' : 'Ні' }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono">{{ eq.has_counter ? eq.initial_counter : '—' }}</td>
                            <td class="px-4 py-3">
                                <span :class="eq.is_active && !eq.deleted_at
                                    ? 'badge bg-green-100 text-green-700'
                                    : 'badge bg-red-100 text-red-600'">
                                    {{ eq.deleted_at ? 'Видалено' : eq.is_active ? 'Активний' : 'Неактивний' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="!eq.deleted_at" class="flex gap-2">
                                    <button @click="openEdit(eq)" class="text-xs text-indigo-600 hover:underline">Ред.</button>
                                    <button @click="openAdjust(eq)" class="text-xs text-amber-700 hover:underline">Лічильник</button>
                                    <button @click="softDelete(eq)" class="text-xs text-red-600 hover:text-red-800">Видалити</button>
                                </div>
                                <div v-else class="flex gap-2">
                                    <span class="text-xs text-gray-600">Деактивовано</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
