/**
 * Task 8 wired `signatory_cost_centers` and `<CostCenterSelect>` onto all
 * four order forms (Orders/Create, Orders/Edit, Admin/BackdatedOrders/Create,
 * Admin/BackdatedOrders/Edit). `CostCenterSelect.spec.js` already covers the
 * component's own behaviour in isolation (auto-fill, narrowing, "Показати
 * всі", the new-centre path) — this file guards the two contracts that only
 * exist at the point where each page hands that component its props, and
 * that are each individually wired per page rather than shared code.
 *
 * Contract 1 — a signatory present in the map reaches CostCenterSelect with
 * exactly *their own* centres, in the order the map gave them (most-used
 * first), for all four pages.
 *
 * This is a positive-case assertion on purpose, not a check for the
 * *absent* case (a signatory whose every cost centre was deactivated, so
 * `ReferenceDataService::signatoryCostCenters()` omits them from the map
 * entirely rather than handing back `[]`). A round-1 version of this file
 * asserted the absent case — `options` toEqual `[]` — and that assertion
 * cannot fail, at all, regardless of what the page's own lookup does:
 * `CostCenterSelect.vue` declares `options: { type: Array, default: () =>
 * [] }`, and Vue applies a prop's default whenever the *resolved* value is
 * `undefined` — including when the parent's binding expression evaluates to
 * `undefined`, not only when the attribute is omitted. So even a page whose
 * `signatoryCentres` computed always returned `undefined` (a broken lookup,
 * a deleted `?? []`, the wrong key entirely) would still show `options ===
 * []` from the component's own default, and a test reading `.props('options')`
 * after mount cannot tell the two apart. A page that always passed a
 * constant `[]` for every signatory would have passed that test too.
 *
 * The only way to catch a broken lookup is to check what a *correct* one
 * must produce: the known centres of a signatory who has them, in order.
 * That is what the tests below do, and each one drives the real page
 * computed (via the actual signatory `<select>`, or via the order prop the
 * page seeds its ref from) rather than recomputing the expected lookup by
 * hand — recomputing it here would just restate the production expression
 * and pass even if that expression were wrong in a way the restatement
 * shared.
 *
 * On the `?? []` fallback itself, now that the above is clear: it is *not*
 * currently load-bearing. `signatoryCentres` is read in exactly one place
 * on each of these four pages — the `:options="signatoryCentres"` binding —
 * and `CostCenterSelect`'s own default already absorbs `undefined` there,
 * as established above. Removing the `?? []` today would not change what
 * reaches the browser. It stays anyway, as belt-and-braces: it keeps
 * `signatoryCentres` itself a well-typed `Array` at its own definition,
 * independent of how its one current consumer happens to declare its prop
 * — so if a later change ever reads `signatoryCentres.value.length` or
 * similar directly, before or instead of handing it to `CostCenterSelect`,
 * it inherits a safe value rather than a `TypeError` waiting on `undefined`.
 * That is a real but *hypothetical* future benefit, not a crash this round
 * prevents — call it what it is rather than dressing it up as one.
 *
 * Contract 2 — a saved `cost_center` survives first render on the edit
 * pages. `CostCenterSelect` only protects a saved value from being silently
 * narrowed/cleared if it arrives as `modelValue` on the *first* render (see
 * its own spec + docstring on `visible`). Each edit page initializes its
 * `costCenter` ref from `props.order.cost_center` in its own line of
 * `<script setup>` — a page that instead started that ref empty and relied
 * on a later watcher to fill it would pass this file's contract-1 checks
 * and every other suite, and still rewrite a real order's cost centre the
 * moment the page opened.
 *
 * A green `vite build` proves none of this: this round already shipped a
 * `v-for` scope bug and a half-typed name surviving as the submitted value,
 * both invisible to the build — and, as above, a contract-1 test that
 * cannot fail is no better than no test at all.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import CostCenterSelect from '@/Components/CostCenterSelect.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import OrdersCreate from '@/Pages/Orders/Create.vue'
import OrdersEdit from '@/Pages/Orders/Edit.vue'
import BackdatedCreate from '@/Pages/Admin/BackdatedOrders/Create.vue'
import BackdatedEdit from '@/Pages/Admin/BackdatedOrders/Edit.vue'

const signatoryWithCentres = { id: 1, full_name: 'Іваненко І.І.', position: 'Декан', group: { categories: [] } }
// Every cost centre of this signatory has been deactivated, so
// ReferenceDataService::signatoryCostCenters() omits them from the map
// below entirely — see the docstring above on why that case is no longer
// asserted directly.
const signatoryWithoutCentres = { id: 2, full_name: 'Петренко П.П.', position: null, group: { categories: [] } }
// Рівно один центр — стан, у якому автопідстановка спрацьовує. На формі
// редагування вона спрацьовувати не має, і це перевіряється нижче.
const signatoryWithOneCentre = { id: 3, full_name: 'Сидоренко С.С.', position: null, group: { categories: [] } }
const signatories = [signatoryWithCentres, signatoryWithoutCentres, signatoryWithOneCentre]

const departments = [
    { id: 10, name: 'Кафедра A' },
    { id: 11, name: 'Кафедра B' },
]

// The real backend hands this back as `Collection::groupBy('university_ref_id')`
// serialized to JSON — an object whose keys are strings even when they look
// numeric — while the page looks it up as `map[selectedSignatory.value?.id]`
// with a *numeric* id. Written as `{ 1: [...] }` here is the same object
// JS would produce either way (a JS object literal's numeric-looking keys
// are strings regardless of how they're written), and property access
// coerces a numeric key to its string form automatically — so this was
// never actually a live bug, just a detail worth the fixture matching on
// purpose rather than by accident. Two centres, in a specific order, so a
// broken lookup (wrong key, wrong signatory, a stub returning `[]` for
// everyone) has something concrete to disagree with.
const signatory_cost_centers = {
    1: [
        { id: 10, name: 'Кафедра A' },
        { id: 11, name: 'Кафедра B' },
    ],
    3: [
        { id: 10, name: 'Кафедра A' },
    ],
}

const categories = [{ id: 1, name: 'Чорно-білий друк', available_for: ['internal', 'commercial'] }]
const services = [{ id: 1, name: 'Друк', service_category_id: 1, type: 'static', base_price_commercial: '1', base_price_cost: '1' }]

const baseProps = {
    services, categories, departments, signatories,
    riso_tiers: [], riso_papers: [], riso_paper_cost: 0,
    inventory_stock: {}, brochure_papers: [],
    brochure_click_costs: {}, diploma_click_costs: {},
    signatory_cost_centers,
}

const global = {
    stubs: {
        AppLayout: { template: '<div><slot /></div>' },
        Link: { template: '<a><slot /></a>' },
    },
    mocks: { route: (name) => `/${name}` },
}

// Not derived from `signatory_cost_centers` above — a literal, so this
// assertion cannot pass by tautology if the production lookup were wrong
// in a way that happened to agree with re-deriving it the same way.
const expectedCentres = [
    { id: 10, name: 'Кафедра A' },
    { id: 11, name: 'Кафедра B' },
]

describe('signatory cost centres reach CostCenterSelect on every order form', () => {
    describe('contract 1 — a signatory present in the map reaches CostCenterSelect with their own centres, in order', () => {
        it('Orders/Create, after picking the signatory from the real <select>', async () => {
            const wrapper = mount(OrdersCreate, { props: { ...baseProps, prefill: null }, global })
            await wrapper.find('select[aria-label="Підписант"]').setValue(signatoryWithCentres.full_name)

            expect(wrapper.findComponent(CostCenterSelect).props('options')).toEqual(expectedCentres)
        })

        it('Orders/Edit, from the order it was seeded with on mount', () => {
            const order = {
                id: 5, order_number: 'INT-2608-1', type: 'internal',
                authorized_person: signatoryWithCentres.full_name, cost_center: '',
                is_at_cost: false, version: 1, items: [],
            }
            const wrapper = mount(OrdersEdit, { props: { ...baseProps, order }, global })

            expect(wrapper.findComponent(CostCenterSelect).props('options')).toEqual(expectedCentres)
        })

        it('Admin/BackdatedOrders/Create, after picking the signatory from the real <select>', async () => {
            const wrapper = mount(BackdatedCreate, { props: baseProps, global })
            await wrapper.find('select[aria-label="Підписант"]').setValue(signatoryWithCentres.full_name)

            expect(wrapper.findComponent(CostCenterSelect).props('options')).toEqual(expectedCentres)
        })

        it('Admin/BackdatedOrders/Edit, from the order it was seeded with on mount', () => {
            const order = {
                id: 7, order_number: 'INT-2608-2', created_at: '2026-08-01T00:00:00Z',
                authorized_person: signatoryWithCentres.full_name, cost_center: '',
                limit_exceeded: false, items: [],
            }
            const wrapper = mount(BackdatedEdit, { props: { ...baseProps, order }, global })

            expect(wrapper.findComponent(CostCenterSelect).props('options')).toEqual(expectedCentres)
        })
    })

    describe('contract 1, negative side — selecting a signatory absent from the map does not throw', () => {
        // This is deliberately a weaker check than the positive-case tests
        // above: per the docstring, CostCenterSelect's own default means
        // `.props('options')` cannot distinguish "the page never crashed"
        // from "the lookup is broken and always empty". What this *does*
        // still cover is the `selectedSignatory.value?.id` optional-chaining
        // path — a page written without the `?.` would throw reading `.id`
        // off `null` at initial render, before anything is selected. It is
        // not the unmapped signatory that would trigger it: that name is in
        // `signatories`, so `.find()` returns a real object and `.id` reads
        // fine — it is only absent from the cost-centre map, not from the list.
        it('Orders/Create tolerates a signatory the map omits', async () => {
            const wrapper = mount(OrdersCreate, { props: { ...baseProps, prefill: null }, global })
            await wrapper.find('select[aria-label="Підписант"]').setValue(signatoryWithoutCentres.full_name)

            expect(wrapper.findComponent(CostCenterSelect).exists()).toBe(true)
        })

        it('Admin/BackdatedOrders/Create tolerates a signatory the map omits', async () => {
            const wrapper = mount(BackdatedCreate, { props: baseProps, global })
            await wrapper.find('select[aria-label="Підписант"]').setValue(signatoryWithoutCentres.full_name)

            expect(wrapper.findComponent(CostCenterSelect).exists()).toBe(true)
        })
    })

    describe('contract 2 — a saved cost centre survives first render on the edit pages', () => {
        it('Orders/Edit hands the saved cost_center to CostCenterSelect as modelValue on mount', () => {
            const order = {
                id: 5, order_number: 'INT-2608-1', type: 'internal',
                authorized_person: signatoryWithCentres.full_name, cost_center: 'Кафедра B',
                is_at_cost: false, version: 1, items: [],
            }
            const wrapper = mount(OrdersEdit, { props: { ...baseProps, order }, global })

            expect(wrapper.findComponent(CostCenterSelect).props('modelValue')).toBe('Кафедра B')
        })

        it('Admin/BackdatedOrders/Edit hands the saved cost_center to CostCenterSelect as modelValue on mount', () => {
            const order = {
                id: 7, order_number: 'INT-2608-2', created_at: '2026-08-01T00:00:00Z',
                authorized_person: signatoryWithCentres.full_name, cost_center: 'Кафедра B',
                limit_exceeded: false, items: [],
            }
            const wrapper = mount(BackdatedEdit, { props: { ...baseProps, order }, global })

            expect(wrapper.findComponent(CostCenterSelect).props('modelValue')).toBe('Кафедра B')
        })
    })

    /**
     * Contract 3 — the edit pages neither hide a saved cost centre nor invent
     * one. Both are the same field on the same two pages, and both change what
     * `LimitService` counts the order against, so both are pinned per page
     * rather than only on the component in isolation: the pages are what
     * decide which of `departments`, `options` and `modelValue` the component
     * ever sees.
     */
    describe('contract 3 — the edit pages keep the order\'s own cost centre, and only it', () => {
        // Не входить до `departments`: цей проп несе лише активні й невидалені
        // підрозділи, а замовлення оформлене на той, який відтоді деактивували.
        const deactivated = 'Кафедра ІМЗД'

        it('Orders/Edit still shows a centre whose department was since deactivated', () => {
            const order = {
                id: 5, order_number: 'INT-2608-1', type: 'internal',
                authorized_person: signatoryWithCentres.full_name, cost_center: deactivated,
                is_at_cost: false, version: 1, items: [],
            }
            const wrapper = mount(OrdersEdit, { props: { ...baseProps, order }, global })

            expect(wrapper.find('select[aria-label="Центр витрат"]').element.value).toBe(deactivated)
        })

        it('Admin/BackdatedOrders/Edit still shows a centre whose department was since deactivated', () => {
            const order = {
                id: 7, order_number: 'INT-2608-2', created_at: '2026-08-01T00:00:00Z',
                authorized_person: signatoryWithCentres.full_name, cost_center: deactivated,
                limit_exceeded: false, items: [],
            }
            const wrapper = mount(BackdatedEdit, { props: { ...baseProps, order }, global })

            expect(wrapper.find('select[aria-label="Центр витрат"]').element.value).toBe(deactivated)
        })

        it('Orders/Edit does not give an order saved without a cost centre one on open', async () => {
            const order = {
                id: 5, order_number: 'INT-2608-1', type: 'internal',
                authorized_person: signatoryWithOneCentre.full_name, cost_center: '',
                is_at_cost: false, version: 1, items: [],
            }
            const wrapper = mount(OrdersEdit, { props: { ...baseProps, order }, global })
            await wrapper.vm.$nextTick()

            expect(wrapper.findComponent(CostCenterSelect).props('options')).toEqual([{ id: 10, name: 'Кафедра A' }])
            expect(wrapper.findComponent(CostCenterSelect).props('modelValue')).toBe('')
        })

        it('Admin/BackdatedOrders/Edit does not give an order saved without a cost centre one on open', async () => {
            const order = {
                id: 7, order_number: 'INT-2608-2', created_at: '2026-08-01T00:00:00Z',
                authorized_person: signatoryWithOneCentre.full_name, cost_center: '',
                limit_exceeded: false, items: [],
            }
            const wrapper = mount(BackdatedEdit, { props: { ...baseProps, order }, global })
            await wrapper.vm.$nextTick()

            expect(wrapper.findComponent(CostCenterSelect).props('options')).toEqual([{ id: 10, name: 'Кафедра A' }])
            expect(wrapper.findComponent(CostCenterSelect).props('modelValue')).toBe('')
        })
    })
})

