/**
 * Shared composable for clean service option display.
 *
 * Handles all service types:
 *   - Constructor: hierarchical option names → last segment extraction
 *   - RISO: riso_params → format, sides, paper
 *   - Brochure: brochure_params → format, cover, block details
 *   - Diploma: diploma_params → component breakdown
 *
 * Also filters out irrelevant groups (e.g. Заповненість for internal orders).
 */

/**
 * Extract clean option labels from constructor snapshot.
 *
 * @param {Array} snapshot - constructor_snapshot array from service_snapshot JSONB
 * @param {Object} options
 * @param {boolean} [options.isInternal=false] - whether the order is internal (hides fill %)
 * @returns {string[]} Clean option labels
 */
export function cleanOptionLabels(snapshot, { isInternal = false } = {}) {
    if (!snapshot?.length) return []

    return snapshot
        // Hide fill percentage (Заповненість) for internal orders — not used in pricing
        .filter(s => !(isInternal && s.group_name === 'Заповненість'))
        .map(s => s.option_name)
        .filter(Boolean)
        // Extract only the last segment after ": " from hierarchical names
        .map(o => {
            const idx = o.lastIndexOf(': ')
            return idx >= 0 ? o.slice(idx + 2) : o
        })
}

/**
 * Extract display tags from RISO params.
 *
 * @param {Object} risoParams - service_snapshot.riso_params
 * @returns {string[]} e.g. ["А3", "1 стор.", "Папір А3 80 г/м²"]
 */
export function risoOptionLabels(risoParams) {
    if (!risoParams) return []
    const labels = []
    if (risoParams.format) labels.push(risoParams.format)

    // The number that decides the price, and the one the card used to leave out.
    // A3 sheets are rounded up **per original**, so 2 originals × 99
    // copies is 100 sheets at the 100–149 rate, while 1 original × 198 copies is
    // 99 sheets at the 50–99 rate — same 198 copies, different money. Without
    // this chip the order card shows the quantity and the total and nothing
    // that explains why the total is what it is.
    //
    // Only when the snapshot actually recorded it: rounds before 14 have no
    // such key, and printing «1 ориг.» there would state something the snapshot
    // never held.
    if (risoParams.originals) labels.push(`${risoParams.originals} ориг.`)

    if (risoParams.sides) labels.push(risoParams.sides === 1 ? '1 стор.' : '2 стор.')
    if (risoParams.paper_name && risoParams.paper_name !== 'Невідомо') {
        labels.push(risoParams.paper_name)
    }
    return labels
}

/**
 * Extract display tags from any service_snapshot — handles all service types.
 *
 * @param {Object} serviceSnapshot - full service_snapshot JSONB
 * @param {Object} options
 * @param {boolean} [options.isInternal=false]
 * @returns {string[]} Clean option labels
 */
export function serviceOptionLabels(serviceSnapshot, { isInternal = false } = {}) {
    if (!serviceSnapshot) return []

    // Constructor services
    if (serviceSnapshot.constructor_snapshot?.length) {
        return cleanOptionLabels(serviceSnapshot.constructor_snapshot, { isInternal })
    }

    // RISO services
    if (serviceSnapshot.riso_params) {
        return risoOptionLabels(serviceSnapshot.riso_params)
    }

    // Brochure services
    if (serviceSnapshot.brochure_params) {
        const bp = serviceSnapshot.brochure_params
        const labels = []
        if (bp.format) labels.push(bp.format)
        if (bp.cover_mode) labels.push(`Обкл: ${bp.cover_mode}`)
        return labels
    }

    // Diploma services
    if (serviceSnapshot.diploma_params) {
        const dp = serviceSnapshot.diploma_params
        const labels = []
        if (dp.format) labels.push(dp.format)
        return labels
    }

    return []
}

/**
 * Format clean options into a single string (for text contexts like emails).
 *
 * @param {Array} snapshot - constructor_snapshot array
 * @param {Object} options
 * @param {boolean} [options.isInternal=false]
 * @param {string} [options.separator=' · ']
 * @returns {string}
 */
export function formatOptionsSummary(snapshot, { isInternal = false, separator = ' · ' } = {}) {
    return cleanOptionLabels(snapshot, { isInternal }).join(separator)
}
