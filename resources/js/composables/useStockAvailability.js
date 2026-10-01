/**
 * One definition of "can this option still be offered on its own stock".
 *
 * It used to be written twice inside `ConstructorPanel.getVisibleOptions()`, and
 * the two copies disagreed about **zero**:
 *
 *  - drawing an option hid it only on a **deficit** (`qty < 0`) with no
 *    convertible source — the comment beside it said so on purpose, «zero stock
 *    is shown with a red indicator, so new items stay visible»;
 *  - the parent's forward check ("does this size still have any colour worth
 *    showing?") demanded a **positive** quantity.
 *
 * So one and the same cover at zero was simultaneously «shown, red dot» and
 * «unavailable», depending on which check looked at it. A colour at zero stayed
 * selectable; a size whose colours were *all* at zero vanished from the form
 * without a word.
 *
 * Measured on production 2026-07-31, and the prediction matched 8 of 8: exactly
 * the four hard-binding sizes whose every colour sat at zero — 3.5, 17.5, 24.5
 * and 28 мм — were missing from the order form, while the public price list
 * advertised all eight with prices. A 150-page hardback had a price and no way
 * to order it; a 60-page one in red, equally out of stock, went through.
 *
 * Owner's decision, 2026-07-31: **zero means "we are out of these, order them
 * anyway if you want them"**. Only a deficit with nothing to convert from is
 * hidden. The public price list stays a catalogue and is deliberately not
 * stock-aware.
 *
 * @param {{inventory_item_id?: number|null}} option
 * @param {Record<number, {qty: number, conv_from?: number|null}>|null|undefined} stock
 * @returns {boolean}
 */
export function hasOfferableStock(option, stock) {
    if (!option?.inventory_item_id || !stock) return true;

    const entry = stock[option.inventory_item_id];
    if (!entry) return true;

    if (entry.qty >= 0) return true;

    // In deficit — offerable only if something can still be converted into it.
    if (entry.conv_from) {
        const source = stock[entry.conv_from];
        return !!source && source.qty > 0;
    }

    return false;
}

/**
 * Can this be **sold** right now — a different question from whether to show it.
 *
 * Owner's decision, 2026-08-04, and it narrows the one of 2026-07-31: an
 * operator sells off the shelf, not out of an intention to buy something later.
 * So an item with no stock of its own and nothing to cut it from cannot be put
 * on an order — it stays on screen, greyed out, with the reason in its tooltip.
 *
 * **Visibility deliberately does not use this.** `hasOfferableStock` above is
 * what decides whether an option is drawn, and `ConstructorPanel` hides a parent
 * whose children are all unofferable. Had this rule been folded into that one,
 * eleven hard-binding sizes would have vanished from the form without a word —
 * R16-2 rebuilt by hand two months after it was fixed. Measured on production
 * 2026-08-04, before a line of this was written.
 *
 * Cutting is the exception the operator asked for by name: A3 in the building is
 * A4 in the building, one pass through the guillotine away.
 *
 * @param {number|null|undefined} itemId  inventory item behind the option, if any
 * @param {Record<number, {qty: number, conv_from?: number|null}>|null|undefined} stock
 * @returns {boolean}
 */
export function isSelectable(itemId, stock) {
    if (!itemId || !stock) return true;

    const entry = stock[itemId];
    if (!entry) return true;

    if (entry.qty > 0) return true;

    if (entry.conv_from) {
        const source = stock[entry.conv_from];
        return !!source && source.qty > 0;
    }

    return false;
}

/**
 * One vocabulary for the stock indicator, for every component that draws one.
 *
 * Six components used to answer this with their own copy, and only two of them
 * knew the word «convertible». So a paper backed by 1125 sheets of A3 showed a
 * plain «немає» in four places and «доступно через розрізку А3» in two — the
 * same defect as the visibility rule, one layer up, in the labels.
 *
 * @returns {'ok'|'low'|'convertible'|'out'|null} null when nothing is linked
 */
export function stockState(itemId, stock) {
    if (!itemId || !stock) return null;

    const entry = stock[itemId];
    if (!entry) return null;

    if (entry.qty > 0) {
        return entry.min > 0 && entry.qty <= entry.min ? 'low' : 'ok';
    }

    if (entry.conv_from) {
        const source = stock[entry.conv_from];
        if (source && source.qty > 0) return 'convertible';
    }

    return 'out';
}

/** What the indicator says out loud — one sentence per state, in one place. */
export const STOCK_TITLES = {
    ok: 'В наявності',
    low: 'Мало на складі',
    convertible: 'Немає на складі. Доступно через розрізку А3',
    out: 'Немає на складі — обрати не можна',
};
