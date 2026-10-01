<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({ materials: Array })

const showForm = ref(false)
const editingId = ref(null)

const form = useForm({
    name:                 '',
    counter_type:         'bw',
    click_cost:           '',
    pending_click_cost:   '',
    pending_activated_at: '',
    is_active:            true,
})

function openCreate() { form.reset(); editingId.value = null; showForm.value = true }

function openEdit(m) {
    form.name                 = m.name
    form.counter_type         = m.counter_type ?? 'bw'
    form.click_cost           = m.click_cost
    form.pending_click_cost   = m.pending_click_cost ?? ''
    // The Kyiv-shaped value, not the stored UTC one: the field is labelled
    // "Активація (Kyiv)" and the server reads it back as Kyiv, so feeding it
    // the raw instant moved the time three hours earlier on every save.
    form.pending_activated_at = m.pending_activated_at_local ?? ''
    form.is_active            = m.is_active
    editingId.value           = m.id
    showForm.value            = true
}

function submit() {
    const options = { onSuccess: () => { showForm.value = false } }
    editingId.value
        ? form.patch(route('admin.materials.update', editingId.value), options)
        : form.post(route('admin.materials.store'), options)
}

function softDelete(m) {
    if (confirm(`Деактивувати «${m.name}»?`))
        router.delete(route('admin.materials.destroy', m.id))
}

// No forceDelete(): admin.materials.force-destroy has never existed on the
// server, so the button only threw a Ziggy error into the console and did
// nothing. It could not be built either — the project forbids forceDelete() on
// any model, and a material is referenced by the price snapshots of every
// order that used it. Deactivating is the whole story.

function formatDate(d) {
    return d ? new Date(d).toLocaleString('uk-UA', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Kyiv' }) : '—'
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-orange-400 to-red-500">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Матеріали (кліки)</h1>
                        <p class="page-header-subtitle">Тарифи кліків та відкладені ціни</p>
                    </div>
                </div>
                <button @click="openCreate" class="btn-primary w-full sm:w-auto">+ Додати матеріал</button>
            </div>

            <!-- Form modal -->
            <Teleport to="body">
                <div v-if="showForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                        <h2 class="text-lg font-bold mb-4">
                            {{ editingId ? 'Редагувати матеріал' : 'Новий матеріал' }}
                        </h2>
                        <form @submit.prevent="submit" class="space-y-3">
                            <div>
                                <input aria-label="Назва" v-model="form.name" class="input" placeholder="Назва *" required />
                                <p v-if="form.errors.name" class="text-red-600 text-xs mt-1">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Тип принтера (лічильник)</label>
                                <select aria-label="Тип принтера (лічильник)" v-model="form.counter_type" class="input" required>
                                    <option value="bw">Ч/Б принтер</option>
                                    <option value="color">Кольоровий принтер</option>
                                    <option value="riso">Ризограф</option>
                                </select>
                                <p v-if="form.errors.counter_type" class="text-red-600 text-xs mt-1">{{ form.errors.counter_type }}</p>
                            </div>

                            <div>
                                <label class="text-xs text-gray-600 mb-1 block">Поточна ціна кліку (грн)</label>
                                <input aria-label="Поточна ціна кліку (грн)" v-model="form.click_cost" type="number" step="0.0001" min="0"
                                    class="input" required />
                                <p v-if="form.errors.click_cost" class="text-red-600 text-xs mt-1">{{ form.errors.click_cost }}</p>
                            </div>

                            <div class="border-t border-gray-100 pt-3">
                                <p class="text-xs text-indigo-600 font-medium mb-2">
                                    Відкладена зміна ціни
                                </p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-600 mb-1 block">Нова ціна</label>
                                        <input aria-label="Нова ціна" v-model="form.pending_click_cost"
                                            type="number" step="0.0001" min="0" class="input"
                                            placeholder="0.0000" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-600 mb-1 block">Активація (Kyiv)</label>
                                        <input aria-label="Активація (Kyiv)" v-model="form.pending_activated_at"
                                            type="datetime-local" class="input" />
                                    </div>
                                </div>
                                <p class="text-xs text-gray-600 mt-1.5">
                                    Ціна зміниться автоматично о вказаному часі (00:01 за умовчанням)
                                </p>
                            </div>

                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="rounded" />
                                Активний
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

            <!-- Table -->
            <div class="card p-0 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3">Назва</th>
                            <th scope="col" class="px-4 py-3">Тип</th>
                            <th scope="col" class="px-4 py-3">Ціна/клік</th>
                            <th scope="col" class="px-4 py-3">Відкладена ціна</th>
                            <th scope="col" class="px-4 py-3">Активація</th>
                            <th scope="col" class="px-4 py-3">Статус</th>
                            <th scope="col" class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="m in materials" :key="m.id"
                            :class="{ 'opacity-50': m.deleted_at }"
                            class="table-row-hover">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ m.name }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded font-medium"
                                    :class="{
                                        'bg-gray-100 text-gray-600': m.counter_type === 'bw',
                                        'bg-yellow-50 text-yellow-700': m.counter_type === 'color',
                                        'bg-blue-50 text-blue-700': m.counter_type === 'riso',
                                    }">
                                    {{ m.counter_type === 'bw' ? 'Ч/Б' : m.counter_type === 'color' ? 'Колір' : 'Ризограф' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono">{{ parseFloat(m.click_cost).toFixed(4) }}</td>
                            <td class="px-4 py-3 font-mono text-amber-700">
                                {{ m.pending_click_cost ? parseFloat(m.pending_click_cost).toFixed(4) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                {{ formatDate(m.pending_activated_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <span :class="m.is_active && !m.deleted_at
                                    ? 'badge bg-green-100 text-green-700'
                                    : 'badge bg-red-100 text-red-600'">
                                    {{ m.deleted_at ? 'Видалено' : m.is_active ? 'Активний' : 'Неактивний' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="!m.deleted_at" class="flex gap-2">
                                    <button @click="openEdit(m)" class="text-xs text-indigo-600 hover:underline">Ред.</button>
                                    <button @click="softDelete(m)" class="text-xs text-red-600 hover:text-red-800">Видалити</button>
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
