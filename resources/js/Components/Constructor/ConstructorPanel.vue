<script setup>
/**
 * ConstructorPanel — головний компонент Конструктора (каскадний wizard)
 *
 * Функціональність:
 * - Каскадна фільтрація груп (group-level depends_on)
 * - Каскадна фільтрація опцій (option-level depends_on)
 * - Різні UI-стилі: tiles_large, tiles_small, dropdown, chips (default)
 * - Auto-reset залежних груп при зміні батьківського вибору
 * - Live-оновлення суми без перезавантаження
 * - Sticky footer з тиражем і підсумком
 */
import { ref, computed, nextTick, watch, onMounted } from 'vue'
import axios from 'axios'
import OptionChip from './OptionChip.vue'
import StepTileSelector from './StepTileSelector.vue'
import StepDropdownSelector from './StepDropdownSelector.vue'
import PostPressSection from './PostPressSection.vue'
import { PAPER_GROUP } from '@/constants'
import { hasOfferableStock, isSelectable } from '@/composables/useStockAvailability'

const props = defineProps({
    service:    { type: Object, required: true },
    onAddToCart: { type: Function, default: null },
    orderType: { type: String, default: 'internal' },
    stock:     { type: Object, default: () => ({}) },
    // Backdated forms record what already happened, so today's shelf must not
    // decide what was orderable in July. Selling forms leave this true.
    enforceStock: { type: Boolean, default: true },
})

const emit = defineEmits(['add-to-cart'])

// ─── State ───────────────────────────────────────────
const selectedOptions  = ref({})   // { groupId: optionId } for radio, { groupId: [ids] } for checkbox
const quantity         = ref(1)
const pricing          = ref(null)
const calculating      = ref(false)
const quantityInput    = ref(null)
const customerPaper    = ref(false)
const cardholderName   = ref('')     // ПІБ for business cards

// Detect if current service has a paper type group (= print service)
const hasPaperGroup = computed(() =>
    (props.service.parameter_groups || []).some(g => g.name === PAPER_GROUP)
)
const paperGroupId = computed(() => {
    const g = (props.service.parameter_groups || []).find(g => g.name === PAPER_GROUP)
    return g?.id ?? null
})
// Detect business cards service (for quantity hint: 1 sheet = 10 cards)
const isBusinessCardService = computed(() =>
    props.service.category?.name === 'Візитівки' || props.service.name === 'Візитівки'
)

// ─── Cascade Logic ───────────────────────────────────

/**
 * Check if a group's depends_on condition is satisfied.
 */
function isGroupVisible(group) {
    if (!group.depends_on) return true
    const { group_id, option_ids } = group.depends_on
    const sel = selectedOptions.value[group_id]
    if (!sel) return false
    if (Array.isArray(sel)) return sel.some(id => option_ids.includes(id))
    return option_ids.includes(sel)
}

/**
 * Check if an option's depends_on condition is satisfied.
 */
function isOptionVisible(option) {
    if (!option.depends_on) return true
    const { group_id, option_ids } = option.depends_on
    const sel = selectedOptions.value[group_id]
    if (!sel) return false
    if (Array.isArray(sel)) return sel.some(id => option_ids.includes(id))
    return option_ids.includes(sel)
}

/**
 * Filter groups: only show if depends_on is satisfied
 * and at least one option is visible.
 */
const visibleGroups = computed(() => {
    return props.service.parameter_groups.filter(g => {
        if (!isGroupVisible(g)) return false
        // Hide optional groups for internal orders (e.g. fill%)
        // Checkbox groups (covers) always shown — they have defaults but are deselectable
        // Hide fill-% group for internal orders (irrelevant — cost-only pricing)
        // But keep other optional groups like Ламінація (business cards)
        if (props.orderType === 'internal' && !g.is_required && g.ui_type !== 'checkbox' && g.name === 'Заповненість') return false
        // Hide sidedness for commercial orders — price is per side, operator enters qty
        if (props.orderType === 'commercial' && g.name === 'Сторонність') return false
        // Hide group if ALL its options are filtered out by option-level depends_on
        if (getVisibleOptions(g).length === 0) return false
        return true
    })
})

