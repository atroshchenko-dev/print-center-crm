<script setup>
/**
 * Admin/Settings — системні налаштування
 *
 * Sections:
 * 1. Кешування довідників (toggle)
 * 2. Способи оплати (multi-toggle)
 * 3. Telegram-сповіщення (toggle + threshold)
 * 4. Пороги та параметри (numeric inputs)
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    settings: Object,
    defaults: Object,
    paymentMethods: Array,
})

// ─── Toggles ─────────────────────────────────────
function toggleCache() {
    router.post(route('admin.settings.toggle-cache'), {}, { preserveScroll: true })
}

const togglingMethod = ref(null)
function togglePaymentMethod(method) {
    if (togglingMethod.value) return
    togglingMethod.value = method
    router.post(route('admin.settings.toggle-payment-method', method), {}, {
        preserveScroll: true,
        onFinish: () => { togglingMethod.value = null },
    })
}

const togglingTelegram = ref(false)
function toggleTelegram() {
    if (togglingTelegram.value) return
    togglingTelegram.value = true
    router.post(route('admin.settings.toggle-telegram'), {}, {
        preserveScroll: true,
        onFinish: () => { togglingTelegram.value = false },
    })
}

// ─── Numeric settings ────────────────────────────
const numericFields = [
    {
        key: 'large_order_threshold',
        label: 'Поріг «великого замовлення»',
        description: 'Мінімальна сума (₴) для Telegram-алерту про велике замовлення',
        suffix: '₴',
        step: 50,
        min: 0,
        icon: '📦',
        gradient: 'from-amber-400 to-orange-500',
    },
    {
        key: 'cash_discrepancy_threshold',
        label: 'Поріг розбіжності каси',
        description: 'Різниця (₴) між розрахунковою та фактичною касою для Telegram-алерту',
        suffix: '₴',
        step: 10,
        min: 0,
        icon: '⚠️',
        gradient: 'from-red-400 to-rose-500',
    },
    {
        key: 'riso_commercial_markup',
        label: 'Рисо — комерційна націнка',
        description: 'Множник для комерційної ціни Рисо (1.0 = без націнки, 2.0 = ×2)',
        suffix: '×',
        step: 0.1,
        min: 1,
        icon: '🖨️',
        gradient: 'from-violet-400 to-purple-500',
    },
    {
        key: 'approval_ttl_hours',
        label: 'TTL посилання погодження',
        description: 'Термін дії email-посилання для погодження замовлення підписантом',
        suffix: 'год.',
        step: 12,
        min: 1,
        icon: '🔗',
        gradient: 'from-sky-400 to-blue-500',
    },
]

// One form per numeric field.
//
// Four forms, one field each, and every one of them is called «значення» —
// so the banner `AppLayout` shows («Поле значення обов'язкове») is true and
// useless: it names a field this page has four of. That is why round 29 fixed
// this page even though the taxonomy calls a one-field form banner-enough —
// the rule assumed one form per page.
//
// Inertia keeps errors per form: a form fills its own `errors` in its own
// `onError` handler, so clearing the threshold on one card lights up that
// card only — the four do not share an error bag.
//
// (Written without naming the constructor: `FormsReportValidationErrorsTest`
// counts occurrences of that call in the text, and a comment mentioning it
// invented a fifth, unnamed form on this page. Noted there too.)
const numericForms = {}
numericFields.forEach(f => {
    numericForms[f.key] = useForm({
        key: f.key,
        value: props.settings[f.key] ?? props.defaults[f.key] ?? 0,
    })
})

function saveNumeric(key) {
    numericForms[key].post(route('admin.settings.update-numeric'), {
        preserveScroll: true,
    })
}

const telegramEnabled = computed(() => {
    const val = props.settings.telegram_enabled
    if (val === undefined || val === null) return true
    return val === true || val === 'true'
})

const cacheEnabled = computed(() => {
    const val = props.settings.cache_enabled
    if (val === undefined || val === null) return true
    return val === true || val === 'true'
})
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="page-header">
                <div class="page-header-icon bg-gradient-to-br from-gray-400 to-gray-600">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </div>
                <div>
                    <h1 class="page-header-title">Системні налаштування</h1>
                    <p class="page-header-subtitle">Кеш, оплата, Telegram-сповіщення, пороги та параметри</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- ─── Column 1: Toggles ─── -->
                <div class="space-y-6">

                    <!-- 1. Cache -->
                    <div class="card">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>
                            </div>
                            <h3 class="font-semibold text-gray-800">Кешування довідників</h3>
                        </div>
                        <div class="flex items-center justify-between">
                            <p class="text-sm text-gray-600">
                                Кешує послуги, категорії та тарифи на 1 годину.
                                <br>Вимкніть, якщо активно міняєте прайс-лист.
                            </p>
                            <button @click="toggleCache"
                                :class="[
                                    'relative inline-flex h-8 w-14 items-center rounded-full transition-colors duration-300 focus:outline-none flex-shrink-0 ml-4',
                                    cacheEnabled ? 'bg-green-500' : 'bg-gray-300',
                                ]">
                                <span :class="[
                                    'inline-block h-6 w-6 transform rounded-full bg-white shadow transition-transform duration-300',
                                    cacheEnabled ? 'translate-x-7' : 'translate-x-1',
                                ]"></span>
                            </button>
                        </div>
                        <p class="text-xs mt-3" :class="cacheEnabled ? 'text-green-700' : 'text-amber-700'">
                            {{ cacheEnabled ? 'Кеш увімкнено' : 'Кеш вимкнено — дані завжди йдуть з БД' }}
                        </p>
                    </div>

                    <!-- 2. Payment methods -->
                    <div class="card">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800">Способи оплати</h3>
                                <p class="text-xs text-gray-600 mt-0.5">Для комерційних замовлень</p>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div v-for="pm in paymentMethods" :key="pm.key"
                                class="flex items-center justify-between p-3 rounded-xl border transition-all duration-200"
                                :class="pm.enabled ? 'border-green-200 bg-green-50/50' : 'border-gray-200 bg-gray-50/50'">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-lg flex items-center justify-center"
                                        :class="pm.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'">
                                        <svg v-if="pm.key === 'cash'" class="w-4.5 h-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>
                                        </svg>
                                        <svg v-else class="w-4.5 h-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="font-medium text-sm" :class="pm.enabled ? 'text-gray-800' : 'text-gray-600'">{{ pm.label }}</p>
                                        <p class="text-xs" :class="pm.enabled ? 'text-green-700' : 'text-gray-600'">
                                            {{ pm.enabled ? 'Доступно' : 'Вимкнено' }}
                                        </p>
                                    </div>
                                </div>
                                <button @click="togglePaymentMethod(pm.key)"
                                    :disabled="togglingMethod === pm.key"
                                    :class="[
                                        'relative inline-flex h-7 w-12 items-center rounded-full transition-colors duration-300 focus:outline-none',
                                        pm.enabled ? 'bg-green-500' : 'bg-gray-300',
                                        togglingMethod === pm.key ? 'opacity-50' : '',
                                    ]">
                                    <span :class="[
                                        'inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform duration-300',
                                        pm.enabled ? 'translate-x-6' : 'translate-x-1',
                                    ]"></span>
                                </button>
                            </div>
                        </div>
                        <p class="text-xs text-gray-600 mt-3">Мінімум один спосіб повинен бути увімкненим</p>
                    </div>

                    <!-- 3. Telegram -->
                    <div class="card">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-sky-400 to-blue-500 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
                            </div>
                            <h3 class="font-semibold text-gray-800">Telegram-сповіщення</h3>
                        </div>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">
                                    Сповіщення про зміни, великі замовлення, розбіжності каси та склад.
                                </p>
                                <p class="text-xs text-gray-600 mt-1">Вимикає всі повідомлення в Telegram-бот</p>
                            </div>
                            <button @click="toggleTelegram"
                                :disabled="togglingTelegram"
                                :class="[
                                    'relative inline-flex h-8 w-14 items-center rounded-full transition-colors duration-300 focus:outline-none flex-shrink-0 ml-4',
                                    telegramEnabled ? 'bg-green-500' : 'bg-gray-300',
                                    togglingTelegram ? 'opacity-50' : '',
                                ]">
                                <span :class="[
                                    'inline-block h-6 w-6 transform rounded-full bg-white shadow transition-transform duration-300',
                                    telegramEnabled ? 'translate-x-7' : 'translate-x-1',
                                ]"></span>
                            </button>
                        </div>
                        <p class="text-xs mt-3" :class="telegramEnabled ? 'text-green-700' : 'text-amber-700'">
                            {{ telegramEnabled ? 'Сповіщення увімкнено' : 'Сповіщення вимкнено — бот мовчить' }}
                        </p>
                    </div>
                </div>

                <!-- ─── Column 2: Numeric settings ─── -->
                <div class="space-y-6">
                    <div v-for="field in numericFields" :key="field.key" class="card">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br flex items-center justify-center flex-shrink-0 text-base"
                                :class="field.gradient">
                                {{ field.icon }}
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800">{{ field.label }}</h3>
                                <p class="text-xs text-gray-600 mt-0.5">{{ field.description }}</p>
                            </div>
                        </div>
                        <form @submit.prevent="saveNumeric(field.key)" class="flex items-center gap-3">
                            <div class="relative flex-1 max-w-[200px]">
                                <input
                                    :aria-label="field.label"
                                    v-model.number="numericForms[field.key].value"
                                    type="number"
                                    :step="field.step"
                                    :min="field.min"
                                    class="input pr-10 text-sm"
                                />
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-600 pointer-events-none">
                                    {{ field.suffix }}
                                </span>
                            </div>
                            <button
                                type="submit"
                                class="btn-primary text-sm"
                                :disabled="numericForms[field.key].processing">
                                Зберегти
                            </button>
                        </form>
                        <p v-if="numericForms[field.key].errors.value" class="text-xs text-red-600 mt-2">
                            {{ numericForms[field.key].errors.value }}
                        </p>
                        <p v-if="numericForms[field.key].errors.key" class="text-xs text-red-600 mt-2">
                            {{ numericForms[field.key].errors.key }}
                        </p>
                        <p v-if="numericForms[field.key].recentlySuccessful" class="text-xs text-green-700 mt-2">
                            ✓ Збережено
                        </p>
                        <p class="text-xs text-gray-600 mt-2">
                            За замовчуванням: {{ defaults[field.key] }} {{ field.suffix }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
