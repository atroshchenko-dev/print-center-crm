<script setup>
/**
 * RisoCalculator — live pricing form for risograph duplication.
 *
 * Props: service, tiers[], papers[], paperCost (fallback)
 * Emits: add-to-cart(item)
 */
import { ref, computed, watch } from 'vue'
import { findRisoTier } from '@/composables/useRisoTier'
import { hasOfferableStock, stockState, isSelectable, STOCK_TITLES } from '@/composables/useStockAvailability'

const props = defineProps({
    service:   Object,
    tiers:     Array,
    papers:    { type: Array, default: () => [] },
    paperCost: Number,
    stock:     { type: Object, default: () => ({}) },
    allowBelowMinimum: { type: Boolean, default: false },
    // Backdated forms record what already happened — today's shelf must not
    // decide what was orderable in July.
    enforceStock: { type: Boolean, default: true },
})

const emit = defineEmits(['add-to-cart'])

const format    = ref('A3')
const sides     = ref(1)
const originals = ref(1)
const copies    = ref(100)

// ─── Paper selection ─────────────────────────────────
const selectedPaperId = ref(null)

// Auto-select first A3 80g paper if available
watch(() => props.papers, (papers) => {
    if (papers?.length && !selectedPaperId.value) {
        // Prefer "Папір А3 80 г/м²" as default
        // Never pre-select a paper the operator is not allowed to sell — that
        // would put an order on a sheet nobody has.
        const a3 = papers
            .filter(p => p.name.includes('А3') || p.name.includes('A3'))
            .filter(p => !props.enforceStock || isSelectable(p.id, props.stock))
        const defaultPaper = a3.find(p => p.name.includes('80')) || a3[0]
        selectedPaperId.value = defaultPaper?.id ?? null
    }
}, { immediate: true })

const selectedPaper = computed(() =>
    props.papers?.find(p => p.id === selectedPaperId.value) ?? null
)

// Use selected paper's avg_cost, fallback to config paperCost
const effectivePaperCost = computed(() => {
    if (selectedPaper.value && parseFloat(selectedPaper.value.avg_cost) > 0) {
        return parseFloat(selectedPaper.value.avg_cost)
    }
    return props.paperCost ?? 0
})

// ─── Calculations ────────────────────────────────────
const sheetsPerOriginal = computed(() =>
    format.value === 'A4' ? Math.ceil(copies.value / 2) : copies.value
)
const sheetsA3 = computed(() => sheetsPerOriginal.value * originals.value)

const minCopies = computed(() => format.value === 'A4' ? 2 : 1)

// Правило вибору тарифу винесено в `useRisoTier` і покрите тестом.
//
// Тут стояло `if (!exact && props.allowBelowMinimum) return tiers[0]`, тобто
// відкат ловив **будь-який** тираж без тарифу — включно з пропуском усередині
// драбини — і віддавав найдешевший за номером, тобто найдорожчий за ціною.
// Той самий дефект, що й на сервері, а коментар поруч стверджував, що
// ця гілка «matches backend RisoPriceTier::findForQuantity».
const activeTier = computed(() => findRisoTier(props.tiers, sheetsA3.value, props.allowBelowMinimum))

const tierLabel = computed(() => {
    const t = activeTier.value
    if (!t) return '—'
    return t.max_qty ? `${t.min_qty}–${t.max_qty}` : `${t.min_qty}+`
})

const pricing = computed(() => {
    const t = activeTier.value
    if (!t) return null
    const costPerCopy = parseFloat(t.cost_per_copy)
    const printCost = sheetsA3.value * costPerCopy * sides.value
    const paperCost = sheetsA3.value * effectivePaperCost.value
    return {
        costPerCopy,
        printCost: Math.round(printCost * 100) / 100,
        paperCost: Math.round(paperCost * 100) / 100,
        totalCost: Math.round((printCost + paperCost) * 100) / 100,
    }
})

// ─── Paper grouping & icons (matches StepTileSelector) ────
function getPaperType(paper) {
    const n = paper.name.toLowerCase()
    if (n.includes('паст')) return 'pastel'
    if (n.includes('крейдован')) return 'coated'
    return 'plain'
}

// Riso always prints on A3 sheets — filter out non-A3 papers and those with no stock
const a3Papers = computed(() =>
    props.papers.filter(p => {
        if (!p.name.includes('А3') && !p.name.includes('A3')) return false

        // One rule for "can this be offered", shared with the constructor.
        // The copy that used to live here was the harshest of the three: it hid
        // a paper at exactly 0 and ignored convertibility entirely, while the
        // constructor offered the same sheet with a red dot. Zero means "we are
        // out of these, order them anyway" — owner's decision 2026-07-31.
        return hasOfferableStock({ inventory_item_id: p.id }, props.stock)
    })
)

