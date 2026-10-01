<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import Modal from '@/Components/UI/Modal.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import { ref, watch, computed, nextTick } from 'vue'

const props = defineProps({
    users: Array,
    availableModules: { type: Object, default: () => ({}) },
    roleDefaults: { type: Object, default: () => ({}) },
})

const modules = computed(() => props.availableModules)

const showForm = ref(false)
const editingId = ref(null)

const form = useForm({
    name:        '',
    email:       '',
    password:    '',
    role:        'executor',
    is_active:   true,
    permissions: [],
})

const roleLabels = { admin: 'Адмін', executor: 'Виконавець', manager: 'Менеджер' }

const isSelf = computed(() => editingId.value === usePage().props.auth?.user?.id)

// ─── ConfirmDialog state ─────────────────────────────
const confirmDialog = ref({
    show: false,
    title: '',
    message: '',
    confirmLabel: 'Підтвердити',
    variant: 'danger',
    onConfirm: null,
})

function showConfirm({ title, message, confirmLabel = 'Підтвердити', variant = 'danger' }) {
    return new Promise((resolve) => {
        confirmDialog.value = {
            show: true,
            title,
            message,
            confirmLabel,
            variant,
            onConfirm: () => {
                confirmDialog.value.show = false
                resolve(true)
            },
        }
        // If cancelled, we don't resolve (dialog stays closed)
    })
}

function cancelConfirm() {
    confirmDialog.value.show = false
}

// ─── Role-change confirm dialog state ────────────────
const roleConfirm = ref({ show: false, newRole: null })

function confirmRoleChange() {
    if (roleConfirm.value.newRole && props.roleDefaults?.[roleConfirm.value.newRole]) {
        form.permissions = [...props.roleDefaults[roleConfirm.value.newRole]]
    }
    roleConfirm.value.show = false
}

function cancelRoleChange() {
    roleConfirm.value.show = false
}

// ─── CRUD actions ────────────────────────────────────
async function softDelete(u) {
    await showConfirm({
        title: 'Деактивувати користувача',
        message: `Деактивувати «${u.name}»? Користувач не зможе входити в систему.`,
        confirmLabel: 'Деактивувати',
        variant: 'danger',
    })
    router.delete(route('admin.users.destroy', u.id))
}

async function restoreUser(u) {
    await showConfirm({
        title: 'Відновити користувача',
        message: `Відновити «${u.name}»? Користувач знову зможе входити в систему.`,
        confirmLabel: 'Відновити',
        variant: 'primary',
    })
    router.patch(route('admin.users.restore', u.id))
}

function getPermissionsPreview(u) {
    if (u.role === 'admin') return 'Повний доступ'
    if (!u.permissions || u.permissions.length === 0) return 'Без доступу'
    return u.permissions
        .map(k => modules.value?.[k] ?? k)
        .join(', ')
}

// Suppress watcher during programmatic form population
const suppressRoleWatch = ref(false)

// When role changes via user interaction, offer to apply default permissions
watch(() => form.role, (newRole, oldRole) => {
    if (suppressRoleWatch.value) return
    if (! oldRole) return // initial set

    if (editingId.value) {
        // Editing existing user — ask via ConfirmDialog before overwriting permissions
        if (props.roleDefaults?.[newRole]) {
            roleConfirm.value = { show: true, newRole }
        }
    } else if (props.roleDefaults?.[newRole]) {
        // Creating new user — auto-apply defaults silently
        form.permissions = [...props.roleDefaults[newRole]]
    }
})

function openCreate() {
    suppressRoleWatch.value = true
    form.reset()
    form.clearErrors()
    form.role = 'executor'
    form.permissions = [...(props.roleDefaults?.executor || ['orders', 'ledger'])]
    editingId.value = null
    showForm.value = true
    nextTick(() => { suppressRoleWatch.value = false })
}

function openEdit(u) {
    suppressRoleWatch.value = true
    form.clearErrors()
    form.name  = u.name
    form.email = u.email
    form.password = ''
    form.role  = u.role
    form.is_active = u.is_active
    // Filtered against the module list, not copied raw. All three production
    // admins carry `users` in the column from when it was grantable; it is no
    // longer a module, so posting it back would fail validation and make every
    // one of them uneditable. Filtering here also clears the dead key on the
    // next save instead of leaving it to a migration.
    form.permissions = (u.permissions ?? []).filter(k => k in props.availableModules)
    editingId.value = u.id
    showForm.value = true
    nextTick(() => { suppressRoleWatch.value = false })
}

function submit() {
    const options = {
        onSuccess: () => { showForm.value = false },
        preserveScroll: true,
    }
    editingId.value
        ? form.patch(route('admin.users.update', editingId.value), options)
        : form.post(route('admin.users.store'), options)
}

function togglePermission(key) {
    if (form.role === 'admin') return // Admin always gets all
    const idx = form.permissions.indexOf(key)
    if (idx >= 0) {
        form.permissions.splice(idx, 1)
    } else {
        form.permissions.push(key)
    }
}

function selectAllPermissions() {
    form.permissions = Object.keys(modules.value)
}

