<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * AutoCloseShiftJob
 *
 * Automatically closes any open shift at 03:00 Europe/Kyiv.
 * Scheduled in AppServiceProvider (or Kernel).
 *
 * After auto-close, executor must perform morning settlement:
 * - Enter morning counter readings
 * - Confirm actual cash balance
 *
 * TZ §4.4: Auto-close set settlement_required = true.
 */
class AutoCloseShiftJob implements ShouldQueue
{
    use Queueable;

    public function handle(ShiftService $shiftService): void
    {
        $openShifts = Shift::where('status', ShiftStatus::Open->value)->get();

        foreach ($openShifts as $shift) {
            $shiftService->autoCloseShift($shift);
        }
    }
}
