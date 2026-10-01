<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCounterAdjustmentRequest;
use App\Models\Equipment;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;

/**
 * CounterAdjustmentController
 *
 * Admin-only endpoint for manual counter adjustments (TZ §3.4).
 * Creates an 'adjustment' reading type — preserves append-only integrity.
 */
class CounterAdjustmentController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Create a manual counter adjustment for equipment.
     */
    public function adjust(StoreCounterAdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $equipment = Equipment::findOrFail($data['equipment_id']);

        // Find the current open shift or latest closed shift. `id` decides
        // between shifts of the same day — the adjustment is filed against one
        // of them, and "the latest" has to mean the latest.
        $shift = Shift::current()->first()
            ?? Shift::whereIn('status', [ShiftStatus::Closed->value, ShiftStatus::AutoClosed->value])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();

        if (! $shift) {
            return back()->with('error', 'Немає доступної зміни для прив\'язки коригування.');
        }

        // Create adjustment reading (append-only — new record, no update)
        ShiftCounterReading::create([
            'shift_id'      => $shift->id,
            'equipment_id'  => $data['equipment_id'],
            'user_id'       => $request->user()->id,
            'reading_type'  => 'adjustment',
            'counter_value' => $data['counter_value'],
        ]);

        // Audit trail (TZ §3.4)
        $this->auditService->log(
            'counter_adjustment',
            $request->user(),
            "Counter adjustment for '{$equipment->name}': set to {$data['counter_value']}. Reason: {$data['reason']}",
            $shift->id,
            [
                'equipment_id'  => $data['equipment_id'],
                'counter_value' => $data['counter_value'],
                'reason'        => $data['reason'],
            ],
        );

        return back()->with('success', "Лічильник для '{$equipment->name}' скориговано до {$data['counter_value']}.");
    }
}
