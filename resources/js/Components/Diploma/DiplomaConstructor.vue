<script setup>
/**
 * DiplomaConstructor — specialized form for diploma/supplement services.
 *
 * Sections:
 *   1. Дипломи (A4 160g, 4+0 color, qty input)
 *   2. Додатки (4 types with editable sheet blocks per type)
 *   3. Академдовідки (A3 160g, 4+4 color, qty input)
 *   4. Копії (diploma copies + supplement copies, bw, mode selector)
 */
import { ref, computed, reactive } from 'vue'

const props = defineProps({
    service:    Object,
    clickCosts: { type: Object, default: () => ({ bw: 0.12, color: 0.60 }) },
    paperCosts: { type: Object, default: () => ({ a4_160: 0, a3_160: 0, a4_80: 0 }) },
    paperStock: { type: Object, default: () => ({ a4_160: { qty: 0 }, a3_160: { qty: 0 }, a4_80: { qty: 0 } }) },
})

const emit = defineEmits(['add-to-cart'])

// ─── State ───────────────────────────────────────────

// Section 1: Дипломи
const diplomaQty = ref(0)

// Section 2: Додатки (each with qty + editable block breakdown)
const supplements = reactive([
    {
        type: 'college', label: 'Коледж', qty: 0,
        blocks: [
            { sheets: 4, mode: '4+4' },
            { sheets: 1, mode: '4+0' },
            { sheets: 1, mode: '0+0' },
        ],
    },
    {
        type: 'bachelor', label: 'Бакалавр', qty: 0,
        blocks: [
            { sheets: 5, mode: '4+4' },
            { sheets: 1, mode: '0+0' },
        ],
    },
    {
        type: 'master', label: 'Магістр', qty: 0,
        blocks: [
            { sheets: 4, mode: '4+4' },
            { sheets: 1, mode: '4+0' },
            { sheets: 1, mode: '0+0' },
        ],
    },
    {
        type: 'phd', label: 'PhD', qty: 0,
        blocks: [
            { sheets: 4, mode: '4+4' },
            { sheets: 1, mode: '4+0' },
            { sheets: 1, mode: '0+0' },
        ],
    },
])

// Section 3: Академдовідки
const academicQty = ref(0)

// Section 4: Копії
const diplomaCopiesQty = ref(0)  // Always 1 sheet per diploma, mode 1+0 (fixed)

// Supplement copies: block-based (operator defines sheets per mode in 1 set)
const suppCopiesQty = ref(0)
const suppCopiesBlocks = reactive([
    { sheets: 1, mode: '1+0' },
])

// ─── Print modes ─────────────────────────────────────
const PRINT_MODES = [
    { value: '4+4', label: '4+4', desc: 'Колір 2 стор.' },
    { value: '4+0', label: '4+0', desc: 'Колір 1 стор.' },
    { value: '0+0', label: '0+0', desc: 'Пустий' },
]

const COPY_MODES = [
    { value: '1+0', label: '1+0', desc: 'Одностороння' },
    { value: '1+1', label: '1+1', desc: 'Двостороння' },
]

const totalSuppCopySheets = computed(() =>
    suppCopiesBlocks.reduce((sum, b) => sum + (b.sheets || 0), 0)
)

// ─── Pricing (client-side estimate) ──────────────────
function modeColorClicks(mode) {
    if (mode === '4+4') return 2
    if (mode === '4+0') return 1
    return 0
}

function modeBwClicks(mode) {
    return mode === '1+1' ? 2 : 1
}

