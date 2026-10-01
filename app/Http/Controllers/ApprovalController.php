<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Services\ApprovalItemsPresenter;
use App\Services\AuditService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ApprovalController — handles digital approval/rejection of orders by signatories.
 *
 * Signatories don't have CRM accounts. Authentication is via signed URLs
 * with one-time tokens (HMAC-SHA256). Each link has a 72-hour TTL — held by
 * `order_approvals.expires_at` and checked below, not by the signature. The
 * signature outlives it by OrderApproval::LINK_GRACE_DAYS on purpose: when the
 * two died together, `signed` answered 403 first and the expiry page below
 * could not render for the link the system mails.
 *
 * Flow:
 *   1. Order created → email with signed approve/reject URLs
 *   2. Signatory clicks link → sees order details (show)
 *   3. Clicks "Approve" or "Reject" → respond() processes action
 *   4. Approve: sets request_received on order + audit log
 *   5. Reject: stores the reason + audit log
 *
 * Either answer is announced on Telegram, and it is the only notification in
 * this system with two audiences: the operators' chat, and the group opened for
 * the electronic requests when `TELEGRAM_APPROVALS_CHAT_ID` names one. Both
 * texts are composed in `TelegramService` with the other notifications — this
 * controller does not know which chats exist.
 */
class ApprovalController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Show the approval page with order details.
     * Public route — authenticated via signed URL.
     * Accepts optional ?action=approve|reject to show focused view.
     */
    public function show(Request $request, string $token): View
    {
        $approval = OrderApproval::where('token', $token)
            ->with(['order' => fn ($q) => $q->withTrashed()->with('items')])
            ->firstOrFail();

        // Order was deleted — show friendly message
        if (! $approval->order || $approval->order->trashed()) {
            return view('approvals.already-responded', [
                'approval'      => $approval,
                'order_deleted' => true,
            ]);
        }

        // Cancelled after the mail went out — the link outlives the order by
        // up to 72 hours, and a dead order has nothing left to approve.
        if ($approval->order->status === OrderStatus::Cancelled) {
            return view('approvals.already-responded', [
                'approval'        => $approval,
                'order_cancelled' => true,
            ]);
        }

        // Already responded
        if (! $approval->isPending()) {
            return view('approvals.already-responded', [
                'approval' => $approval,
            ]);
        }

        // Expired
        if ($approval->isExpired()) {
            return view('approvals.expired', [
                'approval' => $approval,
            ]);
        }

        $action = $request->query('action');

        // Direct approve confirmation (from email button)
        if ($action === 'approve') {
            return view('approvals.confirm-approve', [
                'approval'        => $approval,
                'order'           => $approval->order,
                'itemsByCategory' => app(ApprovalItemsPresenter::class)->groupedByCategory($approval->order),
            ]);
        }

        // Direct reject form (from email button)
        if ($action === 'reject') {
            return view('approvals.confirm-reject', [
                'approval'        => $approval,
                'order'           => $approval->order,
                'itemsByCategory' => app(ApprovalItemsPresenter::class)->groupedByCategory($approval->order),
            ]);
        }

        // Full page with both options (fallback)
        return view('approvals.show', [
            'approval'        => $approval,
            'order'           => $approval->order,
            'itemsByCategory' => app(ApprovalItemsPresenter::class)->groupedByCategory($approval->order),
        ]);

    }

    /**
     * Process approval or rejection.
     * Public route — authenticated via signed URL.
     */
    public function respond(Request $request, string $token)
    {
        $action = $request->input('action');

        if (! in_array($action, ['approve', 'reject'], true)) {
            abort(400, 'Invalid action');
        }

        // A public endpoint behind nothing but the signed link: the column
        // is `text` and the Telegram line carries the reason verbatim, so
        // cap it before anything downstream sees it (F-3).
        $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $action === 'reject'
            ? (string) ($request->input('rejection_reason') ?? '')
            : null;

        // Claim the approval inside a row lock: checking "is it still pending"
        // and writing the final status must be one atomic step, otherwise two
        // concurrent POSTs (double click, mail-scanner prefetch) both pass the
        // check and both act. Mirrors the pessimistic locking already used for
        // orders and the advisory locks used by the ledger.
        $claim = DB::transaction(function () use ($token, $action, $reason, $request) {
            $approval = OrderApproval::where('token', $token)
                ->lockForUpdate()
                ->firstOrFail();

            $approval->setRelation('order', Order::withTrashed()->find($approval->order_id));

            if (! $approval->order || $approval->order->trashed()) {
                return ['approval' => $approval, 'view' => 'approvals.already-responded', 'deleted' => true];
            }

            // Same guard as show(): a cancelled order must not be approved.
            if ($approval->order->status === OrderStatus::Cancelled) {
                return ['approval' => $approval, 'view' => 'approvals.already-responded', 'cancelled' => true];
            }

            if (! $approval->isPending()) {
                return ['approval' => $approval, 'view' => 'approvals.already-responded'];
            }

            if ($approval->isExpired()) {
                return ['approval' => $approval, 'view' => 'approvals.expired'];
            }

            // Write the terminal status while still holding the lock — a
            // concurrent request now sees a non-pending record and is turned
            // away by the isPending() check above.
            $approval->update(array_filter([
                'status'           => $action === 'approve' ? 'approved' : 'rejected',
                'rejection_reason' => $reason ?: null,
                'responded_at'     => now(),
                'responded_ip'     => $request->ip(),
            ], fn ($v) => $v !== null));

            return ['approval' => $approval, 'view' => null];
        });

        $approval = $claim['approval'];

        if ($claim['view'] !== null) {
            return view($claim['view'], array_filter([
                'approval'        => $approval,
                'order_deleted'   => $claim['deleted'] ?? null,
                'order_cancelled' => $claim['cancelled'] ?? null,
            ], fn ($v) => $v !== null));
        }

        // Side effects (order update, audit log, Telegram) run outside the
        // transaction so network I/O never holds a row lock.
        return $action === 'approve'
            ? $this->handleApproval($approval, $request)
            : $this->handleRejection($approval, (string) $reason, $request);
    }

    /**
     * Process approval — sets request_received on order.
     */
    private function handleApproval(OrderApproval $approval, Request $request): View
    {
        // Status, responded_at and responded_ip are already written by
        // respond() inside the row lock — this method only performs the
        // side effects that must not run while holding it.
        //
        // Residual window, accepted (F-5): the cancelled-order check ran
        // inside the claim transaction, and this update runs after it, so a
        // cancellation landing exactly in between still gets the stamp. I-1
        // shrank that window from 72 hours to milliseconds; closing it fully
        // would mean holding the order lock across network I/O — the very
        // thing respond() was built to avoid.

        // Auto-set request_received on the order (equivalent to paper signature)
        $order = $approval->order;
        $order->update([
            'request_received'    => true,
            'request_received_at' => now(),
            'request_received_by' => null, // No CRM user — approved via email
        ]);

        // Audit log (no User — approved via email link)
        AuditLog::record(
            eventType: 'order_approved_email',
            user: null,
            description: "Order {$order->order_number} approved via email by {$approval->signatory_name} ({$approval->signatory_email})",
            meta: [
                'order_id'  => $order->id,
                'signatory' => $approval->signatory_name,
                'email'     => $approval->signatory_email,
                'ip'        => $request->ip(),
            ],
        );

        // Telegram notification — the operators' chat and the approvals group
        $composition = app(ApprovalItemsPresenter::class)->categorySummary($order);

        $this->telegram->approvalApproved(
            $order->order_number,
            $approval->signatory_name,
            $composition,
        );

        return view('approvals.success', [
            'approval' => $approval,
            'action'   => 'approved',
        ]);
    }

    /**
     * Process rejection — notifies operators via Telegram.
     */
    private function handleRejection(OrderApproval $approval, string $reason, Request $request): View
    {
        // Status, rejection_reason, responded_at and responded_ip are already
        // written by respond() inside the row lock.

        $order = $approval->order;

        // Audit log
        AuditLog::record(
            eventType: 'order_rejected_email',
            user: null,
            description: "Order {$order->order_number} rejected via email by {$approval->signatory_name}".($reason ? ": {$reason}" : ''),
            meta: [
                'order_id'  => $order->id,
                'signatory' => $approval->signatory_name,
                'email'     => $approval->signatory_email,
                'reason'    => $reason,
                'ip'        => $request->ip(),
            ],
        );

        // Telegram notification — the operators' chat and the approvals group
        $composition = app(ApprovalItemsPresenter::class)->categorySummary($order);

        $this->telegram->approvalRejected(
            $order->order_number,
            $approval->signatory_name,
            $composition,
            $reason !== '' ? $reason : null,
        );

        return view('approvals.success', [
            'approval' => $approval,
            'action'   => 'rejected',
        ]);
    }
}
