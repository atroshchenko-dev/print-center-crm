<script setup>
/**
 * BrochureConstructor — specialized constructor for brochure services.
 *
 * Structure: Format → Cover (paper + print) → Block (paper + mixed print entries) → Тираж
 * Block supports multiple print mode rows (e.g. 5 sheets 1+1 + 1 sheet 1+0).
 */
import { ref, computed, watch } from 'vue'
import { hasOfferableStock, stockState, isSelectable, STOCK_TITLES } from '@/composables/useStockAvailability'

const props = defineProps({
    service:    Object,
    papers:     { type: Array, default: () => [] },
    stock:      { type: Object, default: () => ({}) },
    clickCosts: { type: Object, default: () => ({ bw: 0.12, color: 0.60 }) },
    // Backdated forms record what already happened — today's shelf must not
    // decide what was orderable in July.
    enforceStock: { type: Boolean, default: true },
})

/** Sellable, not merely visible — owner's decision 2026-08-04. */
function isPaperDisabled(paper) {
    return props.enforceStock && !isSelectable(paper.id, props.stock)
}

const emit = defineEmits(['add-to-cart'])

// ─── State ───────────────────────────────────────────
const format       = ref('А4')
const coverPaperId = ref(null)
const coverMode    = ref('4+0')
const blockPaperId = ref(null)
const blockEntries = ref([{ mode: '1+0', sheets: 1 }])
const quantity     = ref(1)

// ─── Paper filtering by brochure format ──────────────
// Brochure format is the FINAL size after folding:
//   А4 brochure → uses А3 paper (folded in half → А4)
//   А5 brochure → uses А4 paper (folded in half → А5)
function papersForFormat(fmt) {
    return props.papers.filter(p => {
        const n = p.name
        if (fmt === 'А4' && !n.includes('А3')) return false // A4 brochure needs A3 paper
        if (fmt === 'А5' && !n.includes('А4')) return false // A5 brochure needs A4 paper

        // One rule for "can this be offered", shared with the constructor.
        // This used to be its own copy, and it disagreed about zero: it hid a
        // paper at exactly 0, while the constructor showed the same paper with
        // a red dot. Zero means "we are out of these, order them anyway" —
        // owner's decision 2026-07-31, the one R16-2 was decided by.
        return hasOfferableStock({ inventory_item_id: p.id }, props.stock)
    })
}

const coverPapers = computed(() => papersForFormat(format.value))
const blockPapers = computed(() => papersForFormat(format.value))

// Auto-select first paper when format changes
watch(format, () => {
    const cp = coverPapers.value
    const bp = blockPapers.value
    // Try to keep selection, fallback to first
    if (!cp.find(p => p.id === coverPaperId.value)) {
        coverPaperId.value = cp[0]?.id ?? null
    }
    if (!bp.find(p => p.id === blockPaperId.value)) {
        blockPaperId.value = bp[0]?.id ?? null
    }
}, { immediate: true })

// Initialize defaults
watch(() => props.papers, (papers) => {
    if (papers?.length && !coverPaperId.value) {
        // Never pre-select a paper the operator is not allowed to sell.
        const sellable = papers.filter(p => !isPaperDisabled(p))
        const pool = sellable.length ? sellable : []
        const heavy = pool.find(p => p.name.includes('300') || p.name.includes('250'))
        const light = pool.find(p => p.name.includes('80'))
        coverPaperId.value = (heavy || pool[0])?.id ?? null
        blockPaperId.value = (light || pool[0])?.id ?? null
    }
}, { immediate: true })

// ─── Print modes ─────────────────────────────────────
const PRINT_MODES = [
    { value: '1+0', label: 'Ч/Б 1+0', desc: '1 сторона' },
    { value: '1+1', label: 'Ч/Б 1+1', desc: '2 сторони' },
    { value: '4+0', label: 'Колір 4+0', desc: '1 сторона' },
    { value: '4+4', label: 'Колір 4+4', desc: '2 сторони' },
]

// ─── Block entries management ────────────────────────
function addBlockEntry() {
    blockEntries.value.push({ mode: '1+0', sheets: 1 })
}
function removeBlockEntry(index) {
    if (blockEntries.value.length > 1) {
        blockEntries.value.splice(index, 1)
    }
}

const totalBlockSheets = computed(() =>
    blockEntries.value.reduce((sum, e) => sum + (parseInt(e.sheets) || 0), 0)
)

