/**
 * Which categories an order form may offer, and what happens to a cart when
 * that set changes.
 *
 * Written once because it had been written four times. `Orders/Create.vue`,
 * `Orders/Edit.vue` and both `Admin/BackdatedOrders` forms each carried their
 * own copy, and the copies had already drifted: only Create synthesised the
 * «Інше» tab, and the two edit forms defaulted to the first tab of the list
 * rather than the category of the order they had just loaded.
 *
 * What is deliberately gone: `lockedCategoryId`. An internal order used to be
 * locked to the category of its first cart item, enforced by a `:disabled`
 * attribute on the other tabs — client-side only, never a server rule, and
 * never true of `retro:import`, which has been grouping several categories into
 * one order since it was written. The rule that remains is the signatory's:
 * a cart may hold any categories the chosen signatory's group allows.
 */

/** Uncategorised services live under a synthetic tab; the id is genuinely null. */
export const UNCATEGORISED = { id: null, name: 'Інше' }

/**
 * The categories this signatory's group allows, or `null` for no restriction.
 *
 * An empty list is what the admin form means by "no restriction", and the
 * server reads it the same way (LimitService::servicesOutsideSignatoryCategories).
 *
 * @param {?{group?: {categories?: Array<{id: number}>}}} signatory
 * @returns {?Set<number>}
 */
export function allowedCategoryIds(signatory) {
    const categories = signatory?.group?.categories ?? []

    return categories.length ? new Set(categories.map(c => c.id)) : null
}

/**
 * The tabs to draw: what the order type offers, narrowed to the signatory.
 *
 * «Інше» appears only while some service has no category, and disappears the
 * moment a signatory narrows the list — the server mirrors that exact rule, so
 * changing it here without changing it there makes every such order warn.
 *
 * @param {Array<{id: number, name: string, available_for?: string[]}>} categories
 * @param {Array<{service_category_id: ?number}>} services
 * @param {string} orderType
 * @param {?object} signatory
 * @returns {Array<{id: ?number, name: string}>}
 */
export function displayCategories(categories, services, orderType, signatory) {
    let cats = (categories ?? []).filter(c => c.available_for?.includes(orderType))

    if ((services ?? []).some(s => !s.service_category_id)) {
        cats = [...cats, UNCATEGORISED]
    }

    const allowed = allowedCategoryIds(signatory)

    return allowed
        ? cats.filter(c => c.id !== null && allowed.has(c.id))
        : cats
}

/**
 * The cart items a category set would exclude — what a confirm dialog has to
 * name before anything is deleted.
 *
 * An item whose service the page does not know is kept: the page cannot prove
 * it is disallowed, and dropping an order line on a guess is worse than keeping
 * one that may not belong.
 *
 * @param {Array<{service_id: number}>} cart
 * @param {Array<{id: number, service_category_id: ?number}>} services
 * @param {Array<{id: ?number}>} displayCats
 * @returns {Array<object>}
 */
export function itemsOutsideCategories(cart, services, displayCats) {
    const allowed = new Set((displayCats ?? []).map(c => c.id))

    return (cart ?? []).filter(item => {
        const service = (services ?? []).find(s => s.id === item.service_id)

        return service ? !allowed.has(service.service_category_id) : false
    })
}

/**
 * What the operator is told before the items go. One wording for four forms —
 * before this, the deletion was silent on every one of them, including the two
 * edit forms, where the items belong to an order that is already saved.
 *
 * Orders/Create and Orders/Edit both let the operator switch order type as
 * well as signatory, and either one can narrow `displayCategories` (the
 * signatory via its group's category list, the order type via `available_for`)
 * — so their wording has to name both, or the dialog blames the signatory for
 * a drop the operator actually triggered by switching Внутр./Комерц. The two
 * backdated forms have no order-type toggle, so naming it there would
 * describe a control the operator cannot see; `orderTypeSwitchable` defaults
 * to false and keeps their wording to the signatory alone.
 *
 * @param {Array<{service_name?: string, quantity?: number}>} items
 * @param {{orderTypeSwitchable?: boolean}} [options]
 * @returns {string}
 */
export function describeDropped(items, { orderTypeSwitchable = false } = {}) {
    const lines = (items ?? [])
        .map(item => `${item.service_name ?? 'Послуга'} × ${item.quantity ?? 1}`)
        .join(', ')

    return orderTypeSwitchable
        ? `Ці позиції недоступні для обраного підписанта або типу замовлення — їх буде вилучено з кошика: ${lines}.`
        : `Обраний підписант не має права на ці позиції — їх буде вилучено з кошика: ${lines}.`
}

/**
 * The cart, grouped the way the signatory will see it in the approval letter.
 *
 * @param {Array<{service_id: number}>} cart
 * @param {Array<{id: number, service_category_id: ?number}>} services
 * @param {Array<{id: number, name: string, sort_order?: number}>} categories
 * @returns {Array<{id: ?number, name: string, items: Array<object>}>}
 */
export function groupCartByCategory(cart, services, categories) {
    const byId = new Map((categories ?? []).map(c => [c.id, c]))
    const groups = new Map()

    for (const item of cart ?? []) {
        const service = (services ?? []).find(s => s.id === item.service_id)
        const category = byId.get(service?.service_category_id ?? null)
        const id = category?.id ?? null

        if (!groups.has(id)) {
            groups.set(id, {
                id,
                name: category?.name ?? UNCATEGORISED.name,
                sort: category?.sort_order ?? Number.MAX_SAFE_INTEGER,
                items: [],
            })
        }

        groups.get(id).items.push(item)
    }

    return [...groups.values()]
        .sort((a, b) => a.sort - b.sort)
        .map(({ id, name, items }) => ({ id, name, items }))
}
