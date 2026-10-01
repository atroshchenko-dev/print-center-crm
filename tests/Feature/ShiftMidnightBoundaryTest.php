<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ShiftStatus;
use App\Jobs\AutoCloseShiftJob;
use App\Models\Equipment;
use App\Models\LedgerTransaction;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftOpenService;
use App\Services\ShiftService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ShiftMidnightBoundaryTest
 *
 * A shift is auto-closed at 03:00 Kyiv (TZ §4.4), so between midnight and
 * 03:00 the open shift is dated yesterday. Everything that looked it up by
 * `date = today()` went blind in that window: the operator was locked out of
 * their own shift, a second shift could be opened on top of the first, the
 * till balance stopped carrying over, and the shift holding the day's real
 * takings was left unsettled with nothing to bring it back.
 */
class ShiftMidnightBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Equipment $equipment;
    private ShiftService $shifts;
    private ShiftOpenService $opener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create(['role' => 'admin']);
        $this->equipment = Equipment::factory()->create([
            'type' => 'bw', 'is_active' => true, 'has_counter' => true,
            'initial_counter' => 0,
        ]);
        $this->shifts = app(ShiftService::class);
        $this->opener = app(ShiftOpenService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Yesterday 10:00 Kyiv: a shift opens and takes `$cash` in cash. */
    private function openTradingShift(float $cash): Shift
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', 'Europe/Kyiv'));

        $shift = $this->shifts->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 100],
        ]);

        LedgerTransaction::create([
            'shift_id'       => $shift->id,
            'user_id'        => $this->user->id,
            'type'           => 'payment_cash',
            'payment_method' => 'cash',
            'amount'         => $cash,
            'balance_after'  => $cash,
            'description'    => 'day takings',
        ]);

        return $shift->fresh();
    }

    /** Move to 01:00 Kyiv — past midnight, before the 03:00 auto-close. */
    private function travelPastMidnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 01:00:00', 'Europe/Kyiv'));
    }

    public function test_yesterdays_shift_is_still_the_current_one_after_midnight(): void
    {
        $shift = $this->openTradingShift(2000.00);
        $this->travelPastMidnight();

        $this->assertEquals($shift->id, $this->shifts->getCurrentShift()?->id);
    }

    public function test_operator_is_not_locked_out_of_their_own_shift_after_midnight(): void
    {
        $this->openTradingShift(2000.00);
        $this->travelPastMidnight();

        $this->actingAs($this->user)->get('/orders')->assertOk();
    }

    public function test_a_second_shift_cannot_be_opened_on_top_of_a_running_one(): void
    {
        $this->openTradingShift(2000.00);
        $this->travelPastMidnight();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already open');

        $this->shifts->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 200],
        ]);
    }

    public function test_database_rejects_a_second_open_shift_even_on_another_date(): void
    {
        $this->openTradingShift(2000.00);

        $this->expectException(QueryException::class);

        // Straight past the application guard, the way a race would arrive.
        \Illuminate\Support\Facades\DB::table('shifts')->insert([
            'date'       => '2026-07-28',
            'opened_by'  => $this->user->id,
            'status'     => ShiftStatus::Open->value,
            'cash_start' => 0,
            'opened_at'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_till_carries_over_from_the_shift_that_actually_traded(): void
    {
        $trading = $this->openTradingShift(2000.00);
        $this->assertEquals(2000.00, $trading->calculateExpectedBalance());

        // 03:00 — the scheduler closes it.
        Carbon::setTestNow(Carbon::parse('2026-07-28 03:00:00', 'Europe/Kyiv'));
        app(AutoCloseShiftJob::class)->handle($this->shifts);

        // 08:00 — operator counts the till and opens the new shift.
        Carbon::setTestNow(Carbon::parse('2026-07-28 08:00:00', 'Europe/Kyiv'));
        $morning = $this->shifts->openShift(
            $this->user,
            [['equipment_id' => $this->equipment->id, 'counter_value' => 300]],
            2000.00,
        );

        $this->assertEquals(2000.00, (float) $morning->cash_start);
    }

    public function test_no_shift_is_left_unsettled_after_the_night(): void
    {
        $this->openTradingShift(2000.00);

        Carbon::setTestNow(Carbon::parse('2026-07-28 03:00:00', 'Europe/Kyiv'));
        app(AutoCloseShiftJob::class)->handle($this->shifts);

        Carbon::setTestNow(Carbon::parse('2026-07-28 08:00:00', 'Europe/Kyiv'));
        $this->shifts->openShift(
            $this->user,
            [['equipment_id' => $this->equipment->id, 'counter_value' => 300]],
            2000.00,
        );

        $stranded = Shift::whereIn('status', [ShiftStatus::Closed->value, ShiftStatus::AutoClosed->value])
            ->whereNull('cash_actual')
            ->pluck('id');

        $this->assertEmpty($stranded, 'A closed shift was left with no cash settlement.');
    }

    public function test_settlement_drains_the_oldest_unsettled_shift_first(): void
    {
        $old = Shift::create([
            'date' => '2026-07-20', 'opened_by' => $this->user->id,
            'status' => ShiftStatus::AutoClosed->value, 'cash_start' => 100.00,
            'cash_calculated' => 100.00, 'auto_closed' => true, 'settlement_required' => true,
            'opened_at' => '2026-07-20 08:00:00', 'closed_at' => '2026-07-21 03:00:00',
        ]);
        Shift::create([
            'date' => '2026-07-21', 'opened_by' => $this->user->id,
            'status' => ShiftStatus::AutoClosed->value, 'cash_start' => 100.00,
            'cash_calculated' => 100.00, 'auto_closed' => true, 'settlement_required' => true,
            'opened_at' => '2026-07-21 08:00:00', 'closed_at' => '2026-07-22 03:00:00',
        ]);

        $this->assertEquals($old->id, $this->opener->getPreviousUnsettledShift()?->id);
    }
}
