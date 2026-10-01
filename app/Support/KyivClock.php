<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * Between the clock on the wall in Kyiv and the instants stored in UTC.
 *
 * Everything in the database is a UTC instant; everything a person types or
 * reads is a Kyiv wall clock. Each crossing of that line has been a defect at
 * least once:
 *
 *  - an instant read as a calendar day named the day before, and a report for
 *    July opened with June's last shift;
 *  - an instant formatted for a filter field came back a day early, so
 *    resubmitting the form asked for the previous month;
 *  - a Kyiv wall clock stored without conversion landed three hours late, and
 *    the same value validated against a UTC `now()` let a "scheduled" price
 *    change be already in the past.
 *
 * One class, because those were three different files each doing its own
 * version of the same arithmetic.
 *
 * Named KyivDay when it only did the first of these.
 */
final class KyivClock
{
    public const TZ = 'Europe/Kyiv';

    /** The Kyiv calendar day an instant falls on. */
    public static function format(Carbon $instant, string $format = 'Y-m-d'): string
    {
        return $instant->copy()->setTimezone(self::TZ)->format($format);
    }

    /**
     * The instant meant by a wall-clock string a person typed in Kyiv.
     *
     * Parsed here rather than left to the framework: Carbon defaults to
     * config('app.timezone'), which is UTC, so "2026-07-30 00:30" would be read
     * as half past midnight UTC — three hours after what the field said.
     */
    public static function instantFromLocal(string $localDateTime): Carbon
    {
        return Carbon::parse($localDateTime, self::TZ)->utc();
    }

    /**
     * An instant as the `datetime-local` input expects it: a Kyiv wall clock,
     * no zone. Feed a form field with this, never with the stored value.
     */
    public static function toLocalInput(?Carbon $instant): ?string
    {
        return $instant?->copy()->setTimezone(self::TZ)->format('Y-m-d\TH:i');
    }

    /**
     * The Kyiv calendar month an instant falls in, as the pair of UTC instants
     * that bound it.
     *
     * `now()->startOfMonth()` is a UTC month, and its first three hours belong
     * to the previous month in Kyiv — the same window that produced R3-7 and
     * R6-1. Anything selecting "this month" has to come through here.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthWindow(?Carbon $instant = null): array
    {
        $local = ($instant?->copy() ?? Carbon::now())->setTimezone(self::TZ);

        return [
            $local->copy()->startOfMonth()->utc(),
            $local->copy()->endOfMonth()->utc(),
        ];
    }

    /** Days in the Kyiv calendar month an instant falls in (28–31). */
    public static function daysInMonth(?Carbon $instant = null): int
    {
        return ($instant?->copy() ?? Carbon::now())->setTimezone(self::TZ)->daysInMonth;
    }
}
