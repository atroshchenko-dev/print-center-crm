<script setup>
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'
import Icon from '@/Components/Icon.vue'
import { fmtQty } from '@/utils/fmtQty'

defineProps({
    procurement: { type: Object, required: true },
})

defineEmits(['receipt', 'refill'])

// AVCO-based estimate; null means «cost unknown», never a lying 0.00.
function fmtMoney(val) {
    return val === null || val === undefined ? '—' : `${Number(val).toFixed(2)} ₴`
}

const HORIZONS = [30, 60, 90]
const reloading = ref(false)

function setHorizon(days) {
    reloading.value = true
    router.reload({
        only: ['procurement'],
        data: { horizon: days },
        onFinish: () => { reloading.value = false },
    })
}

function daysBadgeClass(days) {
    if (days === null || days === undefined) return 'bg-gray-100 text-gray-600'
    if (days <= 3) return 'bg-red-100 text-red-700'
    if (days <= 14) return 'bg-amber-100 text-amber-700'
    return 'bg-gray-100 text-gray-600'
}

const COLOR_DOTS = { 'Червоний': 'bg-red-500', 'Синій': 'bg-blue-600' }
const expandedSizes = ref({})

function toggleSizes(color) {
    expandedSizes.value[color] = !expandedSizes.value[color]
}
</script>