/**
 * Get filtered options for a group (option-level cascade).
 * Hides inventory-linked options that are completely unavailable
 * (no stock AND no convertible source with stock).
 * Also hides "parent" options that have zero visible children
 * in downstream groups (e.g. a Size with all Colors out of stock).
 */
function getVisibleOptions(group) {
    return (group.options || []).filter(opt => {
        if (!opt.is_active || !isOptionVisible(opt)) return false

        // Zero stock is shown with a red indicator; only a deficit with nothing
        // to convert from is hidden. See useStockAvailability — the rule lives
        // in one place now, because the copy used by the forward check below had
        // drifted to demanding a positive quantity, and that made a size whose
        // colours were all at zero disappear while a single colour at zero
        // beside it stayed selectable.
        if (!hasOfferableStock(opt, props.stock)) return false

        // Forward check: if downstream groups depend on this option,
        // hide it when ALL their children for this parent are unavailable.
        if (!opt.inventory_item_id && props.stock) {
            const childGroups = (props.service.parameter_groups || []).filter(cg =>
                (cg.options || []).some(co => co.depends_on?.group_id === group.id)
            )
            for (const cg of childGroups) {
                const childrenForOpt = (cg.options || []).filter(co =>
                    co.is_active &&
                    co.depends_on?.group_id === group.id &&
                    co.depends_on?.option_ids?.includes(opt.id)
                )
                if (childrenForOpt.length === 0) continue
                if (!childrenForOpt.some(co => hasOfferableStock(co, props.stock))) return false
            }
        }

        return true
    })
}

/**
 * Options that stay on screen but cannot go on an order — the cascade half of
 * the owner's decision of 2026-08-04.
 *
 * A leaf is handled by `isSelectable` inside each selector. This function does
 * the part only the panel can do: a **parent** carries no stock of its own, so
 * it is unsellable exactly when every one of its children is. On production
 * that is eleven hard-binding sizes — seven channel sizes and four complete
 * covers — whose every colour sits at zero.
 *
 * Deliberately separate from `getVisibleOptions`. Folding this into the
 * visibility filter above would make those eleven sizes **disappear**, which is
 * R16-2 rebuilt by hand: the defect there was a size vanishing while the price
 * list still advertised it. Greyed out and explained is an answer; gone is not.
 */
function unsellableOptionIds(group) {
    if (!props.enforceStock || !props.stock) return []

    return (group.options || [])
        .filter(opt => {
            if (opt.inventory_item_id) return false

            const childGroups = (props.service.parameter_groups || []).filter(cg =>
                (cg.options || []).some(co => co.depends_on?.group_id === group.id)
            )

            for (const cg of childGroups) {
                const childrenForOpt = (cg.options || []).filter(co =>
                    co.is_active &&
                    co.depends_on?.group_id === group.id &&
                    co.depends_on?.option_ids?.includes(opt.id)
                )
                if (childrenForOpt.length === 0) continue
                if (!childrenForOpt.some(co => isSelectable(co.inventory_item_id, props.stock))) return true
            }

            return false
        })
        .map(opt => opt.id)
}

/**
 * Reset all groups that depend on the changed group (cascade reset).
 * Checks both group-level and option-level depends_on references.
 *
 * Smart re-select: when a dependent group has option-level depends_on,
 * tries to preserve the selection suffix (e.g. "4+0" stays "4+0" when
 * paper changes). This avoids annoying resets in deep cascades.
 */
