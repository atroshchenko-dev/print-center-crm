<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One morning reading per machine per shift.
 *
 * The rule shipped with the original table and was dropped in April to make
 * room for the repeatable `adjustment` type. Narrowing it to the once-per-shift
 * types is what that migration's own down() describes; its up() only dropped,
 * so between April and now the rule lived nowhere — not in the schema, not in
 * OpenShiftRequest, only in the shape of the form.
 *
 * Both halves are pinned here: the two types that are once per shift, and the
 * one that is deliberately not.
 */
class CounterReadingUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create(['role' => 'executor']);
        $this->equipment = Equipment::factory()->create([
            'type' => 'bw', 'is_active' => true, 'has_counter' => true,
        ]);
    }

    /** The readable half: the operator gets a message, not a 500. */
    public function test_the_open_form_refuses_two_readings_for_one_machine(): void
    {
        $this->actingAs($this->user)
            ->post(route('shifts.open'), [
                'readings' => [
                    ['equipment_id' => $this->equipment->id, 'counter_value' => 1000],
                    ['equipment_id' => $this->equipment->id, 'counter_value' => 5000],
                ],
            ])
            ->assertSessionHasErrors('readings.0.equipment_id');

        $this->assertSame(0, Shift::count(), 'The shift opened on a payload the rule forbids.');
    }

    public function test_a_single_reading_per_machine_still_opens_the_shift(): void
    {
        $this->actingAs($this->user)
            ->post(route('shifts.open'), [
                'readings' => [
                    ['equipment_id' => $this->equipment->id, 'counter_value' => 1000],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Shift::count());
    }

    /**
     * The half that holds when the request never goes through the form —
     * a console command, a seeder, a second window racing the first.
     */
    public function test_the_database_refuses_a_second_morning_reading(): void
    {
        $shift = app(ShiftService::class)->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 1000],
        ]);

        $this->expectException(QueryException::class);

        ShiftCounterReading::create([
            'shift_id'      => $shift->id,
            'equipment_id'  => $this->equipment->id,
            'user_id'       => $this->user->id,
            'reading_type'  => 'morning',
            'counter_value' => 5000,
        ]);
    }

    /**
     * And the reason the old constraint had to go in the first place: a machine
     * can be corrected more than once in a shift, and the index must not be the
     * thing that stops it.
     */
    public function test_two_adjustments_in_one_shift_are_still_allowed(): void
    {
        $shift = app(ShiftService::class)->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 1000],
        ]);

        foreach ([0, 500] as $value) {
            ShiftCounterReading::create([
                'shift_id'      => $shift->id,
                'equipment_id'  => $this->equipment->id,
                'user_id'       => $this->user->id,
                'reading_type'  => 'adjustment',
                'counter_value' => $value,
            ]);
        }

        $this->assertSame(2, ShiftCounterReading::where('reading_type', 'adjustment')->count());
    }
}
