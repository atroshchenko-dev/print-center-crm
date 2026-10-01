<script setup>
/**
 * Admin/BackdatedOrders/Create — Ретро-замовлення
 *
 * Admin-only page for entering historical internal orders.
 * Simplified version of Orders/Create with:
 * - Datepicker for past dates
 * - Internal-only (no type toggle)
 * - Auto completed_issued + request_received
 * - No shift requirement
 */
import { ref, computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConstructorPanel from '@/Components/Constructor/ConstructorPanel.vue'
import RisoCalculator from '@/Components/Riso/RisoCalculator.vue'
import BrochureConstructor from '@/Components/Brochure/BrochureConstructor.vue'
import DiplomaConstructor from '@/Components/Diploma/DiplomaConstructor.vue'
import { kyivToday } from '@/constants'
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
    services:        Array,
    categories:      Array,
    departments:     Array,
    signatories:     Array,
    inventory_stock: Object,
    riso_tiers:      Array,
    riso_papers:     Array,
    riso_paper_cost: Number,
    brochure_papers: Array,
    brochure_click_costs: Object,
    diploma_click_costs: Object,
    signatory_cost_centers: { type: Object, default: () => ({}) },
})

// ─── Order meta ──────────────────────────────────────
const orderDate        = ref('')
const authorizedPerson = ref('')
const costCenter       = ref('')
const limitExceeded    = ref(false)

// ─── Category Icons ──────────────────────────────────
const CATEGORY_ICONS = {
    'Чорно-білий друк': 'M6 2h9l5 5v15H6a1 1 0 01-1-1V3a1 1 0 011-1zM14 2v5h5',
    'Кольоровий друк':  'M12 2L2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
    'Ламінування':      'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
    'Палітурка м\'яка': 'M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 006.5 22H20V17H6.5A2.5 2.5 0 014 19.5zM4 19.5V4a2 2 0 012-2h14v17',
    'Палітурка тверда': 'M4 19.5v-15A2.5 2.5 0 016.5 2H20v20H6.5a2.5 2.5 0 010-5H20',
    'Сканування':       'M3 7V5a2 2 0 012-2h2M17 3h2a2 2 0 012 2v2M21 17v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2M8 12h8M12 8v8',
    'Брошури':          'M4 19.5v-15A2.5 2.5 0 016.5 2H14l6 6v11.5a2 2 0 01-2 2H6.5A2.5 2.5 0 014 19.5zM14 2v6h6M9 13h6M9 17h4',
    'Різографія':       'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2',
}

// Signatory
const selectedSignatory = computed(() =>
    props.signatories.find(s => s.full_name === authorizedPerson.value) ?? null
)

// Список приходив у порядку таблиці; за абетку відповідає фронт — причина
// записана в sortSignatories.js.
const sortedSignatories = computed(() => sortSignatories(props.signatories))

// Центри витрат обраного підписанта, найчастіші першими. Порожній масив —
// підписанта не обрано або за ним ще нічого не назбиралось.
const signatoryCentres = computed(() =>
    props.signatory_cost_centers?.[selectedSignatory.value?.id] ?? []
)
const displayCategories = computed(() =>
    buildDisplayCategories(props.categories, props.services, 'internal', selectedSignatory.value)
)

const activeCategoryId = ref(displayCategories.value[0]?.id ?? null)

const activeCategoryServices = computed(() =>
    props.services.filter(s => s.service_category_id === activeCategoryId.value)
)

const activeServiceId = ref(null)
watch(activeCategoryServices, (newServices) => {
    activeServiceId.value = newServices.length > 0 ? newServices[0].id : null
}, { immediate: true })