const paperGroups = computed(() => {
    const groups = [
        { key: 'plain', label: 'Звичайний', items: [] },
        { key: 'pastel', label: 'Пастельний', items: [] },
        { key: 'coated', label: 'Крейдований', items: [] },
    ]
    const map = Object.fromEntries(groups.map(g => [g.key, g]))
    for (const p of a3Papers.value) {
        map[getPaperType(p)].items.push(p)
    }
    // Sort each group by weight (80 → 160 → 200 → 250 → 300)
    const weightOf = (p) => {
        const m = p.name.match(/(\d+)\s*г/)
        return m ? parseInt(m[1]) : 999
    }
    for (const g of groups) {
        g.items.sort((a, b) => weightOf(a) - weightOf(b))
    }
    const filled = groups.filter(g => g.items.length > 0)
    return filled.length <= 1
        ? [{ key: 'all', label: null, items: a3Papers.value }]
        : filled
})

function paperIcon(paper) {
    const n = paper.name.toLowerCase()
    if (n.includes('рожев')) return { type: 'dot', color: '#F9A8D4' }
    if (n.includes('жовт')) return { type: 'dot', color: '#FCD34D' }
    if (n.includes('зелен')) return { type: 'dot', color: '#86EFAC' }
    if (n.includes('блакит')) return { type: 'dot', color: '#93C5FD' }
    if (n.includes('крейдован')) return { type: 'coated' }
    if (n.includes('200') || n.includes('250') || n.includes('300')) return { type: 'weight', lines: 3 }
    if (n.includes('160') || n.includes('150')) return { type: 'weight', lines: 2 }
    return { type: 'weight', lines: 1 }
}

function paperLabel(paper) {
    return paper.name
        .replace(/^Папір А[34]\s*/i, '')
        .replace(/^А[34]\s*/i, '')
}

// One vocabulary, shared. This copy had never heard of 'convertible'.
function stockLevel(paperId) {
    return stockState(paperId, props.stock)
}

/** Sellable, not merely visible — owner's decision 2026-08-04. */
function isPaperDisabled(paper) {
    return props.enforceStock && !isSelectable(paper.id, props.stock)
}

function addToCart() {
    if (!pricing.value) return
    const totalQty = copies.value * originals.value
    const paperName = selectedPaper.value ? selectedPaper.value.name.replace(/^Папір\s*/i, '') : ''
    emit('add-to-cart', {
        service_id:          props.service.id,
        service_name:        `${props.service.name} (${format.value}, ${sides.value} ст., ${originals.value} ориг.)`,
        quantity:            totalQty,
        selected_option_ids: [],
        riso_format:         format.value,
        riso_sides:          sides.value,
        riso_originals:      originals.value,
        riso_paper_id:       selectedPaperId.value,
        cart_details:        [
            format.value === 'A3' ? 'А3' : 'А4',
            paperName,
            `${sides.value === 1 ? '1+0' : '1+1'}`,
            `${originals.value} ориг. × ${copies.value} коп.`,
        ].filter(Boolean),
        pricing: {
            // Комерційної ціни цей екран не знає — і більше не вдає.
            //
            // Тут стояла собівартість, така сама, як у полі поруч. Сервер
            // множить її на `riso_commercial_markup` (2,0 за замовчуванням),
            // а сторінці той множник не передається взагалі, тож порахувати
            // її клієнт не міг би, навіть якби хотів. `null` означає «порахує
            // сервер» — і, на відміну від нуля чи собівартості, не додається
            // до підсумку як число.
            unit_price_commercial:  null,
            total_price_commercial: null,
            unit_price_cost:        pricing.value.totalCost > 0 ? Math.round(pricing.value.totalCost / totalQty * 10000) / 10000 : 0,
            total_price_cost:       pricing.value.totalCost,
        },
    })
}
</script>

