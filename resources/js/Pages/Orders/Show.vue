<script setup>
/**
 * Orders/Show — деталь замовлення
 * Переходи статусів + оплата + скасування з причиною + облік заявки
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { statusLabels, statusColors } from '@/composables/useOrderStatus'
import { serviceOptionLabels } from '@/composables/useConstructorOptions'

const props = defineProps({
    order: Object,
    enabledPaymentMethods: { type: Array, default: () => ['cash'] },
})

const paymentMethodLabels = { cash: 'Готівка', card: 'Картка' }

// Allowed transitions from current status
// `completed_issued` is how an internal order finishes. A commercial one
// finishes by being paid for — the server refuses anything else, so the two
// must not be offered side by side here either.
const transitionMap = {
    new: [
        { status: 'in_progress',      label: 'В роботу' },
        { status: 'completed_issued', label: '✓ Виконано/Видано', internalOnly: true },
    ],
    in_progress: [
        { status: 'ready',            label: 'Готово' },
        { status: 'completed_issued', label: '✓ Виконано/Видано', internalOnly: true },
    ],
    ready: [
        { status: 'paid_issued',      label: 'Оплачено/Видано', needsPayment: true },
        { status: 'completed_issued', label: '✓ Завершено/Видано', internalOnly: true },
    ],
}

const availableTransitions = computed(() => {
    const transitions = transitionMap[props.order.status] ?? []
    if (props.order.type === 'internal') {
        // Internal orders skip payment — hide "Оплачено/Видано"
        return transitions.filter(t => !t.needsPayment)
    }
    return transitions.filter(t => !t.internalOnly)
})

// Status transition form
const statusForm = useForm({ status: '', version: props.order.version, payment_method: '' })
const showPaymentField = ref(false)

/**
 * The dialog opens only when there is something to choose.
 *
 * `showPaymentField` used to be set from `needsPayment` **before** the
 * single-method branch below, so with one payment method enabled — which is the
 * default, and what production runs — the screen put up «Спосіб оплати /
 * Підтвердити» and sent the PATCH in the same tick. Measured 2026-07-31: the
 * dialog is visible and the request is already gone, with nothing touched.
 *
 * It is a confirmation for an action already submitted, and it lives exactly as
 * long as the request. Clicking «Підтвердити» inside that window sends a second
 * status PATCH carrying the version captured before the first one — an
 * optimistic-lock error on a payment that had in fact succeeded. It is also
 * what made the E2E suite flaky: the click lands on a button that the arriving
 * response is about to detach, and when it loses the race the button never
 * comes back, because the order is terminal by then.
 */
function applyTransition(transition) {
    statusForm.status  = transition.status
    statusForm.version = props.order.version

    if (transition.needsPayment) {
        // One method enabled: nothing to choose, so nothing to confirm.
        if (props.enabledPaymentMethods.length === 1) {
            showPaymentField.value = false
            statusForm.payment_method = props.enabledPaymentMethods[0]
            doUpdateStatus()
            return
        }
        // Several methods — show the dropdown and wait for a choice.
        showPaymentField.value = true
        return
    }

    showPaymentField.value = false
    doUpdateStatus()
}

function doUpdateStatus() {
    statusForm.patch(route('orders.status', props.order.id), {
        preserveScroll: true,
        onSuccess: () => { showPaymentField.value = false },
    })
}

// Cancel form
const showCancelModal = ref(false)
const cancelForm = useForm({
    reason: '',
    version: props.order.version,
    is_technical_defect: false,
})

function submitCancel() {
    cancelForm.post(route('orders.cancel', props.order.id), {
        onSuccess: () => { showCancelModal.value = false },
    })
}

const isTerminal = computed(() =>
    ['paid_issued', 'completed_issued', 'cancelled'].includes(props.order.status)
)

// Request received toggle (internal orders only)
const requestForm = useForm({
    request_received: props.order.request_received ?? false,
})

// Where the signature is: in the folder, or only in the system. The card
// already loads latestApproval, so no extra flag is needed here.
const approvedByLetter = computed(() => props.order.latest_approval?.status === 'approved')

function toggleRequest() {
    requestForm.request_received = !requestForm.request_received
    requestForm.patch(route('orders.request', props.order.id), {
        preserveScroll: true,
    })
}

// Send approval email to signatory
const approvalForm = useForm({})
const approvalSending = ref(false)

function sendApproval() {
    approvalSending.value = true
    approvalForm.post(route('orders.send-approval', props.order.id), {
        preserveScroll: true,
        onFinish: () => { approvalSending.value = false },
    })
}

