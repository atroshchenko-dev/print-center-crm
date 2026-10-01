<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * `shifts.date` is a Kyiv calendar day. It is not a moment in time.
 *
 * Laravel casts it to a date and serialises it as an instant, so what reaches
 * the page is `2026-08-01T00:00:00.000000Z`. Found on production 2026-08-02 in
 * «Аналітика лічильників», which printed exactly that under «Деталі по змінах»
 * where every other page shows a date.
 *
 * Two ways to get it wrong, and this pins both:
 *
 *  - **printing it raw** — the operator reads a machine string with
 *    microseconds and a `Z` that claims a timezone the value never had;
 *  - **parsing it with `new Date(…)`** — that re-reads midnight UTC in the
 *    viewer's zone, and anywhere west of Greenwich it names the **previous
 *    day**. The project has paid for this class twice already:
 *    a UTC instant of Kyiv midnight, read as a calendar day, is off by one.
 *
 * The safe form is to cut the day out of the string. A source assertion is the
 * only kind available — the formatting happens in the browser, so no endpoint
 * test can see it. Brittle to refactoring, and that is the accepted price; it
 * is the same trade `FrontendRouteWiringTest` makes.
 */
class CalendarDaysAreNotInstantsTest extends TestCase
{
    public function test_no_page_prints_a_shift_date_raw_or_parses_it_as_an_instant(): void
    {
        $offenders = [];

        foreach ($this->vueSources() as $relative => $source) {
            // `{{ shift.date }}`, `{{ s.date }}` — the value straight onto the page.
            if (preg_match('/\{\{\s*\w+\.date\s*\}\}/', $source)) {
                $offenders[] = $relative.' — prints a calendar day raw';
            }

            // `new Date(shift.date)` — midnight UTC re-read in the viewer's zone.
            if (preg_match('/new Date\(\s*\w+\.date\s*\)/', $source)) {
                $offenders[] = $relative.' — parses a calendar day as an instant';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A calendar day is not a moment in time.\n".
            "Cut the day out of the string (`String(v).slice(0, 10)`), do not print it raw and\n".
            'do not hand it to `new Date()` — see Reports/Counters.vue::shiftDay().',
        );
    }

    /** @return array<string, string> relative path => source */
    private function vueSources(): array
    {
        $root = resource_path('js');
        $sources = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $sources;
    }
}
