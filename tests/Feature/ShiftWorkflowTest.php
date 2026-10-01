<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShiftWorkflowTest — Feature tests for shift open/close workflow.
 *
 * Covers:
 * - Open shift with morning counter readings (only has_counter equipment)
 * - Open shift without readings when no equipment has counters
 * - Close shift (simple confirmation)
 * - Open shift with settlement of previous shift
 * - Cash discrepancy requires a reason
 */
class ShiftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Equipment $equipment;
    private ShiftService $shiftService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create(['role' => 'executor']);
        $this->equipment = Equipment::factory()->create([
            'type' => 'bw', 'is_active' => true, 'has_counter' => true,
        ]);
        $this->shiftService = app(ShiftService::class);
    }

    /**
     * Shift opens successfully when all equipment with counters has morning readings.
     */
    public function test_shift_opens_successfully_with_valid_counters(): void
    {
        $shift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 12345],
        ]);

        $this->assertNotNull($shift->id);
        $this->assertEquals('open', $shift->status->value);
        // Kyiv, not the app default: config('app.timezone') is UTC, and a shift
        // is dated `today('Europe/Kyiv')`. A bare today() disagrees with it for
        // the three hours after Kyiv midnight, so on a UTC runner this assertion
        // was red every night between 21:00 and 00:00.
        $this->assertEquals(today('Europe/Kyiv')->toDateString(), $shift->date->toDateString());
    }

    /**
     * Shift opens without readings when equipment has no counters.
     */
    public function test_shift_opens_without_readings_when_no_counter_equipment(): void
    {
        // Mark all equipment as having no counter
        $this->equipment->update(['has_counter' => false]);

        $shift = $this->shiftService->openShift($this->user, []);

        $this->assertNotNull($shift->id);
        $this->assertEquals('open', $shift->status->value);
    }

    /**
     * Cannot open a shift when one is already open today.
     */
    public function test_cannot_open_second_shift_on_same_day(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10000],
        ]);

        // Second attempt on same day must throw
        $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10001],
        ]);
    }

    /**
     * Shift close is a simple status change (no readings/cash required).
     */
    public function test_shift_closes_successfully(): void
    {
        $shift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10000],
        ]);

        $this->shiftService->closeShift($shift, $this->user);

        $shift->refresh();
        $this->assertEquals('closed', $shift->status->value);
        $this->assertNotNull($shift->closed_at);
    }

    /**
     * Opening a new shift settles the previous shift (cash reconciliation).
     */
    public function test_open_with_settlement_of_previous_shift(): void
    {
        // Open and close first shift
        $firstShift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10000],
        ]);
        $this->shiftService->closeShift($firstShift, $this->user);

        // Move date to next day and clear cash_actual to simulate unsettled auto-closed shift
        $firstShift->update([
            'date'        => today('Europe/Kyiv')->subDay(),
            'cash_actual' => null,
            'status'      => 'auto_closed',
            'auto_closed' => true,
        ]);

        // Open second shift WITH settlement of previous
        $secondShift = $this->shiftService->openShift(
            user:              $this->user,
            morningReadings:   [['equipment_id' => $this->equipment->id, 'counter_value' => 10500]],
            cashActual:        0.00,
        );

        $this->assertNotNull($secondShift->id);

        // Previous shift should now have cash_actual set
        $firstShift->refresh();
        $this->assertEquals(0.00, (float) $firstShift->cash_actual);
    }

    /**
     * Cash discrepancy during settlement requires a reason.
     */
    public function test_settlement_with_discrepancy_requires_reason(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reason|discrepancy/i');

        // Open and close first shift
        $firstShift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10000],
        ]);
        $this->shiftService->closeShift($firstShift, $this->user);

        // Simulate auto-closed unsettled shift (cash_actual = null triggers settlement)
        $firstShift->update([
            'date'        => today('Europe/Kyiv')->subDay(),
            'cash_actual' => null,
            'status'      => 'auto_closed',
            'auto_closed' => true,
        ]);

        // Try to open with cash mismatch and no reason
        $this->shiftService->openShift(
            user:              $this->user,
            morningReadings:   [['equipment_id' => $this->equipment->id, 'counter_value' => 10500]],
            cashActual:        50.00, // mismatch with calculated (0)
            discrepancyReason: null,  // missing → throw
        );
    }

    /**
     * Morning counter reading must be >= previous morning reading.
     */
    public function test_morning_reading_cannot_be_less_than_previous(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/менш/i');

        // First shift with morning reading of 10000
        $firstShift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10000],
        ]);
        $this->shiftService->closeShift($firstShift, $this->user);
        $firstShift->update(['date' => today('Europe/Kyiv')->subDay()]);

        // Try to open with morning reading LESS than previous morning
        $this->shiftService->openShift(
            user:              $this->user,
            morningReadings:   [['equipment_id' => $this->equipment->id, 'counter_value' => 5000]],
            cashActual:        0.0,
        );
    }

    /**
     * Close DOES set cash_calculated and cash_actual (auto-confirmed).
     */
    public function test_close_sets_cash_calculated_and_actual(): void
    {
        $shift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10000],
        ]);

        $this->shiftService->closeShift($shift, $this->user);

        $shift->refresh();
        $this->assertNotNull($shift->cash_actual);
        $this->assertNotNull($shift->cash_calculated);
        $this->assertEquals($shift->cash_calculated, $shift->cash_actual);
    }

    /**
     * Settlement with valid discrepancy reason succeeds.
     */
    public function test_settlement_with_discrepancy_and_reason_succeeds(): void
    {
        $firstShift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 10000],
        ]);
        $this->shiftService->closeShift($firstShift, $this->user);

        // Simulate auto-closed unsettled shift
        $firstShift->update([
            'date'        => today('Europe/Kyiv')->subDay(),
            'cash_actual' => null,
            'status'      => 'auto_closed',
            'auto_closed' => true,
        ]);

        $secondShift = $this->shiftService->openShift(
            user:              $this->user,
            morningReadings:   [['equipment_id' => $this->equipment->id, 'counter_value' => 10500]],
            cashActual:        100.00,
            discrepancyReason: 'Розмінна каса',
        );

        $this->assertNotNull($secondShift->id);

        $firstShift->refresh();
        $this->assertEquals(100.00, (float) $firstShift->cash_actual);
        $this->assertNotNull($firstShift->cash_discrepancy_reason);
    }

    /**
     * Mixed equipment: only has_counter=true requires readings.
     */
    public function test_mixed_equipment_only_counter_requires_readings(): void
    {
        // Create equipment WITHOUT counter
        Equipment::factory()->create([
            'type' => 'riso', 'is_active' => true, 'has_counter' => false,
        ]);

        // Only provide readings for the equipment WITH counter
        $shift = $this->shiftService->openShift($this->user, [
            ['equipment_id' => $this->equipment->id, 'counter_value' => 5000],
        ]);

        $this->assertNotNull($shift->id);
        $this->assertEquals('open', $shift->status->value);
    }
}

