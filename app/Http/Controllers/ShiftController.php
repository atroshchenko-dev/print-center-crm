<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Shift\CloseShiftRequest;
use App\Http\Requests\Shift\OpenShiftRequest;
use App\Models\Equipment;
use App\Services\ShiftOpenService;
use App\Services\TelegramService;
use App\Services\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shiftService,
        private readonly ShiftOpenService $openService,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Show the "Open Shift" form.
     * Includes settlement section if previous shift needs reconciliation.
     */
    public function openForm(): Response|RedirectResponse
    {
        // First-deploy guard: at least one active equipment must exist
        if (Equipment::where('is_active', true)->count() === 0) {
            return redirect()->route('admin.equipment.index')
                ->with('warning', 'Для відкриття зміни потрібен хоча б один активний апарат. Додайте його нижче.');
        }

        $lastShift      = $this->shiftService->getLastClosedShift();
        $previousUnsettled = $this->openService->getPreviousUnsettledShift();

        // Equipment with counters for morning readings
        $equipmentWithCounters = $this->getEquipmentWithLastReadings();

        // Previous shift settlement data
        $settlementData = null;
        if ($previousUnsettled) {
            $settlementData = [
                'id'              => $previousUnsettled->id,
                'date'            => $previousUnsettled->date->toDateString(),
                'cash_start'      => $previousUnsettled->cash_start,
                'cash_calculated' => $previousUnsettled->calculateExpectedBalance(),
                'auto_closed'     => $previousUnsettled->auto_closed,
                'ledger_history'  => $previousUnsettled->ledgerTransactions()->latest('created_at')->get(),
            ];
        }

        return Inertia::render('Shift/Open', [
            'equipment'        => $equipmentWithCounters,
            // From the service that will write it, not a second reading of the
            // shifts table: the two disagreed whenever the last shift was
            // closed but not yet counted, which is every morning after an
            // auto-close.
            'cash_start'       => $this->openService->carriedCashStart(),
            'last_shift'       => $lastShift ? [
                'date'        => $lastShift->date->toDateString(),
                'cash_actual' => $lastShift->cash_actual,
                'auto_closed' => $lastShift->auto_closed,
            ] : null,
            'previous_shift'   => $settlementData,
        ]);
    }

    /**
     * Open a new shift with morning counter readings.
     * Also settles previous shift if needed (cash reconciliation).
     */
    public function open(OpenShiftRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Morning counter readings
        $morningReadings = $data['readings'] ?? [];

        $cashActual = isset($data['cash_actual']) && $data['cash_actual'] !== ''
            ? (float) $data['cash_actual']
            : null;

        try {
            $shift = $this->shiftService->openShift(
                user:              $request->user(),
                morningReadings:   $morningReadings,
                cashActual:        $cashActual,
                discrepancyReason: $data['discrepancy_reason'] ?? null,
            );

            $this->telegram->shiftOpened($request->user()->name, $shift->id);

            return redirect()->route('dashboard')
                ->with('success', "Зміну #{$shift->id} відкрито. Гарного дня!");
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Show the "Close Shift" confirmation page.
     */
    public function closeForm(Request $request): Response
    {
        $shift = $this->shiftService->getCurrentShift();

        return Inertia::render('Shift/Close', [
            'shift'           => $shift,
            'cash_calculated' => $shift->calculateExpectedBalance(),
        ]);
    }

    /**
     * Close the current shift.
     */
    public function close(CloseShiftRequest $request): RedirectResponse
    {
        $shift = $this->shiftService->getCurrentShift();

        try {
            $closed = $this->shiftService->closeShift(
                shift: $shift,
                user:  $request->user(),
            );

            $this->telegram->shiftClosed($request->user()->name, $shift->id);

            // Used to redirect to /login without ending the session, so the
            // guest middleware bounced the operator straight back and the
            // message never showed. The session stays; the dashboard is the
            // landing page, and it forwards to the open-shift form itself.
            $cash = number_format((float) $closed->cash_calculated, 2, ',', ' ');

            return redirect()->route('dashboard')
                ->with('success', "Зміну #{$shift->id} закрито. Каса зведена: {$cash} ₴.");
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Get all active equipment WITH counters and their last morning reading value.
     * Only returns equipment where has_counter = true.
     * Pre-fills with last morning reading for one-click confirmation.
     */
    private function getEquipmentWithLastReadings(): \Illuminate\Support\Collection
    {
        $equipment = Equipment::where('is_active', true)
            ->where('has_counter', true)
            ->get();

        if ($equipment->isEmpty()) {
            return collect();
        }

        // Optimized: use PostgreSQL DISTINCT ON to get only the latest reading per equipment.
        //
        // Adjustments count, same as in ShiftOpenService::validateMorningReading()
        // — the two have to name the same number, or this pre-fills a value the
        // open then refuses. After a counter reset it did exactly that.
        $latestReadings = \Illuminate\Support\Facades\DB::table('shift_counter_readings')
            ->select(\Illuminate\Support\Facades\DB::raw(
                'DISTINCT ON (equipment_id) equipment_id, counter_value'
            ))
            ->whereIn('equipment_id', $equipment->pluck('id'))
            ->whereIn('reading_type', \App\Models\ShiftCounterReading::BASELINE_TYPES)
            ->orderBy('equipment_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('equipment_id');

        return $equipment->map(function ($eq) use ($latestReadings) {
            $lastReading = $latestReadings->get($eq->id);

            return [
                'id'              => $eq->id,
                'name'            => $eq->name,
                'type'            => $eq->type,
                'initial_counter' => $eq->initial_counter,
                'last_reading'    => $lastReading?->counter_value ?? $eq->initial_counter,
            ];
        });
    }
}
