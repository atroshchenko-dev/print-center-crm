<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditEventType;
use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\ParsesDateRange;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftCounterReading;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ReportController
 *
 * All reports are read-only, admin-only.
 * Data is always scoped to a selected date range (defaults to current month).
 *
 * TZ §5 — Reports:
 *   1. Internal verification report (internal orders)
 *   2. Commercial report (commercial orders)
 *   3. Cash flow report (ledger)
 *   4. Counter analytics (click counts per equipment)
 *   5. Audit log viewer
 */
class ReportController extends Controller
{
    use ParsesDateRange;

    /**
     * Internal verification report — internal orders by department/cost center.
     */
    public function internal(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $orders = Order::with(['items', 'user'])
            ->where('type', OrderType::Internal->value)
            ->operational()
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->whereBetween('created_at', [$from, $to])
            ->when($request->reconciled !== null, function ($q) use ($request) {
                $q->where('is_reconciled', filter_var($request->reconciled, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('created_at')
            ->get();

        $grouped = $orders->groupBy('cost_center')
            ->map(fn ($group) => [
                'orders'           => $group,
                'total_cost'       => $group->sum('total_cost'),
                'total_commercial' => $group->sum('total_commercial'),
                'bw_clicks'        => $group->flatMap->items->sum('bw_clicks'),
                'color_clicks'     => $group->flatMap->items->sum('color_clicks'),
            ]);

        return Inertia::render('Reports/Internal', [
            'data'    => $grouped,
            'filters' => ['from' => $request->from, 'to' => $request->to],
            'totals'  => [
                'orders'     => $orders->count(),
                'cost'       => $orders->sum('total_cost'),
                'commercial' => $orders->sum('total_commercial'),
            ],
        ]);
    }

    /**
     * Commercial report — commercial orders with payment breakdown.
     */
    public function commercial(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $orders = Order::with(['items', 'user'])
            ->where('type', OrderType::Commercial->value)
            ->operational()
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        return Inertia::render('Reports/Commercial', [
            'orders'  => $orders,
            'filters' => ['from' => $request->from, 'to' => $request->to],
            'totals'  => [
                'count'      => $orders->count(),
                'commercial' => $orders->sum('total_commercial'),
                'cost'       => $orders->sum('total_cost'),
                'profit'     => $orders->sum('total_commercial') - $orders->sum('total_cost'),
                'cash_count' => $orders->where('payment_method', PaymentMethod::Cash)->count(),
                'card_count' => $orders->where('payment_method', PaymentMethod::Card)->count(),
            ],
        ]);
    }

    /**
     * Cash flow report — ledger grouped by shift.
     */
    public function cashFlow(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        // `date` is a calendar day; the bounds are UTC instants of Kyiv
        // midnights. Read straight off, the start of the range named the day
        // before — so a July report opened with June's last shift and its
        // money landed in the July totals.
        $shifts = Shift::with(['ledgerTransactions.user', 'opener'])
            ->whereBetween('date', $this->dateRangeDays($from, $to))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Reports/CashFlow', [
            'shifts'  => $shifts,
            'filters' => ['from' => $request->from, 'to' => $request->to],
            'totals'  => [
                'cash_in' => $shifts->flatMap->ledgerTransactions->where('type', LedgerTransactionType::PaymentCash)->sum('amount'),
                'card_in' => $shifts->flatMap->ledgerTransactions->where('type', LedgerTransactionType::PaymentCard)->sum('amount'),
                'out'     => abs($shifts->flatMap->ledgerTransactions->where('type', LedgerTransactionType::Withdrawal)->sum('amount')),
            ],
        ]);
    }

    /**
     * Counter analytics — morning readings and click counts per equipment.
     * Physical delta = last morning reading − first morning reading in the period.
     */
    public function counters(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $shifts = Shift::with(['counterReadings.equipment'])
            ->selectRaw('shifts.*')
            ->selectSub(
                "SELECT COALESCE(SUM(oi.bw_clicks), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.shift_id = shifts.id AND o.deleted_at IS NULL AND o.status != 'cancelled'",
                'total_bw_clicks'
            )
            ->selectSub(
                "SELECT COALESCE(SUM(oi.color_clicks), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.shift_id = shifts.id AND o.deleted_at IS NULL AND o.status != 'cancelled'",
                'total_color_clicks'
            )
            ->selectSub(
                "SELECT COALESCE(SUM(oi.riso_clicks), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.shift_id = shifts.id AND o.deleted_at IS NULL AND o.status != 'cancelled'",
                'total_riso_clicks'
            )
            // Kyiv calendar days — see cashFlow(). One extra day at the start
            // also moved the "first morning reading" out of the period, and
            // the physical delta is measured from it.
            ->whereBetween('date', $this->dateRangeDays($from, $to))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        // TZ §5: Calculate delta (physical counters vs software clicks) per equipment
        // Physical delta = last morning reading - first morning reading in period
        //
        // Only machines that have a counter. One without never gets a morning
        // reading, so its delta can only be zero — and every software click of
        // its type then reads as unaccounted. Production has exactly one:
        // ProductionResetCommand marks the Riso has_counter = false.
        //
        // Plus any machine that gave a reading inside the period, retired or
        // not. This report is about a stretch of time that has already
        // happened, and it was built from today's fleet: deactivate a machine
        // this morning and last month's report loses its rows — while its
        // readings stay listed underneath, attached to no figure above. A
        // report about July must not change in August.
        $readSomethingInPeriod = $shifts
            ->flatMap(fn ($s) => $s->counterReadings)
            ->pluck('equipment_id')
            ->unique()
            ->all();

        $equipment = Equipment::withTrashed()
            ->where(function ($q) use ($readSomethingInPeriod) {
                $q->where(fn ($live) => $live->where('is_active', true)
                    ->where('has_counter', true)
                    ->whereNull('deleted_at'))
                    ->orWhereIn('id', $readSomethingInPeriod);
            })
            ->orderBy('id')
            ->get();

        // How many counter-bearing machines share each click column. The
        // NOTE below has always said this figure only means something at one
        // machine per type; nobody reading the report was told. With two, each
        // row subtracts the *type's whole* software total from its own physical
        // delta, so both "Нерозраховано" numbers are wrong and neither is
        // marked.
        //
        // **This is not a guard against a future second machine. Production has
        // two of each** — checked on the live system 2026-08-02: Konica bizhub
        // 283 and Kyocera ECOSYS M4125idn are both `bw` with counters, Develop
        // ineo+ 251i and ineo+ 220 are both `color` with counters, and all four
        // rows on the report carry the asterisk today. The line that used to
        // stand here said "Production has one of each today, so this is a guard
        // against the day a second machine is added, not a fix to today's
        // numbers." Every clause of that was wrong, and the report has been
        // published under it. **Every "Нерозраховано" figure on
        // that page belongs to a counter type, never to a machine.**
        //
        // Counted over the set above, retired machines included: a machine
        // replaced mid-period printed some of the clicks in that period, and
        // the guard counting only today's fleet would have told the survivor's
        // row that the whole column was its own — in the one case it exists for.
        $machinesPerColumn = $equipment
            ->groupBy(fn ($eq) => $eq->type->counterColumn())
            ->map(fn ($group) => $group->count());

        $counterAnalytics = $equipment->map(function ($eq) use ($shifts, $machinesPerColumn) {
            /** @var Collection<int, ShiftCounterReading> $readings */
            $readings = $shifts
                ->flatMap(fn ($s) => $s->counterReadings)
                ->where('equipment_id', $eq->id)
                ->whereIn('reading_type', ShiftCounterReading::BASELINE_TYPES)
                ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                ->values();

            $physicalDelta = $this->physicalDelta($readings);

            // Sum software clicks based on equipment counter_type
            // NOTE: Software clicks are aggregated globally per counter_type, not per-equipment,
            // because order_items don't track which specific equipment was used.
            // The "unaccounted" delta is meaningful only when one equipment per counter_type exists.
            // From the enum, not a fourth copy of the mapping kept here. The
            // copy had a `default` arm that quietly filed an unknown type
            // under black-and-white.
            $clickColumn = 'total_'.$eq->type->counterColumn();
            $softwareClicks = $shifts->sum($clickColumn) ?? 0;

            return [
                'equipment_id'    => $eq->id,
                'equipment_name'  => $eq->name,
                'equipment_type'  => $eq->type,
                'physical_delta'  => $physicalDelta,
                'software_clicks' => (int) $softwareClicks,
                'unaccounted'     => $physicalDelta - (int) $softwareClicks,
                // Whether this row's clicks could be attributed to this machine
                // at all. False means the column is shared and the two numbers
                // beside it were never about one machine.
                'clicks_are_this_machines' => ($machinesPerColumn[$eq->type->counterColumn()] ?? 1) === 1,
                // On the report because it gave readings in this period, not
                // because it is still in the room.
                'is_retired' => $eq->trashed() || ! $eq->is_active,
            ];
        });

        // The figure this page can stand behind when a type has more than one
        // counter-bearing machine — and until round 22 it showed none at all.
        //
        // Every row above subtracts the type's *whole* software total from its
        // *own* physical delta. That is not the machine's balance (an order
        // does not record the machine) and it is not the type's either: on
        // deltas of 1000 and 800 against 1500 clicks the rows read −500 and
        // −700, while the type is +300 — a different number, and the other
        // sign. R21-2 marked those rows as unattributable and R21-5 measured
        // why (two machines of each type on production); neither put the
        // correct number anywhere.
        //
        // It needs nothing the system does not already store. The counters are
        // per machine, the clicks are per type, the period is the same for
        // both, so summing the deltas of the type's machines lands on exactly
        // the question the department opens this page with: how much was
        // printed that no order accounts for.
        //
        // Owner's decision, 2026-08-02: **the order item will not record the
        // machine.** Narrowing a discrepancy from two machines to one is a
        // minute of looking; the field would cost the operator a choice on
        // every order forever. So the type is the finest grain this report
        // will ever have, and it is stated here rather than left implied.
        //
        // Only shared types get a row: where a type has one machine, its own
        // row is already this number, and repeating it would read as a second
        // measurement.
        $counterTypeTotals = $counterAnalytics
            ->reject(fn ($row) => $row['clicks_are_this_machines'])
            ->groupBy(fn ($row) => $row['equipment_type']->counterColumn())
            ->map(function (Collection $rows) use ($shifts) {
                $physicalDelta = (int) $rows->sum('physical_delta');
                $softwareClicks = (int) ($shifts->sum('total_'.$rows->first()['equipment_type']->counterColumn()) ?? 0);

                return [
                    'equipment_type'  => $rows->first()['equipment_type'],
                    'machines'        => $rows->count(),
                    'physical_delta'  => $physicalDelta,
                    'software_clicks' => $softwareClicks,
                    'unaccounted'     => $physicalDelta - $softwareClicks,
                ];
            })
            ->values();

        return Inertia::render('Reports/Counters', [
            'shifts'            => $shifts,
            'counterAnalytics'  => $counterAnalytics,
            'counterTypeTotals' => $counterTypeTotals,
            'filters'           => ['from' => $request->from, 'to' => $request->to],
        ]);
    }

    /**
     * Pages the machine actually printed over the period.
     *
     * Not "last reading minus first": that assumes the counter only ever
     * climbs, and a replaced board starts again from zero. An adjustment
     * (TZ §3.4) records exactly that — the number changed without anything
     * being printed — so the step *into* one counts as nothing, and measuring
     * resumes from it. Across a reset from 100 000 to zero, last-minus-first
     * reported about minus a hundred thousand pages.
     *
     * Until round 7 this could not happen: a reset left the shift impossible
     * to open, so no reading ever followed one. Wiring the adjustment up
     * is what made this arithmetic reachable.
     *
     * @param  Collection<int, ShiftCounterReading>  $readings  chronological
     */
    private function physicalDelta(Collection $readings): int
    {
        $total = 0;
        $previous = null;

        foreach ($readings as $reading) {
            if ($previous !== null && $reading->reading_type !== 'adjustment') {
                // Clamped: a counter cannot run backwards on its own, and a
                // negative step here would mean a reset nobody recorded.
                $total += max(0, (int) $reading->counter_value - (int) $previous->counter_value);
            }

            $previous = $reading;
        }

        return $total;
    }

    /**
     * Audit log viewer — all audit events, paginated.
     */
    public function audit(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        // Filters come from the query string: a mistyped user_id went into
        // the bigint column raw — 22P02, a 500 and a Telegram alert for a
        // typo in the URL.
        $eventType = $request->input('event_type');
        $userId = $request->input('user_id');
        $userId = is_scalar($userId) && ctype_digit((string) $userId) ? (int) $userId : null;

        $logs = AuditLog::with('user')
            ->whereBetween('created_at', [$from, $to])
            ->when(is_string($eventType) && $eventType !== '', fn ($q) => $q->where('event_type', $eventType))
            ->when($userId, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Reports/Audit', [
            'logs'    => $logs,
            'filters' => $request->only('from', 'to', 'event_type', 'user_id'),
            // Deactivated people too, and marked as such. The journal keeps
            // their entries for good; a filter that cannot name them turns
            // «що робив цей користувач» into a question the page refuses to
            // ask — exactly when it is being asked about someone who has left.
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            // The page used to carry its own list of twelve event types, three
            // rounds out of date and with one entry (`counter_adjusted`) that
            // nothing writes — see App\Enums\AuditEventType.
            'eventTypes' => AuditEventType::options(),
        ]);
    }
}
