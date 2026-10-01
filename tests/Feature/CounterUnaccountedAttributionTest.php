<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `order_items` does not record which machine printed a job, so the counters
 * report sums software clicks per counter type. With one counter-bearing
 * machine of a type that is the same number; with two it is not — each row
 * subtracts the type's whole total from its own physical delta, and both
 * "Нерозраховано" figures become arithmetic about nothing.
 *
 * The controller has always known this — the NOTE has been in the code for
 * rounds — and nobody reading the report was told.
 *
 * Round 21 stopped there, and this docblock said the numbers "cannot be fixed
 * without recording the machine on the order item". That was true of the
 * per-machine figure and false of the report: the type's balance — the deltas
 * of the type's machines against the same software total — was computable all
 * along, and the page showed it nowhere. The owner settled the
 * rest on 2026-08-02: **the order item will not record the machine**, so the
 * type is the finest grain this report will ever have.
 */
class CounterUnaccountedAttributionTest extends TestCase
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function analytics(): array
    {
        return $this->props()['counterAnalytics'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function typeTotals(): array
    {
        return collect($this->props()['counterTypeTotals'])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function props(): array
    {
        $response = $this->actingAs($this->admin)->get(route('reports.counters'));
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * A morning reading, at a fixed second inside the report's default range.
     */
    private function reading(Shift $shift, Equipment $equipment, int $value, string $at): void
    {
        $reading = new ShiftCounterReading;
        $reading->shift_id = $shift->id;
        $reading->equipment_id = $equipment->id;
        $reading->user_id = $this->admin->id;
        $reading->reading_type = 'morning';
        $reading->counter_value = $value;
        $reading->created_at = Carbon::parse($at);
        $reading->save();
    }

    /**
     * Black-and-white clicks the orders account for, on one shift.
     */
    private function bwClicks(Shift $shift, int $clicks): void
    {
        $order = Order::factory()->create(['shift_id' => $shift->id]);

        OrderItem::factory()->create([
            'order_id'     => $order->id,
            'bw_clicks'    => $clicks,
            'color_clicks' => 0,
            'riso_clicks'  => 0,
        ]);
    }

    public function test_one_machine_of_a_type_owns_its_clicks(): void
    {
        Equipment::factory()->create(['type' => 'bw',    'has_counter' => true, 'is_active' => true]);
        Equipment::factory()->create(['type' => 'color', 'has_counter' => true, 'is_active' => true]);

        foreach ($this->analytics() as $row) {
            $this->assertTrue($row['clicks_are_this_machines'], $row['equipment_name']);
        }
    }

    public function test_two_machines_of_one_type_do_not_own_their_clicks(): void
    {
        Equipment::factory()->create(['name' => 'Ricoh 1', 'type' => 'bw', 'has_counter' => true, 'is_active' => true]);
        Equipment::factory()->create(['name' => 'Ricoh 2', 'type' => 'bw', 'has_counter' => true, 'is_active' => true]);
        Equipment::factory()->create(['name' => 'Кольор',  'type' => 'color', 'has_counter' => true, 'is_active' => true]);

        $rows = collect($this->analytics())->keyBy('equipment_name');

        $this->assertFalse($rows['Ricoh 1']['clicks_are_this_machines']);
        $this->assertFalse($rows['Ricoh 2']['clicks_are_this_machines']);
        $this->assertTrue(
            $rows['Кольор']['clicks_are_this_machines'],
            'A type with one machine is unaffected by another type having two.',
        );
    }

    /**
     * The arithmetic, with the numbers from the finding.
     *
     * Two black-and-white machines, 1000 and 800 pages on their counters, 1500
     * clicks in the orders. Each row reads its own delta against the type's
     * whole total, so the page shows −500 and −700; the department's actual
     * answer is +300, and before round 22 it appeared nowhere.
     */
    public function test_a_shared_type_is_given_the_balance_its_rows_cannot_carry(): void
    {
        // The two shifts sit on the 1st and 2nd of the month; on the 1st itself
        // the second one would be in the future. Mid-month keeps both in the past.
        $this->travelTo(Carbon::now('Europe/Kyiv')->startOfMonth()->addDays(14)->setTime(12, 0));

        $konica = Equipment::factory()->create(['name' => 'Konica', 'type' => 'bw', 'has_counter' => true, 'is_active' => true]);
        $kyocera = Equipment::factory()->create(['name' => 'Kyocera', 'type' => 'bw', 'has_counter' => true, 'is_active' => true]);

        $month = Carbon::now('Europe/Kyiv')->startOfMonth();
        $first = Shift::factory()->closed()->create(['date' => $month->toDateString()]);
        $second = Shift::factory()->closed()->create(['date' => $month->copy()->addDay()->toDateString()]);

        $this->reading($first, $konica, 10_000, $month->copy()->setTime(8, 0)->toDateTimeString());
        $this->reading($second, $konica, 11_000, $month->copy()->addDay()->setTime(8, 0)->toDateTimeString());
        $this->reading($first, $kyocera, 5_000, $month->copy()->setTime(8, 1)->toDateTimeString());
        $this->reading($second, $kyocera, 5_800, $month->copy()->addDay()->setTime(8, 1)->toDateTimeString());

        $this->bwClicks($first, 1500);

        $rows = collect($this->analytics())->keyBy('equipment_name');

        $this->assertSame(-500, $rows['Konica']['unaccounted']);
        $this->assertSame(-700, $rows['Kyocera']['unaccounted'], 'Both rows subtract the type\'s whole total from their own delta.');

        $totals = collect($this->typeTotals());
        $this->assertCount(1, $totals, 'One shared type, one total.');

        $bw = $totals->first();
        $this->assertSame(2, $bw['machines']);
        $this->assertSame(1800, $bw['physical_delta']);
        $this->assertSame(1500, $bw['software_clicks']);
        $this->assertSame(
            300,
            $bw['unaccounted'],
            'The type is +300 while its rows read −500 and −700 — a different number and the other sign.',
        );
    }

    /**
     * A type with one machine already has this number in its own row, and a
     * second copy of it would read as a second measurement.
     */
    public function test_a_type_with_one_machine_gets_no_total_of_its_own(): void
    {
        Equipment::factory()->create(['name' => 'Ricoh 1', 'type' => 'bw', 'has_counter' => true, 'is_active' => true]);
        Equipment::factory()->create(['name' => 'Ricoh 2', 'type' => 'bw', 'has_counter' => true, 'is_active' => true]);
        Equipment::factory()->create(['name' => 'Кольор', 'type' => 'color', 'has_counter' => true, 'is_active' => true]);

        $types = collect($this->typeTotals())->map(fn ($t) => $t['equipment_type']->value);

        $this->assertSame(['bw'], $types->all());
    }

    /**
     * A second machine of the type that has no counter changes nothing: it never
     * gets a morning reading and never appears on this report.
     */
    public function test_a_machine_without_a_counter_does_not_make_the_column_shared(): void
    {
        Equipment::factory()->create(['name' => 'Ricoh 1', 'type' => 'bw', 'has_counter' => true,  'is_active' => true]);
        Equipment::factory()->create(['name' => 'Ricoh 2', 'type' => 'bw', 'has_counter' => false, 'is_active' => true]);

        $rows = collect($this->analytics())->keyBy('equipment_name');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows['Ricoh 1']['clicks_are_this_machines']);
    }
}