function resetDependentGroups(changedGroupId) {
    for (const group of props.service.parameter_groups) {
        // Group-level depends_on (direct dependency)
        if (group.depends_on?.group_id === changedGroupId) {
            delete selectedOptions.value[group.id]
            resetDependentGroups(group.id)
            continue
        }
        // Option-level depends_on: if ANY option in this group
        // references the changed group, try to preserve the selection suffix
        const hasOptionDep = (group.options || []).some(
            opt => opt.depends_on?.group_id === changedGroupId
        )
        if (hasOptionDep) {
            const prevSelected = selectedOptions.value[group.id]
            delete selectedOptions.value[group.id]

            // Try to re-select by matching the suffix of the previously selected option
            if (prevSelected && !Array.isArray(prevSelected)) {
                const prevOpt = (group.options || []).find(o => o.id === prevSelected)
                if (prevOpt) {
                    // Extract suffix after last ": " (e.g. "А4: Папір 80 г/м²: 4+0" → "4+0")
                    const parts = prevOpt.name.split(': ')
                    const suffix = parts[parts.length - 1]
                    // Find new visible option with the same suffix
                    const newVisibleOpts = getVisibleOptions(group)
                    const match = newVisibleOpts.find(o => o.name.endsWith(`: ${suffix}`))
                    if (match) {
                        selectedOptions.value[group.id] = match.id
                    }
                }
            }

            resetDependentGroups(group.id)
        }
    }
}

// ─── Auto-select defaults ────────────────────────────

/**
 * Auto-select defaults on mount and after cascade resets:
 * - Radio groups with a single option: auto-select it
 * - Checkbox groups: select all active options by default (e.g. covers)
 * - Hidden required groups (e.g. Сторонність in commercial): auto-select first option
 */
function applyDefaults() {
    const uiVisibleIds = new Set(visibleGroups.value.map(g => g.id))

    for (const group of props.service.parameter_groups) {
        if (!isGroupVisible(group)) continue
        const activeOptions = getVisibleOptions(group)
        if (activeOptions.length === 0) continue

        // Hidden required groups: auto-select first option (e.g. 1+0 for commercial)
        if (!uiVisibleIds.has(group.id) && group.is_required && !selectedOptions.value[group.id]) {
            selectedOptions.value[group.id] = activeOptions[0].id
            continue
        }

        if (group.ui_type === 'radio' && activeOptions.length === 1 && !selectedOptions.value[group.id]) {
            selectedOptions.value[group.id] = activeOptions[0].id
        }
        if (group.ui_type === 'checkbox' && !selectedOptions.value[group.id]) {
            selectedOptions.value[group.id] = activeOptions.map(o => o.id)
        }
    }
    recalculate()
}

onMounted(() => applyDefaults())

// ─── Recalculate on orderType change ─────────────────
watch(() => props.orderType, () => {
    const uiVisibleIds = new Set(visibleGroups.value.map(g => g.id))

    for (const gid of Object.keys(selectedOptions.value)) {
        const group = props.service.parameter_groups.find(g => g.id === Number(gid))
        if (!group) continue

        // Remove cascade-inactive groups
        if (!isGroupVisible(group)) {
            delete selectedOptions.value[gid]
            continue
        }

        // Reset hidden required groups (e.g. Сторонність going from visible→hidden)
        // so applyDefaults picks the correct first option (1+0 instead of retained 1+1)
        if (!uiVisibleIds.has(Number(gid)) && group.is_required) {
            delete selectedOptions.value[gid]
        }
    }
    // Re-apply defaults for newly hidden/shown groups
    applyDefaults()
})

// ─── Reset on service change ─────────────────────────
watch(() => props.service.id, () => {
    customerPaper.value = false
})

// ─── Computed ────────────────────────────────────────
const allSelectedOptionIds = computed(() => {
    const ids = []
    // Include options from BOTH visible and hidden-but-auto-selected groups
    // Only skip groups whose depends_on is not satisfied (cascade-inactive)
    const cascadeActiveIds = new Set(
        props.service.parameter_groups
            .filter(g => isGroupVisible(g) && getVisibleOptions(g).length > 0)
            .map(g => g.id)
    )
    for (const [gid, val] of Object.entries(selectedOptions.value)) {
        if (!cascadeActiveIds.has(Number(gid))) continue
        if (Array.isArray(val)) ids.push(...val)
        else if (val) ids.push(val)
    }
    return ids
})

