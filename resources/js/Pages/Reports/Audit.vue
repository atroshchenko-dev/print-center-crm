<script setup>
/**
 * Reports/Audit — журнал аудиту подій
 * Фільтри: дата, тип події, користувач.
 * Expandable meta для деталей.
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import Pagination from '@/Components/Pagination.vue'
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

const props = defineProps({
    logs:       Object,   // paginated
    filters:    Object,
    users:      Array,    // [{id, name}]
    eventTypes: { type: Array, default: () => [] },  // [{value, label}] — App\Enums\AuditEventType
})

const from       = ref(props.filters?.from ?? '')
const to         = ref(props.filters?.to ?? '')
const event_type = ref(props.filters?.event_type ?? '')
const user_id    = ref(props.filters?.user_id ?? '')
const expandedId = ref(null)

function applyFilter() {
    router.get(route('reports.audit'), {
        from: from.value,
        to: to.value,
        event_type: event_type.value,
        user_id: user_id.value,
    }, { preserveScroll: true })
}

function toggleMeta(id) {
    expandedId.value = expandedId.value === id ? null : id
}

function goPage(url) {
    if (url) router.visit(url, { preserveScroll: true })
}

// Both the dropdown and the badge captions come from the server
// (App\Enums\AuditEventType). The copy that used to live here listed twelve
// types, was missing thirteen the system actually writes — approval_email_failed
// among them — and offered `counter_adjusted`, which nothing writes at all, so
// choosing it filtered the journal down to nothing every time.
const eventLabels = computed(() =>
    Object.fromEntries(props.eventTypes.map(t => [t.value, t.label]))
)

// Colour is decoration: an event with no entry here still shows, in grey.
const eventColors = {
    order_cancelled:   'bg-red-50 text-red-700',
    order_defect:      'bg-orange-50 text-orange-700',
    cash_withdrawal:   'bg-yellow-50 text-yellow-800',
    counter_adjustment: 'bg-blue-50 text-blue-700',
    limit_exceeded:    'bg-amber-50 text-amber-800',
    shift_auto_closed: 'bg-gray-100 text-gray-700',
    cash_discrepancy:  'bg-red-50 text-red-700',
    shift_opened:      'bg-green-50 text-green-700',
    price_activated:   'bg-purple-50 text-purple-700',
    shift_closed:      'bg-indigo-50 text-indigo-700',
    price_scheduled:   'bg-teal-50 text-teal-700',
    settlement_completed: 'bg-amber-50 text-amber-700',
    order_edited:      'bg-sky-50 text-sky-700',
    order_deleted:     'bg-red-50 text-red-700',
    approval_email_failed: 'bg-red-50 text-red-700',
    order_approved_email:  'bg-green-50 text-green-700',
    order_rejected_email:  'bg-orange-50 text-orange-700',
    inventory_deficit: 'bg-amber-50 text-amber-800',
    role_changed:      'bg-purple-50 text-purple-700',
    permissions_changed: 'bg-purple-50 text-purple-700',
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-slate-500 to-gray-700">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Журнал аудиту</h1>
                        <p class="page-header-subtitle">Іммутабельний журнал подій системи</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="badge-neutral">{{ logs.total ?? 0 }} записів</span>
                    <!-- The same four filters the list above is showing. -->
                    <a :href="route('reports.audit.export', { from: from, to: to, event_type: event_type, user_id: user_id })"
                       class="btn-ghost border border-gray-200 text-sm text-center">
                        Експорт XLSX
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-6 flex flex-col sm:flex-row flex-wrap items-start sm:items-end gap-3">
                <div>
                    <label class="stat-label block mb-1">Від</label>
                    <input aria-label="Від" v-model="from" type="date" class="input w-full sm:w-36" />
                </div>
                <div>
                    <label class="stat-label block mb-1">До</label>
                    <input aria-label="До" v-model="to" type="date" class="input w-full sm:w-36" />
                </div>
                <div>
                    <label class="stat-label block mb-1">Подія</label>
                    <select aria-label="Подія" v-model="event_type" class="input w-full sm:w-56">
                        <option value="">Всі події</option>
                        <option v-for="type in eventTypes" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="stat-label block mb-1">Користувач</label>
                    <select aria-label="Користувач" v-model="user_id" class="input w-full sm:w-44">
                        <option value="">Всі</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">
                            {{ u.name }}{{ u.deleted_at ? ' (деактивований)' : '' }}
                        </option>
                    </select>
                </div>
                <button @click="applyFilter" class="btn-primary w-full sm:w-auto">Фільтрувати</button>
            </div>

            <!-- Log table -->
            <div class="card p-0 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3 w-36">Час</th>
                            <th scope="col" class="px-4 py-3 w-52">Подія</th>
                            <th scope="col" class="px-4 py-3">Опис</th>
                            <th scope="col" class="px-4 py-3 w-28">Хто</th>
                            <th scope="col" class="px-4 py-3 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-if="!logs.data?.length">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-600">
                                Подій не знайдено
                            </td>
                        </tr>
                        <template v-for="log in logs.data" :key="log.id">
                            <tr class="table-row-hover cursor-pointer" @click="log.meta && toggleMeta(log.id)">
                                <td class="px-4 py-2.5 text-xs text-gray-600 font-mono">
                                    {{ new Date(log.created_at).toLocaleString('uk-UA', {
                                        timeZone: 'Europe/Kyiv',
                                        dateStyle: 'short',
                                        timeStyle: 'short'
                                    }) }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                          :class="eventColors[log.event_type] ?? 'bg-gray-50 text-gray-700'">
                                        {{ eventLabels[log.event_type] ?? log.event_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-gray-600 text-sm">{{ log.description }}</td>
                                <td class="px-4 py-2.5 text-gray-600 text-xs">{{ log.user?.name }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <svg class="w-3 h-3 text-gray-600 transition-transform" :class="expandedId === log.id ? 'rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>
                                </td>
                            </tr>
                            <!-- Expanded meta row -->
                            <tr v-if="expandedId === log.id && log.meta">
                                <td colspan="5" class="px-6 py-3 bg-gray-50/80">
                                    <pre class="text-xs text-gray-600 font-mono whitespace-pre-wrap">{{ JSON.stringify(log.meta, null, 2) }}</pre>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <Pagination :links="logs.links" />
        </div>
    </AppLayout>
</template>