const pricing = computed(() => {
    const cc = props.clickCosts
    const pc = props.paperCosts
    let totalCost = 0, totalColor = 0, totalBw = 0
    let sheets160 = 0, sheetsA3_160 = 0, sheets80 = 0

    // 1. Дипломи
    const dQty = diplomaQty.value || 0
    const dCost = dQty * (pc.a4_160 + 1 * cc.color) // 1 sheet, 1 color click
    totalCost += dCost
    totalColor += dQty * 1
    sheets160 += dQty

    // 2. Додатки
    let suppTotalCost = 0
    for (const supp of supplements) {
        const sq = supp.qty || 0
        if (sq <= 0) continue
        for (const block of supp.blocks) {
            const bs = block.sheets || 0
            const clicks = modeColorClicks(block.mode) * bs * sq
            const cost = (bs * sq * pc.a4_160) + (clicks * cc.color)
            suppTotalCost += cost
            totalColor += clicks
            sheets160 += bs * sq
        }
    }
    totalCost += suppTotalCost

    // 3. Академдовідки
    const aQty = academicQty.value || 0
    const aCost = aQty * (pc.a3_160 + 2 * cc.color) // 1 A3 sheet, 2 color clicks
    totalCost += aCost
    totalColor += aQty * 2
    sheetsA3_160 += aQty

    // 4. Копії дипломів (fixed: 1 sheet, 1+0 = 1 bw click)
    const dcQty = diplomaCopiesQty.value || 0
    const dcCost = dcQty * pc.a4_80 + dcQty * cc.bw
    totalCost += dcCost
    totalBw += dcQty
    sheets80 += dcQty

    // 5. Копії додатків (block-based: N sheets per mode × qty sets)
    const scQty = suppCopiesQty.value || 0
    if (scQty > 0) {
        for (const block of suppCopiesBlocks) {
            const bs = block.sheets || 0
            if (bs <= 0) continue
            const clicks = modeBwClicks(block.mode) * bs * scQty
            const cost = (bs * scQty * pc.a4_80) + (clicks * cc.bw)
            totalCost += cost
            totalBw += clicks
            sheets80 += bs * scQty
        }
    }

    return { totalCost, totalColor, totalBw, sheets160, sheetsA3_160, sheets80 }
})

const hasAnyQty = computed(() =>
    (diplomaQty.value || 0) > 0
    || supplements.some(s => (s.qty || 0) > 0)
    || (academicQty.value || 0) > 0
    || (diplomaCopiesQty.value || 0) > 0
    || (suppCopiesQty.value || 0) > 0
)

// ─── Add to cart ─────────────────────────────────────
function addToCart() {
    if (!hasAnyQty.value) return

    // Build description for cart item name
    const parts = []
    const details = []
    if (diplomaQty.value > 0) {
        parts.push(`Дипл: ${diplomaQty.value}`)
        details.push(`Дипл: ${diplomaQty.value} шт`)
    }
    for (const s of supplements) {
        if (s.qty > 0) {
            parts.push(`${s.label}: ${s.qty}`)
            const blocksDesc = s.blocks.filter(b => b.sheets > 0 && b.mode !== '0+0')
                .map(b => `${b.sheets}×${b.mode}`).join('+')
            details.push(`${s.label}: ${s.qty}${blocksDesc ? ' (' + blocksDesc + ')' : ''}`)
        }
    }
    if (academicQty.value > 0) {
        parts.push(`Акад: ${academicQty.value}`)
        details.push(`Акад: ${academicQty.value} шт`)
    }
    if (diplomaCopiesQty.value > 0) {
        parts.push(`Коп.дипл: ${diplomaCopiesQty.value}`)
        details.push(`Коп.дипл: ${diplomaCopiesQty.value}`)
    }
    if (suppCopiesQty.value > 0) {
        parts.push(`Коп.дод: ${suppCopiesQty.value}`)
        details.push(`Коп.дод: ${suppCopiesQty.value} компл.`)
    }

    emit('add-to-cart', {
        service_id: props.service.id,
        service_name: `Дипломи (${parts.join(', ')})`,
        quantity: 1,
        selected_option_ids: [],
        cart_details: details,
        diploma_params: {
            diplomas: { qty: diplomaQty.value || 0 },
            supplements: supplements
                .filter(s => (s.qty || 0) > 0)
                .map(s => ({
                    type: s.type,
                    label: s.label,
                    qty: s.qty,
                    blocks: s.blocks.map(b => ({ sheets: b.sheets || 0, mode: b.mode })),
                })),
            academic_records: { qty: academicQty.value || 0 },
            copies: {
                diploma_copies: { qty: diplomaCopiesQty.value || 0, mode: '1+0' },
                supplement_copies: {
                    qty: suppCopiesQty.value || 0,
                    blocks: suppCopiesBlocks
                        .filter(b => (b.sheets || 0) > 0)
                        .map(b => ({ sheets: b.sheets || 0, mode: b.mode })),
                },
            },
        },
        pricing: {
            unit_price_commercial: 0,
            total_price_commercial: 0,
            unit_price_cost: pricing.value.totalCost,
            total_price_cost: pricing.value.totalCost,
        },
    })

    // Reset
    diplomaQty.value = 0
    supplements.forEach(s => s.qty = 0)
    academicQty.value = 0
    diplomaCopiesQty.value = 0
    suppCopiesQty.value = 0
    suppCopiesBlocks.splice(0, suppCopiesBlocks.length, { sheets: 1, mode: '1+0' })
}
</script>