<template>
    <div class="flex flex-col h-full">
        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-6">

            <!-- Format -->
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-gray-700">Формат</h3>
                    <span class="text-xs text-red-600">*</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <button type="button" v-for="fmt in ['A3', 'A4']" :key="fmt"
                        :class="[
                            'relative flex flex-col items-center justify-center rounded-xl border-2 transition-all duration-200',
                            'focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-400',
                            'px-4 py-5 min-h-[100px]',
                            format === fmt
                                ? 'border-indigo-500 bg-indigo-50 text-indigo-800 shadow-md ring-1 ring-indigo-200'
                                : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:bg-gray-50 hover:shadow-sm',
                            'cursor-pointer',
                        ]"
                        @click="format = fmt"
                    >
                        <span class="mb-2 flex items-center justify-center">
                            <span class="font-bold text-current text-3xl">{{ fmt === 'A3' ? 'А3' : 'А4' }}</span>
                        </span>
                        <span class="mt-0.5 font-mono text-gray-600 text-[10px]">{{ fmt === 'A3' ? '297×420 мм' : '210×297 мм' }}</span>
                        <div v-if="format === fmt"
                            class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-indigo-500 flex items-center justify-center">
                            <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Paper selection (grouped rows like print constructor) -->
            <div v-if="papers && papers.length > 0">
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-gray-700">Папір</h3>
                </div>
                <div v-for="(group, gi) in paperGroups" :key="group.key" :class="{ 'mt-3': gi > 0 }">
                    <div v-if="group.label" :class="['flex items-center gap-2 mb-2', { 'mt-1': gi > 0 }]">
                        <span class="text-xs font-medium text-gray-600 uppercase tracking-wide">{{ group.label }}</span>
                        <div class="flex-1 border-t border-gray-100"></div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button type="button" v-for="paper in group.items" :key="paper.id"
                            :disabled="isPaperDisabled(paper)"
                            :title="STOCK_TITLES[stockLevel(paper.id)] ?? ''"
                            :class="[
                                'relative flex flex-col items-center justify-center rounded-xl border-2 transition-all duration-200',
                                'focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-400',
                                'px-3 py-3 min-h-[72px]',
                                selectedPaperId === paper.id
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-800 shadow-md ring-1 ring-indigo-200'
                                    : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:bg-gray-50 hover:shadow-sm',
                                isPaperDisabled(paper) ? 'opacity-40 cursor-not-allowed grayscale' : 'cursor-pointer',
                            ]"
                            @click="selectedPaperId = paper.id"
                        >
                            <span class="mb-1 flex items-center justify-center">
                                <!-- Pastel: colored dot -->
                                <span v-if="paperIcon(paper).type === 'dot'"
                                    class="inline-block rounded-full w-6 h-6"
                                    :style="{ backgroundColor: paperIcon(paper).color, boxShadow: '0 0 0 3px ' + paperIcon(paper).color + '33' }"></span>
                                <!-- Coated: glossy-check icon -->
                                <svg v-else-if="paperIcon(paper).type === 'coated'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M9 11l2 2 4-4" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.7"/>
                                    <path d="M17 16l-1-1 1-1" stroke="#a5b4fc" stroke-width="1" stroke-linecap="round" opacity="0.5"/>
                                </svg>
                                <!-- Plain: weight lines -->
                                <svg v-else class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <line x1="9" y1="13" x2="15" y2="13" stroke-linecap="round" opacity="0.5"/>
                                    <line v-if="paperIcon(paper).lines >= 2" x1="9" y1="16" x2="15" y2="16" stroke-linecap="round" opacity="0.5"/>
                                    <line v-if="paperIcon(paper).lines >= 3" x1="9" y1="19" x2="13" y2="19" stroke-linecap="round" opacity="0.5"/>
                                </svg>
                            </span>
                            <span class="text-center font-medium leading-tight text-xs">{{ paperLabel(paper) }}</span>
                            <span v-if="parseFloat(paper.avg_cost) > 0"
                                :class="['mt-0.5 text-[10px]', selectedPaperId === paper.id ? 'text-indigo-500' : 'text-gray-600']">
                                {{ parseFloat(paper.avg_cost).toFixed(2) }} грн
                            </span>
                            <div v-if="selectedPaperId === paper.id"
                                class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-indigo-500 flex items-center justify-center">
                                <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <span v-if="stockLevel(paper.id)"
                                class="absolute bottom-1.5 right-1.5 w-2.5 h-2.5 rounded-full ring-2 ring-white"
                                :class="{ 'bg-green-400': stockLevel(paper.id) === 'ok', 'bg-amber-400': stockLevel(paper.id) === 'low', 'bg-red-400': stockLevel(paper.id) === 'out' }"
                                :title="stockLevel(paper.id) === 'ok' ? 'В наявності' : stockLevel(paper.id) === 'low' ? 'Мало на складі' : 'Немає на складі'"
                            ></span>
                        </button>
                    </div>
                </div>
                <p v-if="selectedPaper && parseFloat(selectedPaper.avg_cost) === 0" class="text-xs text-amber-700 mt-1">
                    ⚠ Папір ще не оприбутковано — вартість 0
                </p>
            </div>

            <!-- Sides -->
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-gray-700">Сторонність</h3>
                    <span class="text-xs text-red-600">*</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <button type="button" v-for="s in [1, 2]" :key="s"
                        :class="[
                            'relative flex flex-col items-center justify-center rounded-xl border-2 transition-all duration-200',
                            'focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-400',
                            'px-4 py-5 min-h-[100px]',
                            sides === s
                                ? 'border-indigo-500 bg-indigo-50 text-indigo-800 shadow-md ring-1 ring-indigo-200'
                                : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:bg-gray-50 hover:shadow-sm',
                            'cursor-pointer',
                        ]"
                        @click="sides = s"
                    >
                        <span class="mb-2 flex items-center justify-center">
                            <span class="text-3xl">{{ s === 1 ? '◐' : '◑' }}</span>
                        </span>
                        <span class="text-center font-medium leading-tight text-sm">{{ s === 1 ? '1+0' : '1+1' }}</span>
                        <div v-if="sides === s"
                            class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-indigo-500 flex items-center justify-center">
                            <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Calculation breakdown -->
            <div v-if="pricing" class="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">
                <div v-if="originals > 1" class="flex justify-between text-gray-600">
                    <span>Оригіналів × копій:</span>
                    <span class="font-mono font-semibold text-gray-700">{{ originals }} × {{ copies }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>Аркушів А3 (всього):</span>
                    <span class="font-mono font-semibold text-gray-700">{{ sheetsA3 }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>Тариф <span class="text-xs text-indigo-500">({{ tierLabel }})</span>:</span>
                    <span class="font-mono">×{{ pricing.costPerCopy.toFixed(4) }} грн</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>Друк ({{ sheetsA3 }} × {{ pricing.costPerCopy.toFixed(4) }} × {{ sides }}):</span>
                    <span class="font-mono">{{ pricing.printCost.toFixed(2) }} грн</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <!--
                        Four decimals, like the click cost two lines up. The paper
                        rate is an AVCO figure carried to four places; printing the
                        factor at two made the line contradict its own result —
                        «Папір (100 × 0.87): 87.25» when 100 × 0.87 is 87.00, and
                        «198 × 0.87: 172.76» when that is 172.26. The true rate was
                        0.8725 both times. Confirmed on production 2026-07-31 with
                        two runs. Anyone checking the arithmetic by hand got a
                        different number and had every reason to distrust the total.
                    -->
                    <span>Папір ({{ sheetsA3 }} × {{ effectivePaperCost.toFixed(4) }}):</span>
                    <span class="font-mono">{{ pricing.paperCost.toFixed(2) }} грн</span>
                </div>
                <div class="border-t border-gray-200 pt-2 flex justify-between font-bold text-gray-800">
                    <span>Собівартість:</span>
                    <span class="text-indigo-700 text-lg">{{ pricing.totalCost.toFixed(2) }} грн</span>
                </div>
            </div>

            <div v-else class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
                Не знайдено тариф для {{ sheetsA3 }} аркушів А3
            </div>
        </div>

        <!-- Sticky bottom bar (matches ConstructorPanel) -->
        <div class="sticky bottom-0 bg-white border-t border-gray-200 shadow-up px-6 py-4">
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex-shrink-0">
                    <label class="block text-xs text-gray-600 mb-1">Оригіналів</label>
                    <input aria-label="Оригіналів" v-model.number="originals" type="number" min="1" step="1"
                        class="input w-20 text-center text-lg font-bold" />
                </div>
                <div class="flex-shrink-0">
                    <label class="block text-xs text-gray-600 mb-1">Копій кожного</label>
                    <input aria-label="Копій кожного" v-model.number="copies" type="number" :min="minCopies" step="1"
                        class="input w-28 text-center text-lg font-bold" />
                </div>
                <div class="flex-1 text-right">
                    <div v-if="pricing">
                        <span class="text-sm text-gray-600">Собівартість:</span>
                        <span class="ml-1 text-2xl font-bold text-indigo-700">{{ pricing.totalCost.toFixed(2) }} грн</span>
                    </div>
                    <div v-else class="text-gray-600 text-sm">Оберіть всі параметри</div>
                </div>
                <button type="button" class="btn-primary flex-shrink-0 py-3 px-6 text-base"
                    :disabled="!pricing || copies < minCopies || originals < 1" @click="addToCart">
                    Додати ↵
                </button>
            </div>
            <p v-if="format === 'A4'" class="text-xs text-gray-600 mt-1">
                мін. {{ minCopies }} копій А4 (= 1 аркуш А3)
            </p>
        </div>
    </div>
</template>
