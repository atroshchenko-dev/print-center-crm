<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Concerns\ParsesDateRange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReportDateRangeTest extends TestCase
{
    use ParsesDateRange;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_defaults_to_the_current_kyiv_month(): void
    {
        // 10:00 Kyiv on the 15th — nothing near a boundary, so this only pins
        // which month the default picks.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv'));

        [$from, $to] = $this->dateRange(Request::create('/reports/internal'));

        $this->assertEquals('2026-06-30 21:00', $from->format('Y-m-d H:i'), 'July in Kyiv starts at 21:00 UTC on 30 June.');
        $this->assertEquals('2026-07-15 20:59', $to->format('Y-m-d H:i'));
    }

    public function test_the_default_month_starts_on_the_kyiv_first_not_the_utc_one(): void
    {
        // 01:00 Kyiv on the 1st: a Kyiv-day report should already be in the new
        // month, and the orders of that hour belong to it. On a UTC boundary
        // the month had not started yet and they fell into the previous one.
        Carbon::setTestNow(Carbon::parse('2026-07-01 01:00:00', 'Europe/Kyiv'));

        [$from, $to] = $this->dateRange(Request::create('/reports/internal'));

        $this->assertTrue(
            now()->between($from, $to),
            'An order taken right now falls outside the report that defaults to "this month".',
        );
        $this->assertEquals('2026-06-30 21:00', $from->format('Y-m-d H:i'));
    }

    /**
     * Query input is untrusted text, and five controllers share this parser
     *: a non-date or an array reached
     * Carbon::parse() raw and answered 500 on every report, export, the
     * analytics page and the reconciliation screen at once. Garbage falls
     * back to the default range — the same answer an empty filter gives.
     */
    public function test_garbage_dates_fall_back_to_the_default_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv'));

        [$from, $to] = $this->dateRange(Request::create('/reports/internal', 'GET', [
            'from' => 'не-дата',
            'to'   => 'теж ні',
        ]));

        $this->assertEquals('2026-06-30 21:00', $from->format('Y-m-d H:i'));
        $this->assertEquals('2026-07-15 20:59', $to->format('Y-m-d H:i'));
    }

    public function test_array_dates_fall_back_to_the_default_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv'));

        [$from, $to] = $this->dateRange(Request::create('/reports/internal', 'GET', [
            'from' => ['2026-01-01'],
            'to'   => ['x'],
        ]));

        $this->assertEquals('2026-06-30 21:00', $from->format('Y-m-d H:i'));
        $this->assertEquals('2026-07-15 20:59', $to->format('Y-m-d H:i'));
    }

    public function test_parses_custom_date_range(): void
    {
        $request = Request::create('/reports/internal', 'GET', [
            'from' => '2026-04-01',
            'to'   => '2026-04-15',
        ]);

        [$from, $to] = $this->dateRange($request);

        // ParsesDateRange converts to UTC, so the date part may shift
        $expectedFrom = Carbon::parse('2026-04-01', 'Europe/Kyiv')->startOfDay()->utc();
        $expectedTo = Carbon::parse('2026-04-15', 'Europe/Kyiv')->endOfDay()->utc();

        $this->assertEquals($expectedFrom->format('Y-m-d H:i'), $from->format('Y-m-d H:i'));
        $this->assertEquals($expectedTo->format('Y-m-d H:i'), $to->format('Y-m-d H:i'));
    }

    public function test_caps_range_at_93_days(): void
    {
        $request = Request::create('/reports/internal', 'GET', [
            'from' => '2025-01-01',
            'to'   => '2026-04-23',
        ]);

        [$from, $to] = $this->dateRange($request);

        // diffInDays returns float due to UTC conversion offsets; floor to get whole days
        $diff = (int) floor($from->diffInDays($to));
        $this->assertLessThanOrEqual(93, $diff);
    }

    public function test_allows_ranges_within_93_days(): void
    {
        $request = Request::create('/reports/internal', 'GET', [
            'from' => '2026-03-01',
            'to'   => '2026-04-15',
        ]);

        [$from, $to] = $this->dateRange($request);

        // Verify dates are correct after UTC conversion
        $expectedFrom = Carbon::parse('2026-03-01', 'Europe/Kyiv')->startOfDay()->utc();
        $expectedTo = Carbon::parse('2026-04-15', 'Europe/Kyiv')->endOfDay()->utc();

        $this->assertEquals($expectedFrom->format('Y-m-d H:i'), $from->format('Y-m-d H:i'));
        $this->assertEquals($expectedTo->format('Y-m-d H:i'), $to->format('Y-m-d H:i'));
    }
}
