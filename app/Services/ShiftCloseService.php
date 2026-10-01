<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ShiftCloseService
 *
 * Handles closing shifts (manual + auto-close) and settlement.
 * Extracted from ShiftService for SRP compliance.
 */
class ShiftCloseService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Close a shift manually.
     * Simplified: no evening readings or cash reconciliation needed.
     * All data entry happens the next morning during shift open.
     *
     * @throws \RuntimeException
     */
    public function closeShift(Shift $shift, User $user): Shift
    {
        if (! $shift->isOpen()) {
            throw new \RuntimeException("Shift #{$shift->id} is not open.");
        }

        // Snapshot the calculated balance at close time.
        // For manual close: operator confirms by clicking "Close" → cash_actual = calculated.
        // This ensures next shift's cash_start carries over correctly.
        $cashCalculated = $shift->calculateExpectedBalance();

        $shift->update([
            'closed_by'       => $user->id,
            'status'          => ShiftStatus::Closed->value,
            'cash_calculated' => $cashCalculated,
            'cash_actual'     => $cashCalculated,
            'closed_at'       => now(),
        ]);

        $this->auditService->log(
            'shift_closed',
            $user,
            "Shift #{$shift->id} closed by {$user->name}",
            $shift->id,
            ['cash_calculated' => $cashCalculated],
        );

        return $shift->fresh();
    }

    /**
     * Auto-close a shift at 03:00 (called by AutoCloseShiftJob).
     * Does NOT require counter readings or cash reconciliation.
     * Sets settlement_required = true for morning executor.
     */
    public function autoCloseShift(Shift $shift): Shift
    {
        if (! $shift->isOpen()) {
            return $shift;
        }

        // Snapshot calculated balance for reporting (cash_actual stays NULL → triggers settlement)
        $cashCalculated = $shift->calculateExpectedBalance();

        $shift->update([
            'status'               => ShiftStatus::AutoClosed->value,
            'auto_closed'          => true,
            'settlement_required'  => true,
            'cash_calculated'      => $cashCalculated,
            'closed_at'            => now(),
        ]);

        AuditLog::record(
            'shift_auto_closed',
            $shift->opener,
            "Shift #{$shift->id} auto-closed by scheduler at 03:00",
            $shift->id,
            ['date' => $shift->date->toDateString(), 'cash_calculated' => $cashCalculated],
        );

        return $shift->fresh();
    }

    // ─── Public Helpers (used by ShiftOpenService for morning settlement) ───

    public function reconcileCash(
        Shift $shift,
        User $user,
        float $cashActual,
        ?string $discrepancyReason,
    ): void {
        $cashCalculated = $shift->calculateExpectedBalance();
        $discrepancy    = abs($cashActual - $cashCalculated);

        if ($discrepancy > 0.01 && empty($discrepancyReason)) {
            throw new \RuntimeException(
                "Cash discrepancy of {$discrepancy} UAH detected. Reason is required.",
            );
        }

        $shift->update([
            'cash_calculated'         => $cashCalculated,
            'cash_actual'             => $cashActual,
            'cash_discrepancy_reason' => $discrepancyReason,
        ]);

        if ($discrepancy > 0.01) {
            $this->auditService->log(
                'cash_discrepancy',
                $user,
                "Cash discrepancy: expected {$cashCalculated}, actual {$cashActual}",
                $shift->id,
                [
                    'expected'   => $cashCalculated,
                    'actual'     => $cashActual,
                    'difference' => $cashActual - $cashCalculated,
                    'reason'     => $discrepancyReason,
                ],
            );

            // Telegram alert for significant discrepancies (threshold from Settings)
            $alertThreshold = (float) \App\Models\Setting::getValue('cash_discrepancy_threshold', 50);
            if ($alertThreshold > 0 && $discrepancy > $alertThreshold) {
                $this->telegram->cashDiscrepancy(
                    $shift->id, $cashCalculated, $cashActual, $discrepancyReason,
                );
            }
        }
    }
}
