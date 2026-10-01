/**
 * Values the frontend has to agree with the backend on, character for
 * character. Introduced for audit finding L-6.
 */

/**
 * The parameter group whose options are free when the customer brings their own
 * paper. There is no flag column for this — the rule is keyed on the group's
 * exact name, so a rename in the admin UI silently disables it.
 *
 * Must stay in sync with App\Models\ServiceParameterGroup::PAPER.
 */
export const PAPER_GROUP = 'Тип паперу'

/** Strips the "А4: " / "А3: " format prefix printers put on paper option names. */
export const PAPER_FORMAT_PREFIX = /^А[34]:\s*/

/**
 * The size classes <Icon> is allowed to render, spelled out whole.
 *
 * Tailwind ships only classes it can read as complete literals somewhere in
 * the scanned source. A class built at runtime — `w-${size}` — is invisible
 * to it, so each icon size worked only while some unrelated file happened to
 * carry the same literal (audit I-2). Add the literal here before passing a
 * new size to <Icon>; Icon.spec.js enforces this.
 */
export const ICON_SIZE_CLASSES = {
    '3': 'w-3 h-3',
    '4': 'w-4 h-4',
    '5': 'w-5 h-5',
    '7': 'w-7 h-7',
}

/** The one timezone this business runs on. Matches App\Support\KyivClock::TZ. */
export const KYIV_TZ = 'Europe/Kyiv'

/**
 * Today's date in Kyiv, as `YYYY-MM-DD` for a `<input type="date">`.
 *
 * Not `new Date().toISOString()`: that is the UTC date, so between midnight and
 * 03:00 Kyiv it names yesterday and the picker refuses the day the operator is
 * living in — the same window the 03:00 auto-close exists for. Not the
 * browser's local date either, since that is whatever the machine is set to;
 * the server validates against Kyiv, so the picker must offer Kyiv.
 *
 * 'sv-SE' is the locale whose short date format is already ISO.
 */
export function kyivToday() {
    return new Date().toLocaleDateString('sv-SE', { timeZone: KYIV_TZ })
}
