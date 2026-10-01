<script setup>
/**
 * Orders/Create — кошик замовлення
 *
 * Ліва колонка: список послуг / Constructor
 * Права колонка: кошик (позиції + підсумок)
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import ConstructorPanel from '@/Components/Constructor/ConstructorPanel.vue'
import RisoCalculator from '@/Components/Riso/RisoCalculator.vue'
import BrochureConstructor from '@/Components/Brochure/BrochureConstructor.vue'
import DiplomaConstructor from '@/Components/Diploma/DiplomaConstructor.vue'
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { commercialTotal, commercialUnknown } from '@/composables/useCartTotals'
import CostCenterSelect from '@/Components/CostCenterSelect.vue'
import { sortSignatories } from '@/utils/sortSignatories'
import {
    displayCategories as buildDisplayCategories,
    itemsOutsideCategories,
    describeDropped,
    groupCartByCategory,
} from '@/composables/useOrderCategories'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'

// ─── Cart panel visibility on mobile ──────────────────
const cartOpen = ref(false)

const props = defineProps({
    services:       Array,
    categories:     Array,
    departments:    Array,
    signatories:    Array,
    riso_tiers:     Array,
    riso_papers:    Array,
    riso_paper_cost: Number,
    inventory_stock: Object,
    brochure_papers: Array,
    brochure_click_costs: Object,
    diploma_click_costs: Object,
    prefill:        Object,     // from ?repeat=ORDER_ID
    signatory_cost_centers: { type: Object, default: () => ({}) },
    cost_center_initiators: { type: Object, default: () => ({}) },
})

// ─── Order meta (must be before computed that reference them) ──
const orderType = ref(props.prefill?.type ?? 'internal')
const authorizedPerson = ref(props.prefill?.authorized_person ?? '')
const costCenter       = ref(props.prefill?.cost_center ?? '')
const initiator        = ref('')
const isAtCost         = ref(false)

// Reset at-cost when switching to internal
watch(orderType, (newType) => {
    if (newType === 'internal') isAtCost.value = false
})

// ─── Category Icons (Lucide SVG paths) ────────────────
const CATEGORY_ICONS = {
    'Чорно-білий друк':  'M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6M6 14h12v8H6z', // printer
    'Кольоровий друк':   'M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2zM5.5 12a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm3-4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm7 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm3 4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z', // palette
    'Сканування':        'M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M3 12h18', // scan-line
    'Палітурка тверда':  'M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20', // book
    "Палітурка м'яка":   'M12 7v14M2 12h4M18 12h4M4 7c0-1.1.9-2 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z', // book-open simplified
    'Ламінування':       'M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83zM2 12l8.58 3.91a2 2 0 0 0 1.66 0L22 12M2 17l8.58 3.91a2 2 0 0 0 1.66 0L22 17', // layers
    'Дипломи/Додатки':   'M22 10v6M2 10l10-5 10 5-10 5zM6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5', // graduation-cap
    'Тиражування':       'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7zM14 2v4a1 1 0 0 0 1 1h4M10 12h4M10 16h4M10 8h1', // file-text (single doc for riso)
    'Брошури':           'M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H14l6 6v11.5a2 2 0 0 1-2 2H6.5A2.5 2.5 0 0 1 4 19.5zM14 2v6h6M9 13h6M9 17h4', // booklet
    'Візитівки':         'M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18', // credit-card
    'Розрізання паперу': 'M6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 15a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12', // scissors
}

// ─── Categories & Services ────────────────────────────
// Resolve selected signatory object (for internal orders)
const selectedSignatory = computed(() => {
    if (orderType.value !== 'internal' || !authorizedPerson.value) return null
    return props.signatories.find(s => s.full_name === authorizedPerson.value) ?? null
})

// Список приходив у порядку таблиці; за абетку відповідає фронт — причина
// записана в sortSignatories.js.
const sortedSignatories = computed(() => sortSignatories(props.signatories))

// Центри витрат обраного підписанта, найчастіші першими. Порожній масив —
// підписанта не обрано або за ним ще нічого не назбиралось.
const signatoryCentres = computed(() =>
    props.signatory_cost_centers?.[selectedSignatory.value?.id] ?? []
)

// Мапа ключована id підрозділу, а поле тримає його назву — той самий місток,
// що вже є для підписанта.
//
// Порівняння згорнуте так само, як на бекенді (LOWER(TRIM(name)) —
// CostCenterInitiator::remember(), бекфіл, Department::remember()):
// репрінт старого замовлення (`?repeat=`) копіює збережене написання як є
// (OrderController::create → prefill), а CostCenterSelect свідомо лишає
// незбіжне написання непоправленим. Точне порівняння рядків тут губило б
// підказки підрозділу щоразу, коли збережений `cost_center` різниться від
// довідника лише регістром чи пробілами навколо.
const normalizeCentreName = (name) => (name ?? '').trim().toLowerCase()

const selectedDepartment = computed(() =>
    props.departments.find(d => normalizeCentreName(d.name) === normalizeCentreName(costCenter.value)) ?? null
)

// Тільки імена обраного підрозділу — жодного запасного списку «всіх відомих»
// поверх нього. Той список ріс би без стелі: щороку набрані вручну імена
// лишалися б у ньому назавжди, і за рік випадайка при порожньому полі
// показувала б сотні прізвищ з чужих підрозділів. Той самий вибір, що вже
// зроблено для сусіднього поля «Центр витрат»: звужений список, а не все
// підряд. Якщо центр не обраний або за ним ще нічого не назбиралось —
// підказок немає, і поле лишається звичайним текстовим — вписати нове ім'я
// можна завжди.
const initiatorOptions = computed(() =>
    props.cost_center_initiators?.[selectedDepartment.value?.id] ?? []
)

const displayCategories = computed(() =>
    buildDisplayCategories(props.categories, props.services, orderType.value, selectedSignatory.value)
)

const activeCategoryId = ref(displayCategories.value[0]?.id ?? null)

// Filter services by active category
const activeCategoryServices = computed(() => {
    return props.services.filter(s => s.service_category_id === activeCategoryId.value)
})

// Helper: check if cart item belongs to Візитівки category
const businessCardCategoryId = computed(() => props.categories.find(c => c.name === 'Візитівки')?.id)
function isBusinessCardItem(item) {
    const svc = props.services.find(s => s.id === item.service_id)
    return svc?.service_category_id === businessCardCategoryId.value
}

// Automatically select first service when category changes
const activeServiceId = ref(null)
watch(activeCategoryServices, (newServices) => {
    activeServiceId.value = newServices.length > 0 ? newServices[0].id : null
}, { immediate: true })

// Позиції поза категоріями підписанта більше не зникають мовчки. На формах
// редагування це були б позиції вже збереженого замовлення, і збереження
// м'яко видалило б їх на сервері — операторові не показавши нічого.
//
// `displayCategories` звужується не лише зміною підписанта, а й перемиканням
// Внутр./Комерц. (available_for різниться за типом замовлення) — тому діалог
// і cancelDrop розраховані на обидві причини, а не лише на підписанта.
// previousSignatory й previousOrderType завжди записуються парою, і лише там,
// де кошик вже валідний під поточним станом — гілка «нічого поза», і
// confirmDrop після вилучення, — а не в гілці, що заповнює pendingDrop.
//
// Цього досить для кожного переходу після монтування — але не для першого:
// початкові previousSignatory/previousOrderType беруться при монтуванні без
// жодної перевірки, а для вже збереженого замовлення, чий власний підписант
// не дозволяє одну з його позицій (саме те, що місяцями творить
// retro:import), відкат поверне стан, який перевірки ще не проходив.
// suppressNextDropCheck нижче — одноразовий вимикач на цей самий випадок:
// cancelDrop його зводить, а найближчий прогін watch-а (той, що викликає сам
// відкат) його гасить і виходить, не заповнюючи pendingDrop вдруге.
const pendingDrop = ref([])
const previousSignatory = ref(authorizedPerson.value)
const previousOrderType = ref(orderType.value)

let suppressNextDropCheck = false

const dropMessage = computed(() => describeDropped(pendingDrop.value, { orderTypeSwitchable: true }))

watch(displayCategories, (newCats) => {
    if (!newCats.some(c => c.id === activeCategoryId.value)) {
        activeCategoryId.value = newCats[0]?.id ?? null
    }

    if (suppressNextDropCheck) {
        suppressNextDropCheck = false
        return
    }

    const outside = itemsOutsideCategories(cart.value, props.services, newCats)

    if (outside.length > 0) {
        pendingDrop.value = outside
    } else {
        previousSignatory.value = authorizedPerson.value
        previousOrderType.value = orderType.value
    }
})

function confirmDrop() {
    const dropped = new Set(pendingDrop.value.map(item => item._id))
    cart.value = cart.value.filter(item => !dropped.has(item._id))
    pendingDrop.value = []
    previousSignatory.value = authorizedPerson.value
    previousOrderType.value = orderType.value
}

function cancelDrop() {
    suppressNextDropCheck = true
    pendingDrop.value = []
    authorizedPerson.value = previousSignatory.value
    orderType.value = previousOrderType.value
}

const activeService = computed(() =>
    props.services.find(s => s.id === activeServiceId.value)
)

// Resolve paper avg_cost with A3→A4 conversion fallback
function resolvePaperCost(paper) {
    if (!paper) return 0
    const cost = parseFloat(paper.avg_cost ?? 0)
    if (cost > 0) return cost
    const stock = props.inventory_stock || {}
    const s = stock[paper.id]
    if (s?.conv_from && s?.conv_ratio > 0) {
        const papers = props.brochure_papers || []
        const source = papers.find(p => p.id === s.conv_from)
        const srcCost = parseFloat(source?.avg_cost ?? 0)
        if (srcCost > 0) return srcCost / s.conv_ratio
    }
    return 0
}

// Paper costs for diploma live pricing (from brochure_papers which has all papers)
const diplomaPaperCosts = computed(() => {
    const papers = props.brochure_papers || []
    const find = (keyword) => papers.find(p => p.name?.includes(keyword))
    return {
        a4_160: resolvePaperCost(find('А4 160')),
        a3_160: resolvePaperCost(find('А3 160')),
        a4_80:  resolvePaperCost(find('А4 80')),
    }
})

// Paper stock for diploma (current qty + convertible source)
const diplomaPaperStock = computed(() => {
    const papers = props.brochure_papers || []
    const stock = props.inventory_stock || {}
    const find = (keyword) => papers.find(p => p.name?.includes(keyword))
    const getStock = (paper) => {
        if (!paper) return { qty: 0, conv_from: null, conv_qty: 0 }
        const s = stock[paper.id] || {}
        const convQty = s.conv_from ? (stock[s.conv_from]?.qty ?? 0) : 0
        return { qty: s.qty ?? 0, conv_from: s.conv_from, conv_ratio: s.conv_ratio ?? 0, conv_qty: convQty }
    }
    return {
        a4_160: getStock(find('А4 160')),
        a3_160: getStock(find('А3 160')),
        a4_80:  getStock(find('А4 80')),
    }
})

// ─── Cart ─────────────────────────────────────────────
const cart = ref([])

const cartByCategory = computed(() =>
    groupCartByCategory(cart.value, props.services, props.categories)
)

function addToCart(item) {
    cart.value.push({ ...item, _id: Date.now() })
}

function removeFromCart(cartId) {
    cart.value = cart.value.filter(i => i._id !== cartId)
}

function duplicateCartItem(item) {
    cart.value.push({ ...item, _id: Date.now() })
}

// Inline quantity edit in cart — recalculate pricing proportionally
function updateCartItemQty(item, newQty) {
    const qty = Math.max(1, parseInt(newQty) || 1)
    if (item.pricing && item.quantity > 0) {
        // `null * qty` дорівнює нулю — саме так «невідомо» й перетворилось би
        // назад на число. Невідоме множення лишає невідомим.
        const unitCommercial = item.pricing.unit_price_commercial

        item.pricing = {
            ...item.pricing,
            total_price_commercial: unitCommercial === null || unitCommercial === undefined
                ? null
                : Math.round(unitCommercial * qty * 100) / 100,
            total_price_cost: Math.round(item.pricing.unit_price_cost * qty * 100) / 100,
        }
    }
    item.quantity = qty
}

// `?? 0` тут раніше тихо перетворював «невідомо» на нуль.
const commercial = computed(() => commercialTotal(cart.value))
const cartTotal = computed(() => commercial.value.total)
const cartTotalCost = computed(() =>
    cart.value.reduce((sum, item) => sum + (item.pricing?.total_price_cost ?? 0), 0)
)

// ─── Leave guard: warn when cart has items ─────────────
function onBeforeLeave(e) {
    if (cart.value.length > 0) {
        e.preventDefault()
        e.returnValue = ''
    }
}
onMounted(() => window.addEventListener('beforeunload', onBeforeLeave))
onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeLeave))

// Also guard Inertia navigation
const submitting = ref(false)
let removeInertiaGuard = null
onMounted(() => {
    removeInertiaGuard = router.on('before', (event) => {
        if (cart.value.length > 0 && !submitting.value) {
            return confirm('У кошику є позиції. Покинути сторінку?')
        }
    })
})
onBeforeUnmount(() => removeInertiaGuard?.())

// ─── Submit ───────────────────────────────────────────
const form = useForm({})

/**
 * The message for one cart line.
 *
 * `StoreOrderRequest` validates items as an array, so a refusal comes back
 * keyed `items.0.service_id`, `items.2.quantity` — a dotted key the template
 * cannot reach through `form.errors.x`. The line is what the operator can act
 * on, so the first message belonging to a line is shown under that line.
 *
 * The rules that actually fire here: `ServiceAvailableForOrderType` (a service
 * this order type may not use) and `min:1` on quantity.
 */