function printOrder() {
    window.print()
}

// Delete form (admin only)
const showDeleteModal = ref(false)
const deleteProcessing = ref(false)

function submitDelete() {
    deleteProcessing.value = true
    router.delete(route('orders.destroy', props.order.id), {
        onFinish: () => { deleteProcessing.value = false },
    })
}
</script>

<template>
    <AppLayout>
        <div class="max-w-3xl">
            <!-- Header card with accent strip -->
            <div class="card p-0 overflow-hidden mb-6 no-print">
                <!-- Accent strip -->
                <div class="h-1.5" :class="order.type === 'internal' ? 'bg-indigo-500' : 'bg-amber-500'"></div>

                <div class="p-5 sm:p-6">
                    <!-- Row 1: Order number + status + type -->
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                                :class="order.type === 'internal' ? 'bg-indigo-50' : 'bg-amber-50'">
                                <svg class="w-5 h-5" :class="order.type === 'internal' ? 'text-indigo-600' : 'text-amber-700'"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 7h8M8 12h8M8 17h5"/>
                                </svg>
                            </div>
                            <div>
                                <h1 class="text-xl sm:text-2xl font-bold font-mono text-gray-800 tracking-tight">
                                    {{ order.order_number }}
                                </h1>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full"
                                        :class="order.type === 'internal'
                                            ? 'bg-indigo-50 text-indigo-600'
                                            : 'bg-amber-50 text-amber-700'">
                                        {{ order.type === 'internal' ? 'Внутрішнє' : 'Комерційне' }}
                                    </span>
                                    <span v-if="order.is_at_cost"
                                        class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                                        По собівартості
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="sm:ml-auto">
                            <span v-for="(part, pi) in statusLabels[order.status].split('/')" :key="pi"
                                :class="statusColors[order.status]">
                                {{ part.trim() }}
                            </span>
                        </div>
                    </div>

                    <!-- Row 2: Primary actions -->
                    <div class="flex flex-wrap items-center gap-2">
                        <template v-if="!isTerminal">
                            <div v-if="showPaymentField" class="flex items-center gap-2">
                                <select aria-label="Спосіб оплати" v-model="statusForm.payment_method" class="input w-36 text-sm">
                                    <option value="">Оберіть оплату</option>
                                    <option v-for="m in enabledPaymentMethods" :key="m" :value="m">{{ paymentMethodLabels[m] || m }}</option>
                                </select>
                                <button @click="doUpdateStatus" class="btn-primary text-sm"
                                    :disabled="!statusForm.payment_method">Підтвердити</button>
                            </div>
                            <template v-else>
                                <button v-for="t in availableTransitions" :key="t.status"
                                    type="button" :class="t.quick ? 'btn-success text-sm' : 'btn-primary text-sm'"
                                    :disabled="statusForm.processing" @click="applyTransition(t)">
                                    {{ t.label }}
                                </button>
                            </template>
                            <button type="button" class="btn-danger text-sm" @click="showCancelModal = true">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 18L18 6M6 6l12 12"/></svg>
                                Скасувати
                            </button>
                        </template>

                        <div class="flex-1"></div>

                        <!-- Secondary actions -->
                        <div class="flex items-center gap-1">
                            <Link v-if="['new', 'in_progress'].includes(order.status)"
                                :href="route('orders.edit', order.id)"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                Редагувати
                            </Link>
                            <Link :href="route('orders.create', { repeat: order.id })"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                Повторити
                            </Link>
                            <button type="button" @click="printOrder"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8"/></svg>
                                Друк
                            </button>
                            <button v-if="$page.props.auth?.user?.role === 'admin'" type="button"
                                @click="showDeleteModal = true"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                Видалити
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Print-only header -->
            <div class="hidden print:block mb-4">
                <h1 class="text-2xl font-bold font-mono">{{ order.order_number }}</h1>
                <p class="text-sm text-gray-600">{{ order.type === 'internal' ? 'Внутрішнє' : 'Комерційне' }} · {{ statusLabels[order.status] }}</p>
            </div>

            <!-- Order meta -->
            <div class="card mb-4">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div v-if="order.authorized_person" class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-gray-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600 mb-0.5">Підписант</dt>
                            <dd class="font-medium text-gray-800">{{ order.authorized_person }}</dd>
                        </div>
                    </div>
                    <div v-if="order.cost_center" class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-gray-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18zM6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/></svg>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600 mb-0.5">Підрозділ</dt>
                            <dd class="font-medium text-gray-800">{{ order.cost_center }}</dd>
                        </div>
                    </div>
                    <div v-if="order.initiator" class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-gray-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600 mb-0.5">Ініціатор</dt>
                            <dd class="font-medium text-gray-800">{{ order.initiator }}</dd>
                        </div>
                    </div>
                    <div v-if="order.limit_exceeded" class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600 mb-0.5">Ліміт</dt>
                            <dd class="text-amber-700 font-medium">Перевищено</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-gray-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600 mb-0.5">Виконавець</dt>
                            <dd class="font-medium text-gray-800">{{ order.user?.name }}</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-gray-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600 mb-0.5">Створено</dt>
                            <dd class="text-gray-700 tabular-nums">{{ new Date(order.created_at).toLocaleString('uk-UA') }}</dd>
                        </div>
                    </div>
                    <div v-if="order.payment_method" class="flex items-start gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-green-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-green-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600 mb-0.5">Оплата</dt>
                            <dd class="font-medium text-green-700">{{ order.payment_method === 'cash' ? 'Готівка' : 'Картка' }}</dd>
                        </div>
                    </div>

                    <!-- Request received toggle (internal only) -->
                    <div v-if="order.type === 'internal'" class="sm:col-span-2 pt-2 border-t border-gray-100">
                        <div class="flex items-center justify-between">
                            <div>
                                <dt class="text-gray-600 text-xs uppercase tracking-wide">Заявка</dt>
                                <dd class="font-medium mt-0.5 inline-flex items-center gap-1.5" :class="order.request_received ? 'text-green-700' : 'text-gray-600'">
                                    <Icon v-if="order.request_received" :name="approvedByLetter ? 'mail' : 'file-text'" size="4" />
                                    {{ order.request_received ? 'Отримана' : 'Не отримана' }}
                                    <span v-if="order.request_received && order.request_received_at" class="text-xs text-gray-600 ml-1">
                                        ({{ new Date(order.request_received_at).toLocaleDateString('uk-UA') }})
                                    </span>
                                </dd>
                            </div>
                            <button
                                type="button"
                                class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                                :class="[
                                    order.request_received ? 'bg-green-500' : 'bg-gray-300',
                                    approvedByLetter ? 'cursor-default opacity-70' : 'cursor-pointer',
                                ]"
                                :disabled="requestForm.processing || approvedByLetter"
                                :title="approvedByLetter ? 'Погоджено листом — позначку зняти не можна' : null"
                                @click="toggleRequest"
                                role="switch"
                                :aria-checked="order.request_received"
                            >
                                <span
                                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    :class="order.request_received ? 'translate-x-5' : 'translate-x-0'"
                                />
                            </button>
                        </div>

                        <!-- Email Approval Status + Send Button -->
                        <div class="mt-2 flex items-center gap-2 flex-wrap">
                            <template v-if="order.latest_approval">
                                <span class="text-xs text-gray-600">Email-погодження:</span>
                                <span v-if="order.latest_approval.status === 'approved'"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700">
                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                                    Погоджено
                                </span>
                                <span v-else-if="order.latest_approval.status === 'rejected'"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700">
                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                    Відхилено
                                </span>
                                <span v-else-if="order.latest_approval.status === 'pending'"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700">
                                    <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                                    Очікує
                                </span>
                                <span v-if="order.latest_approval.responded_at" class="text-xs text-gray-600">
                                    ({{ new Date(order.latest_approval.responded_at).toLocaleDateString('uk-UA') }})
                                </span>
                                <span v-if="order.latest_approval.signatory_email" class="text-xs text-gray-600">
                                    &middot; {{ order.latest_approval.signatory_email }}
                                </span>
                            </template>

                            <!-- Send: no approval yet -->
                            <button v-if="!order.latest_approval"
                                type="button"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition"
                                :disabled="approvalSending"
                                @click="sendApproval">
                                <svg v-if="!approvalSending" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                <svg v-else class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                                {{ approvalSending ? 'Відправка...' : 'Надіслати на погодження' }}
                            </button>

                            <!-- Resend: rejected or pending -->
                            <button v-else-if="['rejected', 'pending'].includes(order.latest_approval.status)"
                                type="button"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 transition"
                                :disabled="approvalSending"
                                @click="sendApproval">
                                <svg v-if="!approvalSending" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                <svg v-else class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                                {{ approvalSending ? 'Відправка...' : 'Надіслати повторно' }}
                            </button>
                        </div>
                    </div>
                </dl>
            </div>

            <!-- Order items -->
            <div class="card mb-4">
                <h2 class="font-semibold text-gray-700 mb-3">Позиції замовлення</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-gray-600">
                            <th scope="col" class="pb-2">Послуга</th>
                            <th scope="col" class="pb-2 text-center">К-сть</th>
                            <th scope="col" class="pb-2 text-right">Ціна/шт</th>
                            <th scope="col" class="pb-2 text-right">Разом</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in order.items" :key="item.id"
                            class="border-b border-gray-50 hover:bg-gray-50">
                            <td class="py-2.5">
                                <p class="font-medium text-gray-800">{{ item.service_name }}</p>
                                <!-- Service options summary -->
                                <p v-if="serviceOptionLabels(item.service_snapshot, { isInternal: order.type === 'internal' }).length"
                                    class="text-xs text-gray-600 mt-0.5 flex flex-wrap gap-1">
                                    <span v-for="(opt, oi) in serviceOptionLabels(item.service_snapshot, { isInternal: order.type === 'internal' })"
                                        :key="oi"
                                        class="inline-block px-1.5 py-px rounded bg-gray-100 text-gray-600 text-[10px] leading-tight">
                                        {{ opt }}
                                    </span>
                                </p>
                                <!-- Material description -->
                                <p v-if="item.material_description" class="text-xs text-indigo-500 mt-0.5">
                                    {{ item.material_description }}
                                </p>
                                <!-- Customer paper badge -->
                                <span v-if="item.service_snapshot?.customer_paper"
                                    class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-700 rounded">
                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                    Папір замовника
                                </span>
                            </td>
                            <td class="py-2.5 text-center text-gray-600">{{ item.quantity }}</td>
                            <td class="py-2.5 text-right text-gray-600">
                                <template v-if="order.type === 'commercial' && !order.is_at_cost">
                                    {{ parseFloat(item.unit_price_commercial).toFixed(2) }} грн
                                </template>
                                <template v-else>
                                    <span :class="order.is_at_cost ? 'text-amber-700' : 'text-gray-600'">{{ parseFloat(item.unit_price_cost).toFixed(2) }} грн</span>
                                </template>
                            </td>
                            <td class="py-2.5 text-right font-semibold text-gray-800">
                                <template v-if="order.type === 'commercial' && !order.is_at_cost">
                                    {{ parseFloat(item.total_price_commercial).toFixed(2) }} грн
                                </template>
                                <template v-else>
                                    <span :class="order.is_at_cost ? 'text-amber-700' : 'text-gray-600'">{{ parseFloat(item.total_price_cost).toFixed(2) }} грн</span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="pt-3 text-right font-semibold text-gray-700">
                                {{ order.type === 'commercial' && !order.is_at_cost ? 'Всього:' : 'Собівартість:' }}
                            </td>
                            <td class="pt-3 text-right text-xl font-bold"
                                :class="order.is_at_cost ? 'text-amber-700' : (order.type === 'commercial' ? 'text-indigo-700' : 'text-gray-600')">
                                {{ parseFloat(order.type === 'commercial' && !order.is_at_cost ? order.total_commercial : order.total_cost).toFixed(2) }} грн
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Ledger transactions -->
            <div v-if="order.ledger_transactions?.length" class="card">
                <h2 class="font-semibold text-gray-700 mb-3">Транзакції</h2>
                <div class="space-y-1.5">
                    <div v-for="tx in order.ledger_transactions" :key="tx.id"
                        class="flex justify-between text-sm py-1.5 border-b border-gray-50">
                        <span class="text-gray-600">{{ tx.comment }}</span>
                        <span :class="tx.amount >= 0 ? 'text-green-700' : 'text-red-600'" class="font-mono">
                            {{ tx.amount >= 0 ? '+' : '' }}{{ tx.amount }} грн
                        </span>
                    </div>
                </div>
            </div>

            <!-- Cancellation reason (if cancelled) -->
            <div v-if="order.status === 'cancelled'" class="card mt-4 bg-red-50 border border-red-200">
                <p class="text-sm text-red-700 font-medium mb-1">Причина скасування:</p>
                <p class="text-sm text-red-600">{{ order.cancellation_reason }}</p>
                <p v-if="order.is_technical_defect" class="text-xs text-red-600 mt-1">
                    Технічний брак
                </p>
            </div>

            <!-- Status History Timeline -->
            <div v-if="order.status_history?.length" class="card mt-4">
                <h2 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Історія статусів
                </h2>
                <div class="relative pl-6 space-y-3">
                    <!-- Vertical line -->
                    <div class="absolute left-2 top-1 bottom-1 w-px bg-gradient-to-b from-gray-300 to-gray-100"></div>

                    <div v-for="(entry, idx) in order.status_history" :key="entry.id"
                        class="relative text-sm">
                        <!-- Dot -->
                        <div class="absolute -left-4 top-1 w-2.5 h-2.5 rounded-full ring-2 ring-white"
                            :class="{
                                'bg-gray-400': entry.to_status === 'new',
                                'bg-blue-500': entry.to_status === 'in_progress',
                                'bg-yellow-500': entry.to_status === 'ready',
                                'bg-green-500': ['paid_issued', 'completed_issued'].includes(entry.to_status),
                                'bg-red-500': entry.to_status === 'cancelled',
                            }"
                        ></div>

                        <div class="flex items-baseline gap-2">
                            <span class="font-medium text-gray-700">
                                {{ statusLabels[entry.to_status] || entry.to_status }}
                            </span>
                            <span class="text-xs text-gray-600 tabular-nums">
                                {{ new Date(entry.created_at).toLocaleString('uk-UA', {
                                    day: '2-digit', month: '2-digit',
                                    hour: '2-digit', minute: '2-digit'
                                }) }}
                            </span>
                            <span v-if="entry.user" class="text-xs text-gray-600">
                                — {{ entry.user.name }}
                            </span>
                        </div>
                        <p v-if="entry.comment" class="text-xs text-gray-600 mt-0.5 italic">
                            {{ entry.comment }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cancel Modal -->
        <Teleport to="body">
            <div v-if="showCancelModal"
                class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-1">Скасування замовлення</h3>
                    <p class="text-sm text-gray-600 mb-4">{{ order.order_number }}</p>

                    <textarea aria-label="Вкажіть причину скасування (обов'язково)..."
                        v-model="cancelForm.reason"
                        class="input resize-none mb-3"
                        rows="4"
                        placeholder="Вкажіть причину скасування (обов'язково)..."
                        autofocus
                    />

                    <label class="flex items-center gap-2 text-sm text-gray-600 mb-4 cursor-pointer">
                        <input v-model="cancelForm.is_technical_defect" type="checkbox"
                            class="rounded border-gray-300 text-red-600" />
                        Технічний брак (вид браку фіксується в аудиті)
                    </label>

                    <div class="flex gap-3">
                        <button type="button" class="btn-ghost flex-1 justify-center"
                            @click="showCancelModal = false">
                            Відміна
                        </button>
                        <button type="button" class="btn-danger flex-1 justify-center"
                            :disabled="cancelForm.reason.length < 5 || cancelForm.processing"
                            @click="submitCancel">
                            {{ cancelForm.processing ? 'Скасовую…' : 'Скасувати замовлення' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Modal (admin only) -->
        <Teleport to="body">
            <div v-if="showDeleteModal"
                class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
                    <h3 class="text-lg font-bold text-red-700 mb-1 flex items-center gap-2">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                        Видалення замовлення
                    </h3>
                    <p class="text-sm text-gray-600 mb-4">{{ order.order_number }}</p>

                    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4 text-sm text-red-700">
                        <p class="font-medium mb-1">Увага! Ця дія:</p>
                        <ul class="list-disc list-inside text-xs space-y-0.5">
                            <li>Повністю приховає замовлення зі всіх списків</li>
                            <li v-if="['paid_issued', 'completed_issued'].includes(order.status)">Поверне інвентар та скасує транзакції в касі</li>
                            <li>Записати видалення в аудит-журнал</li>
                            <li>Надішле сповіщення в Telegram</li>
                        </ul>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" class="btn-ghost flex-1 justify-center"
                            @click="showDeleteModal = false">
                            Відміна
                        </button>
                        <button type="button" class="btn-danger flex-1 justify-center"
                            :disabled="deleteProcessing"
                            @click="submitDelete">
                            {{ deleteProcessing ? 'Видаляю…' : 'Видалити замовлення' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
@media print {
    /* Hide interactive elements */
    .no-print,
    button,
    select,
    textarea,
    input {
        display: none !important;
    }
    /* Clean layout */
    :deep(.sidebar),
    :deep(nav),
    :deep(header) {
        display: none !important;
    }
}
</style>
