import { describe, it, expect } from 'vitest';
import { calendarDay } from '@/composables/useCalendarDay';

/**
 * A shift's `date` is a Kyiv calendar day. Laravel serialises it as an instant,
 * so the page receives `2026-08-01T00:00:00.000000Z` — and four pages printed
 * exactly that, two of them operational: the close-shift confirmation and the
 * settlement block on the open form (audit R21-6, found on production).
 *
 * The tests that matter here are the last two. Anyone rewriting this with
 * `new Date(value)` will pass the happy path and fail those, which is the whole
 * reason they are written down: midnight UTC read in a western zone is the
 * previous day, and this project has paid for that twice already.
 */
describe('calendarDay', () => {
    it('prints a Kyiv calendar day as DD.MM.YYYY', () => {
        expect(calendarDay('2026-08-01T00:00:00.000000Z')).toBe('01.08.2026');
    });

    it('accepts a plain date with no time at all', () => {
        expect(calendarDay('2026-08-01')).toBe('01.08.2026');
    });

    it('says nothing rather than NaN when there is nothing to say', () => {
        expect(calendarDay(null)).toBe('—');
        expect(calendarDay(undefined)).toBe('—');
        expect(calendarDay('')).toBe('—');
    });

    it('keeps an unexpected value visible instead of dressing it up', () => {
        expect(calendarDay('позавчора')).toBe('позавчора');
    });

    /**
     * The one that fails a `new Date()` implementation. In UTC-05:00 midnight UTC
     * of the 1st is 19:00 on the **31st**, and `toLocaleDateString` would say so.
     */
    it('does not shift the day in a timezone west of Greenwich', () => {
        const original = Date.prototype.toLocaleDateString;
        Date.prototype.toLocaleDateString = function () {
            return '31.07.2026'; // what a western zone would produce
        };

        try {
            expect(calendarDay('2026-08-01T00:00:00.000000Z')).toBe('01.08.2026');
        } finally {
            Date.prototype.toLocaleDateString = original;
        }
    });

    it('is not fooled by the last day of a month', () => {
        expect(calendarDay('2026-07-31T00:00:00.000000Z')).toBe('31.07.2026');
        expect(calendarDay('2026-01-01T00:00:00.000000Z')).toBe('01.01.2026');
    });
});
