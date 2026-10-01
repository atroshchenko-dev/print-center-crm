<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CounterAdjustmentTest — admin counter adjustment endpoint.
 *
 * TZ §3.4: admin adjusts counters after service, creates audit trail.
 */
class CounterAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_adjustment_reading(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        Shift::factory()->today()->create(['opened_by' => $admin->id]);

        $response = $this->actingAs($admin)
            ->post(route('admin.equipment.adjust'), [
                'equipment_id'  => $equipment->id,
                'counter_value' => 99999,
                'reason'        => 'After service maintenance',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('shift_counter_readings', [
            'equipment_id'  => $equipment->id,
            'reading_type'  => 'adjustment',
            'counter_value' => 99999,
            'user_id'       => $admin->id,
        ]);
    }

    public function test_adjustment_creates_audit_log(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        Shift::factory()->today()->create(['opened_by' => $admin->id]);

        $this->actingAs($admin)->post(route('admin.equipment.adjust'), [
            'equipment_id'  => $equipment->id,
            'counter_value' => 50000,
            'reason'        => 'Reset after repair',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'counter_adjustment',
            'user_id'    => $admin->id,
        ]);
    }

    /**
     * An adjustment exists for one situation: the physical counter no longer
     * reads what it used to. A replaced board, a service reset. TZ §3.4.
     *
     * The record was written and audited and then read by nobody. The morning
     * validation compares against the last *morning* reading and hard-refuses
     * anything lower, so after a reset from 100 000 to zero the next shift
     * could not be opened at all: the true reading of 500 was rejected as
     * "менше за попередній (100000)", and the only way past it was to type a
     * number that was not true.
     *
     * The three tests above assert that a row appears and a log line appears.
     * Neither asks what the row does — the same hole R3-6 was about.
     */
    public function test_an_adjustment_becomes_the_floor_for_the_next_morning(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create([
            'type' => 'bw', 'is_active' => true, 'has_counter' => true, 'initial_counter' => 0,
        ]);

        $shiftService = app(\App\Services\ShiftService::class);

        $first = $shiftService->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 100_000],
        ]);
        $shiftService->closeShift($first, $admin);

        // The board is replaced; the counter now reads zero.
        $this->actingAs($admin)->post(route('admin.equipment.adjust'), [
            'equipment_id'  => $equipment->id,
            'counter_value' => 0,
            'reason'        => 'Заміна плати, лічильник обнулився',
        ])->assertSessionHas('success');

        $second = $shiftService->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 500],
        ]);

        $this->assertNotNull($second, 'A shift could not be opened on the counter the machine actually shows.');
        $this->assertSame(500, (int) $second->counterReadings()->where('reading_type', 'morning')->value('counter_value'));
    }

    /**
     * The floor still holds — an adjustment redefines the baseline, it does not
     * remove it.
     */
    public function test_a_reading_below_the_adjustment_is_still_refused(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create([
            'type' => 'bw', 'is_active' => true, 'has_counter' => true, 'initial_counter' => 0,
        ]);

        $shiftService = app(\App\Services\ShiftService::class);
        $first = $shiftService->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 100],
        ]);
        $shiftService->closeShift($first, $admin);

        $this->actingAs($admin)->post(route('admin.equipment.adjust'), [
            'equipment_id'  => $equipment->id,
            'counter_value' => 5_000,
            'reason'        => 'Показник зчитано з сервісного меню',
        ]);

        $this->expectException(\RuntimeException::class);

        $shiftService->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 4_000],
        ]);
    }

    /**
     * The open form pre-fills the field with "the last reading" so the operator
     * can confirm in one click. It has to be the same last reading the
     * validation measures against, or the form offers a value it then refuses.
     */
    public function test_the_open_form_offers_the_adjusted_value(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create([
            'type' => 'bw', 'is_active' => true, 'has_counter' => true, 'initial_counter' => 0,
        ]);

        $shiftService = app(\App\Services\ShiftService::class);
        $first = $shiftService->openShift($admin, [
            ['equipment_id' => $equipment->id, 'counter_value' => 100_000],
        ]);
        $shiftService->closeShift($first, $admin);

        $this->actingAs($admin)->post(route('admin.equipment.adjust'), [
            'equipment_id'  => $equipment->id,
            'counter_value' => 0,
            'reason'        => 'Заміна плати',
        ]);

        $offered = collect($this->actingAs($admin)
            ->get(route('shifts.open.form'))
            ->viewData('page')['props']['equipment'])
            ->firstWhere('id', $equipment->id);

        $this->assertSame(
            0,
            (int) $offered['last_reading'],
            'The form offered a value the validation would reject.',
        );
    }

    public function test_adjustment_requires_reason(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        Shift::factory()->today()->create(['opened_by' => $admin->id]);

        $response = $this->actingAs($admin)->post(route('admin.equipment.adjust'), [
            'equipment_id'  => $equipment->id,
            'counter_value' => 50000,
            // Missing reason
        ]);

        $response->assertSessionHasErrors('reason');
    }
}