// Позиції поза категоріями підписанта більше не зникають мовчки — збереження
// м'яко видалило б їх на сервері, оператору нічого не показавши. Форма
// завжди внутрішня (жодного перемикача Внутр./Комерц.), тому список звужує
// лише підписант — на відміну від Orders/Create.vue тут не потрібен
// previousOrderType, лише previousSignatory. Він записується лише там, де
// кошик вже валідний під поточним підписантом — гілка «нічого поза», і
// confirmDrop після вилучення, — а не в гілці, що заповнює pendingDrop.
//
// Цього досить для кожного переходу після монтування — але не для першого:
// початковий previousSignatory береться при монтуванні без жодної перевірки,
// а для вже збереженого замовлення, чий власний підписант не дозволяє одну з
// його позицій (саме те, що місяцями творить retro:import), відкат поверне
// стан, який перевірки ще не проходив. suppressNextDropCheck нижче —
// одноразовий вимикач на цей самий випадок: cancelDrop його зводить, а
// найближчий прогін watch-а (той, що викликає сам відкат) його гасить і
// виходить, не заповнюючи pendingDrop вдруге.
const pendingDrop = ref([])
const previousSignatory = ref(authorizedPerson.value)

let suppressNextDropCheck = false

const dropMessage = computed(() => describeDropped(pendingDrop.value))

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
    }
})

function confirmDrop() {
    const dropped = new Set(pendingDrop.value.map(item => item._id))
    cart.value = cart.value.filter(item => !dropped.has(item._id))
    pendingDrop.value = []
    previousSignatory.value = authorizedPerson.value
}