// ─── Pricing (client-side estimate for display) ──────
// Click multiplier based on PAPER size used (not brochure format):
//   А4 brochure → А3 paper → 2 clicks per side
//   А5 brochure → А4 paper → 1 click per side
function parseClicks(mode, brochureFmt) {
    const m = brochureFmt === 'А4' ? 2 : 1 // А4 brochure = А3 paper = 2× clicks
    switch (mode) {
        case '1+0': return { bw: 1 * m, color: 0 }
        case '1+1': return { bw: 2 * m, color: 0 }
        case '4+0': return { bw: 0, color: 1 * m }
        case '4+4': return { bw: 0, color: 2 * m }
        default: return { bw: 0, color: 0 }
    }
}

const coverPaper = computed(() => props.papers.find(p => p.id === coverPaperId.value))
const blockPaper = computed(() => props.papers.find(p => p.id === blockPaperId.value))

// Resolve paper avg_cost with A3→A4 conversion fallback.
// When paper has avg_cost=0 but is convertible from a source (A3),
// use source's avg_cost ÷ conversion_ratio.
function resolveAvgCost(paper) {
    if (!paper) return 0
    const cost = parseFloat(paper.avg_cost ?? 0)
    if (cost > 0) return cost

    // Fallback: check stock data for convertible source
    const s = props.stock[paper.id]
    if (s?.conv_from && s?.conv_ratio > 0) {
        const sourcePaper = props.papers.find(p => p.id === s.conv_from)
        const sourceCost = parseFloat(sourcePaper?.avg_cost ?? 0)
        if (sourceCost > 0) return sourceCost / s.conv_ratio
    }
    return 0
}

const pricing = computed(() => {
    if (!coverPaperId.value || !blockPaperId.value || totalBlockSheets.value < 1) return null

    const coverPaperCost = resolveAvgCost(coverPaper.value)
    const blockPaperCost = resolveAvgCost(blockPaper.value)
    const bwCost    = props.clickCosts.bw
    const colorCost = props.clickCosts.color

    // Cover: 1 sheet per brochure (paper + clicks)
    const coverClicks = parseClicks(coverMode.value, format.value)
    const coverClickCost = (coverClicks.bw * bwCost) + (coverClicks.color * colorCost)
    const coverUnit = coverPaperCost + coverClickCost

    // Block: sum of all entries (paper + clicks)
    let blockUnit = 0
    let totalBw = coverClicks.bw, totalColor = coverClicks.color

    for (const entry of blockEntries.value) {
        const sheets = parseInt(entry.sheets) || 0
        if (sheets <= 0) continue
        const clicks = parseClicks(entry.mode, format.value)
        const entryBw = clicks.bw * sheets
        const entryColor = clicks.color * sheets
        blockUnit += (blockPaperCost * sheets) + (entryBw * bwCost) + (entryColor * colorCost)
        totalBw += entryBw
        totalColor += entryColor
    }

    const unitCost = coverUnit + blockUnit
    const totalCost = unitCost * quantity.value

    return {
        coverCost: coverUnit,
        coverClickCost,
        blockCost: blockUnit,
        unitCost,
        totalCost,
        totalBw: totalBw * quantity.value,
        totalColor: totalColor * quantity.value,
    }
})

const isValid = computed(() =>
    coverPaperId.value && blockPaperId.value && totalBlockSheets.value >= 1 && quantity.value >= 1
)

// ─── Paper display helpers (matching StepTileSelector) ──

function paperLabel(paper) {
    return paper.name
        .replace(/^Папір\s*/i, '')
}

function getIcon(name) {
    const sn = name.toLowerCase()
    if (sn.includes('рожев'))    return { type: 'dot', color: '#F9A8D4' }
    if (sn.includes('жовт'))     return { type: 'dot', color: '#FCD34D' }
    if (sn.includes('зелен'))    return { type: 'dot', color: '#86EFAC' }
    if (sn.includes('блакит'))   return { type: 'dot', color: '#93C5FD' }
    if (sn.includes('крейдован')) return { type: 'svg', value: 'coated' }
    if (sn.includes('200') || sn.includes('250') || sn.includes('300')) return { type: 'svg', value: 'heavy' }
    if (sn.includes('160') || sn.includes('150')) return { type: 'svg', value: 'medium' }
    return { type: 'svg', value: 'light' }
}

// ─── Paper grouping ──────────────────────────────────
function getPaperCategory(name) {
    const sn = name.toLowerCase()
    if (sn.includes('паст.') || sn.includes('пастельн')) return 'pastel'
    if (sn.includes('крейдован')) return 'coated'
    return 'plain'
}

