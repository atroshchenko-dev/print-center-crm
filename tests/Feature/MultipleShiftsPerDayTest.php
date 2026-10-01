<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ShiftStatus;
use App\Models\Equipment;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftOpenService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MultipleShiftsPerDayTest
 *
 * Until the midnight fix, `unique_open_shift_per_date` meant a calendar date
 * identified a shift, and every lookup ordered by `date` alone. The index now
 * allows one open shift regardless of date, which also makes several closed
 * shifts on one date ordinary — a day shift plus the one that ran into the
 * night, or simply two operators.
 *
 * From that point `ORDER BY date` is no longer a total order: which of the
 * day's shifts came back was up to the query planner. The one that decides the
 * money is the till carry-over — take the earlier shift and the new shift
 * starts on a stale count, which surfaces at the next close as a discrepancy
 * nobody can explain.
 */
class MultipleShiftsPerDayTest extends TestCase
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

    /**
     * A shift that opened and closed on $date, leaving $cashActual in the till.
     */
    private function closedShift(string $date, ?float $cashActual, string $openedAt): Shift
    {
        return Shift::create([
            'date'            => $date,
            'opened_by'       => $this->user->id,
            'status'          => ShiftStatus::Closed->value,
            'cash_start'      => 0.00,
            'cash_calculated' => $cashActual,
            'cash_actual'     => $cashActual,
            'opened_at'       => $openedAt,
            'closed_at'       => $openedAt,
        ]);
    }

    /**
     * Put the row the query is supposed to return at the end of the heap.
     *
     * An UPDATE writes a new tuple version at the end of the table, so an
     * unordered scan reads the untouched row first — and a sort on `date`
     * alone leaves ties in exactly that order. This is what the planner did on
     * its own; the test only makes it repeatable.
     */
    private function shuffleHeapOrder(Shift $shift): void
    {
        Shift::where('id', $shift->id)->update(['updated_at' => now()]);
    }

    public function test_till_carries_over_from_the_later_of_two_shifts_the_same_day(): void
    {
        $this->closedShift('2026-07-27', 800.00, '2026-07-27 08:00:00');
        $evening = $this->closedShift('2026-07-27', 950.00, '2026-07-27 16:00:00');

        $this->shuffleHeapOrder($evening);

        Carbon::setTestNow(Carbon::parse('2026-07-28 08:00:00', 'Europe/Kyiv'));

        $shift = $this->shifts->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 100],
        ]);

        $this->assertEquals(950.00, (float) $shift->cash_start);
    }

    public function test_settlement_takes_the_earlier_of_two_unsettled_shifts_the_same_day(): void
    {
        $morning = $this->closedShift('2026-07-27', null, '2026-07-27 08:00:00');
        $evening = $this->closedShift('2026-07-27', null, '2026-07-27 16:00:00');

        $this->shuffleHeapOrder($morning);

        $this->assertEquals(
            $morning->id,
            $this->opener->getPreviousUnsettledShift()?->id,
            'Settlement skipped the day\'s first shift, and nothing brings it back.',
        );
        $this->assertNotEquals($evening->id, $this->opener->getPreviousUnsettledShift()?->id);
    }

    public function test_the_last_closed_shift_is_the_later_of_two_the_same_day(): void
    {
        $this->closedShift('2026-07-27', 800.00, '2026-07-27 08:00:00');
        $evening = $this->closedShift('2026-07-27', 950.00, '2026-07-27 16:00:00');

        $this->shuffleHeapOrder($evening);

        $this->assertEquals($evening->id, $this->shifts->getLastClosedShift()?->id);
    }

    public function test_the_open_shift_form_offers_the_later_shifts_closing_count(): void
    {
        $this->closedShift('2026-07-27', 800.00, '2026-07-27 08:00:00');
        $evening = $this->closedShift('2026-07-27', 950.00, '2026-07-27 16:00:00');

        $this->shuffleHeapOrder($evening);

        $this->actingAs($this->user)
            ->get(route('shifts.open.form'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('cash_start', fn ($cash) => (float) $cash === 950.00));
    }
}
