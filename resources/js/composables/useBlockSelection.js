/**
 * The «Обрати все» checkbox on the reconciliation screen — scoped to its block.
 *
 * Owner's decision, 2026-07-31: the checkbox acts within the service block whose
 * header it sits in. It used to be one shared computed over the whole page, so
 * ticking it in «Чорно-білий друк» selected «Ламінування» as well and lit up
 * every other block's checkbox at the same time — a control standing in one
 * place and acting on another. Deliberately left alone four rounds running,
 * because both behaviours are defensible; this is the answer, written down.
 *
 * One caveat that is not a bug and cannot be designed away: reconciliation
 * applies to a whole **order**, while the page groups by **service**, so an
 * order on print *and* lamination appears in both blocks. Selecting either block
 * selects that order. The alternative — reconciling half an order — is not a
 * thing the ledger has.
 */

/**
 * The orders in a block that can still be reconciled: what its checkbox owns.
 *
 * @param {{orders: Array<{id: number, is_reconciled: boolean}>}} group
 * @returns {number[]}
 */
export function selectableIn(group) {
    return (group?.orders ?? [])
        .filter(order => !order.is_reconciled)
        .map(order => order.id);
}

/**
 * Is every selectable order of this block currently selected?
 *
 * An empty block is **not** "all selected" — a ticked checkbox over nothing to
 * tick reads as a promise that pressing the batch button will do something.
 *
 * @param {object} group
 * @param {number[]} selectedIds
 * @returns {boolean}
 */
export function isGroupSelected(group, selectedIds) {
    const ids = selectableIn(group);
    return ids.length > 0 && ids.every(id => selectedIds.includes(id));
}

/**
 * Add or remove this block's orders, leaving every other block's alone.
 *
 * Returns a new array rather than mutating: the selection is shared state and
 * the whole point here is that one block's checkbox stops reaching into another.
 *
 * @param {object} group
 * @param {boolean} checked
 * @param {number[]} selectedIds
 * @returns {number[]}
 */
export function toggleGroup(group, checked, selectedIds) {
    const ids = selectableIn(group);

    return checked
        ? [...new Set([...selectedIds, ...ids])]
        : selectedIds.filter(id => !ids.includes(id));
}