function cancelDrop() {
    suppressNextDropCheck = true
    pendingDrop.value = []
    authorizedPerson.value = previousSignatory.value
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

// Paper costs for diploma live pricing
const diplomaPaperCosts = computed(() => {
    const papers = props.brochure_papers || []
    const find = (keyword) => papers.find(p => p.name?.includes(keyword))
    return {
        a4_160: resolvePaperCost(find('А4 160')),
        a3_160: resolvePaperCost(find('А3 160')),
        a4_80:  resolvePaperCost(find('А4 80')),
    }
})

// Paper stock for diploma
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

// ─── Cart ────────────────────────────────────────────
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

const cartTotalCost = computed(() =>
    cart.value.reduce((sum, item) => sum + (item.pricing?.total_price_cost ?? 0), 0)
)

// ─── Submit ──────────────────────────────────────────
const form = useForm({})

function submit() {
    form
        .transform(() => ({
            order_date:        orderDate.value,
            authorized_person: authorizedPerson.value,
            cost_center:       costCenter.value,
            limit_exceeded:    limitExceeded.value,
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
        .post(route('admin.backdated-orders.store'), {
            onSuccess: () => {
                cart.value = []
                orderDate.value = ''
                authorizedPerson.value = ''
                costCenter.value = ''
                limitExceeded.value = false
            },
        })
}
</script>

<template>
    <AppLayout>
        <div class="flex gap-4 h-[calc(100vh-5rem)]">
            <!-- LEFT: Constructor panel -->
            <div class="flex flex-col card p-0 overflow-hidden flex-1" style="min-height: calc(100vh - 7rem)">
                <!-- Header -->
                <div class="px-6 py-3 bg-gradient-to-r from-amber-50 to-orange-50 border-b border-amber-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-lg font-bold text-amber-800">Ретро-замовлення</h1>
                            <p class="text-xs text-amber-700">Внесення внутрішніх замовлень за минулі дати</p>
                        </div>
                        <a :href="route('admin.backdated-orders.index')"
                            class="text-xs text-amber-700 hover:text-amber-900 font-medium transition flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                            Список
                        </a>
                    </div>
                </div>

                <!-- Category Tabs -->
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
                        {{ cat.name }}
                    </button>
                </div>

                <!-- Service Tabs -->
                <div v-if="activeCategoryServices.length > 1"
                    class="flex gap-1 border-b border-gray-100 bg-gray-50 px-3 py-1.5 flex-shrink-0 overflow-x-auto">
                    <button
                        v-for="svc in activeCategoryServices"
                        :key="svc.id"
                        type="button"
                        :class="[
                            'px-3 py-1.5 text-xs font-medium rounded-lg transition whitespace-nowrap',
                            activeServiceId === svc.id
                                ? 'bg-indigo-100 text-indigo-800'
                                : 'text-gray-600 hover:text-gray-800 hover:bg-gray-100',
                        ]"
                        @click="activeServiceId = svc.id"
                    >
                        {{ svc.name }}
                    </button>
                </div>

                <!-- Constructor -->
                <div class="flex-1 overflow-hidden">
                    <template v-if="activeService?.type === 'constructor'">
                        <ConstructorPanel
                            :service="activeService"
                            order-type="internal"
                            :stock="inventory_stock"
                            :enforce-stock="false"
                            @add-to-cart="addToCart"
                        />
                    </template>

                    <!-- Brochure service -->
                    <template v-else-if="activeService?.type === 'brochure'">
                        <BrochureConstructor
                            :service="activeService"
                            :papers="brochure_papers"
                            :stock="inventory_stock"
                            :enforce-stock="false"
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
                            :enforce-stock="false"
                            allow-below-minimum
                            @add-to-cart="addToCart"
                        />
                    </template>

                    <!-- Static service (direct add) -->
                    <template v-else-if="activeService">
                        <div class="p-6">
                            <h3 class="font-semibold text-gray-800 mb-2">{{ activeService.name }}</h3>
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

                    <template v-else>
                        <div class="p-6 text-center text-gray-600 text-sm">
                            Оберіть категорію та послугу
                        </div>
                    </template>
                </div>
            </div>

            <!-- RIGHT: Cart & Meta panel -->
            <div class="w-80 flex flex-col bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden flex-shrink-0">
                <!-- Date & Signatory -->
                <div class="p-4 space-y-3 border-b border-gray-100 bg-amber-50/50">
                    <div>
                        <label class="text-xs text-gray-600 mb-1 block font-medium">Дата замовлення *</label>
                        <input aria-label="Дата замовлення" v-model="orderDate" type="date" :max="kyivToday()"
                            class="input w-full text-sm" required />
                    </div>
                    <div>
                        <label class="text-xs text-gray-600 mb-1 block">Підписант *</label>
                        <select aria-label="Підписант" v-model="authorizedPerson" class="input w-full text-sm">
                            <option value="">— Оберіть —</option>
                            <option v-for="s in sortedSignatories" :key="s.id" :value="s.full_name">{{ s.full_name }}</option>
                        </select>
                    </div>
                    <div>
                        <CostCenterSelect
                            v-model="costCenter"
                            :departments="departments"
                            :options="signatoryCentres"
                            :signatory-id="selectedSignatory?.id ?? null" />
                    </div>
                    <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                        <input v-model="limitExceeded" type="checkbox" class="rounded border-gray-300" />
                        Ліміт перевищено
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
                            class="rounded-lg border border-gray-200 px-3 py-2.5 bg-white hover:border-indigo-200 transition">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-800 text-sm truncate">{{ item.service_name }}</p>
                                    <p class="text-xs text-gray-600 mt-0.5">{{ item.quantity }} шт</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="font-bold text-gray-600 text-sm">
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
                                Папір замовника
                            </span>

                            <!-- Material description (per item) -->
                            <div class="mt-1.5">
                                <input aria-label="Назва матеріалів"
                                    v-model="item.material_description"
                                    class="w-full text-xs px-2 py-1 border border-gray-200 rounded focus:border-indigo-300 focus:ring-1 focus:ring-indigo-200 outline-none"
                                    placeholder="Назва матеріалів…"
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
                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cart total + submit -->
                <div class="border-t border-gray-200 px-4 py-4 flex-shrink-0 space-y-3 bg-white">
                    <div class="flex justify-between items-baseline">
                        <span class="text-sm text-gray-600">Разом:</span>
                        <span class="text-lg font-bold text-gray-600">
                            0.00 грн
                            <span class="text-xs text-gray-600 font-normal">({{ cartTotalCost.toFixed(2) }})</span>
                        </span>
                    </div>

                    <button
                        type="button"
                        class="w-full btn-primary py-3 text-base disabled:opacity-50"
                        :disabled="!orderDate || !authorizedPerson || cart.length === 0 || form.processing"
                        @click="submit"
                    >
                        {{ form.processing ? 'Зберігаємо…' : 'Створити ретро-замовлення' }}
                    </button>

                    <!-- Info badge -->
                    <div class="text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
                        Замовлення буде створено зі статусом «Завершено/Видано».
                        Без впливу на касу та склад.
                    </div>
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
    </AppLayout>
</template>
