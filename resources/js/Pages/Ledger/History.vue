<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    shift:        Object,
    transactions: Array,
    balance:      Number,
})

const txTypeLabels = {
    payment_cash: 'Оплата готівкою',
    payment_card: 'Оплата карткою',
    withdrawal:   'Видача готівки',
    reversal:     '↩ Сторнування',
}

// Withdrawal form
const form = useForm({ amount: '', comment: '' })
function submitWithdrawal() {
    form.post(route('ledger.withdrawal'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    })
}

const cashTransactions = computed(() =>
    props.transactions.filter(tx =>
        ['payment_cash', 'withdrawal', 'reversal'].includes(tx.type)
    )
)
</script>

<template>
    <AppLayout>
        <div class="max-w-2xl">
            <div class="page-header">
                <div class="page-header-icon bg-gradient-to-br from-emerald-400 to-green-600">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                </div>
                <div>
                    <h1 class="page-header-title">Каса</h1>
                    <p class="page-header-subtitle">Журнал транзакцій поточної зміни</p>
                </div>
            </div>

            <!-- Balance card -->
            <div class="card mb-6 bg-gradient-to-br from-indigo-50 to-white border-indigo-200">
                <p class="text-sm text-indigo-500 mb-1">Поточний залишок каси</p>
                <p class="text-4xl font-bold text-indigo-700">
                    {{ balance.toFixed(2) }} <span class="text-xl font-normal">грн</span>
                </p>
                <p class="text-xs text-gray-600 mt-2">
                    Старт зміни: {{ shift.cash_start }} грн
                </p>
            </div>

            <!-- Withdrawal form -->
            <div class="card mb-6">
                <h2 class="font-semibold text-gray-700 mb-3">Видача готівки</h2>
                <form @submit.prevent="submitWithdrawal" class="flex flex-wrap gap-3 items-start">
                    <input aria-label="Сума"
                        v-model="form.amount"
                        type="number" step="0.01" min="0.01"
                        class="input w-32"
                        placeholder="Сума"
                    />
                    <input aria-label="Призначення (обов'язково)"
                        v-model="form.comment"
                        type="text"
                        class="input flex-1"
                        placeholder="Призначення (обов'язково)"
                    />
                    <button
                        type="submit"
                        class="btn-primary flex-shrink-0"
                        :disabled="!form.amount || !form.comment || form.processing"
                    >
                        Видати
                    </button>
                </form>
                <div v-if="form.hasErrors" class="mt-2 text-xs text-red-600 space-y-0.5">
                    <p v-for="(err, field) in form.errors" :key="field">{{ err }}</p>
                </div>
            </div>

            <!-- Transaction history -->
            <div class="card p-0 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                    <h2 class="font-semibold text-gray-700 text-sm">Журнал транзакцій зміни</h2>
                </div>

                <div v-if="transactions.length === 0" class="px-4 py-8 text-center text-gray-600 text-sm">
                    Транзакцій ще немає
                </div>

                <div v-else class="divide-y divide-gray-50">
                    <div v-for="tx in transactions" :key="tx.id"
                        class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-700">
                                {{ txTypeLabels[tx.type] ?? tx.type }}
                            </p>
                            <p class="text-xs text-gray-600 truncate mt-0.5">
                                {{ tx.comment }}
                                <span v-if="tx.order" class="ml-1 text-indigo-600">
                                    · {{ tx.order.order_number }}
                                </span>
                            </p>
                            <p class="text-xs text-gray-600 mt-0.5">{{ tx.user?.name }}</p>
                        </div>

                        <div class="text-right flex-shrink-0 ml-4">
                            <p class="font-mono font-semibold text-sm"
                                :class="tx.amount >= 0 ? 'text-green-700' : 'text-red-600'">
                                {{ tx.amount >= 0 ? '+' : '' }}{{ parseFloat(tx.amount).toFixed(2) }} грн
                            </p>
                            <p class="text-xs text-gray-600">
                                баланс: {{ parseFloat(tx.balance_after).toFixed(2) }}
                            </p>
                            <p class="text-xs text-gray-600">
                                {{ new Date(tx.created_at).toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' }) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
