<script setup>
/**
 * Orders/Edit — edit operational order (new/in_progress only)
 * Based on Create.vue layout with pre-populated cart from existing items.
 */
import AppLayout from '@/Layouts/AppLayout.vue'
import ConstructorPanel from '@/Components/Constructor/ConstructorPanel.vue'
import RisoCalculator from '@/Components/Riso/RisoCalculator.vue'
import BrochureConstructor from '@/Components/Brochure/BrochureConstructor.vue'
import DiplomaConstructor from '@/Components/Diploma/DiplomaConstructor.vue'
import { ref, computed, watch } from 'vue'
import { commercialTotal, commercialUnknown } from '@/composables/useCartTotals'
import { useForm, Link } from '@inertiajs/vue3'
import { serviceOptionLabels } from '@/composables/useConstructorOptions'
import CostCenterSelect from '@/Components/CostCenterSelect.vue'
import { sortSignatories } from '@/utils/sortSignatories'
import {
    displayCategories as buildDisplayCategories,
    itemsOutsideCategories,
    describeDropped,
    groupCartByCategory,
} from '@/composables/useOrderCategories'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'

const props = defineProps({
    order: Object,
    services: Array, categories: Array, departments: Array, signatories: Array,
    riso_tiers: Array, riso_papers: Array, riso_paper_cost: Number,
    inventory_stock: Object, brochure_papers: Array,
    brochure_click_costs: Object, diploma_click_costs: Object,
    signatory_cost_centers: { type: Object, default: () => ({}) },
})

// ─── Order meta (pre-populated) ──────────────────────
const orderType = ref(props.order.type)
const authorizedPerson = ref(props.order.authorized_person || '')
const costCenter = ref(props.order.cost_center || '')
const isAtCost = ref(props.order.is_at_cost || false)
const editReason = ref('')

watch(orderType, (v) => { if (v === 'internal') isAtCost.value = false })

// ─── Categories & Services ───────────────────────────
const selectedSignatory = computed(() =>
    orderType.value === 'internal' && authorizedPerson.value
        ? props.signatories.find(s => s.full_name === authorizedPerson.value) ?? null : null)

// Список приходив у порядку таблиці; за абетку відповідає фронт — причина
// записана в sortSignatories.js.
const sortedSignatories = computed(() => sortSignatories(props.signatories))

// Центри витрат обраного підписанта, найчастіші першими. Порожній масив —
// підписанта не обрано або за ним ще нічого не назбиралось.
const signatoryCentres = computed(() =>
    props.signatory_cost_centers?.[selectedSignatory.value?.id] ?? []
)

const displayCategories = computed(() =>
    buildDisplayCategories(props.categories, props.services, orderType.value, selectedSignatory.value)
)

// Відкриваємось на категорії першої позиції замовлення, а не на першій вкладці
// списку: доки діяв замок, активною могла стати сіра «заблокована» вкладка,
// чия панель додавання при цьому працювала.
const firstItemCategoryId = props.order.items
    ?.map(item => props.services.find(s => s.id === item.service_id)?.service_category_id)
    .find(id => id !== undefined) ?? null

const activeCategoryId = ref(
    displayCategories.value.some(c => c.id === firstItemCategoryId)
        ? firstItemCategoryId
        : displayCategories.value[0]?.id ?? null
)
const activeCategoryServices = computed(() =>
    props.services.filter(s => s.service_category_id === activeCategoryId.value))

const activeServiceId = ref(null)
watch(activeCategoryServices, (s) => {
    activeServiceId.value = s.length > 0 ? s[0].id : null
}, { immediate: true })

// Позиції поза категоріями підписанта більше не зникають мовчки. На цій формі
// це позиції вже збереженого замовлення, і збереження м'яко видалило б їх на
// сервері — операторові не показавши нічого.
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

const activeService = computed(() => props.services.find(s => s.id === activeServiceId.value))