<template>
    <div class="mt-8 space-y-6" :class="{ 'opacity-60 pointer-events-none': reloading }">
        <!-- Summary cards + horizon switcher -->
        <div class="flex flex-col lg:flex-row lg:items-center gap-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 flex-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                        <Icon name="banknote" size="5" />
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Орієнтовна сума</div>
                        <div class="text-xl font-bold text-gray-900">{{ fmtMoney(procurement.summary.total_cost) }}</div>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                        <Icon name="shopping-cart" size="5" />
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Позицій до закупівлі</div>
                        <div class="text-xl font-bold text-gray-900">{{ procurement.summary.items_count }}</div>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-red-100 text-red-700 flex items-center justify-center">
                        <Icon name="activity" size="5" />
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Критичних (≤ 3 дні)</div>
                        <div class="text-xl font-bold" :class="procurement.summary.critical_count > 0 ? 'text-red-700' : 'text-gray-900'">
                            {{ procurement.summary.critical_count }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1 self-start">
                <button v-for="h in HORIZONS" :key="h" @click="setHorizon(h)"
                    :class="[
                        'px-3 py-1.5 text-sm font-semibold rounded-md transition',
                        procurement.horizon_days === h ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-600 hover:text-gray-800'
                    ]">
                    {{ h }} дн.
                </button>
            </div>
        </div>

        <!-- Regular replenishment -->
        <div v-if="procurement.regular.length" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                <h2 class="text-lg font-bold text-gray-800">До закупівлі</h2>
                <p class="text-xs text-gray-600">Покриття споживання на {{ procurement.horizon_days }} днів. Суми — за середньою собівартістю (AVCO), орієнтовно</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left font-semibold text-gray-600 uppercase tracking-widest">Назва</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Залишок</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Темп/день</th>
                            <th scope="col" class="px-6 py-3 text-center font-semibold text-gray-600 uppercase tracking-widest">Днів лишилось</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Купити</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Сума</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Дії</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="row in procurement.regular" :key="row.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap border-r border-gray-100">
                                <div class="font-medium text-gray-900">{{ row.name }}</div>
                                <div class="text-xs text-gray-600">{{ row.category_name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right border-r border-gray-100">
                                <div class="font-bold" :class="row.days_left !== null && row.days_left <= 3 ? 'text-red-700' : 'text-gray-900'">
                                    {{ fmtQty(row.current_quantity) }} <span class="font-normal text-gray-600">{{ row.unit }}.</span>
                                </div>
                                <div v-if="row.reserve" class="text-xs text-gray-600 whitespace-normal max-w-52 ml-auto">
                                    + {{ fmtQty(row.reserve.qty) }} арк. «{{ row.reserve.name }}»
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-mono text-gray-700 border-r border-gray-100">
                                {{ row.daily_rate > 0 ? fmtQty(row.daily_rate) : '—' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center border-r border-gray-100">
                                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold" :class="daysBadgeClass(row.days_left)">
                                    {{ row.days_left === null ? '—' : `${row.days_left} дн.` }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right border-r border-gray-100">
                                <span class="text-lg font-bold text-indigo-700">{{ fmtQty(row.recommended_qty) }}</span>
                                <span class="text-gray-600 ml-1">{{ row.unit }}.</span>
                                <div v-if="row.reason === 'min_fallback'" class="text-xs text-gray-600">до 2× мінімуму</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-mono text-gray-700 bg-gray-50 border-r border-gray-100">
                                {{ fmtMoney(row.est_cost) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <button @click="$emit('receipt', row)" class="text-green-700 hover:text-green-900 font-medium">
                                    Оприбуткувати
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div v-else class="bg-emerald-50 border border-emerald-200 rounded-xl p-6 flex items-center gap-3">
            <Icon name="check-circle" size="5" class="text-emerald-600 flex-shrink-0" />
            <p class="text-sm text-emerald-800 font-medium">
                Запасів достатньо на горизонті {{ procurement.horizon_days }} днів.
            </p>
        </div>

        <!-- Hard-binding pairs (phased-out line) -->
        <div v-if="!procurement.pairs.all_empty && procurement.pairs.colors.length"
            class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between gap-2 flex-wrap">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Тверда палітурка — комплектація</h2>
                    <p class="text-xs text-gray-600">Докуповуємо лише те, чого бракує до пар «канал + обкладинка»</p>
                </div>
                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700">
                    Лінійка на виведенні
                </span>
            </div>
            <div class="p-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="block in procurement.pairs.colors" :key="block.color"
                    class="border border-gray-200 rounded-xl p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full" :class="COLOR_DOTS[block.color] || 'bg-gray-400'"></span>
                        <h3 class="font-semibold text-gray-800">{{ block.color }}</h3>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="bg-gray-50 rounded-lg py-2">
                            <div class="text-lg font-bold text-gray-900">{{ fmtQty(block.channels_total) }}</div>
                            <div class="text-xs text-gray-600">каналів</div>
                        </div>
                        <div class="bg-gray-50 rounded-lg py-2">
                            <div class="text-lg font-bold text-gray-900">{{ fmtQty(block.covers_total) }}</div>
                            <div class="text-xs text-gray-600">обкладинок</div>
                        </div>
                        <div class="bg-emerald-50 rounded-lg py-2">
                            <div class="text-lg font-bold text-emerald-700">{{ fmtQty(block.ready_sets) }}</div>
                            <div class="text-xs text-gray-600">комплектів</div>
                        </div>
                    </div>

                    <div v-if="block.buy_covers" class="flex items-center justify-between gap-2 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2">
                        <div class="text-sm text-indigo-900">
                            Докупити обкладинок: <span class="font-bold">{{ fmtQty(block.buy_covers.qty) }} шт.</span>
                            <span class="text-indigo-700">({{ fmtMoney(block.buy_covers.est_cost) }})</span>
                        </div>
                        <button @click="$emit('receipt', block.buy_covers)" class="text-green-700 hover:text-green-900 text-sm font-medium whitespace-nowrap">
                            Оприбуткувати
                        </button>
                    </div>

                    <div v-else-if="block.channel_deficit" class="bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                        <div class="text-sm text-amber-900">
                            Обкладинок без пари: <span class="font-bold">{{ fmtQty(block.channel_deficit) }}</span>
                            — докупіть каналів, розміри на ваш вибір:
                        </div>
                        <button @click="toggleSizes(block.color)" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium mt-1">
                            {{ expandedSizes[block.color] ? 'Сховати розміри' : 'Показати розміри' }}
                        </button>
                        <table v-if="expandedSizes[block.color]" class="w-full text-xs mt-2">
                            <thead>
                                <tr class="text-gray-600">
                                    <th class="text-left font-semibold py-1">Канал</th>
                                    <th class="text-right font-semibold py-1">Залишок</th>
                                    <th class="text-right font-semibold py-1">Спожито за 30 дн.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="size in block.channel_sizes" :key="size.id" class="border-t border-amber-100">
                                    <td class="py-1 text-gray-800">{{ size.name }}</td>
                                    <td class="py-1 text-right font-mono">{{ fmtQty(size.qty) }}</td>
                                    <td class="py-1 text-right font-mono">{{ fmtQty(size.used_30d) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else-if="block.covers_total === 0 && block.channels_total > 0"
                        class="text-sm text-amber-900 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                        Активної обкладинки цього кольору немає на складі — комплекти неможливі.
                    </div>

                    <div v-else class="text-sm text-gray-600 bg-gray-50 rounded-lg px-3 py-2">
                        Пари збалансовані — докуповувати нічого.
                    </div>
                </div>
            </div>
        </div>

        <!-- Toners: refills waiting + low-full warnings -->
        <div v-if="procurement.toners.refill.length || procurement.toners.low_full.length"
            class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                <h2 class="text-lg font-bold text-gray-800">Тонери та витратні матеріали</h2>
                <p class="text-xs text-gray-600">Порожні до заправки та запаси нижче мінімуму</p>
            </div>
            <div v-if="procurement.toners.refill.length" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left font-semibold text-gray-600 uppercase tracking-widest">Назва</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Порожніх</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Заправка/шт</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Сума</th>
                            <th scope="col" class="px-6 py-3 text-right font-semibold text-gray-600 uppercase tracking-widest">Дії</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="row in procurement.toners.refill" :key="row.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 border-r border-gray-100">{{ row.name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-bold text-gray-900 border-r border-gray-100">{{ fmtQty(row.empty_quantity) }} шт.</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-mono text-gray-700 border-r border-gray-100">{{ row.refill_cost > 0 ? fmtMoney(row.refill_cost) : '—' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-mono text-gray-700 bg-gray-50 border-r border-gray-100">{{ fmtMoney(row.est_cost) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <button @click="$emit('refill', row)" class="text-indigo-600 hover:text-indigo-900 font-medium">Заправити</button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="3" class="px-6 py-3 text-right font-semibold text-gray-700">Разом за заправку:</td>
                            <td class="px-6 py-3 text-right font-mono font-bold text-gray-900">{{ fmtMoney(procurement.toners.refill_total_cost) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="procurement.toners.low_full.length" class="p-4 space-y-2">
                <div v-for="row in procurement.toners.low_full" :key="row.id"
                    class="bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 text-sm text-amber-900">
                    <span class="font-semibold">{{ row.name }}</span>: повних {{ fmtQty(row.current_quantity) }} шт.
                    (мінімум {{ fmtQty(row.min_quantity) }}) — час заправити або докупити нові.
                </div>
            </div>
        </div>
    </div>
</template>
