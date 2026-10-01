<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;

/**
 * ShiftService — Thin facade for shift lifecycle.
 *
 * Query methods used by OrderController, LedgerController, ShiftController.
 * Mutation methods delegate to ShiftOpenService and ShiftCloseService.
 */
class ShiftService
{
    public function __construct(
        private readonly ShiftOpenService  $openService,
        private readonly ShiftCloseService $closeService,
    ) {}

    // ─── Query Methods (used by multiple controllers) ────

    /**
     * Get the shift that is currently open.
     *
     * @see Shift::scopeCurrent() for why this is not keyed on today's date.
     */
    public function getCurrentShift(): ?Shift
    {
        return Shift::current()->first();
    }

    /**
     * Get the last closed shift (for cash carry-over).
     *
     * Ordered by `id` after `date`: several shifts can share a calendar date,
     * so the date alone does not say which one closed last.
     */
    public function getLastClosedShift(): ?Shift
    {
        return Shift::whereIn('status', [ShiftStatus::Closed->value, ShiftStatus::AutoClosed->value])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();
    }

    // ─── Delegation to Specialized Services ──────────────

    /**
     * @see ShiftOpenService::openShift()
     */
    public function openShift(
        User $user,
        array $morningReadings,
        ?float $cashActual = null,
        ?string $discrepancyReason = null,
    ): Shift {
        return $this->openService->openShift($user, $morningReadings, $cashActual, $discrepancyReason);
    }

    /**
     * @see ShiftCloseService::closeShift()
     */
    public function closeShift(Shift $shift, User $user): Shift
    {
        return $this->closeService->closeShift($shift, $user);
    }

    /**
     * @see ShiftCloseService::autoCloseShift()
     */
    public function autoCloseShift(Shift $shift): Shift
    {
        return $this->closeService->autoCloseShift($shift);
    }

}