function resolvePaperCost(paper) {
    if (!paper) return 0
    const cost = parseFloat(paper.avg_cost ?? 0)
    if (cost > 0) return cost
    const stock = props.inventory_stock || {}
    const s = stock[paper.id]
    if (s?.conv_from && s?.conv_ratio > 0) {
        const source = (props.brochure_papers || []).find(p => p.id === s.conv_from)
        const srcCost = parseFloat(source?.avg_cost ?? 0)
        if (srcCost > 0) return srcCost / s.conv_ratio
    }
    return 0
}

const diplomaPaperCosts = computed(() => {
    const papers = props.brochure_papers || []
    const find = (kw) => papers.find(p => p.name?.includes(kw))
    return { a4_160: resolvePaperCost(find('А4 160')), a3_160: resolvePaperCost(find('А3 160')), a4_80: resolvePaperCost(find('А4 80')) }
})

const diplomaPaperStock = computed(() => {
    const papers = props.brochure_papers || [], stock = props.inventory_stock || {}
    const find = (kw) => papers.find(p => p.name?.includes(kw))
    const gs = (paper) => {
        if (!paper) return { qty: 0, conv_from: null, conv_qty: 0 }
        const s = stock[paper.id] || {}
        return { qty: s.qty ?? 0, conv_from: s.conv_from, conv_ratio: s.conv_ratio ?? 0, conv_qty: s.conv_from ? (stock[s.conv_from]?.qty ?? 0) : 0 }
    }
    return { a4_160: gs(find('А4 160')), a3_160: gs(find('А3 160')), a4_80: gs(find('А4 80')) }
})

// ─── Cart (pre-populated) ────────────────────────────
function buildCartFromOrder() {
    return (props.order.items || []).map((item, idx) => ({
        _id: Date.now() + idx,
        service_id: item.service_id,
        service_name: item.service_name,
        quantity: item.quantity,
        selected_option_ids: (item.service_snapshot?.constructor_snapshot || []).filter(e => e.option_id).map(e => e.option_id),
        material_description: item.material_description || '',
        customer_paper: item.service_snapshot?.customer_paper || false,
        pricing: {
            unit_price_commercial: parseFloat(item.unit_price_commercial || 0),
            total_price_commercial: parseFloat(item.total_price_commercial || 0),
            unit_price_cost: parseFloat(item.unit_price_cost || 0),
            total_price_cost: parseFloat(item.total_price_cost || 0),
        },
        cart_details: serviceOptionLabels(item.service_snapshot, { isInternal: props.order.type === 'internal' }),
        _snapshot: item.service_snapshot,
    }))
}

const cart = ref(buildCartFromOrder())

const cartByCategory = computed(() =>
    groupCartByCategory(cart.value, props.services, props.categories)
)

const businessCardCategoryId = computed(() => props.categories.find(c => c.name === 'Візитівки')?.id)
function isBusinessCardItem(item) {
    const svc = props.services.find(s => s.id === item.service_id)
    return svc?.service_category_id === businessCardCategoryId.value
}

function addToCart(item) { cart.value.push({ ...item, _id: Date.now() }) }
function removeFromCart(id) { cart.value = cart.value.filter(i => i._id !== id) }
function duplicateCartItem(item) { cart.value.push({ ...item, _id: Date.now() }) }

function updateCartItemQty(item, newQty) {
    const qty = Math.max(1, parseInt(newQty) || 1)
    if (item.pricing && item.quantity > 0) {
        item.pricing = {
            ...item.pricing,
            // `null * qty` = 0 — так «невідомо» й ставало числом.
            total_price_commercial: item.pricing.unit_price_commercial === null || item.pricing.unit_price_commercial === undefined
                ? null
                : Math.round(item.pricing.unit_price_commercial * qty * 100) / 100,
            total_price_cost: Math.round(item.pricing.unit_price_cost * qty * 100) / 100,
        }
    }
    item.quantity = qty
}

