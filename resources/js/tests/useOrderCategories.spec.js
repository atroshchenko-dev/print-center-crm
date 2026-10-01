import { describe, it, expect } from 'vitest'
import {
    allowedCategoryIds,
    displayCategories,
    itemsOutsideCategories,
    describeDropped,
    groupCartByCategory,
} from '../composables/useOrderCategories'

// sort_order deliberately does not track id — id:3 sorts before id:1 — so a
// test that reads `id` instead of `sort_order` cannot pass by accident.
const CATEGORIES = [
    { id: 1, name: 'Чорно-білий друк', sort_order: 10, available_for: ['internal', 'commercial'] },
    { id: 2, name: 'Кольоровий друк', sort_order: 20, available_for: ['internal', 'commercial'] },
    { id: 3, name: 'Палітурні роботи', sort_order: 5, available_for: ['internal'] },
]

const SERVICES = [
    { id: 10, name: 'Друк А4', service_category_id: 1 },
    { id: 20, name: 'Друк кольоровий', service_category_id: 2 },
    { id: 30, name: 'Прошивка', service_category_id: 3 },
    { id: 40, name: 'Разова послуга', service_category_id: null },
]

describe('allowedCategoryIds', () => {
    it('has no restriction without a signatory', () => {
        expect(allowedCategoryIds(null)).toBeNull()
    })

    it('has no restriction when the group lists no categories', () => {
        expect(allowedCategoryIds({ group: { categories: [] } })).toBeNull()
    })

    it('is the group\'s set when the group lists some', () => {
        const ids = allowedCategoryIds({ group: { categories: [{ id: 1 }, { id: 3 }] } })
        expect([...ids].sort()).toEqual([1, 3])
    })
})

describe('displayCategories', () => {
    it('keeps only what the order type offers', () => {
        const cats = displayCategories(CATEGORIES, SERVICES, 'commercial', null)
        expect(cats.map(c => c.id)).toEqual([1, 2, null])
    })

    it('adds the «Інше» tab when an uncategorised service exists', () => {
        const cats = displayCategories(CATEGORIES, SERVICES, 'internal', null)
        expect(cats.at(-1)).toEqual({ id: null, name: 'Інше' })
    })

    it('omits «Інше» when every service has a category', () => {
        const cats = displayCategories(CATEGORIES, SERVICES.slice(0, 3), 'internal', null)
        expect(cats.some(c => c.id === null)).toBe(false)
    })

    /**
     * The server mirrors this rule in LimitService::servicesOutsideSignatoryCategories:
     * a narrowed signatory drops «Інше» entirely. Both sides must keep agreeing.
     */
    it('drops «Інше» once a signatory narrows the list', () => {
        const signatory = { group: { categories: [{ id: 1 }, { id: 3 }] } }
        const cats = displayCategories(CATEGORIES, SERVICES, 'internal', signatory)
        expect(cats.map(c => c.id)).toEqual([1, 3])
    })

    it('lets several categories stand at once — no first-item lock', () => {
        const signatory = { group: { categories: [{ id: 1 }, { id: 2 }, { id: 3 }] } }
        expect(displayCategories(CATEGORIES, SERVICES, 'internal', signatory)).toHaveLength(3)
    })
})

describe('itemsOutsideCategories', () => {
    const cart = [
        { _id: 'a', service_id: 10, service_name: 'Друк А4' },
        { _id: 'b', service_id: 30, service_name: 'Прошивка' },
    ]

    it('names the items a narrowed list would remove', () => {
        const cats = [{ id: 1, name: 'Чорно-білий друк' }]
        expect(itemsOutsideCategories(cart, SERVICES, cats).map(i => i._id)).toEqual(['b'])
    })

    it('removes nothing when everything still fits', () => {
        const cats = [{ id: 1, name: 'Ч/Б' }, { id: 3, name: 'Палітурні' }]
        expect(itemsOutsideCategories(cart, SERVICES, cats)).toEqual([])
    })

    it('keeps an item whose service is unknown to the page', () => {
        const orphan = [{ _id: 'c', service_id: 999, service_name: 'Невідома' }]
        expect(itemsOutsideCategories(orphan, SERVICES, [])).toEqual([])
    })
})

describe('describeDropped', () => {
    it('names every item that will go', () => {
        const message = describeDropped([
            { service_name: 'Прошивка', quantity: 3 },
            { service_name: 'Друк кольоровий', quantity: 10 },
        ])
        expect(message).toContain('Прошивка')
        expect(message).toContain('Друк кольоровий')
    })

    // Orders/Create and Orders/Edit both let the operator switch order type,
    // and either input can narrow displayCategories — so their wording names
    // both. The two backdated forms have no order-type toggle, so naming it
    // there would describe a control the operator cannot see.
    it('names the order type too when the form lets the operator switch it', () => {
        const message = describeDropped([{ service_name: 'Прошивка', quantity: 3 }], { orderTypeSwitchable: true })
        expect(message).toContain('типу замовлення')
    })

    it('keeps the signatory-only wording by default, for the forms with no order-type toggle', () => {
        const message = describeDropped([{ service_name: 'Прошивка', quantity: 3 }])
        expect(message).not.toContain('типу замовлення')
    })
})

describe('groupCartByCategory', () => {
    it('groups in the categories\' own order and puts the uncategorised last', () => {
        const cart = [
            { _id: 'a', service_id: 40 },
            { _id: 'b', service_id: 30 },
            { _id: 'c', service_id: 10 },
        ]
        const groups = groupCartByCategory(cart, SERVICES, CATEGORIES)
        expect(groups.map(g => g.name)).toEqual(['Палітурні роботи', 'Чорно-білий друк', 'Інше'])
        expect(groups[0].items.map(i => i._id)).toEqual(['b'])
    })
})