function groupPapers(papers) {
    const categories = [
        { key: 'plain',  label: 'Звичайний',   items: [] },
        { key: 'pastel', label: 'Пастельний',  items: [] },
        { key: 'coated', label: 'Крейдований', items: [] },
    ]
    const catMap = Object.fromEntries(categories.map(c => [c.key, c]))
    for (const p of papers) {
        catMap[getPaperCategory(p.name)].items.push(p)
    }
    const nonEmpty = categories.filter(c => c.items.length > 0)
    return nonEmpty.length <= 1
        ? [{ key: 'all', label: null, items: papers }]
        : nonEmpty
}

const coverPaperGroups = computed(() => groupPapers(coverPapers.value))
const blockPaperGroups = computed(() => groupPapers(blockPapers.value))

// ─── Stock level indicator ───────────────────────────
// One vocabulary, shared. This copy was already complete — it and
// StepTileSelector were the only two that knew 'convertible'.
function stockLevel(paperId) {
    return stockState(paperId, props.stock)
}

// ─── Add to cart ─────────────────────────────────────
function addToCart() {
    if (!isValid.value || !pricing.value) return

    const entriesDesc = blockEntries.value
        .filter(e => parseInt(e.sheets) > 0)
        .map(e => `${e.sheets}×${e.mode}`)
        .join(', ')

    const coverName = coverPaper.value ? paperLabel(coverPaper.value) : ''
    const blockName = blockPaper.value ? paperLabel(blockPaper.value) : ''

    emit('add-to-cart', {
        service_id:   props.service.id,
        service_name: `Брошура ${format.value} (${entriesDesc})`,
        quantity:     quantity.value,
        selected_option_ids: [],
        brochure_format:         format.value,
        brochure_cover_paper_id: coverPaperId.value,
        brochure_cover_mode:     coverMode.value,
        brochure_block_paper_id: blockPaperId.value,
        brochure_block_entries:  blockEntries.value
            .filter(e => parseInt(e.sheets) > 0)
            .map(e => ({ mode: e.mode, sheets: parseInt(e.sheets) })),
        cart_details: [
            format.value,
            `Обкл: ${coverName} ${coverMode.value}`,
            `Блок: ${blockName} ${entriesDesc}`,
        ].filter(Boolean),
        pricing: {
            // Нуль тут був неправдою, а не «поки що невідомо»: сервер
            // рахує комерційну ціну брошури як собівартість × 2, і множник
            // цьому екрану не передається. `null` каже те, що є насправді —
            // порахує сервер.
            unit_price_commercial:  null,
            total_price_commercial: null,
            unit_price_cost:        pricing.value.unitCost,
            total_price_cost:       pricing.value.totalCost,
        },
    })

    // Reset for next
    blockEntries.value = [{ mode: '1+0', sheets: 1 }]
    quantity.value = 1
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
                    <button type="button" v-for="fmt in ['А4', 'А5']" :key="fmt"
                        :class="[
                            'relative flex flex-col items-center justify-center rounded-xl border-2 transition-all duration-200',
                            'focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-400',
                            'px-4 py-5 min-h-[100px] cursor-pointer',
                            format === fmt
                                ? 'border-indigo-500 bg-indigo-50 text-indigo-800 shadow-md ring-1 ring-indigo-200'
                                : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:bg-gray-50 hover:shadow-sm',
                        ]"
                        @click="format = fmt"
                    >
                        <span class="font-bold text-3xl">{{ fmt }}</span>
                        <span class="mt-1 font-mono text-gray-600 text-[10px]">
                            {{ fmt === 'А4' ? '210×297 мм' : '148×210 мм' }}
                        </span>
                        <div v-if="format === fmt"
                            class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-indigo-500 flex items-center justify-center">
                            <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                    </button>
                </div>
            </div>

            <!-- ═══ COVER SECTION ═══ -->
            <div class="bg-amber-50/60 rounded-xl p-4 space-y-4 border border-amber-200/60">
                <h3 class="text-sm font-bold text-amber-800 flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5v-15A2.5 2.5 0 016.5 2H20v20H6.5a2.5 2.5 0 010-5H20"/>
                    </svg>
                    Обкладинка
                </h3>

                <!-- Cover Paper -->
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block font-medium">Папір обкладинки *</label>
                    <div v-for="(cat, ci) in coverPaperGroups" :key="cat.key" :class="{ 'mt-2': ci > 0 }">
                        <div v-if="cat.label" class="flex items-center gap-2 mb-1.5" :class="{ 'mt-1': ci > 0 }">
                            <span class="text-xs font-medium text-gray-600 uppercase tracking-wide">{{ cat.label }}</span>
                            <div class="flex-1 border-t border-gray-100"></div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <button type="button" v-for="paper in cat.items" :key="paper.id"
                                :disabled="isPaperDisabled(paper)"
                                :title="STOCK_TITLES[stockLevel(paper.id)] ?? ''"
                                :class="[
                                    'relative flex flex-col items-center justify-center rounded-xl border-2 transition-all duration-200',
                                    'px-3 py-3 min-h-[72px]',
                                    isPaperDisabled(paper) ? 'opacity-40 cursor-not-allowed grayscale' : 'cursor-pointer',
                                    coverPaperId === paper.id
                                        ? 'border-amber-500 bg-amber-50 text-amber-800 shadow-md ring-1 ring-amber-200'
                                        : 'border-gray-200 bg-white text-gray-700 hover:border-amber-300 hover:bg-gray-50',
                                ]"
                                @click="coverPaperId = paper.id"
                            >
                                <span class="mb-1 flex items-center justify-center">
                                    <span v-if="getIcon(paper.name).type === 'dot'"
                                        class="inline-block rounded-full w-6 h-6"
                                        :style="{ backgroundColor: getIcon(paper.name).color, boxShadow: '0 0 0 3px ' + getIcon(paper.name).color + '33' }"></span>
                                    <svg v-else-if="getIcon(paper.name).value === 'light'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><line x1="9" y1="13" x2="15" y2="13" stroke-linecap="round" opacity="0.4"/></svg>
                                    <svg v-else-if="getIcon(paper.name).value === 'medium'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><line x1="9" y1="12" x2="15" y2="12" stroke-linecap="round" opacity="0.5"/><line x1="9" y1="15" x2="15" y2="15" stroke-linecap="round" opacity="0.5"/></svg>
                                    <svg v-else-if="getIcon(paper.name).value === 'heavy'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><line x1="9" y1="11" x2="15" y2="11" stroke-linecap="round" stroke-width="2" opacity="0.6"/><line x1="9" y1="14.5" x2="15" y2="14.5" stroke-linecap="round" stroke-width="2" opacity="0.6"/><line x1="9" y1="18" x2="13" y2="18" stroke-linecap="round" stroke-width="2" opacity="0.6"/></svg>
                                    <svg v-else-if="getIcon(paper.name).value === 'coated'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 11l2 2 4-4" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.7"/></svg>
                                </span>
                                <span class="text-center font-medium leading-tight text-xs">{{ paperLabel(paper) }}</span>
                                <div v-if="coverPaperId === paper.id"
                                    class="absolute top-1 right-1 w-4 h-4 rounded-full bg-amber-500 flex items-center justify-center">
                                    <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <span v-if="stockLevel(paper.id) === 'convertible'"
                                    class="absolute -bottom-1 -right-1 flex items-center gap-0.5 pointer-events-none"
                                    title="Немає на складі. Доступно через розрізку А3">
                                    <svg class="w-3.5 h-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg><span class="w-2.5 h-2.5 rounded-full bg-amber-400 ring-1 ring-white"></span>
                                </span>
                                <span v-else-if="stockLevel(paper.id)"
                                    class="absolute bottom-1 right-1 w-2.5 h-2.5 rounded-full ring-2 ring-white"
                                    :class="{ 'bg-green-400': stockLevel(paper.id) === 'ok', 'bg-amber-400': stockLevel(paper.id) === 'low', 'bg-red-400': stockLevel(paper.id) === 'out' }"
                                    :title="stockLevel(paper.id) === 'ok' ? 'В наявності' : stockLevel(paper.id) === 'low' ? 'Мало на складі' : 'Немає на складі'"
                                ></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Cover Print Mode -->
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block font-medium">Друк обкладинки *</label>
                    <div class="grid grid-cols-4 gap-2">
                        <button type="button" v-for="mode in PRINT_MODES" :key="mode.value"
                            :class="[
                                'rounded-lg border-2 px-3 py-2.5 text-center transition-all duration-200 cursor-pointer',
                                coverMode === mode.value
                                    ? 'border-amber-500 bg-amber-50 text-amber-800 font-bold shadow-sm'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-amber-300',
                            ]"
                            @click="coverMode = mode.value"
                        >
                            <div class="text-sm font-semibold">{{ mode.label }}</div>
                            <div class="text-[10px] text-gray-600 mt-0.5">{{ mode.desc }}</div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══ BLOCK SECTION ═══ -->
            <div class="bg-blue-50/60 rounded-xl p-4 space-y-4 border border-blue-200/60">
                <h3 class="text-sm font-bold text-blue-800 flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V7z"/>
                        <path d="M14 2v4a1 1 0 001 1h4M10 12h4M10 16h4"/>
                    </svg>
                    Блок (внутрішні сторінки)
                </h3>

                <!-- Block Paper -->
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block font-medium">Папір блоку *</label>
                    <div v-for="(cat, ci) in blockPaperGroups" :key="cat.key" :class="{ 'mt-2': ci > 0 }">
                        <div v-if="cat.label" class="flex items-center gap-2 mb-1.5" :class="{ 'mt-1': ci > 0 }">
                            <span class="text-xs font-medium text-gray-600 uppercase tracking-wide">{{ cat.label }}</span>
                            <div class="flex-1 border-t border-gray-100"></div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <button type="button" v-for="paper in cat.items" :key="paper.id"
                                :disabled="isPaperDisabled(paper)"
                                :title="STOCK_TITLES[stockLevel(paper.id)] ?? ''"
                                :class="[
                                    'relative flex flex-col items-center justify-center rounded-xl border-2 transition-all duration-200',
                                    'px-3 py-3 min-h-[72px]',
                                    isPaperDisabled(paper) ? 'opacity-40 cursor-not-allowed grayscale' : 'cursor-pointer',
                                    blockPaperId === paper.id
                                        ? 'border-blue-500 bg-blue-50 text-blue-800 shadow-md ring-1 ring-blue-200'
                                        : 'border-gray-200 bg-white text-gray-700 hover:border-blue-300 hover:bg-gray-50',
                                ]"
                                @click="blockPaperId = paper.id"
                            >
                                <span class="mb-1 flex items-center justify-center">
                                    <span v-if="getIcon(paper.name).type === 'dot'"
                                        class="inline-block rounded-full w-6 h-6"
                                        :style="{ backgroundColor: getIcon(paper.name).color, boxShadow: '0 0 0 3px ' + getIcon(paper.name).color + '33' }"></span>
                                    <svg v-else-if="getIcon(paper.name).value === 'light'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><line x1="9" y1="13" x2="15" y2="13" stroke-linecap="round" opacity="0.4"/></svg>
                                    <svg v-else-if="getIcon(paper.name).value === 'medium'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><line x1="9" y1="12" x2="15" y2="12" stroke-linecap="round" opacity="0.5"/><line x1="9" y1="15" x2="15" y2="15" stroke-linecap="round" opacity="0.5"/></svg>
                                    <svg v-else-if="getIcon(paper.name).value === 'heavy'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><line x1="9" y1="11" x2="15" y2="11" stroke-linecap="round" stroke-width="2" opacity="0.6"/><line x1="9" y1="14.5" x2="15" y2="14.5" stroke-linecap="round" stroke-width="2" opacity="0.6"/><line x1="9" y1="18" x2="13" y2="18" stroke-linecap="round" stroke-width="2" opacity="0.6"/></svg>
                                    <svg v-else-if="getIcon(paper.name).value === 'coated'" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1z" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2v5h5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 11l2 2 4-4" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.7"/></svg>
                                </span>
                                <span class="text-center font-medium leading-tight text-xs">{{ paperLabel(paper) }}</span>
                                <div v-if="blockPaperId === paper.id"
                                    class="absolute top-1 right-1 w-4 h-4 rounded-full bg-blue-500 flex items-center justify-center">
                                    <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <span v-if="stockLevel(paper.id) === 'convertible'"
                                    class="absolute -bottom-1 -right-1 flex items-center gap-0.5 pointer-events-none"
                                    title="Немає на складі. Доступно через розрізку А3">
                                    <svg class="w-3.5 h-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg><span class="w-2.5 h-2.5 rounded-full bg-amber-400 ring-1 ring-white"></span>
                                </span>
                                <span v-else-if="stockLevel(paper.id)"
                                    class="absolute bottom-1 right-1 w-2.5 h-2.5 rounded-full ring-2 ring-white"
                                    :class="{ 'bg-green-400': stockLevel(paper.id) === 'ok', 'bg-amber-400': stockLevel(paper.id) === 'low', 'bg-red-400': stockLevel(paper.id) === 'out' }"
                                    :title="stockLevel(paper.id) === 'ok' ? 'В наявності' : stockLevel(paper.id) === 'low' ? 'Мало на складі' : 'Немає на складі'"
                                ></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Block Print Entries (mixed modes) -->
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block font-medium">
                        Друк блоку * <span class="text-gray-600">(можна кілька режимів)</span>
                    </label>
                    <div class="space-y-3">
                        <div v-for="(entry, idx) in blockEntries" :key="idx"
                            class="bg-white rounded-xl border border-gray-200 p-3 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-gray-600">Рядок {{ idx + 1 }}</span>
                                <button v-if="blockEntries.length > 1" type="button"
                                    class="text-red-600 hover:text-red-800 transition p-0.5"
                                    @click="removeBlockEntry(idx)">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 6L6 18M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="grid grid-cols-4 gap-2">
                                <button type="button" v-for="mode in PRINT_MODES" :key="mode.value"
                                    :class="[
                                        'rounded-lg border-2 px-2 py-2 text-center transition-all duration-200 cursor-pointer',
                                        entry.mode === mode.value
                                            ? 'border-blue-500 bg-blue-50 text-blue-800 font-bold shadow-sm'
                                            : 'border-gray-200 bg-white text-gray-600 hover:border-blue-300',
                                    ]"
                                    @click="entry.mode = mode.value"
                                >
                                    <div class="text-xs font-semibold">{{ mode.label }}</div>
                                    <div class="text-[10px] text-gray-600 mt-0.5">{{ mode.desc }}</div>
                                </button>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-600">Кількість аркушів:</span>
                                <input aria-label="арк." v-model.number="entry.sheets" type="number" min="1"
                                    class="input w-20 text-center text-sm font-bold" placeholder="арк." />
                            </div>
                        </div>
                    </div>
                    <button type="button"
                        class="mt-2 text-xs text-blue-600 hover:text-blue-800 font-medium transition flex items-center gap-1"
                        @click="addBlockEntry">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        Додати режим друку
                    </button>
                    <div class="mt-2 text-xs text-gray-600">
                        Всього аркушів блоку: <span class="font-bold text-gray-700">{{ totalBlockSheets }}</span>
                    </div>
                </div>
            </div>

            <!-- ═══ SUMMARY ═══ -->
            <div v-if="pricing" class="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">
                <div class="flex justify-between text-gray-600">
                    <span>Обкладинка ({{ coverMode }}):</span>
                    <span class="font-mono">{{ pricing.coverCost.toFixed(2) }} грн</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>Блок ({{ totalBlockSheets }} арк.):</span>
                    <span class="font-mono">{{ pricing.blockCost.toFixed(2) }} грн</span>
                </div>
                <div class="flex justify-between text-gray-600 text-xs">
                    <span>Кліки: {{ pricing.totalBw / quantity }} ч/б + {{ pricing.totalColor / quantity }} колір / брошура</span>
                </div>
                <div class="border-t border-gray-200 pt-2 flex justify-between font-bold text-gray-800">
                    <span>Собівартість 1 брошури:</span>
                    <span class="text-indigo-700">{{ pricing.unitCost.toFixed(2) }} грн</span>
                </div>
            </div>
        </div>

        <!-- Sticky footer -->
        <div class="sticky bottom-0 bg-white border-t border-gray-200 shadow-up px-6 py-4">
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex-shrink-0">
                    <label class="block text-xs text-gray-600 mb-1">Тираж (шт. брошур)</label>
                    <input aria-label="Тираж (шт. брошур)" v-model.number="quantity" type="number" min="1"
                        class="input w-28 text-center text-lg font-bold"
                        @keydown.enter="isValid && addToCart()" />
                </div>
                <div class="flex-1 text-right">
                    <div v-if="pricing">
                        <span class="text-sm text-gray-600">Собівартість:</span>
                        <span class="ml-1 text-2xl font-bold text-indigo-700">
                            {{ pricing.totalCost.toFixed(2) }} грн
                        </span>
                    </div>
                    <div v-else class="text-gray-600 text-sm">Оберіть всі параметри</div>
                </div>
                <button type="button" class="btn-primary flex-shrink-0 py-3 px-6 text-base"
                    :disabled="!isValid || !pricing" @click="addToCart">
                    Додати ↵
                </button>
            </div>
        </div>
    </div>
</template>