const commercial = computed(() => commercialTotal(cart.value))
const cartTotal = computed(() => commercial.value.total)
const cartTotalCost = computed(() => cart.value.reduce((s, i) => s + (i.pricing?.total_price_cost ?? 0), 0))

// ─── Submit ──────────────────────────────────────────
const form = useForm({})

/** One message per cart line — see Orders/Create.vue for why the keys are dotted. */
function itemError(index) {
    const key = Object.keys(form.errors).find(
        k => k === `items.${index}` || k.startsWith(`items.${index}.`),
    )

    return key ? form.errors[key] : null
}

function submit() {
    form.transform(() => ({
        version: props.order.version,
        edit_reason: editReason.value || undefined,
        type: orderType.value,
        authorized_person: orderType.value === 'internal' ? authorizedPerson.value : null,
        cost_center: orderType.value === 'internal' ? costCenter.value : null,
        is_at_cost: orderType.value === 'commercial' && isAtCost.value,
        items: cart.value.map(item => ({
            service_id: item.service_id, quantity: item.quantity,
            selected_option_ids: item.selected_option_ids || [],
            existing_snapshot: item._snapshot || undefined,
            riso_format: item.riso_format || undefined,
            riso_sides: item.riso_sides || undefined,
            riso_originals: item.riso_originals || undefined,
            riso_paper_id: item.riso_paper_id || undefined,
            material_description: item.material_description || undefined,
            customer_paper: item.customer_paper || undefined,
            brochure_format: item.brochure_format || undefined,
            brochure_cover_paper_id: item.brochure_cover_paper_id || undefined,
            brochure_cover_mode: item.brochure_cover_mode || undefined,
            brochure_block_paper_id: item.brochure_block_paper_id || undefined,
            brochure_block_entries: item.brochure_block_entries || undefined,
            diploma_params: item.diploma_params || undefined,
        })),
    })).put(route('orders.update', props.order.id))
}
</script>

