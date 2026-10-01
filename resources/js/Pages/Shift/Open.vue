<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { calendarDay } from '@/composables/useCalendarDay'

const props = defineProps({
    equipment: Array,       // Equipment with counters [{id, name, type, last_reading}, ...]
    cash_start: Number,
    last_shift: Object,
    previous_shift: Object, // Previous unsettled shift (null if no settlement needed)
})

const needsSettlement = computed(() => !!props.previous_shift)

// Whether a reading is still exactly what was pre-filled.
//
// The form fills every counter with the last known value so the usual morning
// is one click. The cost is that accepting it without walking to the machines
// looks identical to a machine that genuinely printed nothing — and the
// counters report cannot tell those apart either. July has one such day: shift
// #83 recorded all four counters unchanged from the previous shift while the
// orders of that day booked 664 black-and-white clicks (audit R22-5, §4).
//
// One day in a month is a suspicion, not a defect, so this says so and blocks
// nothing: an unchanged counter is perfectly normal on a quiet day, and a
// shift opened twice in one day has unchanged counters by definition.
function isUntouched(item, idx) {
    const filled = props.equipment[idx]?.last_reading

    return filled !== null && filled !== undefined
        && Number(item.counter_value) === Number(filled)
}

// ─── Pre-fill all values for one-click confirmation ──────
const form = useForm({
    // Morning = last evening (pre-filled, operator just confirms)
    readings: props.equipment.map(eq => ({
        equipment_id:  eq.id,
        counter_value: eq.last_reading,
    })),
    // Cash = calculated (pre-filled)
    cash_actual: props.previous_shift
        ? String(props.previous_shift.cash_calculated.toFixed(2))
        : '',
    discrepancy_reason: '',
})

// What the new shift will actually start with. While a settlement is pending
// that is the amount being counted right here, not the carried figure — those
// are the same number only after the previous shift has been settled.
const startingCash = computed(() => {
    const entered = parseFloat(form.cash_actual)
    if (needsSettlement.value && Number.isFinite(entered)) return entered
    return parseFloat(props.cash_start) || 0
})

// Cash discrepancy
const discrepancy = computed(() => {
    if (!needsSettlement.value || form.cash_actual === '') return 0
    return parseFloat(form.cash_actual) - props.previous_shift.cash_calculated
})
const hasDiscrepancy = computed(() => form.cash_actual !== '' && Math.abs(discrepancy.value) > 0.01)

function submit() {
    form.post(route('shifts.open'))
}
</script>

