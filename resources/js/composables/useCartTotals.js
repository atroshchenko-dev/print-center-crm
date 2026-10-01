/**
 * What the cart may claim about money — audit R32-1 and R32-2.
 *
 * Two constructors used to state a commercial price they could not know:
 * `RisoCalculator` sent the **cost** as the commercial figure (the server
 * multiplies it by `riso_commercial_markup`, 2,0 by default), and
 * `BrochureConstructor` sent **zero** (the server applies a 2× markup). The
 * page is not sent either multiplier, so neither component could have been
 * right — the numbers were invented, not computed.
 *
 * The order itself was never mispriced: `OrderItemBuilder` trusts a snapshot
 * from the request only when it is byte-identical to one already stored, and
 * rebuilds everything else from authoritative data. What was wrong is the
 * number an operator reads out to a customer.
 *
 * Measured on production 2026-08-04 and found **latent**: «Брошури» and
 * «Тиражування» are both internal-only, and the commercial report has never
 * contained either. One checkbox on the categories page would change that.
 *
 * So the rule here is the one round 30 arrived at for the tier ladder:
 * **a missing price is shown as missing, not as a number**. `null` means the
 * server will price it; it is not zero, and it must not be summed as zero.
 */

/**
 * @param {Array<{pricing?: {total_price_commercial?: number|null}}>} cart
 * @returns {{total: number, incomplete: boolean}}
 *          `incomplete` — at least one line has no commercial price, so the
 *          total is a lower bound and must not be shown as if it were final.
 */
export function commercialTotal(cart) {
    let total = 0
    let incomplete = false

    for (const item of cart ?? []) {
        const value = item?.pricing?.total_price_commercial

        if (value === null || value === undefined) {
            incomplete = true
            continue
        }

        total += Number(value) || 0
    }

    return { total, incomplete }
}

/** A line whose commercial price only the server knows. */
export function commercialUnknown(item) {
    const value = item?.pricing?.total_price_commercial

    return value === null || value === undefined
}
