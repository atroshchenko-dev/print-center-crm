<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\KyivClock;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Shared date range parsing for report controllers.
 * Parses from/to query params with Europe/Kyiv timezone,
 * defaults to current month, caps at 93 days max.
 *
 * Every boundary is a Kyiv midnight converted to UTC. The submitted dates
 * always were; the defaults were not — `now()` is UTC here, so an unfiltered
 * report began and ended at 03:00 Kyiv and quietly took in three hours of the
 * next day.
 */
trait ParsesDateRange
{
    private function dateRange(Request $request): array
    {
        // Query input is untrusted text: an array or a non-date must fall
        // back to the default range, not answer 500 from Carbon — five
        // controllers share this parser, so the class closes here once.
        $from = $this->kyivDayOrNull($request->input('from'))?->startOfDay()->utc()
            ?? today('Europe/Kyiv')->startOfMonth()->utc();

        $to = $this->kyivDayOrNull($request->input('to'))?->endOfDay()->utc()
            ?? today('Europe/Kyiv')->endOfDay()->utc();

        // Safety: cap range at 93 days (1 quarter) to prevent memory exhaustion
        if ($from->diffInDays($to) > 93) {
            $from = $to->copy()->setTimezone('Europe/Kyiv')->subDays(93)->startOfDay()->utc();
        }

        return [$from, $to];
    }

    /** The Kyiv wall-clock day a query value names, or null if it names none. */
    private function kyivDayOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Europe/Kyiv');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The same range as the Kyiv dates a person would name it by.
     *
     * The bounds are UTC instants, so formatting them directly moves the start
     * of a Kyiv day back onto the previous date: a report asked for from the
     * 1st was exported as `…-20260630-…`. Anything a user reads — file names,
     * headings, the values echoed back into the filter fields — goes through
     * here, and so does anything compared against a calendar day.
     *
     * @return array{0: string, 1: string}
     */
    private function dateRangeLabel(Carbon $from, Carbon $to, string $format = 'Ymd'): array
    {
        return [
            KyivClock::format($from, $format),
            KyivClock::format($to, $format),
        ];
    }

    /**
     * The range as calendar days, for the two reports that select on
     * `shifts.date` rather than on an instant.
     *
     * @return array{0: string, 1: string}
     */
    private function dateRangeDays(Carbon $from, Carbon $to): array
    {
        return $this->dateRangeLabel($from, $to, 'Y-m-d');
    }
}