<template>
    <AppLayout>
        <div class="xl:pr-[280px]">
            <div class="flex flex-col card p-0 overflow-hidden" style="min-height: calc(100vh - 7rem)">
                <!-- Header -->
                <div class="px-4 py-2.5 bg-gradient-to-r from-blue-50 to-indigo-50 border-b border-blue-200 flex items-center justify-between">
                    <div>
                        <h1 class="text-lg font-bold text-blue-800">Редагування {{ order.order_number }}</h1>
                        <p class="text-xs text-blue-600">Зміна позицій та метаданих замовлення</p>
                    </div>
                    <Link :href="route('orders.show', order.id)"
                        class="text-xs text-blue-700 hover:text-blue-900 font-medium transition flex items-center gap-1">
                        ← Назад
                    </Link>
                </div>

                <!-- Category Tabs -->
                <div class="flex flex-wrap border-b border-gray-200 bg-gray-100 flex-shrink-0 gap-0.5 px-1 pt-1">
                    <button v-for="cat in displayCategories" :key="cat.id" type="button"
                        :class="['px-3 py-2.5 text-sm font-semibold transition rounded-t-lg whitespace-nowrap',
                            activeCategoryId === cat.id ? 'bg-white text-indigo-700 shadow-sm'
                                : 'text-gray-600 hover:text-gray-800 hover:bg-white/60']"
                        @click="activeCategoryId = cat.id">
                        {{ cat.name }}
                    </button>
                </div>

                <!-- Service Tabs -->
                <div v-if="activeCategoryServices.length > 1" class="flex flex-wrap border-b border-gray-100 bg-white px-2 py-2 gap-1 flex-shrink-0">
                    <button v-for="svc in activeCategoryServices" :key="svc.id" type="button"
                        :class="['px-4 py-1.5 text-sm font-medium rounded-md transition',
                            activeServiceId === svc.id ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50']"
                        @click="activeServiceId = svc.id">{{ svc.name }}</button>
                </div>

                <!-- Constructor -->
                <div class="flex-1 overflow-hidden">
                    <template v-if="activeService?.type === 'constructor'">
                        <ConstructorPanel :service="activeService" :order-type="orderType" :stock="inventory_stock" @add-to-cart="addToCart" />
                    </template>
                    <template v-else-if="activeService?.type === 'brochure'">
                        <BrochureConstructor :service="activeService" :papers="brochure_papers" :stock="inventory_stock" :click-costs="brochure_click_costs" @add-to-cart="addToCart" />
                    </template>
                    <template v-else-if="activeService?.type === 'diploma'">
                        <DiplomaConstructor :service="activeService" :click-costs="diploma_click_costs" :paper-costs="diplomaPaperCosts" :paper-stock="diplomaPaperStock" @add-to-cart="addToCart" />
                    </template>
                    <template v-else-if="activeService?.type === 'riso'">
                        <RisoCalculator :service="activeService" :tiers="riso_tiers" :papers="riso_papers" :paper-cost="riso_paper_cost" :stock="inventory_stock" @add-to-cart="addToCart" />
                    </template>
                    <template v-else-if="activeService">
                        <div class="p-6">
                            <h3 class="font-semibold text-gray-800 mb-2">{{ activeService.name }}</h3>
                            <p class="text-gray-600 text-sm">Ціна: {{ parseFloat(activeService.base_price_commercial).toFixed(2) }} грн</p>
                            <div class="flex items-center gap-3 mt-3">
                                <button type="button" class="btn-primary" @click="addToCart({
                                    service_id: activeService.id, service_name: activeService.name, quantity: 1,
                                    selected_option_ids: [], pricing: {
                                        unit_price_commercial: parseFloat(activeService.base_price_commercial),
                                        total_price_commercial: parseFloat(activeService.base_price_commercial),
                                        unit_price_cost: parseFloat(activeService.base_price_cost),
                                        total_price_cost: parseFloat(activeService.base_price_cost),
                                    }
                                })">Додати ↵</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :show="pendingDrop.length > 0"
            title="Позиції поза дозволами для цього замовлення"
            :message="dropMessage"
            confirm-label="Вилучити"
            cancel-label="Скасувати зміну"
            @confirm="confirmDrop"
            @cancel="cancelDrop" />

        <!-- Cart panel (fixed sidebar) -->
        <div class="fixed right-0 top-0 bottom-0 w-[272px] z-40 bg-white border-l border-gray-200 shadow-lg flex-col hidden xl:flex">
            <!-- Order type -->
            <div class="px-4 py-3 border-b border-gray-100 bg-blue-50/50 flex-shrink-0">
                <div class="flex gap-2 mb-3">
                    <button type="button" :class="['btn flex-1 justify-center text-xs', orderType === 'internal' ? 'btn-primary' : 'btn-ghost border border-gray-200']"
                        @click="orderType = 'internal'">Внутр.</button>
                    <button type="button" :class="['btn flex-1 justify-center text-xs', orderType === 'commercial' ? 'btn-primary' : 'btn-ghost border border-gray-200']"
                        @click="orderType = 'commercial'">Комерц.</button>
                </div>

                <template v-if="orderType === 'internal'">
                    <div>
                        <label class="text-xs text-gray-600 mb-1 block">Підписант *</label>
                        <select aria-label="Підписант" v-model="authorizedPerson" class="input mb-2 text-sm" required>
                            <option value="" disabled>— Оберіть —</option>
                            <option v-for="s in sortedSignatories" :key="s.id" :value="s.full_name">{{ s.full_name }}{{ s.position ? ` (${s.position})` : '' }}</option>
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
                </template>

                <label v-if="orderType === 'commercial'" class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer mt-2">
                    <input v-model="isAtCost" type="checkbox" class="rounded border-gray-300 text-amber-700" /> По собівартості
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
                                    <input aria-label="Кількість" type="number" min="1" :value="item.quantity"
                                        @change="updateCartItemQty(item, $event.target.value)"
                                        class="w-12 text-xs text-center px-1 py-0.5 border border-gray-200 rounded focus:border-indigo-300 focus:ring-1 focus:ring-indigo-200 outline-none tabular-nums" />
                                    <span class="text-xs text-gray-600">шт</span>
                                </div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p v-if="orderType !== 'internal' && !isAtCost" class="font-bold text-sm"
                                    :class="commercialUnknown(item) ? 'text-gray-600' : 'text-indigo-700'">
                                    <template v-if="commercialUnknown(item)">
                                        — <span class="text-[10px] font-normal">ціну порахує сервер</span>
                                    </template>
                                    <template v-else>{{ item.pricing.total_price_commercial.toFixed(2) }} грн</template>
                                </p>
                                <p v-else-if="isAtCost" class="font-bold text-sm text-amber-700">
                                    {{ item.pricing?.total_price_cost?.toFixed(2) }} грн
                                </p>
                                <p class="font-bold text-gray-600 text-sm" v-else>
                                    0.00 грн <span class="text-xs text-gray-600 font-normal">({{ item.pricing?.total_price_cost?.toFixed(2) }})</span>
                                </p>
                            </div>
                        </div>

                        <div v-if="item.cart_details?.length" class="flex flex-wrap gap-1 mt-1.5">
                            <span v-for="(d, di) in item.cart_details" :key="di"
                                class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 font-medium leading-tight">{{ d }}</span>
                        </div>

                        <span v-if="item.customer_paper"
                            class="inline-flex items-center gap-1 mt-1.5 px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-700 rounded">
                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            Папір замовника
                        </span>

                        <div v-if="orderType === 'internal' || isBusinessCardItem(item)" class="mt-1.5">
                            <input aria-label="Опис матеріалу" v-model="item.material_description"
                                class="w-full text-xs px-2 py-1 border border-gray-200 rounded focus:border-indigo-300 focus:ring-1 focus:ring-indigo-200 outline-none"
                                :placeholder="isBusinessCardItem(item) ? 'ПІБ на візитці…' : 'Назва матеріалів…'" />
                        </div>

                        <div class="flex gap-2 mt-2">
                            <button type="button" class="text-xs text-gray-600 hover:text-indigo-600 transition" @click="duplicateCartItem(item)">Дублювати</button>
                            <button type="button" class="text-xs text-gray-600 hover:text-red-600 transition ml-auto" @click="removeFromCart(item._id)"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total + reason + submit -->
            <div class="border-t border-gray-200 px-4 py-4 flex-shrink-0 space-y-3 bg-white">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Разом:</span>
                    <span v-if="orderType !== 'internal' && !isAtCost" class="text-xl font-bold text-gray-800">
                        {{ cartTotal.toFixed(2) }} грн<template v-if="commercial.incomplete"><span class="text-xs text-amber-700 font-normal block">і ще позиції, ціну яких порахує сервер</span></template>
                    </span>
                    <span v-else-if="isAtCost" class="text-xl font-bold text-amber-700">{{ cartTotalCost.toFixed(2) }} грн</span>
                    <span v-else class="text-xl font-bold text-gray-800">0.00 грн <span class="text-sm text-gray-600 font-normal">({{ cartTotalCost.toFixed(2) }})</span></span>
                </div>

                <textarea aria-label="Причина зміни (необов'язково)" v-model="editReason" class="input resize-none text-xs" rows="2" placeholder="Причина зміни (необов'язково)…" />

                <button type="button" class="btn-primary w-full justify-center py-2.5"
                    :disabled="cart.length === 0 || form.processing || (orderType === 'internal' && !authorizedPerson)"
                    @click="submit">
                    {{ form.processing ? 'Зберігаю…' : 'Зберегти зміни' }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