const isValid = computed(() => {
    // All VISIBLE required groups must have a selection
    for (const group of visibleGroups.value) {
        // Optional groups (is_required=false) can have empty selection
        if (!group.is_required) continue
        const sel = selectedOptions.value[group.id]
        if (!sel || (Array.isArray(sel) && sel.length === 0)) {
            return false
        }
    }
    return quantity.value >= 1
})

const totalCommercial = computed(() =>
    pricing.value?.total_price_commercial ?? 0
)
const totalCost = computed(() =>
    pricing.value?.total_price_cost ?? 0
)

// ─── Selection Handlers ──────────────────────────────

function selectRadioOption(group, optionId) {
    const prevValue = selectedOptions.value[group.id]
    selectedOptions.value = {
        ...selectedOptions.value,
        [group.id]: optionId,
    }
    // Reset dependent groups if selection changed
    if (prevValue !== optionId) {
        resetDependentGroups(group.id)
        // Re-apply auto-select for hidden required groups (e.g. sidedness)
        autoSelectHiddenGroups()
    }
    recalculate()
}

/**
 * Auto-select first option for required groups that are cascade-active
 * but hidden from UI (e.g. Сторонність in commercial mode).
 */
function autoSelectHiddenGroups() {
    const uiVisibleIds = new Set(visibleGroups.value.map(g => g.id))
    for (const group of props.service.parameter_groups) {
        if (uiVisibleIds.has(group.id)) continue
        if (!group.is_required) continue
        if (!isGroupVisible(group)) continue
        const opts = getVisibleOptions(group)
        if (opts.length > 0 && !selectedOptions.value[group.id]) {
            selectedOptions.value[group.id] = opts[0].id
        }
    }
}

function toggleCheckboxOption(group, optionId) {
    const current = selectedOptions.value[group.id] ?? []
    selectedOptions.value = {
        ...selectedOptions.value,
        [group.id]: current.includes(optionId)
            ? current.filter(id => id !== optionId)
            : [...current, optionId],
    }
    recalculate()
}

// Legacy OptionChip handler (for 'chips' ui_style)
function toggleOption(group, option) {
    if (group.ui_type === 'radio') {
        selectRadioOption(group, option.id)
    } else {
        toggleCheckboxOption(group, option.id)
    }
}

function isSelected(group, option) {
    const sel = selectedOptions.value[group.id]
    if (Array.isArray(sel)) return sel.includes(option.id)
    return sel === option.id
}

// ─── Price Calculation ───────────────────────────────
let calcTimer = null
async function recalculate() {
    if (calcTimer) clearTimeout(calcTimer)
    calcTimer = setTimeout(async () => {
        if (!isValid.value) {
            pricing.value = null
            return
        }
        calculating.value = true
        try {
            const { data } = await axios.post(route('constructor.calculate'), {
                service_id:          props.service.id,
                quantity:            quantity.value,
                selected_option_ids: allSelectedOptionIds.value,
                customer_paper:      customerPaper.value || undefined,
            })
            pricing.value = data
        } finally {
            calculating.value = false
        }
    }, 250)
}

function onQuantityChange() {
    recalculate()
}

function onQuantityEnter() {
    if (isValid.value) {
        addToCart()
    }
}