describe('an internal order may hold several categories', () => {
    const twoCategories = [
        { id: 1, name: 'Чорно-білий друк', sort_order: 10, available_for: ['internal', 'commercial'] },
        { id: 2, name: 'Палітурні роботи', sort_order: 20, available_for: ['internal'] },
    ]
    const twoServices = [
        { id: 1, name: 'Друк', service_category_id: 1, type: 'static', base_price_commercial: '1', base_price_cost: '1' },
        { id: 2, name: 'Прошивка', service_category_id: 2, type: 'static', base_price_commercial: '2', base_price_cost: '2' },
    ]
    const narrowSignatory = {
        id: 9,
        full_name: 'Вузький В.В.',
        position: null,
        group: { categories: [{ id: 1, name: 'Чорно-білий друк' }] },
    }

    const wideProps = {
        ...baseProps,
        categories: twoCategories,
        services: twoServices,
        signatories: [...signatories, narrowSignatory],
    }

    const tabFor = (wrapper, name) =>
        wrapper.findAll('button').find(button => button.text().includes(name))

    it('leaves every allowed category tab enabled once the cart has an item', async () => {
        const wrapper = mount(OrdersCreate, { props: { ...wideProps, prefill: null }, global })

        wrapper.vm.cart.push({ _id: 'a', service_id: 1, service_name: 'Друк', quantity: 1, pricing: {} })
        await wrapper.vm.$nextTick()

        expect(tabFor(wrapper, 'Палітурні роботи').attributes('disabled')).toBeUndefined()
    })

    it('asks before dropping cart items a narrowed signatory may not order', async () => {
        const wrapper = mount(OrdersCreate, { props: { ...wideProps, prefill: null }, global })

        wrapper.vm.cart.push({ _id: 'b', service_id: 2, service_name: 'Прошивка', quantity: 3, pricing: {} })
        await wrapper.vm.$nextTick()

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        // Asserted through the dialog's props, not the page text: `Modal`
        // renders inside `<Teleport to="body">`, so its content never appears
        // in `wrapper.text()`.
        const dialog = wrapper.findComponent(ConfirmDialog)
        expect(dialog.props('show')).toBe(true)
        expect(dialog.props('message')).toContain('Прошивка')
        expect(wrapper.vm.cart).toHaveLength(1)   // named, not yet removed
    })

    it('restores the previous signatory when the operator declines the drop', async () => {
        const wrapper = mount(OrdersCreate, { props: { ...wideProps, prefill: null }, global })

        wrapper.vm.cart.push({ _id: 'b', service_id: 2, service_name: 'Прошивка', quantity: 3, pricing: {} })
        await wrapper.vm.$nextTick()

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        wrapper.findComponent(ConfirmDialog).vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cart).toHaveLength(1)
        expect(wrapper.vm.authorizedPerson).not.toBe(narrowSignatory.full_name)
    })

    it('asks before dropping cart items when switching order type narrows categories, and restores the order type on cancel', async () => {
        const wrapper = mount(OrdersCreate, { props: { ...wideProps, prefill: null }, global })

        // Палітурні роботи (category 2) is internal-only — switching to
        // commercial narrows displayCategories without touching the
        // signatory at all, so authorizedPerson === previousSignatory
        // already and only orderType actually moved.
        wrapper.vm.cart.push({ _id: 'b', service_id: 2, service_name: 'Прошивка', quantity: 3, pricing: {} })
        await wrapper.vm.$nextTick()

        await wrapper.findAll('button').find(b => b.text().includes('Комерц.')).trigger('click')
        await wrapper.vm.$nextTick()

        const dialog = wrapper.findComponent(ConfirmDialog)
        expect(dialog.props('show')).toBe(true)
        expect(dialog.props('message')).toContain('Прошивка')

        dialog.vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cart).toHaveLength(1)
        expect(wrapper.vm.orderType).toBe('internal')
    })

    it('groups the cart by category', async () => {
        const wrapper = mount(OrdersCreate, { props: { ...wideProps, prefill: null }, global })

        wrapper.vm.cart.push(
            { _id: 'a', service_id: 2, service_name: 'Прошивка', quantity: 1, pricing: {} },
            { _id: 'b', service_id: 1, service_name: 'Друк', quantity: 1, pricing: {} },
        )
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cartByCategory.map(g => g.name)).toEqual(['Чорно-білий друк', 'Палітурні роботи'])
    })

    it('Orders/Edit opens on the category of the order it just loaded', () => {
        const order = {
            id: 5, order_number: 'INT-2608-1', type: 'internal',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            is_at_cost: false, version: 1,
            items: [{ id: 1, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} }],
        }
        const wrapper = mount(OrdersEdit, { props: { ...wideProps, order }, global })

        expect(wrapper.vm.activeCategoryId).toBe(2)
    })

    it('Orders/Edit reaches every category its order holds', () => {
        const order = {
            id: 6, order_number: 'INT-2608-3', type: 'internal',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            is_at_cost: false, version: 1,
            items: [
                { id: 1, service_id: 1, service_name: 'Друк', quantity: 5, service_snapshot: {} },
                { id: 2, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} },
            ],
        }
        const wrapper = mount(OrdersEdit, { props: { ...wideProps, order }, global })

        expect(tabFor(wrapper, 'Чорно-білий друк').attributes('disabled')).toBeUndefined()
        expect(tabFor(wrapper, 'Палітурні роботи').attributes('disabled')).toBeUndefined()
    })

    // Edit.vue's pendingDrop/previousSignatory/previousOrderType/watcher/
    // confirmDrop/cancelDrop are a new copy of Create.vue's already-tested
    // logic, not a call into shared code — so it needs its own regression
    // guard on the same three cases proven above for Create.vue.
    it('Orders/Edit asks before dropping cart items a narrowed signatory may not order', async () => {
        const order = {
            id: 8, order_number: 'INT-2608-4', type: 'internal',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            is_at_cost: false, version: 1,
            items: [
                { id: 1, service_id: 1, service_name: 'Друк', quantity: 5, service_snapshot: {} },
                { id: 2, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} },
            ],
        }
        const wrapper = mount(OrdersEdit, { props: { ...wideProps, order }, global })

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        const dialog = wrapper.findComponent(ConfirmDialog)
        expect(dialog.props('show')).toBe(true)
        expect(dialog.props('message')).toContain('Прошивка')
        expect(wrapper.vm.cart).toHaveLength(2)   // named, not yet removed
    })

    it('Orders/Edit restores the previous signatory when the operator declines the drop', async () => {
        const order = {
            id: 8, order_number: 'INT-2608-4', type: 'internal',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            is_at_cost: false, version: 1,
            items: [
                { id: 1, service_id: 1, service_name: 'Друк', quantity: 5, service_snapshot: {} },
                { id: 2, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} },
            ],
        }
        const wrapper = mount(OrdersEdit, { props: { ...wideProps, order }, global })

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        wrapper.findComponent(ConfirmDialog).vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cart).toHaveLength(2)
        expect(wrapper.vm.authorizedPerson).toBe(signatoryWithCentres.full_name)
    })

    /**
     * `retro:import` has been creating orders whose own signatory already
     * disallows one of its items for months — a saved order is not
     * guaranteed to already satisfy its own signatory's category list.
     * Before this fix, declining the drop reverted to that signatory, which
     * re-ran the "outside" check against the very data that made it true in
     * the first place, and the dialog reopened blaming the signatory the
     * operator had just cancelled back to — a second, no-op cancel was the
     * only way out.
     */
    it("Orders/Edit closes the dialog after one decline, even when the order's own signatory already disallows one of its items", async () => {
        const legacyRestricted = {
            id: 20,
            full_name: 'Застарілий Ю.Ю.',
            position: null,
            group: { categories: [{ id: 1, name: 'Чорно-білий друк' }] },
        }
        const order = {
            id: 12, order_number: 'INT-2608-6', type: 'internal',
            // service_id 2 (Палітурні роботи) sits outside legacyRestricted's
            // own categories from the moment the order loads.
            authorized_person: legacyRestricted.full_name, cost_center: '',
            is_at_cost: false, version: 1,
            items: [{ id: 1, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} }],
        }
        const wrapper = mount(OrdersEdit, {
            props: { ...wideProps, signatories: [...wideProps.signatories, legacyRestricted], order },
            global,
        })

        // narrowSignatory excludes the same category, so switching to it
        // reopens the dialog exactly as switching signatory always did.
        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()
        expect(wrapper.findComponent(ConfirmDialog).props('show')).toBe(true)

        wrapper.findComponent(ConfirmDialog).vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.findComponent(ConfirmDialog).props('show')).toBe(false)
    })

    it('Orders/Edit asks before dropping cart items when switching order type narrows categories, and restores the order type on cancel', async () => {
        // Палітурні роботи (category 2) is internal-only — switching to
        // commercial narrows displayCategories without touching the
        // signatory at all, so authorizedPerson === previousSignatory
        // already and only orderType actually moved. Same setup as the
        // equivalent Orders/Create case above.
        const order = {
            id: 9, order_number: 'INT-2608-5', type: 'internal',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            is_at_cost: false, version: 1,
            items: [{ id: 1, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} }],
        }
        const wrapper = mount(OrdersEdit, { props: { ...wideProps, order }, global })

        await wrapper.findAll('button').find(b => b.text().includes('Комерц.')).trigger('click')
        await wrapper.vm.$nextTick()

        const dialog = wrapper.findComponent(ConfirmDialog)
        expect(dialog.props('show')).toBe(true)
        expect(dialog.props('message')).toContain('Прошивка')

        dialog.vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cart).toHaveLength(1)
        expect(wrapper.vm.orderType).toBe('internal')
    })

    // Both BackdatedOrders forms are always internal — no orderType ref, no
    // Внутр./Комерц. toggle. They take the single-ref shape (previousSignatory
    // only) rather than Orders/Create's and Orders/Edit's pair, and their
    // dropMessage calls describeDropped without orderTypeSwitchable, so the
    // wording below names only the signatory.
    it('BackdatedOrders/Edit reaches every category the importer put in one order', () => {
        const order = {
            id: 7, order_number: 'INT-2608-2', created_at: '2026-08-01T00:00:00Z',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            limit_exceeded: false,
            items: [
                { id: 1, service_id: 1, service_name: 'Друк', quantity: 5, service_snapshot: {} },
                { id: 2, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} },
            ],
        }
        const wrapper = mount(BackdatedEdit, { props: { ...wideProps, order }, global })

        expect(tabFor(wrapper, 'Палітурні роботи').attributes('disabled')).toBeUndefined()
        expect(wrapper.vm.activeCategoryId).toBe(1)
    })

    it('BackdatedOrders/Create offers a second category with an item already in the cart', async () => {
        const wrapper = mount(BackdatedCreate, { props: wideProps, global })

        wrapper.vm.cart.push({ _id: 'a', service_id: 1, service_name: 'Друк', quantity: 1, pricing: {} })
        await wrapper.vm.$nextTick()

        expect(tabFor(wrapper, 'Палітурні роботи').attributes('disabled')).toBeUndefined()
    })

    it('BackdatedOrders/Create asks before dropping cart items a narrowed signatory may not order', async () => {
        const wrapper = mount(BackdatedCreate, { props: wideProps, global })

        wrapper.vm.cart.push({ _id: 'b', service_id: 2, service_name: 'Прошивка', quantity: 3, pricing: {} })
        await wrapper.vm.$nextTick()

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        const dialog = wrapper.findComponent(ConfirmDialog)
        expect(dialog.props('show')).toBe(true)
        expect(dialog.props('message')).toContain('Прошивка')
        expect(wrapper.vm.cart).toHaveLength(1)   // named, not yet removed
    })

    it('BackdatedOrders/Create restores the previous signatory when the operator declines the drop', async () => {
        const wrapper = mount(BackdatedCreate, { props: wideProps, global })

        wrapper.vm.cart.push({ _id: 'b', service_id: 2, service_name: 'Прошивка', quantity: 3, pricing: {} })
        await wrapper.vm.$nextTick()

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        wrapper.findComponent(ConfirmDialog).vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cart).toHaveLength(1)
        expect(wrapper.vm.authorizedPerson).not.toBe(narrowSignatory.full_name)
    })

    it('BackdatedOrders/Edit asks before dropping cart items a narrowed signatory may not order', async () => {
        const order = {
            id: 8, order_number: 'INT-2608-4', created_at: '2026-08-01T00:00:00Z',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            limit_exceeded: false,
            items: [
                { id: 1, service_id: 1, service_name: 'Друк', quantity: 5, service_snapshot: {} },
                { id: 2, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} },
            ],
        }
        const wrapper = mount(BackdatedEdit, { props: { ...wideProps, order }, global })

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        const dialog = wrapper.findComponent(ConfirmDialog)
        expect(dialog.props('show')).toBe(true)
        expect(dialog.props('message')).toContain('Прошивка')
        expect(wrapper.vm.cart).toHaveLength(2)   // named, not yet removed
    })

    it('BackdatedOrders/Edit restores the previous signatory when the operator declines the drop', async () => {
        const order = {
            id: 8, order_number: 'INT-2608-4', created_at: '2026-08-01T00:00:00Z',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            limit_exceeded: false,
            items: [
                { id: 1, service_id: 1, service_name: 'Друк', quantity: 5, service_snapshot: {} },
                { id: 2, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} },
            ],
        }
        const wrapper = mount(BackdatedEdit, { props: { ...wideProps, order }, global })

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()

        wrapper.findComponent(ConfirmDialog).vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cart).toHaveLength(2)
        expect(wrapper.vm.authorizedPerson).toBe(signatoryWithCentres.full_name)
    })

    // Same legacy-data regression as Orders/Edit above, on the single-ref
    // (previousSignatory-only) shape this form and Admin/BackdatedOrders/Create
    // both use.
    it("BackdatedOrders/Edit closes the dialog after one decline, even when the order's own signatory already disallows one of its items", async () => {
        const legacyRestricted = {
            id: 21,
            full_name: 'Застарілий З.З.',
            position: null,
            group: { categories: [{ id: 1, name: 'Чорно-білий друк' }] },
        }
        const order = {
            id: 13, order_number: 'INT-2608-7', created_at: '2026-08-01T00:00:00Z',
            authorized_person: legacyRestricted.full_name, cost_center: '',
            limit_exceeded: false,
            items: [{ id: 1, service_id: 2, service_name: 'Прошивка', quantity: 3, service_snapshot: {} }],
        }
        const wrapper = mount(BackdatedEdit, {
            props: { ...wideProps, signatories: [...wideProps.signatories, legacyRestricted], order },
            global,
        })

        await wrapper.find('select[aria-label="Підписант"]').setValue(narrowSignatory.full_name)
        await wrapper.vm.$nextTick()
        expect(wrapper.findComponent(ConfirmDialog).props('show')).toBe(true)

        wrapper.findComponent(ConfirmDialog).vm.$emit('cancel')
        await wrapper.vm.$nextTick()

        expect(wrapper.findComponent(ConfirmDialog).props('show')).toBe(false)
    })

    // Mirrors OrdersCreate's 'groups the cart by category' above: pushed in
    // reverse of sort_order to prove the grouping sorts by category
    // (sort_order 10 then 20), not by push/array order.
    it('BackdatedOrders/Create groups the cart by category', async () => {
        const wrapper = mount(BackdatedCreate, { props: wideProps, global })

        wrapper.vm.cart.push(
            { _id: 'a', service_id: 2, service_name: 'Прошивка', quantity: 1, pricing: {} },
            { _id: 'b', service_id: 1, service_name: 'Друк', quantity: 1, pricing: {} },
        )
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.cartByCategory.map(g => g.name)).toEqual(['Чорно-білий друк', 'Палітурні роботи'])
    })

    it('BackdatedOrders/Edit groups the cart by category', () => {
        const order = {
            id: 7, order_number: 'INT-2608-2', created_at: '2026-08-01T00:00:00Z',
            authorized_person: signatoryWithCentres.full_name, cost_center: '',
            limit_exceeded: false,
            items: [
                { id: 1, service_id: 2, service_name: 'Прошивка', quantity: 1, service_snapshot: {} },
                { id: 2, service_id: 1, service_name: 'Друк', quantity: 1, service_snapshot: {} },
            ],
        }
        const wrapper = mount(BackdatedEdit, { props: { ...wideProps, order }, global })

        expect(wrapper.vm.cartByCategory.map(g => g.name)).toEqual(['Чорно-білий друк', 'Палітурні роботи'])
    })
})
