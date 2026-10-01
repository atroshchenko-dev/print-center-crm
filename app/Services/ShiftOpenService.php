<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Models\Equipment;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ShiftOpenService
 *
 * Handles opening a new shift with morning counter validation.
 * Also performs cash settlement of the previous shift.
 */
class ShiftOpenService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ShiftCloseService $closeService,
    ) {}

    /**
     * Open a new shift.
     *
     * Flow:
     *   1. If previous shift needs settlement — reconcile cash
     *   2. Validate morning counter readings >= previous morning readings (TZ §4.1)
     *   3. Create new shift with morning readings
     *
     * @param  User   $user
     * @param  array  $morningReadings     [{equipment_id, counter_value}, ...]
     * @param  float|null  $cashActual     Actual cash from previous shift
     * @param  string|null $discrepancyReason
     * @throws \RuntimeException
     */
    public function openShift(
        User $user,
        array $morningReadings,
        ?float $cashActual = null,
        ?string $discrepancyReason = null,
    ): Shift {
        // Check for an existing open shift — any date, not just today's. Scoped
        // to today, this guard was blind between midnight and the 03:00
        // auto-close and let a second shift open on top of a running one.
        if (Shift::current()->exists()) {
            throw new \RuntimeException('A shift is already open.');
        }

        // Only require morning readings if there is equipment with counters
        $hasCounterEquipment = Equipment::where('is_active', true)->where('has_counter', true)->exists();
        if ($hasCounterEquipment && empty($morningReadings)) {
            throw new \RuntimeException('Morning counter readings are required for all active equipment with counters.');
        }

        return DB::transaction(function () use ($user, $morningReadings, $cashActual, $discrepancyReason) {
            // ─── Step 1: Settle previous shift (cash only) ──────
            $previousShift = $this->getPreviousUnsettledShift();
            if ($previousShift) {
                $this->settlePreviousShift($previousShift, $user, $cashActual, $discrepancyReason);
            }

            // ─── Step 2: Carry cash from last settled shift ────
            // After step 1, that may be the shift just settled.
            $cashStart = $this->carriedCashStart();

            // ─── Step 3: Create new shift ──────────────────────
            $shift = Shift::create([
                'date'       => today('Europe/Kyiv'),
                'opened_by'  => $user->id,
                'status'     => ShiftStatus::Open->value,
                'cash_start' => $cashStart,
                'opened_at'  => now(),
            ]);

            // ─── Step 4: Validate and save morning counter readings
            foreach ($morningReadings as $reading) {
                $this->validateMorningReading(
                    $reading['equipment_id'],
                    $reading['counter_value'],
                );

                ShiftCounterReading::create([
                    'shift_id'      => $shift->id,
                    'equipment_id'  => $reading['equipment_id'],
                    'user_id'       => $user->id,
                    'reading_type'  => 'morning',
                    'counter_value' => $reading['counter_value'],
                    'created_at'    => now(),
                ]);
            }

            $this->auditService->log(
                'shift_opened',
                $user,
                "Shift #{$shift->id} opened for {$shift->date->toDateString()}",
                $shift->id,
                ['cash_start' => $cashStart],
            );

            return $shift;
        });
    }

    /**
     * The till a new shift starts with: the count of the last settled shift.
     *
     * One definition, because the open form shows this number and openShift()
     * writes it. The form used to derive it from the newest closed shift
     * whether or not it had been counted — so on the ordinary morning after an
     * auto-close it read "Стартовий залишок каси: 0.00 грн" over a till with
     * money in it, and the shift then opened on the real figure.
     *
     * Ordered by `id` after `date`: two shifts can share a date, and the last
     * one settled is the one that has the cash.
     */
    public function carriedCashStart(): float
    {
        $lastSettled = Shift::whereIn('status', [ShiftStatus::Closed->value, ShiftStatus::AutoClosed->value])
            ->whereNotNull('cash_actual')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        return (float) ($lastSettled->cash_actual ?? 0.0);
    }

    /**
     * Get the previous closed shift that still needs settlement (no cash_actual set).
     *
     * Oldest first: opening a shift settles exactly one, so newest-first left
     * any older unsettled shift stranded with no way back to it. `id` breaks
     * ties, because two shifts of the same day carry the same `date`.
     */
    public function getPreviousUnsettledShift(): ?Shift
    {
        return Shift::whereIn('status', [ShiftStatus::Closed->value, ShiftStatus::AutoClosed->value])
            ->whereNull('cash_actual')
            ->orderBy('date')
            ->orderBy('id')
            ->first();
    }

    /**
     * Settle the previous shift: reconcile cash.
     * Counters are morning-only — no evening readings needed.
     */
    private function settlePreviousShift(
        Shift $shift,
        User $user,
        ?float $cashActual,
        ?string $discrepancyReason,
    ): void {
        if ($cashActual === null) {
            // Fallback: use calculated balance as actual
            $cashActual = $shift->calculateExpectedBalance();
        }

        $this->closeService->reconcileCash($shift, $user, $cashActual, $discrepancyReason);

        // Mark settlement done if it was required (auto-closed)
        if ($shift->settlement_required) {
            $shift->update(['settlement_done' => true]);
        }

        $this->auditService->log(
            'settlement_completed',
            $user,
            "Settlement completed for shift #{$shift->id} ({$shift->date->toDateString()})",
            $shift->id,
            ['cash_actual' => $cashActual],
        );
    }

    /**
     * Validate morning counter value is not less than the last known one.
     * TZ §4.1: hard validation — counters must be monotonically increasing.
     */
    private function validateMorningReading(int $equipmentId, int $morningValue): void
    {
        // withTrashed(), and an exception rather than a return.
        //
        // The rule above calls itself hard validation, and it had a door: the
        // FormRequest checks `exists:equipment,id`, which queries the raw table
        // and ignores the soft-delete scope, while find() applies it. Deactivate
        // a machine — an ordinary admin action — while an operator holds the
        // shift-open form, and their reading passed validation, skipped the
        // monotonicity check entirely and was written anyway. Counters are money:
        // physical_delta and "unaccounted" are built from exactly these rows
        //.
        $equipment = Equipment::withTrashed()->find($equipmentId);

        if (! $equipment) {
            throw new \RuntimeException("Апарат #{$equipmentId} не знайдено.");
        }

        $expectedMinimum = ShiftCounterReading::lastKnownValue($equipmentId)
            ?? $equipment->initial_counter;

        if ($morningValue < $expectedMinimum) {
            throw new \RuntimeException(
                "Ранковий показник для '{$equipment->name}' ({$morningValue}) " .
                "не може бути меншим за попередній ({$expectedMinimum}).",
            );
        }
    }
}
