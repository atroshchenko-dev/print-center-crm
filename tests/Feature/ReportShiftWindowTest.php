<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerTransactionType;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Exports\CashFlowExport;
use App\Models\Equipment;
use App\Models\LedgerTransaction;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The two reports that select shifts by `shifts.date`.
 *
 * `date` is a calendar day in Kyiv; the range bounds are UTC instants of Kyiv
 * midnights. Formatting a bound with toDateString() names the day before —
 * which is how a report for July came to open with June's last shift.
 */
class ReportShiftWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => UserRole::Admin,
            'permissions' => User::permissionKeys(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function closedShiftOn(string $date): Shift
    {
        return Shift::factory()->create([
            'date'            => $date,
            'status'          => ShiftStatus::Closed,
            'opened_by'       => $this->admin->id,
            'closed_by'       => $this->admin->id,
            'closed_at'       => Carbon::parse($date.' 20:00:00', 'Europe/Kyiv'),
            'cash_start'      => 0,
            'cash_calculated' => 0,
            'cash_actual'     => 0,
        ]);
    }

    public function test_cash_flow_leaves_out_the_shift_before_the_range(): void
    {
        $before = $this->closedShiftOn('2026-06-30');
        $inside = $this->closedShiftOn('2026-07-01');

        $response = $this->actingAs($this->admin)
            ->get(route('reports.cash-flow', ['from' => '2026-07-01', 'to' => '2026-07-31']));

        $response->assertOk();

        $ids = collect($response->viewData('page')['props']['shifts'])->pluck('id')->all();

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains(
            $before->id,
            $ids,
            'A July report opened with the shift of 30 June: the UTC bound was read as a Kyiv date.',
        );
    }

    public function test_cash_flow_totals_do_not_carry_the_previous_days_money(): void
    {
        $before = $this->closedShiftOn('2026-06-30');

        LedgerTransaction::create([
            'shift_id'       => $before->id,
            'type'           => LedgerTransactionType::PaymentCash->value,
            'payment_method' => 'cash',
            'amount'         => 777.00,
            'balance_after'  => 777.00,
            'user_id'        => $this->admin->id,
        ]);

        $this->closedShiftOn('2026-07-01');

        $response = $this->actingAs($this->admin)
            ->get(route('reports.cash-flow', ['from' => '2026-07-01', 'to' => '2026-07-31']));

        $response->assertOk();

        $this->assertEquals(
            0.0,
            (float) $response->viewData('page')['props']['totals']['cash_in'],
            'June money was counted into the July cash-flow total.',
        );
    }

    public function test_counters_leave_out_the_shift_before_the_range(): void
    {
        $before = $this->closedShiftOn('2026-06-30');
        $inside = $this->closedShiftOn('2026-07-01');

        $response = $this->actingAs($this->admin)
            ->get(route('reports.counters', ['from' => '2026-07-01', 'to' => '2026-07-31']));

        $response->assertOk();

        $ids = collect($response->viewData('page')['props']['shifts'])->pluck('id')->all();

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($before->id, $ids);
    }

    /**
     * The default range is "this Kyiv month" — and that is the one an operator
     * gets by opening the page without touching the filter.
     */
    public function test_the_default_month_does_not_reach_back_into_the_previous_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv'));

        $lastMonth = $this->closedShiftOn('2026-06-30');
        $thisMonth = $this->closedShiftOn('2026-07-02');

        $response = $this->actingAs($this->admin)->get(route('reports.cash-flow'));

        $response->assertOk();

        $ids = collect($response->viewData('page')['props']['shifts'])->pluck('id')->all();

        $this->assertContains($thisMonth->id, $ids);
        $this->assertNotContains(
            $lastMonth->id,
            $ids,
            'The unfiltered report reached one day back into June.',
        );
    }

    /**
     * Physical delta is last morning reading minus first. Pull in the previous
     * day's shift and the "first" reading is one the period never saw, so the
     * whole delta — and the unaccounted figure built on it — is wrong.
     */
    public function test_counter_delta_starts_at_the_first_reading_inside_the_range(): void
    {
        $equipment = Equipment::factory()->create([
            'name'        => 'Ricoh MP',
            'type'        => 'bw',
            'is_active'   => true,
            'has_counter' => true,
        ]);

        $before = $this->closedShiftOn('2026-06-30');
        $first = $this->closedShiftOn('2026-07-01');
        $last = $this->closedShiftOn('2026-07-10');

        foreach ([[$before, 1000], [$first, 5000], [$last, 5300]] as [$shift, $value]) {
            ShiftCounterReading::create([
                'shift_id'      => $shift->id,
                'equipment_id'  => $equipment->id,
                'user_id'       => $this->admin->id,
                'reading_type'  => 'morning',
                'counter_value' => $value,
                'created_at'    => $shift->date->copy()->setTime(8, 0),
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('reports.counters', ['from' => '2026-07-01', 'to' => '2026-07-31']));

        $response->assertOk();

        $row = collect($response->viewData('page')['props']['counterAnalytics'])
            ->firstWhere('equipment_id', $equipment->id);

        $this->assertEquals(
            300,
            $row['physical_delta'],
            'The delta was measured from June\'s reading, not the first one in the range.',
        );
    }

    /**
     * A machine marked as having no counter is never given a morning reading,
     * so it can only ever report a delta of zero — and every software click of
     * its type then reads as unaccounted. Production has exactly one such
     * machine: ProductionResetCommand sets has_counter = false on the Riso.
     */
    public function test_a_machine_without_a_counter_is_not_in_the_counter_report(): void
    {
        $counted = Equipment::factory()->create([
            'name' => 'Ricoh MP', 'type' => 'bw', 'is_active' => true, 'has_counter' => true,
        ]);
        $uncounted = Equipment::factory()->create([
            'name'        => 'Ricoh DD4450', 'type' => 'riso', 'is_active' => true,
            'has_counter' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.counters', ['from' => '2026-07-01', 'to' => '2026-07-31']));

        $response->assertOk();

        $ids = collect($response->viewData('page')['props']['counterAnalytics'])
            ->pluck('equipment_id')->all();

        $this->assertContains($counted->id, $ids);
        $this->assertNotContains(
            $uncounted->id,
            $ids,
            'A machine with no counter was given a row, and every click of its type counted as unaccounted.',
        );
    }

    /**
     * A counter reset inside the reported period.
     *
     * The delta was "last morning reading minus first", which assumes the
     * counter only ever climbs. It does not: a replaced board starts again
     * from zero, and TZ §3.4 exists to record that. Until round 7 the case was
     * unreachable — a reset left the shift impossible to open at all — so
     * fixing that is what made this arithmetic reachable, and it produces a
     * delta of about minus a hundred thousand on the one report whose job is
     * to say whether machine usage matches what was billed.
     */
    public function test_a_counter_reset_does_not_turn_the_delta_negative(): void
    {
        $equipment = Equipment::factory()->create([
            'name' => 'Ricoh MP', 'type' => 'bw', 'is_active' => true, 'has_counter' => true,
        ]);

        $before = $this->closedShiftOn('2026-07-01');
        $after = $this->closedShiftOn('2026-07-06');
        $last = $this->closedShiftOn('2026-07-10');

        $this->reading($before, $equipment, 'morning', 100_000, '08:00');
        // Board replaced on the 5th; the counter starts again from zero.
        $this->reading($before, $equipment, 'adjustment', 0, '18:00');
        $this->reading($after, $equipment, 'morning', 500, '08:00');
        $this->reading($last, $equipment, 'morning', 2_000, '08:00');

        $row = $this->counterRow($equipment);

        // 500 from the reset to the 6th, 1 500 from the 6th to the 10th. What
        // happened between the 1st and the reset is unmeasurable and counts as
        // nothing — that is the cost of losing the counter, not a negative.
        $this->assertSame(
            2_000,
            $row['physical_delta'],
            'The reset was counted as printing, in reverse.',
        );
    }

    /**
     * With no reset in the period, the answer is unchanged: last minus first.
     */
    public function test_without_a_reset_the_delta_is_still_last_minus_first(): void
    {
        $equipment = Equipment::factory()->create([
            'name' => 'Ricoh MP', 'type' => 'bw', 'is_active' => true, 'has_counter' => true,
        ]);

        $this->reading($this->closedShiftOn('2026-07-01'), $equipment, 'morning', 5_000, '08:00');
        $this->reading($this->closedShiftOn('2026-07-05'), $equipment, 'morning', 5_400, '08:00');
        $this->reading($this->closedShiftOn('2026-07-10'), $equipment, 'morning', 6_200, '08:00');

        $this->assertSame(1_200, $this->counterRow($equipment)['physical_delta']);
    }

    /**
     * An adjustment upward — the admin read the true figure off the service
     * menu — is not printing either. Nobody produced those pages here.
     */
    public function test_an_upward_adjustment_is_not_counted_as_printing(): void
    {
        $equipment = Equipment::factory()->create([
            'name' => 'Ricoh MP', 'type' => 'bw', 'is_active' => true, 'has_counter' => true,
        ]);

        $first = $this->closedShiftOn('2026-07-01');
        $this->reading($first, $equipment, 'morning', 1_000, '08:00');
        $this->reading($first, $equipment, 'adjustment', 50_000, '18:00');
        $this->reading($this->closedShiftOn('2026-07-10'), $equipment, 'morning', 50_300, '08:00');

        $this->assertSame(300, $this->counterRow($equipment)['physical_delta']);
    }

    private function reading(Shift $shift, Equipment $equipment, string $type, int $value, string $time): void
    {
        ShiftCounterReading::create([
            'shift_id'      => $shift->id,
            'equipment_id'  => $equipment->id,
            'user_id'       => $this->admin->id,
            'reading_type'  => $type,
            'counter_value' => $value,
            'created_at'    => $shift->date->copy()->setTimeFromTimeString($time),
        ]);
    }

    /** @return array<string, mixed> */
    private function counterRow(Equipment $equipment): array
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.counters', ['from' => '2026-07-01', 'to' => '2026-07-31']));

        $response->assertOk();

        return collect($response->viewData('page')['props']['counterAnalytics'])
            ->firstWhere('equipment_id', $equipment->id);
    }

    public function test_the_cash_flow_export_uses_the_same_window_as_the_page(): void
    {
        Excel::fake();

        $before = $this->closedShiftOn('2026-06-30');
        $inside = $this->closedShiftOn('2026-07-01');

        LedgerTransaction::create([
            'shift_id'       => $before->id,
            'type'           => LedgerTransactionType::PaymentCash->value,
            'payment_method' => 'cash',
            'amount'         => 777.00,
            'balance_after'  => 777.00,
            'user_id'        => $this->admin->id,
        ]);
        LedgerTransaction::create([
            'shift_id'       => $inside->id,
            'type'           => LedgerTransactionType::PaymentCash->value,
            'payment_method' => 'cash',
            'amount'         => 100.00,
            'balance_after'  => 100.00,
            'user_id'        => $this->admin->id,
        ]);

        $rows = (new CashFlowExport(
            Carbon::parse('2026-07-01', 'Europe/Kyiv')->startOfDay()->utc(),
            Carbon::parse('2026-07-31', 'Europe/Kyiv')->endOfDay()->utc(),
        ))->collection();

        $this->assertCount(1, $rows, 'The export carried a transaction from the shift before the range.');
        $this->assertEquals($inside->id, $rows->first()->shift_id);
    }
}
