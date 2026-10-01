<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exports\BackdatedOrdersExport;
use App\Http\Controllers\Concerns\ParsesDateRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestroyBackdatedOrderRequest;
use App\Http\Requests\Admin\ReconcileBackdatedRequest;
use App\Http\Requests\Admin\StoreBackdatedOrderRequest;
use App\Http\Requests\Admin\UpdateBackdatedOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LimitService;
use App\Services\OrderItemBuilder;
use App\Services\OrderNumberService;
use App\Services\ReferenceDataService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * BackdatedOrderController
 *
 * Admin-only module for entering historical internal orders from past months.
 * No impact on cash, ledger, or inventory — purely for reporting/audit records.
 *
 * Features:
 *  - Create retro-orders for past dates
 *  - List & filter retro-orders
 *  - Reconcile retro-orders against paper requests
 *  - Export to XLSX for accounting
 *
 * **Which of the three order rules apply here** — owner's decision, 2026-08-01.
 * The two quotas do not: retro records work from a period whose quota has
 * already been spent and reset, so counting it again would be counting it twice,
 * and `limit_exceeded` stays the admin's own checkbox. The **category** rule
 * does: which services a signatory may order from is a statement about the
 * signatory, not about the month, and a month entered here is the accounting
 * record somebody will read later. It warns and records; it refuses nothing.
 *
 * Until round 18 the distinction was not a decision at all — all three rules
 * live in one method, `LimitService::recordForOrder()`, and retro simply never
 * called it.
 */
class BackdatedOrderController extends Controller
{
    use ParsesDateRange;

    public function __construct(
        private readonly ReferenceDataService $refData,
        private readonly OrderNumberService $orderNumberService,
        private readonly OrderItemBuilder $itemBuilder,
        private readonly AuditService $auditService,
        private readonly LimitService $limitService,
    ) {}

    // ─── Index (List + Reconciliation) ──────────────────

