<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ShiftStatus;
use App\Models\Equipment;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use App\Services\ShiftOpenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShiftOpenServiceTest — verifies shift opening pipeline.
 * Covers: shift creation, morning counter validation, cash carry-over, settlement.
 */
class ShiftOpenServiceTest extends TestCase
{
    use RefreshDatabase;

    private ShiftOpenService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftOpenService::class);
        $this->user    = User::factory()->create(['role' => 'admin']);
    }

    public function test_opens_shift_for_today(): void
    {
        $equipment = Equipment::factory()->create(['initial_counter' => 1000]);

        $shift = $this->service->openShift($this->user, [
            ['equipment_id' => $equipment->id, 'counter_value' => 1000],
        ]);

        $this->assertNotNull($shift->id);
        $this->assertEquals(today('Europe/Kyiv')->toDateString(), $shift->date->toDateString());
        $this->assertEquals(ShiftStatus::Open, $shift->status);
        $this->assertEquals($this->user->id, $shift->opened_by);
    }

    public function test_saves_morning_counter_readings(): void
    {
        $eq1 = Equipment::factory()->bw()->create(['initial_counter' => 500]);
        $eq2 = Equipment::factory()->color()->create(['initial_counter' => 200]);

        $shift = $this->service->openShift($this->user, [
            ['equipment_id' => $eq1->id, 'counter_value' => 500],
            ['equipment_id' => $eq2->id, 'counter_value' => 200],
        ]);

        $readings = ShiftCounterReading::where('shift_id', $shift->id)->get();
        $this->assertCount(2, $readings);
        $this->assertTrue($readings->every(fn ($r) => $r->reading_type === 'morning'));
    }

    public function test_carries_cash_from_previous_settled_shift(): void
    {
        // Create a closed shift with cash_actual = 250.00
        Shift::factory()->closed()->create([
            'date'        => today('Europe/Kyiv')->subDay(),
            'cash_actual' => 250.00,
        ]);

        $equipment = Equipment::factory()->create(['initial_counter' => 0]);

        $shift = $this->service->openShift($this->user, [
            ['equipment_id' => $equipment->id, 'counter_value' => 0],
        ]);

        $this->assertEquals(250.00, (float) $shift->cash_start);
    }

    public function test_throws_if_shift_already_open(): void
    {
        // Create an already open shift for today
        Shift::factory()->create([
            'date'   => today('Europe/Kyiv'),
            'status' => ShiftStatus::Open,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already open');

        $this->service->openShift($this->user, []);
    }

    public function test_throws_if_morning_readings_missing_and_counter_equipment_exists(): void
    {
        Equipment::factory()->create(['has_counter' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Morning counter readings are required');

        $this->service->openShift($this->user, []);
    }

    public function test_throws_if_morning_value_less_than_previous_morning(): void
    {
        $equipment = Equipment::factory()->create(['initial_counter' => 1000]);

        // Create a previous shift with a morning reading of 1500
        $prevShift = Shift::factory()->closed()->create([
            'date'        => today('Europe/Kyiv')->subDay(),
            'cash_actual' => 0,
        ]);

        ShiftCounterReading::create([
            'shift_id'      => $prevShift->id,
            'equipment_id'  => $equipment->id,
            'user_id'       => $this->user->id,
            'reading_type'  => 'morning',
            'counter_value' => 1500,
            'created_at'    => now()->subDay(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('не може бути меншим');

        $this->service->openShift($this->user, [
            ['equipment_id' => $equipment->id, 'counter_value' => 1400], // < 1500
        ]);
    }

    public function test_settles_previous_auto_closed_shift(): void
    {
        $equipment = Equipment::factory()->create(['initial_counter' => 0]);

        // Create a previous auto-closed shift without cash_actual (needs settlement)
        $prevShift = Shift::factory()->create([
            'date'                => today('Europe/Kyiv')->subDay(),
            'status'              => ShiftStatus::AutoClosed,
            'auto_closed'         => true,
            'settlement_required' => true,
            'settlement_done'     => false,
            'cash_actual'         => null,
            'closed_at'           => now()->subDay(),
        ]);

        $shift = $this->service->openShift(
            $this->user,
            [['equipment_id' => $equipment->id, 'counter_value' => 0]],
            100.0, // cashActual
            'Залишок готівки від попередньої зміни', // discrepancyReason (required when cash differs from expected)
        );

        $prevShift->refresh();
        $this->assertTrue($prevShift->settlement_done);
        $this->assertEquals(100.00, (float) $prevShift->cash_actual);
    }

    public function test_defaults_cash_to_calculated_when_no_actual_provided(): void
    {
        $equipment = Equipment::factory()->create(['initial_counter' => 0]);

        // Create auto-closed shift without cash — should use calculateExpectedBalance()
        $prevShift = Shift::factory()->create([
            'date'                => today('Europe/Kyiv')->subDay(),
            'status'              => ShiftStatus::AutoClosed,
            'auto_closed'         => true,
            'settlement_required' => true,
            'settlement_done'     => false,
            'cash_start'          => 50.00,
            'cash_actual'         => null,
            'closed_at'           => now()->subDay(),
        ]);

        $shift = $this->service->openShift(
            $this->user,
            [['equipment_id' => $equipment->id, 'counter_value' => 0]],
            null, // cashActual = null → fallback to calculated
        );

        $prevShift->refresh();
        $this->assertNotNull($prevShift->cash_actual);
    }
}
