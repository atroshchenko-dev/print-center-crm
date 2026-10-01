/**
 * A shift's `date` is a **Kyiv calendar day**, not a moment in time.
 *
 * Laravel casts it and serialises it as an instant, so what reaches the page is
 * `2026-08-01T00:00:00.000000Z`. Four pages printed that string as-is — two of
 * them operational, not reports: the shift-close confirmation asked whether to
 * close the shift «за 2026-08-01T00:00:00.000000Z», and the open form offered to
 * settle «зміну від 2026-08-01T00:00:00.000000Z».
 *
 * **Cut the day out of the string. Never `new Date(…)`.** That parses midnight
 * UTC and prints it in the viewer's zone: west of Greenwich it names the
 * *previous* day, and this project has already paid for that class twice —
 * R5-5 (report bounds off by a day) and R6-2 (four readers of the same bound).
 *
 * One definition rather than four, for the reason round 16 extracted three
 * modules: a copy per page is how the copies drift.
 *
 * @param {string|null|undefined} value  ISO-ish string whose first 10 chars are `YYYY-MM-DD`
 * @returns {string} `DD.MM.YYYY`, or `—` when there is nothing to show
 */
export function calendarDay(value) {
    if (value === null || value === undefined || value === '') return '—'

    const [year, month, day] = String(value).slice(0, 10).split('-')

    // Anything that is not a date keeps its own shape — showing the raw value is
    // better than showing `NaN.NaN.NaN`, and it makes the surprise visible.
    if (!year || !month || !day) return String(value)

    return `${day}.${month}.${year}`
}