function itemError(index) {
    const key = Object.keys(form.errors).find(
        k => k === `items.${index}` || k.startsWith(`items.${index}.`),
    )

    return key ? form.errors[key] : null
}

function submit() {
    submitting.value = true
    form
        .transform(() => ({
            type:              orderType.value,
            authorized_person: orderType.value === 'internal' ? authorizedPerson.value : null,
            cost_center:       orderType.value === 'internal' ? costCenter.value : null,
            initiator:         orderType.value === 'internal' ? (initiator.value || null) : null,
            is_at_cost:        orderType.value === 'commercial' && isAtCost.value,
            items: cart.value.map(item => ({
                service_id:          item.service_id,
                quantity:            item.quantity,
                selected_option_ids: item.selected_option_ids || [],
                riso_format:         item.riso_format || undefined,
                riso_sides:          item.riso_sides || undefined,
                riso_originals:      item.riso_originals || undefined,
                riso_paper_id:       item.riso_paper_id || undefined,
                material_description: item.material_description || undefined,
                customer_paper:      item.customer_paper || undefined,
                // Brochure fields
                brochure_format:         item.brochure_format || undefined,
                brochure_cover_paper_id: item.brochure_cover_paper_id || undefined,
                brochure_cover_mode:     item.brochure_cover_mode || undefined,
                brochure_block_paper_id: item.brochure_block_paper_id || undefined,
                brochure_block_entries:  item.brochure_block_entries || undefined,
                // Diploma fields
                diploma_params:          item.diploma_params || undefined,
            })),
        }))
        .post(route('orders.store'), {
            onFinish: () => { submitting.value = false },
        })
}
</script>

