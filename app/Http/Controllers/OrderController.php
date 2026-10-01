<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Http\Requests\Order\BatchUpdateStatusRequest;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\DestroyOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\ToggleRequestReceivedRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\UniversityRef;
use App\Notifications\OrderCreatedNotification;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\LedgerService;
use App\Services\LimitService;
use App\Services\OrderCreationService;
use App\Services\OrderItemBuilder;
use App\Services\OrderNumberService;
use App\Services\ReferenceDataService;
use App\Services\ShiftService;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderCreationService $orderCreationService,
        private readonly OrderItemBuilder $itemBuilder,
        private readonly LedgerService $ledgerService,
        private readonly AuditService $auditService,
        private readonly InventoryService $inventoryService,
        private readonly LimitService $limitService,
        private readonly ShiftService $shiftService,
        private readonly ReferenceDataService $refData,
        private readonly TelegramService $telegram,
        private readonly OrderNumberService $orderNumberService,
    ) {}

    /**
     * Order list — paginated, filterable.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $shift = $this->shiftService->getCurrentShift();
        $view = $request->input('view', 'shift'); // 'shift' or 'month'

        if (! $shift && $view === 'shift') {
            return redirect()->route('shifts.open.form')
                ->with('error', 'Немає відкритої зміни.');
        }

        // A string or nothing: `?search[]=x` used to reach addcslashes() as an
        // array and answer 500.
        $search = $request->input('search');
        $search = is_string($search) ? trim($search) : '';

        $terminal = array_map(
            fn (OrderStatus $s) => $s->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isTerminal()),
        );

        $query = Order::with(['user', 'items'])
            ->withEmailApprovalFlag()
            ->where('is_backdated', false)
            // Search and chips are a month-view contract: the month is
            // paginated, so its filtering must happen here — a chip that only
            // filters the loaded page shows «Нічого не знайдено» while the
            // matches sit on page two. The shift always arrives whole:
            // the richer client-side search (items, initiator) works on the
            // page, and a term carried over from the month view must not
            // quietly thin the shift out.
            ->when($view === 'month' && $search !== '', function ($q) use ($search) {
                // %, _ and \ are LIKE syntax, not text: "100%" must narrow to
                // the one name with a percent sign, not everything with "100".
                // ilike throughout — the number field answered case-sensitively
                // while every other field did not.
                $term = '%'.addcslashes($search, '\\%_').'%';

                return $q->where(function ($sub) use ($term) {
                    $sub->where('order_number', 'ilike', $term)
                        ->orWhere('authorized_person', 'ilike', $term)
                        ->orWhere('cost_center', 'ilike', $term)
                        ->orWhere('initiator', 'ilike', $term);
                });
            })
            ->when($view === 'month' && $request->input('status_group') === 'active',
                fn ($q) => $q->whereNotIn('status', $terminal))
            ->when($view === 'month' && $request->input('status_group') === 'terminal',
                fn ($q) => $q->whereIn('status', $terminal))
            ->when($view === 'month' && in_array($request->input('type'), ['internal', 'commercial'], true),
                fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s));

        if ($view === 'month') {
            // A Kyiv month, bounded, not whereMonth() against a UTC `now()`.
            // The working day runs to the 03:00 auto-close, so between midnight
            // and 03:00 on the 1st this listed the month that had already
            // ended — and the orders of those three hours then stayed out of
            // the new month's list for the whole month.
            $query->whereBetween('created_at', [
                today('Europe/Kyiv')->startOfMonth()->utc(),
                today('Europe/Kyiv')->endOfMonth()->endOfDay()->utc(),
            ]);
        } else {
            $query->where('shift_id', $shift->id);
        }

        $orders = $query->latest()
            // The month is paginated at 50; the shift is the operators'
            // working set and must arrive whole — every client-side contract
            // on the page (search, chips, «Обрати всі») assumes it.
            // 500 is a recorded ceiling like carryover's limit(100): far
            // beyond a physical day, and if it is ever crossed the Pagination
            // component appears rather than anything being silently hidden.
            ->paginate($view === 'month' ? 50 : 500)
            ->withQueryString();

        // Incomplete orders from previous shifts (only in shift view)
        $carryover = collect();
        if ($view === 'shift' && $shift) {
            $carryover = Order::with(['user', 'items'])
                ->withEmailApprovalFlag()
                ->where('shift_id', '!=', $shift->id)
                ->where('is_backdated', false)
                ->whereNotIn('status', [
                    OrderStatus::PaidIssued->value,
                    OrderStatus::CompletedIssued->value,
                    OrderStatus::Cancelled->value,
                ])
                ->where('created_at', '>=', now()->subDays(30))
                ->latest()
                ->limit(100)
                ->get();
        }

        return Inertia::render('Orders/Index', [
            'orders'    => $orders,
            'carryover' => $carryover,
            // The sanitized string, not the raw input — the box must never be
            // fed the array that R3-6 refused above.
            'filters' => array_merge(
                $request->only('status', 'view', 'status_group', 'type'),
                ['search' => $search],
            ),
            'shift' => $shift,
            'view'  => $view,
        ]);
    }

    /**
     * Show the order creation "basket" form.
     * Accepts optional ?repeat=ORDER_ID to pre-fill metadata from a previous order.
     */
    public function create(Request $request): Response
    {
        $services = $this->refData->services();
        $categories = $this->refData->categories();
        $departments = $this->refData->departments();
        $signatories = $this->refData->signatories();
        $risoTiers = $this->refData->risoTiers();

        $risoPapers = $this->refData->risoPapers();
        $clickCosts = $this->refData->clickCosts();
        $inventoryStock = $this->refData->inventoryStock();

        // Pre-fill from previous order if ?repeat=ID. Digits only: an array
        // handed to find() returns a Collection and ->type on it is a 500
        //, and a non-numeric scalar dies in the bigint cast on
        // Postgres — 22P02, the same 500 (fifth pass).
        $prefill = null;
        $repeatId = $request->input('repeat');
        if (is_scalar($repeatId) && ctype_digit((string) $repeatId)) {
            $source = Order::with('items')->find((int) $repeatId);
            if ($source) {
                $prefill = [
                    'type'              => $source->type->value,
                    'authorized_person' => $source->authorized_person,
                    'cost_center'       => $source->cost_center,
                ];
            }
        }

        return Inertia::render('Orders/Create', [
            'services'               => $services,
            'categories'             => $categories,
            'departments'            => $departments,
            'signatories'            => $signatories,
            'riso_tiers'             => $risoTiers,
            'riso_papers'            => $risoPapers,
            'riso_paper_cost'        => $this->refData->risoPaperCost(),
            'inventory_stock'        => $inventoryStock,
            'brochure_papers'        => $risoPapers,
            'brochure_click_costs'   => $clickCosts,
            'diploma_click_costs'    => $clickCosts,
            'prefill'                => $prefill,
            'signatory_cost_centers' => $this->refData->signatoryCostCenters(),
            'cost_center_initiators' => $this->refData->costCenterInitiators(),
        ]);
    }

    /**
     * Store a new order with all its items.
     */
    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $shift = $this->shiftService->getCurrentShift();
        $order = $this->orderCreationService->createOrder(
            $request->validated(),
            $shift,
            $request->user(),
        );

        // Notify about large orders. The threshold rule moved into
        // TelegramService::isLargeOrder() when the edit notification became its
        // second caller — one rule, one place.
        if ($this->telegram->isLargeOrder((float) $order->total_cost)) {
            $this->telegram->largeOrder(
                $order->order_number,
                (float) $order->total_cost,
                $order->type->value,
            );
        }

        // Record initial status
        OrderStatusHistory::record($order, null, 'new', $request->user());

        $redirect = redirect()->route('orders.show', $order)
            ->with('success', "Замовлення {$order->order_number} створено.");

        // The order is saved either way — ТЗ §3 makes a breached limit a warning,
        // not a refusal, and the owner put the group quota and the category rule
        // on the same footing (2026-07-31). Every one of these is also in the
        // audit journal; this is so the operator sees it while they are still
        // standing there.
        $warnings = $this->orderCreationService->warnings();

        return $warnings === []
            ? $redirect
            : $redirect->with('warning', implode("\n", $warnings));
    }

    /**
     * Show a single order.
     */
    public function show(Order $order): Response
    {
        $order->load(['user', 'items', 'ledgerTransactions', 'statusHistory.user', 'latestApproval']);

        // Enabled payment methods for the payment dropdown
        $enabledMethods = array_filter(
            explode(',', Setting::getValue('payment_methods_enabled', 'cash') ?: 'cash')
        );

        return Inertia::render('Orders/Show', [
            'order'                 => $order,
            'enabledPaymentMethods' => array_values($enabledMethods),
        ]);
    }

    /**
     * Update order status (with Optimistic Locking).
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
    {
        $data = $request->validated();
        $newStatus = OrderStatus::from($data['status']);
        $shift = $this->shiftService->getCurrentShift();
        $user = $request->user();

        // Validate allowed transition (shape of the flow + how this type may finish)
        if (! $order->canTransitionTo($newStatus)) {
            if ($newStatus === OrderStatus::CompletedIssued && $order->isCommercial()) {
                return back()->with('error', 'Комерційне замовлення закривається лише через оплату.');
            }

            return back()->with('error', "Перехід зі статусу '{$order->status->value}' у '{$newStatus->value}' заборонений.");
        }

        try {
            $conversions = [];
            $deficits = [];
            // Atomic: status + ledger + inventory must all succeed or all rollback
            DB::transaction(function () use ($order, $newStatus, $data, $shift, $user, &$conversions, &$deficits) {
                $fromStatus = $order->status->value;
                $order->status = $newStatus;

                if ($data['payment_method'] ?? null) {
                    $order->payment_method = PaymentMethod::from($data['payment_method']);
                }

                $order->saveWithOptimisticLock($data['version']);

                // Record status transition
                OrderStatusHistory::record(
                    $order,
                    $fromStatus,
                    $newStatus->value,
                    $user,
                );

                // Record payment in ledger for commercial orders
                if ($newStatus === OrderStatus::PaidIssued && $order->isCommercial()) {
                    $this->ledgerService->recordPayment(
                        shift: $shift,
                        order: $order,
                        method: $order->payment_method,
                        amount: $order->getPayableAmount(),
                        user: $user,
                    );
                }

                // Auto deduct inventory if order is completed / issued
                if (in_array($newStatus, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true)) {
                    ['conversions' => $conversions, 'deficits' => $deficits]
                        = $this->inventoryService->autoDeductForOrder($order, $user);

                    // Increment department limits for internal orders (TZ §3.1)
                    if ($order->type === OrderType::Internal && $order->cost_center) {
                        $totalBwClicks = $order->items->sum('bw_clicks');
                        $this->limitService->incrementUsage($order->cost_center, $totalBwClicks);
                    }
                }
            });

            // Send notifications for auto-conversions (outside transaction)
            if (! empty($conversions)) {
                $this->telegram->paperConverted($order->order_number, $conversions);
            }

            // A shortfall is tolerated on purpose — the job is already printed,
            // so refusing the status change would only make the books disagree
            // with the shelf. But the operator has to hear about it now, not
            // from the audit log a month later.
            if (! empty($deficits)) {
                return back()->with('warning', "Статус оновлено, але складу не вистачило:\n".implode("\n", $deficits));
            }

            if (! empty($conversions)) {
                return back()->with('success', "Статус замовлення оновлено.\n".implode("\n", $conversions));
            }

            return back()->with('success', 'Статус замовлення оновлено.');
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Конфлікт: замовлення було змінено іншим користувачем. Оновіть сторінку.');
        }
    }

    /**
     * Cancel an order (with mandatory reason).
     *
     * Only an order that has **not** been handed over can be cancelled: the
     * guard below refuses every terminal status. Reversing an issued order —
     * the ledger, the stock and the department quota — is `destroy()`, which is
     * admin-only for that reason.
     *
     * This method used to carry its own copy of all three reversals, each
     * conditioned on `$originalStatus` being `paid_issued` or `completed_issued`
     * — statuses the guard three lines below had already turned away. The copy
     * could not run, and all it did was make two rules out of one, which is how
     * they drift. `ReferenceRenameTest` names the surviving
     * path.
     */
    public function cancel(CancelOrderRequest $request, Order $order): RedirectResponse
    {
        $data = $request->validated();
        $isTechnicalDefect = $data['is_technical_defect'] ?? false;
        $user = $request->user();
        $shift = $this->shiftService->getCurrentShift();

        if (! $shift) {
            return back()->with('error', 'Немає відкритої зміни для скасування.');
        }

        if ($order->status->isTerminal()) {
            return back()->with('error', 'Замовлення вже завершено або скасовано.');
        }

        \DB::transaction(function () use ($order, $data, $isTechnicalDefect, $user, $shift) {
            $originalStatus = $order->status;

            $order->status = OrderStatus::Cancelled;
            $order->cancellation_reason = $data['reason'];
            $order->is_technical_defect = $isTechnicalDefect;
            $order->saveWithOptimisticLock($data['version']);

            // Record status transition
            OrderStatusHistory::record(
                $order,
                $originalStatus->value,
                OrderStatus::Cancelled->value,
                $user,
                $data['reason'],
            );

            $this->auditService->log(
                eventType: $isTechnicalDefect ? 'order_defect' : 'order_cancelled',
                user: $user,
                description: "Order {$order->order_number} cancelled. Reason: {$data['reason']}",
                shiftId: $shift?->id,
                meta: ['order_id' => $order->id, 'technical_defect' => $isTechnicalDefect],
            );
        });

        return redirect()->route('orders.index')
            ->with('success', "Замовлення {$order->order_number} скасовано.");
    }

    /**
     * Batch update status for multiple orders at once.
     * Each order is processed individually in its own transaction.
     */
    public function batchUpdateStatus(BatchUpdateStatusRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $newStatus = OrderStatus::from($data['status']);
        $shift = $this->shiftService->getCurrentShift();
        $user = $request->user();

        // Shift guard: inventory/ledger operations require an open shift
        if (! $shift && in_array($newStatus, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true)) {
            return back()->with('error', 'Немає відкритої зміни для цієї операції.');
        }

        $processed = 0;
        $skipped = 0;
        $unpaid = 0;
        $allConversions = [];
        $allDeficits = [];

        $orders = Order::with('items')->whereIn('id', $data['order_ids'])->get();

        foreach ($orders as $order) {
            // Skip if transition not allowed. Batch takes up to 50 ids at once,
            // so a mixed selection must not sweep commercial orders past the
            // cash register along with the internal ones.
            if (! $order->canTransitionTo($newStatus)) {
                // "Обрати всі" grabs both types, so this is the ordinary case,
                // not an error — but the operator has to know those orders are
                // still waiting for payment rather than quietly not done.
                if ($newStatus === OrderStatus::CompletedIssued && $order->isCommercial()) {
                    $unpaid++;
                } else {
                    $skipped++;
                }

                continue;
            }

            try {
                $conversions = [];
                $deficits = [];
                \DB::transaction(function () use ($order, $newStatus, $user, &$conversions, &$deficits) {
                    // Lock row to prevent concurrent modification (replaces optimistic locking for batch)
                    $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

                    // The check above ran on a copy read before this transaction.
                    // By the time the lock is granted another request may have
                    // finished the order; writing blindly would repeat the
                    // deduction and the quota increment on top of its commit.
                    if (! $lockedOrder || ! $lockedOrder->canTransitionTo($newStatus)) {
                        throw new \RuntimeException("Order {$order->id} was changed concurrently");
                    }

                    $lockedOrder->load('items');
                    $fromStatus = $lockedOrder->status->value;
                    $lockedOrder->status = $newStatus;
                    $lockedOrder->version = $lockedOrder->version + 1;
                    $lockedOrder->save();

                    OrderStatusHistory::record($lockedOrder, $fromStatus, $newStatus->value, $user);

                    // Auto deduct inventory if order is completed / issued
                    if ($newStatus === OrderStatus::CompletedIssued) {
                        ['conversions' => $conversions, 'deficits' => $deficits]
                            = $this->inventoryService->autoDeductForOrder($lockedOrder, $user);

                        if ($lockedOrder->type === OrderType::Internal && $lockedOrder->cost_center) {
                            $totalBwClicks = $lockedOrder->items->sum('bw_clicks');
                            $this->limitService->incrementUsage($lockedOrder->cost_center, $totalBwClicks);
                        }
                    }
                });

                $processed++;
                if (! empty($conversions)) {
                    $allConversions = array_merge($allConversions, $conversions);
                }
                if (! empty($deficits)) {
                    $allDeficits = array_merge($allDeficits, ["#{$order->order_number}: ".implode('; ', $deficits)]);
                }
            } catch (\RuntimeException $e) {
                $skipped++;
            }
        }

        $msg = "Оброблено: {$processed}";
        if ($skipped > 0) {
            $msg .= ", пропущено: {$skipped}";
        }
        if ($unpaid > 0) {
            $msg .= ". Комерційних замовлень не закрито (потрібна оплата): {$unpaid}";
        }
        if (! empty($allConversions)) {
            $this->telegram->paperConverted('batch', $allConversions);
            $msg .= "\n".implode("\n", $allConversions);
        }

        // Same as updateStatus: the batch went through, but a shortfall must not
        // vanish into the audit log.
        if (! empty($allDeficits)) {
            return back()->with('warning', $msg."\nСкладу не вистачило:\n".implode("\n", $allDeficits));
        }

        return back()->with('success', $msg);
    }

    /**
     * Toggle "request received" flag for an internal order.
     */
    public function toggleRequestReceived(ToggleRequestReceivedRequest $request, Order $order): RedirectResponse
    {
        if (! $order->isInternal()) {
            return back()->with('error', 'Облік заявок доступний лише для внутрішніх замовлень.');
        }

        $received = $request->boolean('request_received');

        // The signatory's own click is not the operator's to undo. Removing the
        // mark here would leave the approval row saying «Погоджено» while the
        // order says the request never arrived — and the paper it then sends
        // the accountant looking for does not exist.
        if (! $received && $order->hasEmailApproval()) {
            return back()->with('error', 'Заявку погоджено листом — позначку зняти не можна.');
        }

        $order->update([
            'request_received'    => $received,
            'request_received_at' => $received ? now() : null,
            'request_received_by' => $received ? $request->user()->id : null,
        ]);

        $label = $received ? 'отримано' : 'знято';

        return back()->with('success', "Заявку замовлення {$order->order_number} {$label}.");
    }

    // ─── Edit ────────────────────────────────────────────

    /**
     * Show the order edit form (only for editable statuses: new, in_progress).
     */
    public function edit(Order $order): Response|RedirectResponse
    {
        if ($order->isBackdated()) {
            return back()->with('error', 'Для ретро-замовлень використовуйте окремий модуль.');
        }

        if (! $order->isEditable()) {
            return back()->with('error', 'Редагування неможливе для замовлення у статусі "'.$order->status->value.'".');
        }

        $order->load('items');

        return Inertia::render('Orders/Edit', [
            'order'                  => $order,
            'services'               => $this->refData->services(),
            'categories'             => $this->refData->categories(),
            'departments'            => $this->refData->departments(),
            'signatories'            => $this->refData->signatories(),
            'riso_tiers'             => $this->refData->risoTiers(),
            'riso_papers'            => $this->refData->risoPapers(),
            'riso_paper_cost'        => $this->refData->risoPaperCost(),
            'inventory_stock'        => $this->refData->inventoryStock(),
            'brochure_papers'        => $this->refData->risoPapers(),
            'brochure_click_costs'   => $this->refData->clickCosts(),
            'diploma_click_costs'    => $this->refData->clickCosts(),
            'signatory_cost_centers' => $this->refData->signatoryCostCenters(),
        ]);
    }

    /**
     * Update an order: replace meta fields and rebuild items with fresh snapshots.
     * Only allowed for editable statuses (new, in_progress).
     */
    public function update(UpdateOrderRequest $request, Order $order): RedirectResponse
    {
        if ($order->isBackdated()) {
            return back()->with('error', 'Для ретро-замовлень використовуйте окремий модуль.');
        }

        if (! $order->isEditable()) {
            return back()->with('error', 'Редагування неможливе для замовлення у статусі "'.$order->status->value.'".');
        }

        $data = $request->validated();
        $user = $request->user();
        $shift = $this->shiftService->getCurrentShift();
        $reason = $data['edit_reason'] ?? null;

        $warnings = [];
        $costBefore = (float) $order->total_cost;
        $costAfter = $costBefore;

        try {
            DB::transaction(function () use ($order, $data, $user, $shift, $reason, &$warnings, &$costAfter) {
                $oldType = $order->type->value;
                $oldTotal = (float) $order->total_cost;
                $oldNumber = $order->order_number;
                $type = OrderType::from($data['type']);

                // Update order meta. `limit_exceeded` is not among them: it is
                // what the server counted below, not what the form posted.
                $order->type = $type;
                $order->authorized_person = $data['authorized_person'] ?? null;
                $order->cost_center = $data['cost_center'] ?? null;
                $order->is_at_cost = $type === OrderType::Commercial && ($data['is_at_cost'] ?? false);
                $order->saveWithOptimisticLock($data['version']);

                // The number carries the type in its prefix ({PREFIX}-{YY}{MM}-{SEQ}),
                // and the edit form has a two-button switch for the type. Until
                // now only creation ever picked the prefix, so an order switched
                // to commercial went on printing INT- on the commercial export,
                // in the ledger line and on the paper request.
                //
                // The retro module settled this shape already, for the other half
                // of the same number: an edit that moves an order into another
                // month moves its number too. The month here is **not** the
                // edit's to move — the order still belongs to the month it was
                // taken in — so the new number is minted in that month.
                if ($oldType !== $type->value) {
                    $order->update($this->orderNumberService->generate(
                        $type,
                        (int) $order->order_year,
                        (int) $order->order_month,
                    ));
                }

                // The cost centre reaches the department reference through
                // `OrderObserver` — `saveWithOptimisticLock()` above fires
                // `updated`, which is where both reference writes now live.

                // Soft-delete existing items
                foreach ($order->items()->get() as $item) {
                    $item->delete();
                }

                // Build new order items via shared builder (supports existing snapshot reuse)
                [$totalCommercial, $totalCost] = $this->itemBuilder->buildItems(
                    $order, $data['items'], supportExistingSnapshot: true
                );

                $order->update([
                    'total_commercial' => $totalCommercial,
                    'total_cost'       => $totalCost,
                ]);

                $costAfter = (float) $totalCost;

                // The same three rules the order was created under, re-asked of
                // the order as it now stands. Nothing here increments anything,
                // so an edit needs no rule of its own — see LimitService.
                $warnings = $this->limitService->recordForOrder($order, $user, $shift);

                if ($order->clearReconciliationAfterEdit()) {
                    array_unshift($warnings, 'Звірку знято: замовлення змінилося після неї.');
                }

                // Record audit
                $this->auditService->log(
                    eventType: 'order_edited',
                    user: $user,
                    description: "Order {$order->order_number} edited.".($reason ? " Reason: {$reason}" : ''),
                    shiftId: $shift?->id,
                    meta: [
                        'order_id'   => $order->id,
                        'old_type'   => $oldType,
                        'new_type'   => $order->type->value,
                        'old_number' => $oldNumber,
                        'new_number' => $order->order_number,
                        'old_total'  => $oldTotal,
                        'new_total'  => $totalCost,
                        'reason'     => $reason,
                    ],
                );

                // Record in status history as an edit event
                OrderStatusHistory::record(
                    $order,
                    $order->status->value,
                    $order->status->value,
                    $user,
                    $reason ? "Редагування: {$reason}" : 'Замовлення відредаговано',
                );

                Cache::forget('dashboard:charts');
            });

            // Telegram notification (outside transaction)
            $this->telegram->orderEdited(
                $order->order_number,
                $user->name,
                $costBefore,
                $costAfter,
                $reason,
            );

            $redirect = redirect()->route('orders.show', $order)
                ->with('success', "Замовлення {$order->order_number} оновлено.");

            // Saved either way, exactly as on creation — the operator hears about
            // it while they are still standing there, and the audit journal has
            // it regardless.
            return $warnings === []
                ? $redirect
                : $redirect->with('warning', implode("\n", $warnings));
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Конфлікт: замовлення було змінено іншим користувачем. Оновіть сторінку.');
        }
    }

    // ─── Destroy ─────────────────────────────────────────

    /**
     * Soft-delete an order and its items.
     * Admin-only (enforced by route middleware).
     * Reverses side effects if order was in a terminal status.
     */
    public function destroy(DestroyOrderRequest $request, Order $order): RedirectResponse
    {
        if ($order->isBackdated()) {
            return back()->with('error', 'Для ретро-замовлень використовуйте окремий модуль.');
        }

        $orderNumber = $order->order_number;
        $amount = (float) $order->total_cost;
        $user = $request->user();
        $shift = $this->shiftService->getCurrentShift();
        $reason = $request->validated()['reason'] ?? null;

        // Shift guard: reversals require an open shift
        $needsReversal = in_array($order->status, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true);
        if ($needsReversal && ! $shift) {
            return back()->with('error', 'Немає відкритої зміни для видалення замовлення з реверсами.');
        }

        DB::transaction(function () use ($order, $user, $shift, $reason) {
            $originalStatus = $order->status;

            // Reverse payment if order was paid
            if ($originalStatus === OrderStatus::PaidIssued && $order->isCommercial()) {
                $payments = $order->ledgerTransactions()
                    ->whereIn('type', [LedgerTransactionType::PaymentCash->value, LedgerTransactionType::PaymentCard->value])
                    ->get();

                foreach ($payments as $payment) {
                    $this->ledgerService->reverseTransaction(
                        original: $payment,
                        currentShift: $shift,
                        reason: "Deletion of order {$order->order_number}",
                        user: $user,
                    );
                }
            }

            // Return inventory if order was issued
            if (in_array($originalStatus, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true)) {
                $this->inventoryService->returnStockForOrder($order, $user);
            }

            // Return limits if issued internal
            if (in_array($originalStatus, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true)
                && $order->type === OrderType::Internal && $order->cost_center) {
                $totalBwClicks = $order->items->sum('bw_clicks');
                $this->limitService->returnUsage($order->cost_center, $totalBwClicks);
            }

            // Soft-delete items then order
            foreach ($order->items()->get() as $item) {
                $item->delete();
            }
            $order->delete();

            // Audit log
            $this->auditService->log(
                eventType: 'order_deleted',
                user: $user,
                description: "Order {$order->order_number} deleted (soft). Original status: {$originalStatus->value}".($reason ? ". Reason: {$reason}" : ''),
                shiftId: $shift?->id,
                meta: [
                    'order_id'        => $order->id,
                    'original_status' => $originalStatus->value,
                    'total_cost'      => (float) $order->total_cost,
                    'reason'          => $reason,
                ],
            );

            Cache::forget('dashboard:charts');
        });

        // Telegram notification (outside transaction)
        $this->telegram->orderDeleted($orderNumber, $amount, $user->name);

        return redirect()->route('orders.index')
            ->with('success', "Замовлення {$orderNumber} видалено.");
    }
    // ─── Email Approval Request ─────────────────────────

    /**
     * Send email approval request to the authorized signatory.
     * Triggered manually by operator from the order detail page.
     */
    public function sendApproval(Order $order): RedirectResponse
    {
        if ($order->type !== OrderType::Internal || empty($order->authorized_person)) {
            return back()->with('error', 'Погодження доступне лише для внутрішніх замовлень з підписантом.');
        }

        // A finished order has nothing left to approve: the link would stay
        // alive for 72 hours and its click would stamp request_received on a
        // cancelled or already issued order (finding I-1).
        if ($order->status->isTerminal()) {
            return back()->with('error', "Замовлення {$order->order_number} вже завершено або скасовано — погодження не потрібне.");
        }

        try {
            $signatory = UniversityRef::where('full_name', $order->authorized_person)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->first();

            if (! $signatory) {
                return back()->with('error', "Підписант '{$order->authorized_person}' не знайдений або не має email.");
            }

            // Категорії питаються тут, а не лише при створенні: замовлення могли
            // відредагувати після того, як правило вже відпрацювало, і лист —
            // це момент, коли воно виходить за межі системи. Попереджає й не
            // відмовляє, на тій самій підставі, що й решта правил у
            // LimitService (рішення власника 2026-07-31). Питається до
            // постановки листа в чергу: кинутий звідси виняток не повинен
            // залишати лист вже в jobs і водночас звітувати про помилку
            // відправки — оператор тоді перевідправляє, і підписант отримує
            // два листи.
            $outside = $this->limitService->servicesOutsideSignatoryCategories(
                $order->authorized_person,
                $order->items()->pluck('service_id'),
            );

            $signatory->notify(new OrderCreatedNotification($order));

            // "Поставлено в чергу", not "надіслано": OrderCreatedNotification is
            // ShouldQueue, so this call only reaches the jobs table. The mail
            // itself is built and sent on the worker, and nothing below can know
            // whether it arrived — saying "надіслано" here promised the operator
            // something that had not happened yet. Delivery
            // failures now land in the audit log via the notification's failed().
            $response = back()->with('success', "Запит на погодження поставлено в чергу для {$signatory->email}");

            return $outside === []
                ? $response
                : $response->with('warning', 'Увага: позиції поза дозволеними категоріями підписанта: '.implode(', ', $outside).'.');
        } catch (\Throwable $e) {
            Log::warning('Failed to send signatory email', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            return back()->with('error', 'Помилка відправки email. Спробуйте ще раз.');
        }
    }
}