<template>
    <div class="flex flex-col h-full">
        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-5">

            <!-- ═══ SECTION 1: ДИПЛОМИ ═══ -->
            <div class="bg-indigo-50/60 rounded-xl p-4 space-y-3 border border-indigo-200/60">
                <h3 class="text-sm font-bold text-indigo-800 flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5zM6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/>
                    </svg>
                    Дипломи
                    <span class="ml-auto text-[10px] font-normal text-indigo-600">А4 · 160 г/м² · Колір (4+0)</span>
                </h3>
                <div class="flex items-center gap-3">
                    <label class="text-xs text-gray-600 font-medium">Кількість дипломів:</label>
                    <input aria-label="Кількість дипломів" v-model.number="diplomaQty" type="number" min="0"
                        class="input w-24 text-center text-sm font-bold" placeholder="0" />
                </div>
            </div>

            <!-- ═══ SECTION 2: ДОДАТКИ ═══ -->
            <div class="bg-emerald-50/60 rounded-xl p-4 space-y-3 border border-emerald-200/60">
                <h3 class="text-sm font-bold text-emerald-800 flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V7z"/>
                        <path d="M14 2v4a1 1 0 001 1h4M10 12h4M10 16h4"/>
                    </svg>
                    Додатки до дипломів
                    <span class="ml-auto text-[10px] font-normal text-emerald-400">А4 · 160 г/м² · Колір</span>
                </h3>

                <div v-for="(supp, si) in supplements" :key="supp.type"
                    class="bg-white rounded-lg border border-gray-200 p-3 space-y-2">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-semibold text-gray-700 min-w-[80px]">{{ supp.label }}</span>
                        <label class="text-xs text-gray-600">Кількість:</label>
                        <input aria-label="Кількість" v-model.number="supp.qty" type="number" min="0"
                            class="input w-20 text-center text-sm font-bold" placeholder="0" />
                    </div>
                    <!-- Editable blocks -->
                    <div class="flex flex-wrap gap-2 pl-1">
                        <div v-for="(block, bi) in supp.blocks" :key="bi"
                            class="flex items-center gap-1.5 bg-gray-50 rounded-lg px-2 py-1.5 border border-gray-100">
                            <input aria-label="Аркушів у блоці" v-model.number="block.sheets" type="number" min="0"
                                class="w-12 text-center text-xs font-bold border border-gray-200 rounded px-1 py-0.5" />
                            <span class="text-[10px] text-gray-600">арк</span>
                            <select aria-label="Режим друку блоку" v-model="block.mode"
                                class="text-xs border border-gray-200 rounded px-1 py-0.5 font-mono bg-white">
                                <option v-for="m in PRINT_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                        </div>
                        <button type="button"
                            class="text-[10px] text-emerald-700 hover:text-emerald-800 font-medium transition flex items-center gap-0.5 px-1"
                            @click="supp.blocks.push({ sheets: 1, mode: '4+4' })">
                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            блок
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══ SECTION 3: АКАДЕМДОВІДКИ ═══ -->
            <div class="bg-amber-50/60 rounded-xl p-4 space-y-3 border border-amber-200/60">
                <h3 class="text-sm font-bold text-amber-800 flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5v-15A2.5 2.5 0 016.5 2H20v20H6.5a2.5 2.5 0 010-5H20"/>
                    </svg>
                    Академдовідки
                    <span class="ml-auto text-[10px] font-normal text-amber-700">А3 · 160 г/м² · Колір (4+4)</span>
                </h3>
                <div class="flex items-center gap-3">
                    <label class="text-xs text-gray-600 font-medium">Кількість:</label>
                    <input aria-label="Кількість" v-model.number="academicQty" type="number" min="0"
                        class="input w-24 text-center text-sm font-bold" placeholder="0" />
                </div>
            </div>

            <!-- ═══ SECTION 4: КОПІЇ ═══ -->
            <div class="bg-gray-100/80 rounded-xl p-4 space-y-3 border border-gray-200/60">
                <h3 class="text-sm font-bold text-gray-700 flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="8" y="2" width="13" height="16" rx="2"/>
                        <path d="M4 6h0a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2"/>
                    </svg>
                    Копії
                    <span class="ml-auto text-[10px] font-normal text-gray-600">А4 · 80 г/м² · Ч/Б</span>
                </h3>

                <!-- Copies of diplomas (fixed: 1 sheet, 1+0) -->
                <div class="bg-white rounded-lg border border-gray-200 p-3">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="text-sm font-medium text-gray-600 min-w-[120px]">Копії дипломів</span>
                        <input aria-label="0" v-model.number="diplomaCopiesQty" type="number" min="0"
                            class="input w-20 text-center text-sm font-bold" placeholder="0" />
                        <span class="text-xs text-gray-600 font-mono">1 арк · 1+0 (ч/б)</span>
                    </div>
                </div>

                <!-- Copies of supplements (block-based: define per-set composition) -->
                <div class="bg-white rounded-lg border border-gray-200 p-3 space-y-2">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="text-sm font-medium text-gray-600 min-w-[120px]">Копії додатків</span>
                        <label class="text-xs text-gray-600">Кількість комплектів:</label>
                        <input aria-label="Кількість комплектів" v-model.number="suppCopiesQty" type="number" min="0"
                            class="input w-20 text-center text-sm font-bold" placeholder="0" />
                    </div>
                    <!-- Per-set block breakdown -->
                    <div class="pl-1">
                        <label class="text-[10px] text-gray-600 mb-1 block">Склад 1 комплекту копій:</label>
                        <div class="flex flex-wrap gap-2">
                            <div v-for="(block, bi) in suppCopiesBlocks" :key="bi"
                                class="flex items-center gap-1.5 bg-gray-50 rounded-lg px-2 py-1.5 border border-gray-100">
                                <input aria-label="Склад 1 комплекту копій" v-model.number="block.sheets" type="number" min="0"
                                    class="w-12 text-center text-xs font-bold border border-gray-200 rounded px-1 py-0.5" />
                                <span class="text-[10px] text-gray-600">арк</span>
                                <select aria-label="Режим друку блоку" v-model="block.mode"
                                    class="text-xs border border-gray-200 rounded px-1 py-0.5 font-mono bg-white">
                                    <option v-for="m in COPY_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
                                </select>
                                <button v-if="suppCopiesBlocks.length > 1" type="button"
                                    class="text-red-600 hover:text-red-800 transition"
                                    @click="suppCopiesBlocks.splice(bi, 1)">
                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 6L6 18M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                            <button type="button"
                                class="text-[10px] text-gray-600 hover:text-gray-800 font-medium transition flex items-center gap-0.5 px-1"
                                @click="suppCopiesBlocks.push({ sheets: 1, mode: '1+1' })">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 5v14M5 12h14"/>
                                </svg>
                                блок
                            </button>
                        </div>
                        <div v-if="suppCopiesQty > 0" class="mt-1 text-[10px] text-gray-600">
                            Всього арк/комплект: <span class="font-bold text-gray-600">{{ totalSuppCopySheets }}</span>
                            · Всього аркушів: <span class="font-bold text-gray-600">{{ totalSuppCopySheets * suppCopiesQty }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ SUMMARY ═══ -->
            <div v-if="hasAnyQty" class="bg-gray-50 rounded-xl p-4 space-y-1.5 text-sm">
                <div class="flex justify-between text-gray-600" v-if="pricing.sheets160 > 0">
                    <span>А4 160 г/м²:</span>
                    <span class="font-mono">
                        {{ pricing.sheets160 }} арк.
                        <span class="text-[10px] ml-1" :class="pricing.sheets160 > paperStock.a4_160.qty ? 'text-red-600 font-bold' : 'text-gray-600'">
                            (зал: {{ Math.floor(paperStock.a4_160.qty) }})
                        </span>
                    </span>
                </div>
                <div class="flex justify-between text-gray-600" v-if="pricing.sheetsA3_160 > 0">
                    <span>А3 160 г/м²:</span>
                    <span class="font-mono">
                        {{ pricing.sheetsA3_160 }} арк.
                        <span class="text-[10px] ml-1" :class="pricing.sheetsA3_160 > paperStock.a3_160.qty ? 'text-red-600 font-bold' : 'text-gray-600'">
                            (зал: {{ Math.floor(paperStock.a3_160.qty) }})
                        </span>
                    </span>
                </div>
                <div class="flex justify-between text-gray-600" v-if="pricing.sheets80 > 0">
                    <span>А4 80 г/м²:</span>
                    <span class="font-mono">
                        {{ pricing.sheets80 }} арк.
                        <span class="text-[10px] ml-1" :class="pricing.sheets80 > paperStock.a4_80.qty ? 'text-red-600 font-bold' : 'text-gray-600'">
                            (зал: {{ Math.floor(paperStock.a4_80.qty) }}<template v-if="paperStock.a4_80.conv_from"> + розр.</template>)
                        </span>
                    </span>
                </div>
                <div class="flex justify-between text-gray-600 text-xs" v-if="pricing.totalColor > 0 || pricing.totalBw > 0">
                    <span>Кліки: {{ pricing.totalColor }} колір · {{ pricing.totalBw }} ч/б</span>
                </div>
                <!-- Deficit warning -->
                <div v-if="(pricing.sheets160 > 0 && pricing.sheets160 > paperStock.a4_160.qty)
                    || (pricing.sheetsA3_160 > 0 && pricing.sheetsA3_160 > paperStock.a3_160.qty)
                    || (pricing.sheets80 > 0 && pricing.sheets80 > paperStock.a4_80.qty)"
                    class="mt-2 text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">
                    Паперу на складі може не вистачити. Перевірте залишки перед оформленням.
                </div>
            </div>
        </div>

        <!-- Sticky footer -->
        <div class="sticky bottom-0 bg-white border-t border-gray-200 shadow-up px-6 py-4">
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex-1 text-right">
                    <div v-if="hasAnyQty">
                        <span class="text-sm text-gray-600">Собівартість:</span>
                        <span class="ml-1 text-2xl font-bold text-indigo-700">
                            {{ pricing.totalCost.toFixed(2) }} грн
                        </span>
                    </div>
                    <div v-else class="text-gray-600 text-sm">Введіть кількості</div>
                </div>
                <button type="button" class="btn-primary flex-shrink-0 py-3 px-6 text-base"
                    :disabled="!hasAnyQty" @click="addToCart">
                    Додати ↵
                </button>
            </div>
        </div>
    </div>
</template>
