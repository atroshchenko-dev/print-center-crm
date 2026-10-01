<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exports\ReconciliationExport;
use App\Http\Controllers\Concerns\ParsesDateRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestroyReconciliationOrderRequest;
use App\Http\Requests\Admin\ReconcileBatchRequest;
use App\Models\Order;
use App\Models\Shift;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\LimitService;
use App\Services\ShiftService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ReconciliationController
 *
 * Admin interface for verifying internal orders against paper requests (TZ §5).
 * Allows marking orders as "Reconciled" before month-end reporting.
 */
class ReconciliationController extends Controller
{
    use ParsesDateRange;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly InventoryService $inventoryService,
        private readonly LimitService $limitService,
        private readonly ShiftService $shiftService,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * List internal orders with reconciliation status.
     */
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $makeQuery = fn () => $this->filtered($request, $from, $to);

        // Summary stats (fresh query per aggregate to avoid shared builder state)
        $summary = [
            'total'      => $makeQuery()->count(),
            'reconciled' => $makeQuery()->where('is_reconciled', true)->count(),
            'total_cost' => round((float) $makeQuery()->sum('total_cost'), 2),
        ];

        $orders = $makeQuery()
            ->withEmailApprovalFlag()
            ->with(['items', 'user', 'reconciler'])
            ->orderBy('created_at')
            ->paginate(50)
            ->withQueryString();

        // This page lives under permission:reports and needs no open shift, but
        // the edit pencil it draws points at orders.edit, which sits behind
        // permission:orders and EnsureShiftIsOpen. Month-closing is done with
        // the till shut, so the link used to bounce the admin to the shift-open
        // form; a reports-only accountant got a 403. Editing an order genuinely
        // needs an open shift — it moves inventory and cash — so the rule stays
        // and the button is only offered when it will actually work.
        $canEditOrders = $request->user()->hasPermission('orders')
            && Shift::current()->exists();

