<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\CounterAnalyticsExport;
use App\Models\Equipment;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The counter export — the sheet an owner opens to check the report by hand.
 *
 * Its job is to explain the "Лічильники" page, so it has to list readings in the
 * order the page reasons about them. `created_at` is timestamp(0), and an
 * adjustment plus the reading that follows it can land in the same second;
 * lastKnownValue() and physicalDelta() both break that tie on `id`, and this
 * ordered on `created_at` alone.
 */
class CounterAnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    private const SECOND = '2026-07-15 09:00:00';

    private function reading(Shift $shift, Equipment $equipment, User $user, string $type, int $value): ShiftCounterReading
    {
        $reading = new ShiftCounterReading();
        $reading->shift_id      = $shift->id;
        $reading->equipment_id  = $equipment->id;
        $reading->user_id       = $user->id;
        $reading->reading_type  = $type;
        $reading->counter_value = $value;
        $reading->created_at    = Carbon::parse(self::SECOND);
        $reading->save();

        return $reading;
    }

    /**
     * This one reads the query, not the rows, and that is deliberate.
     *
     * With `ORDER BY created_at` alone and two readings in the same second, the
     * order PostgreSQL returns is simply unspecified — on a table this size it
     * hands back insertion order, so a row-level assertion passes just as
     * happily against the ordering that has no tiebreak. It would look like a
     * test and prove nothing. What is worth pinning is that the query asks for a
     * total order at all.
     */
    public function test_the_query_orders_by_more_than_the_shared_second(): void
    {
        $user      = User::factory()->create(['role' => 'executor']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'has_counter' => true]);
        $shift     = Shift::factory()->create(['date' => '2026-07-15']);

        // A reset recorded, then the true reading taken right after it.
        $this->reading($shift, $equipment, $user, 'adjustment', 0);
        $this->reading($shift, $equipment, $user, 'morning', 500);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        (new CounterAnalyticsExport(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        ))->collection();

        $ordered = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from "shift_counter_readings"'),
        ));

        $this->assertNotEmpty($ordered, 'The export never queried the readings table.');
        $this->assertStringContainsString(
            'order by "created_at" asc, "id" asc',
            $ordered[0],
            'The readings are ordered on a second they can share, with nothing to break the tie.',
        );
    }

    public function test_the_sheet_shows_the_reading_type_and_value(): void
    {
        $user      = User::factory()->create(['role' => 'executor']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'has_counter' => true, 'name' => 'Ricoh MP']);
        $shift     = Shift::factory()->create(['date' => '2026-07-15']);

        $reading = $this->reading($shift, $equipment, $user, 'adjustment', 0);

        $export = new CounterAnalyticsExport(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertSame(
            ['15.07.2026', 'Ricoh MP', 'Коригування', 0],
            $export->map($reading->fresh()),
        );
    }

    public function test_readings_outside_the_kyiv_day_range_are_left_out(): void
    {
        $user      = User::factory()->create(['role' => 'executor']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'has_counter' => true]);

        // Both closed: only one shift may be open at a time (unique_open_shift).
        $inside  = Shift::factory()->create(['date' => '2026-07-15', 'status' => 'closed']);
        $outside = Shift::factory()->create(['date' => '2026-08-02', 'status' => 'closed']);

        $kept = $this->reading($inside, $equipment, $user, 'morning', 100);
        $this->reading($outside, $equipment, $user, 'morning', 200);

        $rows = (new CounterAnalyticsExport(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        ))->collection();

        $this->assertSame([$kept->id], $rows->pluck('id')->all());
    }
}