    /**
     * List all backdated orders with filters and reconciliation controls.
     */
    public function index(Request $request): Response
    {
        $orders = Order::with(['items', 'user', 'reconciler'])
            ->where('type', OrderType::Internal->value)
            ->where('is_backdated', true)
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->when($request->from || $request->to, function ($q) use ($request) {
                [$from, $to] = $this->dateRange($request);
                $q->whereBetween('created_at', [$from, $to]);
            })
            ->when($request->cost_center, fn ($q, $cc) => $q->where('cost_center', $cc))
            ->when($request->authorized_person, fn ($q, $ap) => $q->where('authorized_person', $ap))
            ->when($request->reconciled !== null && $request->reconciled !== '', function ($q) use ($request) {
                $q->where('is_reconciled', filter_var($request->reconciled, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->request_received !== null && $request->request_received !== '', function ($q) use ($request) {
                $q->where('request_received', filter_var($request->request_received, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy(
                OrderItem::select('service_name')
                    ->whereColumn('order_id', 'orders.id')
                    ->orderBy('id')
                    ->limit(1),
            )
            ->orderBy('created_at')
            ->paginate(50)
            ->withQueryString();

        $makeSummaryQuery = fn () => Order::where('type', OrderType::Internal->value)
            ->where('is_backdated', true)
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->when($request->from || $request->to, function ($q) use ($request) {
                [$from, $to] = $this->dateRange($request);
                $q->whereBetween('created_at', [$from, $to]);
            });

        $summary = [
            'total'      => $makeSummaryQuery()->count(),
            'reconciled' => $makeSummaryQuery()->where('is_reconciled', true)->count(),
            'total_cost' => round((float) $makeSummaryQuery()->sum('total_cost'), 2),
        ];

        return Inertia::render('Admin/BackdatedOrders/Index', [
            'orders'  => $orders,
            'filters' => $request->only('from', 'to', 'cost_center', 'authorized_person', 'reconciled', 'request_received'),
            'summary' => $summary,
        ]);
    }

    // ─── Create ─────────────────────────────────────────

    public function create(): Response
    {
        $services = $this->refData->services();
        $categories = $this->refData->categories();
        $departments = $this->refData->departments();
        $signatories = $this->refData->signatories();

        $inventoryStock = $this->refData->inventoryStock();

        $risoTiers = $this->refData->risoTiers();
        $risoPapers = $this->refData->risoPapers();
        $clickCosts = $this->refData->clickCosts();

        return Inertia::render('Admin/BackdatedOrders/Create', [
            'services'               => $services,
            'categories'             => $categories,
            'departments'            => $departments,
            'signatories'            => $signatories,
            'inventory_stock'        => $inventoryStock,
            'riso_tiers'             => $risoTiers,
            'riso_papers'            => $risoPapers,
            'riso_paper_cost'        => $this->refData->risoPaperCost(),
            'brochure_papers'        => $risoPapers,
            'brochure_click_costs'   => $clickCosts,
            'diploma_click_costs'    => $clickCosts,
            'signatory_cost_centers' => $this->refData->signatoryCostCenters(),
        ]);
    }

    // ─── Store ──────────────────────────────────────────

    public function store(StoreBackdatedOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $orderDate = Carbon::parse($data['order_date'])->setTimezone('Europe/Kyiv');

        return DB::transaction(function () use ($data, $orderDate, $request) {
            $numberData = $this->orderNumberService->generate(
                OrderType::Internal,
                (int) $orderDate->format('y'),
                (int) $orderDate->format('n'),
            );

            // `created_at` is not in $fillable by design, so the retro date goes
            // in through forceFill — but **before** the insert, not after it.
            // It used to be a second `saveQuietly()` afterwards, which left the
            // row carrying `now()` for the duration of the `created` event, and
            // `OrderObserver` reads `created_at` to date the signatory ↔ cost
            // centre pair. A 2025 retro order therefore stamped the pair with
            // today, while the backfill reading the same orders computes
            // MAX(created_at) and gets 2025 — two answers to one question.
            // Eloquent leaves a dirty `created_at` alone (Model::updateTimestamps),
            // so one save does both.
            $order = (new Order([
                ...$numberData,
                'type'                => OrderType::Internal->value,
                'status'              => OrderStatus::CompletedIssued->value,
                'user_id'             => $request->user()->id,
                'shift_id'            => null,
                'authorized_person'   => $data['authorized_person'],
                'cost_center'         => $data['cost_center'] ?? null,
                'limit_exceeded'      => $data['limit_exceeded'] ?? false,
                'is_backdated'        => true,
                'request_received'    => true,
                'request_received_at' => $orderDate,
                'request_received_by' => $request->user()->id,
                'total_commercial'    => 0,
                'total_cost'          => 0,
            ]))->forceFill(['created_at' => $orderDate]);

            $order->save();

            // The cost centre reaches the department reference through
            // `OrderObserver`, which the save above fires.

            // Build order items via shared builder (pricing only, no inventory deduction)
            [$totalCommercial, $totalCost] = $this->itemBuilder->buildItems($order, $data['items']);

            $order->update([
                'total_commercial' => 0,
                'total_cost'       => $totalCost,
            ]);

            $outside = $this->recordCategoriesOutsideGroup($order, $request->user());

            $redirect = redirect()->route('admin.backdated-orders.create')
                ->with('success', "Ретро-замовлення {$order->order_number} створено успішно");

            return $outside === null
                ? $redirect
                : $redirect->with('warning', $outside);
        });
    }

    // ─── Edit ────────────────────────────────────────────

    /**
     * Show edit form for a backdated order.
     * Loads the same constructor UI as Create, pre-populated with existing order data.
     */
    public function edit(Order $order): Response|RedirectResponse
    {
        if (! $order->isBackdated()) {
            return back()->with('error', 'Ця дія доступна лише для ретро-замовлень.');
        }

        $services = $this->refData->services();
        $categories = $this->refData->categories();
        $departments = $this->refData->departments();
        $signatories = $this->refData->signatories();

        $inventoryStock = $this->refData->inventoryStock();

        $risoTiers = $this->refData->risoTiers();
        $risoPapers = $this->refData->risoPapers();
        $clickCosts = $this->refData->clickCosts();

        $order->load('items');

        return Inertia::render('Admin/BackdatedOrders/Edit', [
            'order'                  => $order,
            'services'               => $services,
            'categories'             => $categories,
            'departments'            => $departments,
            'signatories'            => $signatories,
            'inventory_stock'        => $inventoryStock,
            'riso_tiers'             => $risoTiers,
            'riso_papers'            => $risoPapers,
            'riso_paper_cost'        => $this->refData->risoPaperCost(),
            'brochure_papers'        => $risoPapers,
            'brochure_click_costs'   => $clickCosts,
            'diploma_click_costs'    => $clickCosts,
            'signatory_cost_centers' => $this->refData->signatoryCostCenters(),
        ]);
    }

    // ─── Update ──────────────────────────────────────────

    /**
     * Update a backdated order: replace meta fields and all items.
     * Old items are soft-deleted, new items created with fresh snapshots.
     */
    public function update(UpdateBackdatedOrderRequest $request, Order $order): RedirectResponse
    {
        if (! $order->isBackdated()) {
            return back()->with('error', 'Ця дія доступна лише для ретро-замовлень.');
        }

        $data = $request->validated();
        $orderDate = Carbon::parse($data['order_date'])->setTimezone('Europe/Kyiv');

        return DB::transaction(function () use ($data, $orderDate, $request, $order) {
            $before = [
                'order_number'      => $order->order_number,
                'created_at'        => $order->created_at?->toIso8601String(),
                'authorized_person' => $order->authorized_person,
                'cost_center'       => $order->cost_center,
                'total_cost'        => (float) $order->total_cost,
            ];

            // The retro date rides along with the meta update rather than
            // following it in a separate `saveQuietly()`. `OrderObserver`
            // dates the signatory ↔ cost-centre pair from `created_at` on the
            // `updated` event the line below fires, and a date applied
            // afterwards arrives too late to be seen — the pair got the order's
            // previous date instead of the one the admin just set.
            $order->forceFill(['created_at' => $orderDate]);

            // Update order meta
            $order->update([
                'authorized_person' => $data['authorized_person'],
                'cost_center'       => $data['cost_center'] ?? null,
                'limit_exceeded'    => $data['limit_exceeded'] ?? false,
            ]);

            // The number carries the month it belongs to ({PREFIX}-{YY}{MM}-{SEQ}),
            // and the accounting sheet prints it next to created_at on the same
            // row, so moving an order into another month has to move its number
            // too — otherwise a July export shows a June number on a July line.
            // Within the same month the number stays: it is what the paper
            // request is filed under.
            $newYear = (int) $orderDate->format('y');
            $newMonth = (int) $orderDate->format('n');

            if ($order->order_year !== $newYear || $order->order_month !== $newMonth) {
                $order->update($this->orderNumberService->generate(
                    OrderType::Internal,
                    $newYear,
                    $newMonth,
                ));
            }

            // Soft-delete existing items
            foreach ($order->items()->get() as $item) {
                $item->delete();
            }

            // Build new order items via shared builder (supports existing snapshot reuse)
            [$totalCommercial, $totalCost] = $this->itemBuilder->buildItems(
                $order, $data['items'], supportExistingSnapshot: true
            );

            $order->update([
                'total_commercial' => 0,
                'total_cost'       => $totalCost,
            ]);

            $unreconciled = $order->clearReconciliationAfterEdit();

            // The live module has written `order_edited` since round 2; this one
            // wrote nothing at all, in the module whose whole output is the
            // accounting record of a month that has already been reported. An
            // admin could move an order into another month, renumber it and
            // change what it cost, and the only trace was the row itself.
            $this->auditService->log(
                eventType: 'order_edited',
                user: $request->user(),
                description: "Backdated order {$order->order_number} edited.",
                shiftId: null,
                meta: [
                    'order_id' => $order->id,
                    'source'   => 'backdated',
                    'before'   => $before,
                    'after'    => [
                        'order_number'      => $order->order_number,
                        'created_at'        => $orderDate->toIso8601String(),
                        'authorized_person' => $order->authorized_person,
                        'cost_center'       => $order->cost_center,
                        'total_cost'        => (float) $totalCost,
                    ],
                    'unreconciled' => $unreconciled,
                ],
            );

            $outside = $this->recordCategoriesOutsideGroup($order, $request->user());

            $redirect = redirect()->route('admin.backdated-orders.index')
                ->with('success', "Ретро-замовлення {$order->order_number} оновлено успішно"
                    .($unreconciled ? '. Звірку знято — замовлення змінилося після неї' : ''));

            return $outside === null
                ? $redirect
                : $redirect->with('warning', $outside);
        });
    }

    /**
     * Ask the category question of a saved retro order, and record the answer.
     *
     * The one rule of the three that applies here — see the note on this class.
     * It reports and never refuses: an admin correcting a badly imported month
     * cannot go back and un-order what was printed, and stopping them would only
     * mean the month never gets entered.
     *
     * @return string|null what to tell the admin, if anything
     */
    private function recordCategoriesOutsideGroup(Order $order, User $user): ?string
    {
        $outside = $this->limitService->servicesOutsideSignatoryCategories(
            $order->authorized_person,
            $order->items()->get()->pluck('service_id'),
        );

        if ($outside === []) {
            return null;
        }

        $this->auditService->log(
            eventType: 'category_not_allowed',
            user: $user,
            description: "Services outside the signatory's categories on backdated order {$order->order_number}: ".implode(', ', $outside),
            shiftId: null,
            meta: [
                'order_id'          => $order->id,
                'source'            => 'backdated',
                'authorized_person' => $order->authorized_person,
                'services'          => $outside,
            ],
        );

        return 'Послуги поза дозволеними категоріями підписанта: '.implode(', ', $outside).'.';
    }

    // ─── Destroy ─────────────────────────────────────────

    /**
     * Soft-delete a backdated order and its items.
     * Used when no physical paper request exists for this order.
     */
    public function destroy(DestroyBackdatedOrderRequest $request, Order $order): RedirectResponse
    {
        if (! $order->isBackdated()) {
            return back()->with('error', 'Ця дія доступна лише для ретро-замовлень.');
        }

        $orderNumber = $order->order_number;

        DB::transaction(function () use ($order, $request) {
            // Soft-delete all order items first
            foreach ($order->items()->get() as $item) {
                $item->delete();
            }

            // Same reason as the edit above: `OrderController::destroy()` has
            // always written this line, and a retro order removed from a month
            // that has already been reported left none.
            $this->auditService->log(
                eventType: 'order_deleted',
                user: $request->user(),
                description: "Backdated order {$order->order_number} deleted (soft).",
                shiftId: null,
                meta: [
                    'order_id'      => $order->id,
                    'source'        => 'backdated',
                    'total_cost'    => (float) $order->total_cost,
                    'order_date'    => $order->created_at?->toIso8601String(),
                    'is_reconciled' => (bool) $order->is_reconciled,
                ],
            );

            // Soft-delete the order
            $order->delete();
        });

        return back()->with('success', "Ретро-замовлення {$orderNumber} видалено.");
    }

    // ─── Reconciliation ─────────────────────────────────

    /**
     * Mark a single backdated order as reconciled.
     */
    public function reconcile(Request $request, Order $order): RedirectResponse
    {
        if (! $order->isBackdated()) {
            return back()->with('error', 'Ця дія доступна лише для ретро-замовлень.');
        }

        $order->update([
            'is_reconciled' => true,
            'reconciled_at' => now(),
            'reconciled_by' => $request->user()->id,
        ]);

        return back()->with('success', "Ретро-замовлення {$order->order_number} звірено.");
    }

    /**
     * Batch reconcile multiple backdated orders.
     */
    public function reconcileBatch(ReconcileBackdatedRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $count = Order::whereIn('id', $data['order_ids'])
            ->where('is_backdated', true)
            ->where('is_reconciled', false)
            ->update([
                'is_reconciled' => true,
                'reconciled_at' => now(),
                'reconciled_by' => $request->user()->id,
            ]);

        return back()->with('success', "Звірено ретро-замовлень: {$count}.");
    }

    /**
     * Undo reconciliation of a backdated order.
     */
    public function unreconcile(Request $request, Order $order): RedirectResponse
    {
        if (! $order->isBackdated()) {
            return back()->with('error', 'Ця дія доступна лише для ретро-замовлень.');
        }

        $order->update([
            'is_reconciled' => false,
            'reconciled_at' => null,
            'reconciled_by' => null,
        ]);

        return back()->with('success', "Звірку ретро-замовлення {$order->order_number} скасовано.");
    }

    /**
     * Toggle request_received flag on a backdated order.
     * Separate from OrderController to bypass EnsureShiftIsOpen middleware.
     */
    public function toggleRequest(Request $request, Order $order): RedirectResponse
    {
        if (! $order->isBackdated()) {
            return back()->with('error', 'Ця дія доступна лише для ретро-замовлень.');
        }

        $received = $request->boolean('request_received');

        // Same rule as the order screen: what the signatory approved by letter
        // is not unset from here (OrderController::toggleRequestReceived).
        if (! $received && $order->hasEmailApproval()) {
            return back()->with('error', 'Заявку погоджено листом — позначку зняти не можна.');
        }

        $order->update([
            'request_received'    => $received,
            'request_received_at' => $received ? now() : null,
            'request_received_by' => $received ? $request->user()->id : null,
        ]);

        return back()->with('success', $received
            ? "Заявку для {$order->order_number} позначено як отриману."
            : "Позначку заявки для {$order->order_number} знято.");
    }

    // ─── Export ──────────────────────────────────────────

    /**
     * Export backdated orders to XLSX for accounting.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $from = null;
        $to = null;

        if ($request->from || $request->to) {
            [$from, $to] = $this->dateRange($request);
        }

        $reconciled = $request->reconciled !== null && $request->reconciled !== ''
            ? filter_var($request->reconciled, FILTER_VALIDATE_BOOLEAN)
            : null;

        // Through dateRangeLabel(), like every other export. The bounds are Kyiv
        // midnights held as UTC instants; formatting one directly names the
        // previous day, so a range asked for from the 1st was delivered as
        // `retro-orders-20260630-…`.
        $datePart = 'all';
        if ($from && $to) {
            [$fromLabel, $toLabel] = $this->dateRangeLabel($from, $to);
            $datePart = "{$fromLabel}-{$toLabel}";
        }
        $filename = "retro-orders-{$datePart}.xlsx";

        return Excel::download(new BackdatedOrdersExport($from, $to, $reconciled), $filename);
    }
}