<template>
    <AppLayout>
        <!-- Constructor: full width, with right padding on xl+ to leave room for fixed cart -->
        <div class="xl:pr-[280px]">
            <div class="flex flex-col card p-0 overflow-hidden" style="min-height: calc(100vh - 7rem)">
                <!-- TOP: Category Tabs (scrollable) -->
                <div class="flex flex-wrap border-b border-gray-200 bg-gray-100 flex-shrink-0 gap-0.5 px-1 pt-1">
                    <button
                        v-for="cat in displayCategories"
                        :key="cat.id"
                        type="button"
                        :class="[
                            'px-3 py-2.5 text-sm font-semibold transition rounded-t-lg whitespace-nowrap flex items-center gap-1.5',
                            activeCategoryId === cat.id
                                ? 'bg-white text-indigo-700 shadow-sm'
                                : 'text-gray-600 hover:text-gray-800 hover:bg-white/60',
                        ]"
                        @click="activeCategoryId = cat.id"
                    >
                        <svg v-if="CATEGORY_ICONS[cat.name]" class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path :d="CATEGORY_ICONS[cat.name]"/>
                        </svg>
                        <span v-else class="text-base">—</span>
                        {{ cat.name }}
                    </button>
                </div>

                <!-- SUB: Service Tabs (auto-hide if only 1 service) -->
                <div v-if="activeCategoryServices.length > 1" class="flex flex-wrap border-b border-gray-100 bg-white px-2 py-2 gap-1 flex-shrink-0">
                    <button
                        v-for="service in activeCategoryServices"
                        :key="service.id"
                        type="button"
                        :class="[
                            'px-4 py-1.5 text-sm font-medium rounded-md transition',
                            activeServiceId === service.id
                                ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50',
                        ]"
                        @click="activeServiceId = service.id"
                    >
                        {{ service.name }}
                    </button>
                </div>
                <div v-else-if="activeCategoryServices.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-600">
                    <span class="text-3xl mb-2"><svg class="w-8 h-8 text-gray-600 mx-auto" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/></svg></span>
                    <p class="text-sm">У цій категорії немає активних послуг</p>
                    <p class="text-xs mt-1">Додайте послуги у розділі Прайс-лист</p>
                </div>

                <!-- Constructor or static service -->
                <div class="flex-1 overflow-hidden">
                    <template v-if="activeService?.type === 'constructor'">
                        <ConstructorPanel
                            :service="activeService"
                            :order-type="orderType"
                            :stock="inventory_stock"
                            @add-to-cart="addToCart"
                        />
                    </template>

                    <!-- Brochure service -->
                    <template v-else-if="activeService?.type === 'brochure'">
                        <BrochureConstructor
                            :service="activeService"
                            :papers="brochure_papers"
                            :stock="inventory_stock"
                            :click-costs="brochure_click_costs"
                            @add-to-cart="addToCart"
                        />
                    </template>

                    <!-- Diploma service -->
                    <template v-else-if="activeService?.type === 'diploma'">
                        <DiplomaConstructor
                            :service="activeService"
                            :click-costs="diploma_click_costs"
                            :paper-costs="diplomaPaperCosts"
                            :paper-stock="diplomaPaperStock"
                            @add-to-cart="addToCart"
                        />
                    </template>

                    <!-- Riso service -->
                    <template v-else-if="activeService?.type === 'riso'">
                        <RisoCalculator
                            :service="activeService"
                            :tiers="riso_tiers"
                            :papers="riso_papers"
                            :paper-cost="riso_paper_cost"
                            :stock="inventory_stock"
                            @add-to-cart="addToCart"
                        />
                    </template>

                    <!-- Static service (direct add) -->
                    <template v-else-if="activeService">
                        <div class="p-6">
                            <h3 class="font-semibold text-gray-800 mb-2">{{ activeService.name }}</h3>
                            <p class="text-gray-600 text-sm">
                                Ціна: {{ parseFloat(activeService.base_price_commercial).toFixed(2) }} грн / шт
                            </p>
                            <p class="text-gray-600 text-xs mb-4">
                                Собівартість: {{ parseFloat(activeService.base_price_cost).toFixed(2) }} грн / шт
                            </p>
                            <div class="flex items-center gap-3">
                                <input
                                    id="static-qty"
                                    type="number" min="1"
                                    class="input w-28 text-center text-lg font-bold"
                                    :value="1"
                                    @keydown.enter.prevent="$event => addToCart({
                                        service_id: activeService.id,
                                        service_name: activeService.name,
                                        quantity: parseInt($event.target.value),
                                        selected_option_ids: [],
                                        pricing: {
                                            unit_price_commercial: parseFloat(activeService.base_price_commercial),
                                            total_price_commercial: parseFloat(activeService.base_price_commercial) * parseInt($event.target.value),
                                            unit_price_cost: parseFloat(activeService.base_price_cost),
                                            total_price_cost: parseFloat(activeService.base_price_cost) * parseInt($event.target.value),
                                        }
                                    })"
                                />
                                <button
                                    type="button"
                                    class="btn-primary"
                                    @click="addToCart({
                                        service_id: activeService.id,
                                        service_name: activeService.name,
                                        quantity: 1,
                                        selected_option_ids: [],
                                        pricing: {
                                            unit_price_commercial: parseFloat(activeService.base_price_commercial),
                                            total_price_commercial: parseFloat(activeService.base_price_commercial),
                                            unit_price_cost: parseFloat(activeService.base_price_cost),
                                            total_price_cost: parseFloat(activeService.base_price_cost),
                                        }
                                    })"
                                >
                                    Додати ↵
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Mobile cart FAB (visible below xl when cart is closed) -->
        <button
            v-if="!cartOpen"
            @click="cartOpen = true"
            class="xl:hidden fixed bottom-6 right-6 z-30 bg-indigo-600 text-white rounded-full px-5 py-3 shadow-lg hover:bg-indigo-700 transition flex items-center gap-2"
        >
            <svg class="w-4 h-4 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            <span v-if="cart.length" class="bg-white text-indigo-700 text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">{{ cart.length }}</span>
            <span class="text-sm font-semibold">{{ cartTotal.toFixed(0) }} грн</span>
        </button>

        <!-- Cart backdrop on mobile -->
        <div v-if="cartOpen" class="fixed inset-0 bg-black/40 z-30 xl:hidden" @click="cartOpen = false"></div>

        <ConfirmDialog
            :show="pendingDrop.length > 0"
            title="Позиції поза дозволами для цього замовлення"
            :message="dropMessage"
            confirm-label="Вилучити"
            cancel-label="Скасувати зміну"
            @confirm="confirmDrop"
            @cancel="cancelDrop" />

        <!-- RIGHT: Cart panel — fixed sidebar on xl+, slide-up sheet on mobile -->
        <div :class="[
            'fixed right-0 z-40 bg-white border-l border-gray-200 shadow-lg flex flex-col',
            'xl:top-0 xl:bottom-0 xl:w-[272px]',
            cartOpen
                ? 'inset-x-0 bottom-0 top-[20vh] rounded-t-2xl xl:rounded-none xl:inset-x-auto xl:top-0'
                : 'hidden xl:flex xl:top-0 xl:bottom-0'
        ]">
            <!-- Mobile close handle -->
            <div class="xl:hidden flex justify-center py-2">
                <div class="w-10 h-1 rounded-full bg-gray-300"></div>
            </div>

                <!-- Order type -->
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50 flex-shrink-0">
                    <div class="flex gap-2 mb-3">
                        <button type="button"
                            :class="['btn flex-1 justify-center text-xs', orderType === 'internal' ? 'btn-primary' : 'btn-ghost border border-gray-200']"
                            @click="orderType = 'internal'">Внутр.</button>
                        <button type="button"
                            :class="['btn flex-1 justify-center text-xs', orderType === 'commercial' ? 'btn-primary' : 'btn-ghost border border-gray-200']"
                            @click="orderType = 'commercial'">Комерц.</button>
                    </div>

                    <!-- Internal order fields -->
                    <template v-if="orderType === 'internal'">
                        <div>
                            <label class="text-xs text-gray-600 mb-1 block">Підписант *</label>
                            <select aria-label="Підписант" v-model="authorizedPerson" class="input mb-2 text-sm" required>
                                <option value="" disabled>— Оберіть —</option>
                                <option v-for="s in sortedSignatories" :key="s.id" :value="s.full_name">
                                    {{ s.full_name }}{{ s.position ? ` (${s.position})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.authorized_person" class="text-red-600 text-xs -mt-1 mb-2">{{ form.errors.authorized_person }}</p>
                        </div>
                        <div>
                            <CostCenterSelect
                                v-model="costCenter"
                                :departments="departments"
                                :options="signatoryCentres"
                                :signatory-id="selectedSignatory?.id ?? null" />
                            <p v-if="form.errors.cost_center" class="text-red-600 text-xs -mt-1 mb-2">{{ form.errors.cost_center }}</p>
                        </div>
                        <div>
                            <label class="text-xs text-gray-600 mb-1 block">Ініціатор</label>
                            <input aria-label="Ініціатор" v-model="initiator" class="input mb-2 text-sm"
                                list="initiator-list" placeholder="Хто замовляє (необов'язково)" />
                            <datalist id="initiator-list">
                                <option v-for="name in initiatorOptions" :key="name" :value="name" />
                            </datalist>
                            <p v-if="form.errors.initiator" class="text-red-600 text-xs -mt-1 mb-2">{{ form.errors.initiator }}</p>
                        </div>
                    </template>

                    <!-- Commercial: at-cost checkbox -->
                    <label v-if="orderType === 'commercial'" class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer mt-2">
                        <input v-model="isAtCost" type="checkbox" class="rounded border-gray-300 text-amber-700" />
                        По собівартості
                    </label>
                </div>

                <!-- Cart items -->
                <div class="flex-1 overflow-y-auto px-4 py-3 space-y-2">
                    <div v-if="cart.length === 0" class="text-center text-gray-600 text-sm mt-8">
                        Кошик порожній.<br>Додайте послуги зліва.
                    </div>

                    <div v-for="group in cartByCategory" :key="group.id ?? 'none'" class="space-y-2">
                        <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold px-0.5">
                            {{ group.name }}
                        </p>
                        <div v-for="item in group.items" :key="item._id"
                            class="rounded-lg border px-3 py-2.5 bg-white transition"
                            :class="itemError(cart.indexOf(item)) ? 'border-red-300' : 'border-gray-200 hover:border-indigo-200'">
                            <p v-if="itemError(cart.indexOf(item))" class="text-red-600 text-xs mb-1.5">{{ itemError(cart.indexOf(item)) }}</p>
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-800 text-sm truncate">{{ item.service_name }}</p>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <input
                                            aria-label="Кількість"
                                            type="number" min="1"
                                            :value="item.quantity"
                                            @change="updateCartItemQty(item, $event.target.value)"
                                            class="w-12 text-xs text-center px-1 py-0.5 border border-gray-200 rounded focus:border-indigo-300 focus:ring-1 focus:ring-indigo-200 outline-none tabular-nums"
                                        />
                                        <span class="text-xs text-gray-600">шт</span>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="font-bold text-sm" v-if="orderType !== 'internal' && !isAtCost"
                                        :class="commercialUnknown(item) ? 'text-gray-600' : 'text-indigo-700'">
                                        <template v-if="commercialUnknown(item)">
                                            — <span class="text-[10px] font-normal">ціну порахує сервер</span>
                                        </template>
                                        <template v-else>{{ item.pricing.total_price_commercial.toFixed(2) }} грн</template>
                                    </p>
                                    <p class="font-bold text-amber-700 text-sm" v-else-if="isAtCost">
                                        {{ item.pricing?.total_price_cost?.toFixed(2) }} грн
                                        <span class="text-[10px] text-amber-700 font-normal">соб.</span>
                                    </p>
                                    <p class="font-bold text-gray-600 text-sm" v-else>
                                        0.00 грн
                                        <span class="text-xs text-gray-600 font-normal">({{ item.pricing?.total_price_cost?.toFixed(2) }})</span>
                                    </p>
                                </div>
                            </div>

                            <!-- Order characteristics (cart_details) -->
                            <div v-if="item.cart_details?.length" class="flex flex-wrap gap-1 mt-1.5">
                                <span v-for="(detail, di) in item.cart_details" :key="di"
                                    class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 font-medium leading-tight">
                                    {{ detail }}
                                </span>
                            </div>

                            <span v-if="item.customer_paper"
                                class="inline-flex items-center gap-1 mt-1.5 px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-700 rounded">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                Папір замовника
                            </span>

                            <!-- Material description (internal orders + business cards for "Інший" paper) -->
                            <div v-if="orderType === 'internal' || isBusinessCardItem(item)" class="mt-1.5">
                                <input
                                    aria-label="Опис матеріалу"
                                    v-model="item.material_description"
                                    class="w-full text-xs px-2 py-1 border border-gray-200 rounded focus:border-indigo-300 focus:ring-1 focus:ring-indigo-200 outline-none"
                                    :placeholder="isBusinessCardItem(item) ? 'ПІБ на візитці / уточнення…' : 'Назва матеріалів…'"
                                />
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-2 mt-2">
                                <button type="button" class="text-xs text-gray-600 hover:text-indigo-600 transition"
                                    @click="duplicateCartItem(item)">
                                    Дублювати
                                </button>
                                <button type="button" class="text-xs text-gray-600 hover:text-red-600 transition ml-auto"
                                    @click="removeFromCart(item._id)">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cart total + submit -->
                <div class="border-t border-gray-200 px-4 py-4 flex-shrink-0 space-y-3 bg-white">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Разом:</span>
                        <span v-if="orderType !== 'internal' && !isAtCost" class="text-xl font-bold text-gray-800">
                            {{ cartTotal.toFixed(2) }} грн<template v-if="commercial.incomplete"><span class="text-xs text-amber-700 font-normal block">і ще позиції, ціну яких порахує сервер</span></template>
                        </span>
                        <span v-else-if="isAtCost" class="text-xl font-bold text-amber-700">
                            {{ cartTotalCost.toFixed(2) }} грн
                            <span class="text-xs text-amber-700 font-normal">по собівартості</span>
                        </span>
                        <span v-else class="text-xl font-bold text-gray-800">
                            0.00 грн
                            <span class="text-sm text-gray-600 font-normal">({{ cartTotalCost.toFixed(2) }})</span>
                        </span>
                    </div>

                    <button
                        type="button"
                        class="btn-primary w-full justify-center py-2.5"
                        :disabled="cart.length === 0 || form.processing || (orderType === 'internal' && !authorizedPerson)"
                        @click="submit"
                    >
                        {{ form.processing ? 'Зберігаю…' : 'Оформити замовлення' }}
                    </button>
                </div>
        </div>
    </AppLayout>
</template>