function clearAllPermissions() {
    form.permissions = []
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-sky-400 to-blue-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Користувачі</h1>
                        <p class="page-header-subtitle">Управління обліковими записами та правами доступу</p>
                    </div>
                </div>
                <button @click="openCreate" class="btn-primary w-full sm:w-auto">
                    + Додати
                </button>
            </div>

            <!-- Create/Edit Modal -->
            <Modal
                :show="showForm"
                :title="editingId ? 'Редагувати користувача' : 'Новий користувач'"
                max-width="lg"
                @close="showForm = false"
            >
                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <input aria-label="Ім'я" v-model="form.name" class="input" placeholder="Ім'я *" required />
                        <p v-if="form.errors.name" class="text-red-600 text-xs mt-1">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <input aria-label="Email" v-model="form.email" type="email" class="input" placeholder="Email *" required />
                        <p v-if="form.errors.email" class="text-red-600 text-xs mt-1">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <input aria-label="Пароль" v-model="form.password" type="password" class="input"
                            :placeholder="editingId ? 'Новий пароль (залиш порожнім щоб не змінювати)' : 'Пароль *'"
                            :required="!editingId" />
                        <p v-if="form.errors.password" class="text-red-600 text-xs mt-1">{{ form.errors.password }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-600 mb-1 block">Роль</label>
                        <select aria-label="Роль" v-model="form.role" class="input" :disabled="isSelf">
                            <option value="executor">Виконавець</option>
                            <option value="manager">Менеджер</option>
                            <option value="admin">Адмін</option>
                        </select>
                        <p v-if="form.errors.role" class="text-red-600 text-xs mt-1">{{ form.errors.role }}</p>
                    </div>
                    <p v-if="isSelf" class="text-xs text-amber-700 bg-amber-50 px-3 py-2 rounded-lg -mt-1">
                        Ви не можете змінити свою роль та доступ
                    </p>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input v-model="form.is_active" type="checkbox" class="rounded text-indigo-600" />
                        Активний
                    </label>

                    <!-- Permissions Section -->
                    <div v-if="!isSelf" class="border-t border-gray-100 pt-3 mt-3">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-sm font-semibold text-gray-700">Доступ до модулів</p>
                            <div v-if="form.role !== 'admin'" class="flex gap-2">
                                <button type="button" @click="selectAllPermissions"
                                    class="text-xs text-indigo-600 hover:underline">Всі</button>
                                <button type="button" @click="clearAllPermissions"
                                    class="text-xs text-gray-600 hover:text-red-600">Скинути</button>
                            </div>
                        </div>

                        <p v-if="form.role === 'admin'"
                            class="text-xs text-green-700 bg-green-50 px-3 py-2 rounded-lg">
                            Адміністратор має повний доступ до всіх модулів автоматично
                        </p>

                        <div v-else class="grid grid-cols-2 gap-1.5">
                            <label v-for="(label, key) in modules" :key="key"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm cursor-pointer transition-colors"
                                :class="form.permissions.includes(key)
                                    ? 'bg-indigo-50 text-indigo-700'
                                    : 'bg-gray-50 text-gray-600 hover:bg-gray-100'"
                                @click.prevent="togglePermission(key)">
                                <input type="checkbox"
                                    :checked="form.permissions.includes(key)"
                                    class="rounded text-indigo-600 pointer-events-none" />
                                <span>{{ label }}</span>
                            </label>
                        </div>
                    </div>
                </form>

                <template #footer>
                    <button type="button" class="btn-ghost border border-gray-200"
                        @click="showForm = false">Скасувати</button>
                    <button type="button" class="btn-primary"
                        :disabled="form.processing"
                        @click="submit">Зберегти</button>
                </template>
            </Modal>

            <!-- Role Change Confirm Dialog -->
            <ConfirmDialog
                :show="roleConfirm.show"
                title="Змінити дозволи"
                :message="`Змінити роль на «${roleLabels[roleConfirm.newRole] ?? ''}»?\n\nЗастосувати дефолтні дозволи для цієї ролі?`"
                confirm-label="Застосувати"
                variant="primary"
                @confirm="confirmRoleChange"
                @cancel="cancelRoleChange"
            />

            <!-- Deactivate/Restore Confirm Dialog -->
            <ConfirmDialog
                :show="confirmDialog.show"
                :title="confirmDialog.title"
                :message="confirmDialog.message"
                :confirm-label="confirmDialog.confirmLabel"
                :variant="confirmDialog.variant"
                @confirm="confirmDialog.onConfirm?.()"
                @cancel="cancelConfirm"
            />

            <!-- Users Table -->
            <div class="card p-0 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                                <th scope="col" class="px-4 py-3">Ім'я</th>
                                <th scope="col" class="px-4 py-3">Email</th>
                                <th scope="col" class="px-4 py-3">Роль</th>
                                <th scope="col" class="px-4 py-3">Доступ</th>
                                <th scope="col" class="px-4 py-3">Статус</th>
                                <th scope="col" class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="u in users" :key="u.id"
                                :class="{ 'opacity-50': u.deleted_at }"
                                class="table-row-hover">
                                <td class="px-4 py-3 font-medium text-gray-800">{{ u.name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ u.email }}</td>
                                <td class="px-4 py-3">
                                    <span class="badge"
                                        :class="{
                                            'bg-indigo-100 text-indigo-700': u.role === 'admin',
                                            'bg-blue-100 text-blue-700': u.role === 'executor',
                                            'bg-amber-100 text-amber-700': u.role === 'manager',
                                        }">
                                        {{ roleLabels[u.role] ?? u.role }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 max-w-[200px] truncate"
                                    :title="getPermissionsPreview(u)">
                                    {{ getPermissionsPreview(u) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="u.is_active && !u.deleted_at
                                        ? 'badge bg-green-100 text-green-700'
                                        : 'badge bg-red-100 text-red-600'">
                                        {{ u.deleted_at ? 'Деактивовано' : u.is_active ? 'Активний' : 'Неактивний' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2" v-if="!u.deleted_at">
                                        <button @click="openEdit(u)" class="text-xs text-indigo-600 hover:underline">Ред.</button>
                                        <button @click="softDelete(u)" class="text-xs text-red-600 hover:text-red-800">Деактивувати</button>
                                    </div>
                                    <div v-else>
                                        <button @click="restoreUser(u)" class="text-xs text-green-700 hover:underline">Відновити</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
