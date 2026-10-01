/**
 * Which Riso tier prices a run — the one rule, in one place.
 *
 * It lived twice: in `RisoPriceTier::findForQuantity()` on the server and
 * inline in `RisoCalculator.vue` on the client, with a comment claiming the
 * two matched. They did not, and both were wrong in the same direction
 *.
 *
 * A ladder has two kinds of hole and only one of them was ever decided:
 *
 *  - **below the ladder** — the seeded ladder starts at 50 sheets, and a run
 *    of ten still has to be priced. Somebody chose the lowest tier for that,
 *    and on retro orders the client is told to do the same;
 *  - **a hole inside the ladder** — nobody chose anything, because nobody
 *    meant to leave one. Deleting the 200–249 row on the Різографія page put
 *    a 220-sheet run on the 50–99 rate: 0,1400 → 0,4100 per copy, 30,80 ₴ of
 *    printing → 90,20 ₴, and the label under it said «50–99».
 *
 * Refusing to price is the honest answer to the second, and the admin page
 * lists the holes so the ladder gets repaired rather than guessed around.
 *
 * @param {Array<{min_qty: number, max_qty: number|null, cost_per_copy: string|number}>} tiers
 *        Ordered by `min_qty` — the controller sends them that way.
 * @param {number} sheetsA3
 * @param {boolean} allowBelowMinimum  Retro screens price runs under the ladder.
 * @returns {object|null} the tier, or null when the ladder has no answer
 */
export function findRisoTier(tiers, sheetsA3, allowBelowMinimum = false) {
    const ladder = tiers ?? []

    const exact = ladder.find(t =>
        sheetsA3 >= t.min_qty && (t.max_qty === null || t.max_qty === undefined || sheetsA3 <= t.max_qty)
    )

    if (exact) return exact

    const lowest = ladder[0] ?? null

    if (allowBelowMinimum && lowest && sheetsA3 < lowest.min_qty) return lowest

    return null
}