function addToCart() {
    if (!isValid.value || !pricing.value) return

    // Build human-readable details from selected options
    // Include both UI-visible and hidden auto-selected groups (e.g. Сторонність)
    const details = []
    for (const group of props.service.parameter_groups) {
        if (!isGroupVisible(group)) continue  // skip cascade-inactive
        const sel = selectedOptions.value[group.id]
        if (!sel) continue
        const ids = Array.isArray(sel) ? sel : [sel]
        for (const id of ids) {
            const opt = (group.options || []).find(o => o.id === id)
            if (opt) {
                // Use short label: last part after ": " (e.g. "А4: Папір 80 г/м²: 4+0" → "4+0")
                const parts = opt.name.split(': ')
                details.push(parts.length > 1 ? parts[parts.length - 1] : opt.name)
            }
        }
    }

    const payload = {
        service_id:          props.service.id,
        service_name:        props.service.name,
        quantity:            quantity.value,
        selected_option_ids: allSelectedOptionIds.value,
        pricing:             pricing.value,
        cart_details:        details,
    }
    if (customerPaper.value) {
        payload.customer_paper = true
    }
    // Pass cardholder name as material_description for business cards
    if (isBusinessCardService.value && cardholderName.value.trim()) {
        payload.material_description = cardholderName.value.trim()
    }
    emit('add-to-cart', payload)


    // Reset for next item
    selectedOptions.value = {}
    quantity.value        = 1
    pricing.value         = null
    customerPaper.value   = false
    cardholderName.value  = ''
    nextTick(() => {
        quantityInput.value?.focus()
        applyDefaults()
    })
}
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Parameter groups (cascade wizard) -->
        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-6">
            <template v-for="group in visibleGroups" :key="group.id">

                <!-- Tiles Large (e.g. Base Category) -->
                <StepTileSelector
                    v-if="group.ui_style === 'tiles_large'"
                    :group="group"
                    :options="getVisibleOptions(group)"
                    :selected-id="selectedOptions[group.id] ?? null"
                    :stock="stock"
                    :disabled-ids="unsellableOptionIds(group)"
                    :enforce-stock="enforceStock"
                    :order-type="orderType"
                    size="large"
                    @select="selectRadioOption(group, $event)"
                />

                <!-- Tiles Small (e.g. Colorfulness) -->
                <StepTileSelector
                    v-else-if="group.ui_style === 'tiles_small'"
                    :group="group"
                    :options="getVisibleOptions(group)"
                    :selected-id="selectedOptions[group.id] ?? null"
                    :stock="stock"
                    :disabled-ids="unsellableOptionIds(group)"
                    :enforce-stock="enforceStock"
                    :order-type="orderType"
                    size="small"
                    @select="selectRadioOption(group, $event)"
                />

                <!-- Dropdown (e.g. Format, Material, Grammage) -->
                <StepDropdownSelector
                    v-else-if="group.ui_style === 'dropdown'"
                    :group="group"
                    :options="getVisibleOptions(group)"
                    :selected-id="selectedOptions[group.id] ?? null"
                    :stock="stock"
                    :disabled-ids="unsellableOptionIds(group)"
                    :enforce-stock="enforceStock"
                    @select="selectRadioOption(group, $event)"
                />

                <!-- Checkbox group (e.g. Post-Press) -->
                <PostPressSection
                    v-else-if="group.ui_type === 'checkbox'"
                    :group="group"
                    :options="getVisibleOptions(group)"
                    :selected-ids="selectedOptions[group.id] ?? []"
                    :stock="stock"
                    :disabled-ids="unsellableOptionIds(group)"
                    :enforce-stock="enforceStock"
                    @toggle="toggleCheckboxOption(group, $event)"
                />

                <!-- Default: chips (backward compat) -->
                <div v-else>
                    <div class="flex items-center gap-2 mb-3">
                        <h3 class="text-sm font-semibold text-gray-700">{{ group.name }}</h3>
                        <span v-if="group.is_required"
                            class="text-xs text-red-600 font-normal">*</span>
                        <span class="text-xs text-gray-600">
                            {{ group.ui_type === 'radio' ? '(один варіант)' : '(можна декілька)' }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <OptionChip
                            v-for="option in getVisibleOptions(group)"
                            :key="option.id"
                            :option="option"
                            :selected="isSelected(group, option)"
                            :disabled="!option.is_active || unsellableOptionIds(group).includes(option.id)"
                            :stock="stock"
                            :enforce-stock="enforceStock"
                            @click="toggleOption(group, $event)"
                        />
                    </div>
                </div>
            </template>

            <!-- Customer Paper checkbox (only for print services with paper group) -->
            <div v-if="hasPaperGroup" class="rounded-xl border-2 transition-all duration-200 px-4 py-3"
                :class="customerPaper
                    ? 'border-amber-400 bg-amber-50'
                    : 'border-gray-200 bg-white hover:border-amber-300'"
            >
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input
                        v-model="customerPaper"
                        type="checkbox"
                        class="w-5 h-5 rounded border-gray-300 text-amber-700 focus:ring-amber-400"
                        @change="recalculate()"
                    />
                    <div>
                        <span class="text-sm font-semibold" :class="customerPaper ? 'text-amber-800' : 'text-gray-700'">Папір замовника</span>
                        <p class="text-xs mt-0.5" :class="customerPaper ? 'text-amber-700' : 'text-gray-600'">Тільки друк — вартість паперу та складські запаси не враховуються</p>
                    </div>
                </label>
            </div>

            <!-- Cardholder name field (business cards only) -->
            <div v-if="isBusinessCardService" class="rounded-xl border-2 border-gray-200 bg-white px-4 py-3 transition-all duration-200"
                :class="cardholderName ? 'border-indigo-300 bg-indigo-50/30' : 'hover:border-gray-300'">
                <label class="block text-xs font-semibold text-gray-600 mb-1.5">ПІБ на візитці</label>
                <input aria-label="ПІБ на візитці"
                    v-model="cardholderName"
                    type="text"
                    class="w-full text-sm px-3 py-2 border border-gray-200 rounded-lg focus:border-indigo-400 focus:ring-2 focus:ring-indigo-200 outline-none transition"
                    placeholder="Прізвище Ім'я По-батькові"
                />
            </div>

            <!-- Empty state -->
            <div v-if="visibleGroups.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-600">
                <template v-if="service.parameter_groups?.length > 0">
                    <!-- Groups exist but hidden (e.g. internal order) -->
                    <svg class="w-8 h-8 text-green-600 mx-auto mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    <p class="text-sm text-gray-600">Послуга готова — вкажіть кількість нижче</p>
                </template>
                <template v-else>
                    <svg class="w-8 h-8 text-gray-600 mx-auto mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <p class="text-sm">Не налаштовано параметрів конструктора</p>
                </template>
            </div>
        </div>

        <!-- Sticky footer: quantity + total + add button -->
        <div class="sticky bottom-0 bg-white border-t border-gray-200 shadow-up px-6 py-4">
            <div class="flex flex-wrap items-center gap-4">
                <!-- Quantity -->
                <div class="flex-shrink-0">
                    <label class="block text-xs text-gray-600 mb-1">
                        {{ isBusinessCardService ? 'Тираж (аркушів)' : 'Тираж' }}
                    </label>
                    <input
                        aria-label="Кількість"
                        ref="quantityInput"
                        v-model.number="quantity"
                        type="number"
                        min="1"
                        class="input w-28 text-center text-lg font-bold"
                        @input="onQuantityChange"
                        @keydown.enter="onQuantityEnter"
                    />
                    <p v-if="isBusinessCardService" class="text-[10px] text-indigo-500 mt-0.5 text-center">
                        1 арк. = 10 візиток
                    </p>
                </div>

                <!-- Price display -->
                <div class="flex-1 text-right">
                    <div v-if="calculating" class="text-gray-600 text-sm animate-pulse">
                        Рахуємо…
                    </div>
                    <div v-else-if="pricing">
                        <template v-if="orderType === 'internal'">
                            <span class="text-sm text-gray-600">Собівартість:</span>
                            <span class="ml-1 text-2xl font-bold text-indigo-700">
                                {{ totalCost.toFixed(2) }} грн
                            </span>
                        </template>
                        <template v-else>
                            <span class="text-sm text-gray-600">До сплати:</span>
                            <span class="ml-1 text-2xl font-bold text-indigo-700">
                                {{ totalCommercial.toFixed(2) }} грн
                            </span>
                            <div class="text-xs text-gray-600 mt-0.5">
                                Собівартість: {{ totalCost.toFixed(2) }} грн
                            </div>
                        </template>
                    </div>
                    <div v-else class="text-gray-600 text-sm">
                        Оберіть всі параметри
                    </div>
                </div>

                <!-- Add button -->
                <button
                    type="button"
                    class="btn-primary flex-shrink-0 py-3 px-6 text-base"
                    :disabled="!isValid || !pricing"
                    @click="addToCart"
                >
                    Додати ↵
                </button>
            </div>
        </div>
    </div>
</template>