<template>
    <AuthLayout>
        <div>
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-400 to-indigo-600 flex items-center justify-center shadow-md">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Відкриття зміни</h2>
                    <p class="text-sm text-gray-600">Перевірте показники і натисніть «Відкрити зміну»</p>
                </div>
            </div>
            <p class="text-xs text-gray-600 mb-6 ml-[52px]">
                Змінюйте тільки те, що відрізняється.
            </p>

            <!-- Warning if previous shift was auto-closed -->
            <div v-if="last_shift?.auto_closed"
                class="mb-4 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                Попередня зміна ({{ last_shift?.date }}) була закрита автоматично о 03:00.
            </div>

            <form @submit.prevent="submit" class="space-y-6">

                <!-- ═══ Settlement Block (previous shift) ═══════════════ -->
                <div v-if="needsSettlement" class="card border-amber-200 bg-amber-50/30">
                    <h3 class="font-semibold text-amber-800 mb-1">
                        Врегулювання зміни від {{ calendarDay(previous_shift.date) }}
                    </h3>
                    <p class="text-xs text-amber-700 mb-4">
                        Показники підставлені автоматично. Відкоригуйте якщо потрібно.
                    </p>


                    <!-- Cash -->
                    <div class="space-y-3">
                        <p class="block text-xs font-medium text-gray-600 uppercase tracking-wide">
                            Звірка каси
                        </p>
                        <div class="flex justify-between items-center py-2 border-b border-amber-200/50 text-sm">
                            <span class="text-gray-600">Розрахунковий залишок</span>
                            <strong class="text-gray-800">{{ previous_shift.cash_calculated.toFixed(2) }} грн</strong>
                        </div>
                        <div>
                            <label for="cash-actual" class="block text-sm font-medium text-gray-700 mb-1">
                                Фактичний залишок
                            </label>
                            <input
                                id="cash-actual"
                                v-model="form.cash_actual"
                                type="number" step="0.01" min="0"
                                class="input"
                            />
                        </div>

                        <!-- Discrepancy -->
                        <div v-if="hasDiscrepancy"
                            class="px-3 py-2 rounded-lg text-sm"
                            :class="discrepancy > 0 ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
                            {{ discrepancy > 0 ? '+ Надлишок:' : '- Нестача:' }}
                            <strong>{{ Math.abs(discrepancy).toFixed(2) }} грн</strong>
                        </div>
                        <div v-if="hasDiscrepancy">
                            <label for="discrepancy-reason" class="block text-sm font-medium text-gray-700 mb-1">
                                Причина <span class="text-red-600">*</span>
                            </label>
                            <textarea id="discrepancy-reason" v-model="form.discrepancy_reason"
                                class="input resize-none" rows="2"
                                placeholder="Опишіть причину розбіжності..." required />
                        </div>
                    </div>

                    <!-- Ledger summary (collapsible) -->
                    <details v-if="previous_shift.ledger_history?.length > 0" class="mt-4 pt-3 border-t border-amber-200/50">
                        <summary class="text-xs font-medium text-gray-600 uppercase tracking-wide cursor-pointer">
                            Транзакції зміни ({{ previous_shift.ledger_history.length }})
                        </summary>
                        <div class="mt-2 space-y-1">
                            <div v-for="tx in previous_shift.ledger_history" :key="tx.id"
                                class="flex justify-between text-sm py-1 border-b border-gray-50 last:border-0">
                                <span class="text-gray-600">{{ tx.comment }}</span>
                                <span :class="tx.amount >= 0 ? 'text-green-700' : 'text-red-600'" class="font-mono text-xs">
                                    {{ tx.amount >= 0 ? '+' : '' }}{{ tx.amount }} грн
                                </span>
                            </div>
                        </div>
                    </details>
                </div>

                <!-- ═══ Morning Readings ══════════════════════════ -->
                <div v-if="equipment.length > 0" class="card">
                    <h3 class="font-semibold text-gray-700 mb-1">Ранкові показники</h3>
                    <p class="text-xs text-gray-600 mb-4">
                        Підставлені з попередніх. Змініть якщо відрізняються.
                    </p>
                    <div class="space-y-3">
                        <div v-for="(item, idx) in form.readings" :key="item.equipment_id">
                            <label :for="`reading-${item.equipment_id}`" class="block text-sm font-medium text-gray-700 mb-0.5">
                                {{ equipment[idx]?.name }}
                                <span class="text-xs text-gray-600 font-normal ml-1">({{ equipment[idx]?.type }})</span>
                            </label>
                            <input
                                :id="`reading-${item.equipment_id}`"
                                v-model="item.counter_value"
                                type="number" min="0"
                                class="input"
                                required
                                :aria-invalid="form.errors[`readings.${idx}.counter_value`] ? 'true' : undefined"
                                :aria-describedby="form.errors[`readings.${idx}.counter_value`] ? `reading-${item.equipment_id}-error` : undefined"
                            />
                            <p v-if="form.errors[`readings.${idx}.counter_value`]"
                                :id="`reading-${item.equipment_id}-error`"
                                class="text-xs text-red-600">
                                {{ form.errors[`readings.${idx}.counter_value`] }}
                            </p>
                            <p v-else-if="isUntouched(item, idx)" class="text-xs text-amber-600">
                                Не змінився з попередньої зміни — звірте з апаратом.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- No counters -->
                <div v-else class="card bg-gray-50 text-center py-6">
                    <p class="text-sm text-gray-600">Немає апаратів з лічильниками.</p>
                </div>

                <!-- Cash info -->
                <div class="px-4 py-3 rounded-lg bg-indigo-50 border border-indigo-200 text-sm text-indigo-800">
                    Стартовий залишок каси:
                    <strong class="ml-1">{{ startingCash.toFixed(2) }} грн</strong>
                </div>

                <button type="submit"
                    class="btn-primary w-full justify-center py-3 text-base"
                    :disabled="form.processing">
                    {{ form.processing ? 'Відкриваю…' : 'Відкрити зміну' }}
                </button>
            </form>
        </div>
    </AuthLayout>
</template>