        return Inertia::render('Admin/Reconciliation/Index', [
            'orders'        => $orders,
            'filters'       => $request->only('from', 'to', 'cost_center', 'authorized_person', 'reconciled', 'request_received'),
            'summary'       => $summary,
            'isAdmin'       => $request->user()->isAdmin(),
            'canEditOrders' => $canEditOrders,
        ]);
    }

    /**
     * Mark a single order as reconciled.
     */
    public function reconcile(Request $request, Order $order): RedirectResponse
    {
        if ($order->type !== OrderType::Internal) {
            return back()->with('error', 'Звірка доступна лише для внутрішніх замовлень.');
        }

        $order->update([
            'is_reconciled' => true,
            'reconciled_at' => now(),
            'reconciled_by' => $request->user()->id,
        ]);

        return back()->with('success', "Замовлення {$order->order_number} звірено.");
    }

    /**
     * Batch reconcile multiple orders.
     */
    public function reconcileBatch(ReconcileBatchRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $count = Order::whereIn('id', $data['order_ids'])
            ->where('type', OrderType::Internal->value)
            ->where('is_reconciled', false)
            ->update([
                'is_reconciled' => true,
                'reconciled_at' => now(),
                'reconciled_by' => $request->user()->id,
            ]);

        return back()->with('success', "Звірено замовлень: {$count}.");
    }

    /**
     * Undo reconciliation (admin corrects mistake).
     */
    public function unreconcile(Request $request, Order $order): RedirectResponse
    {
        // Its siblings all check this; this one did not.
        if ($order->type !== OrderType::Internal) {
            return back()->with('error', 'Звірка доступна лише для внутрішніх замовлень.');
        }

        $order->update([
            'is_reconciled' => false,
            'reconciled_at' => null,
            'reconciled_by' => null,
        ]);

        return back()->with('success', "Звірку замовлення {$order->order_number} скасовано.");
    }

    /**
     * Toggle "request received" flag for an internal order.
     */
    public function toggleRequest(Request $request, Order $order): RedirectResponse
    {
        if ($order->type !== OrderType::Internal) {
            return back()->with('error', 'Облік заявок доступний лише для внутрішніх замовлень.');
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

        $label = $received ? 'отримано' : 'знято';

        return back()->with('success', "Заявку замовлення {$order->order_number} {$label}.");
    }

    /**
     * Soft-delete an operational internal order with full reversal.
     * Admin-only — reverses inventory and limits if the order was in a
     * terminal status.
     *
     * No ledger reversal, despite what this said before: the method refuses
     * anything that is not an internal order, and internal orders never reach
     * the cash register — recordPayment() only fires for commercial ones.
     * There is nothing to reverse. OrderController::destroy() is the path that
     * handles commercial orders, and it does reverse the ledger.
     */
    public function destroy(DestroyReconciliationOrderRequest $request, Order $order): RedirectResponse
    {
        if ($order->type !== OrderType::Internal) {
            return back()->with('error', 'Видалення доступне лише для внутрішніх замовлень.');
        }

        if ($order->is_backdated) {
            return back()->with('error', 'Для ретро-замовлень використовуйте окремий модуль.');
        }

        $orderNumber = $order->order_number;
        $amount = (float) $order->total_cost;
        $user = $request->user();
        $shift = $this->shiftService->getCurrentShift();
        $reason = $request->validated()['reason'];

        // Shift guard: reversals require an open shift
        $needsReversal = in_array($order->status, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true);
        if ($needsReversal && ! $shift) {
            return back()->with('error', 'Немає відкритої зміни для видалення замовлення з реверсами.');
        }

        DB::transaction(function () use ($order, $user, $shift, $reason) {
            $originalStatus = $order->status;

            // Return inventory if order was issued
            if (in_array($originalStatus, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true)) {
                $this->inventoryService->returnStockForOrder($order, $user);
            }

            // Return limits if issued internal
            if (in_array($originalStatus, [OrderStatus::PaidIssued, OrderStatus::CompletedIssued], true)
                && $order->cost_center) {
                $totalBwClicks = $order->items->sum('bw_clicks');
                $this->limitService->returnUsage($order->cost_center, $totalBwClicks);
            }

            // Soft-delete items then order
            // Not ->each(): a callback returning false stops Builder::each()
            // early, and delete() returns exactly that on a halted event.
            foreach ($order->items()->get() as $item) {
                $item->delete();
            }
            $order->delete();

            // Audit log
            $this->auditService->log(
                eventType: 'order_deleted',
                user: $user,
                description: "Order {$order->order_number} deleted via reconciliation (soft). Original status: {$originalStatus->value}. Reason: {$reason}",
                shiftId: $shift?->id,
                meta: [
                    'order_id'        => $order->id,
                    'original_status' => $originalStatus->value,
                    'total_cost'      => (float) $order->total_cost,
                    'reason'          => $reason,
                    'source'          => 'reconciliation',
                ],
            );

            Cache::forget('dashboard:charts');
        });

        // Telegram notification (outside transaction)
        $this->telegram->orderDeleted($orderNumber, $amount, $user->name);

        return back()->with('success', "Замовлення {$orderNumber} видалено.");
    }

    /**
     * The orders the screen is showing, for whatever the filter bar currently says.
     *
     * One builder for the list, the summary and the month close. They used to be
     * two, and the second one knew about four filters out of six: closing the
     * month ignored "Звірено" and "Заявка" while the dialog above the button
     * counted with them. An accountant who narrowed the screen to the orders
     * whose paper request had arrived was told "Буде звірено 3" and reconciled
     * every unreconciled order of the month — the paper request is the whole
     * point of this screen (ТЗ §5), and closing past it is what it exists to
     * prevent.
     */
    private function filtered(Request $request, Carbon $from, Carbon $to): Builder
    {
        return Order::where('type', OrderType::Internal->value)
            ->operational()
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->whereBetween('created_at', [$from, $to])
            ->when($request->cost_center, fn ($q, $cc) => $q->where('cost_center', $cc))
            ->when($request->authorized_person, fn ($q, $ap) => $q->where('authorized_person', $ap))
            ->when($request->reconciled !== null && $request->reconciled !== '', function ($q) use ($request) {
                $q->where('is_reconciled', filter_var($request->reconciled, FILTER_VALIDATE_BOOLEAN));
            })
            // Five values, not three: 'paper' and 'email' would both come out
            // of filter_var() as false, so the parsing lives in the scope.
            ->filterRequestSource(
                $request->request_received !== null ? (string) $request->request_received : null,
            );
    }

    /**
     * Close month: batch-reconcile ALL unreconciled orders within the current filter.
     */
    public function closeMonth(Request $request): RedirectResponse
    {
        [$from, $to] = $this->dateRange($request);

        $count = $this->filtered($request, $from, $to)
            ->where('is_reconciled', false)
            ->update([
                'is_reconciled' => true,
                'reconciled_at' => now(),
                'reconciled_by' => $request->user()->id,
            ]);

        return back()->with('success', "Місяць закрито. Звірено замовлень: {$count}.");
    }

    /**
     * Export reconciliation orders to XLSX for accounting.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $from = null;
        $to = null;

        if ($request->from || $request->to) {
            [$from, $to] = $this->dateRange($request);
        }

        // Every filter the screen applies, so the file matches what was read on
        // it. The workbook could always take the cost centre and the signatory
        // — nothing passed them, because the download link carried three
        // parameters out of five. "Заявка" it could not take at all.
        $reconciled = $request->reconciled !== null && $request->reconciled !== ''
            ? filter_var($request->reconciled, FILTER_VALIDATE_BOOLEAN)
            : null;

        // The raw value, not a boolean: the workbook filters through the same
        // scope the screen does, and 'paper'/'email' do not survive filter_var().
        $requestReceived = $request->request_received !== null && $request->request_received !== ''
            ? (string) $request->request_received
            : null;

        $authorizedPerson = $request->authorized_person;
        $costCenter = $request->cost_center;

        // Through dateRangeLabel(), like every other export. The bounds are Kyiv
        // midnights held as UTC instants, so formatting one directly puts the
        // start of the range on the previous date: a month closed from the 1st
        // downloaded as `reconciliation-20260630-…`. The rows inside were right,
        // which is what kept it quiet.
        $datePart = 'all';
        if ($from && $to) {
            [$fromLabel, $toLabel] = $this->dateRangeLabel($from, $to);
            $datePart = "{$fromLabel}-{$toLabel}";
        }
        $filename = "reconciliation-{$datePart}.xlsx";

        return Excel::download(
            new ReconciliationExport($from, $to, $reconciled, $authorizedPerson, $costCenter, $requestReceived),
            $filename,
        );
    }
}
