<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Setting;
use App\Services\ApprovalItemsPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Email notification sent to authorized signatories when an internal
 * order is created on their behalf.
 *
 * Phase 2: includes separate Approve/Reject buttons via signed URLs.
 * Creates an OrderApproval record with a unique token for tracking.
 * Signatory can approve or reject with minimal clicks — no CRM login needed.
 *
 * Uses a custom branded Blade email template instead of the default
 * Laravel MailMessage layout for a professional, CRM-branded appearance.
 */
class OrderCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const DEFAULT_APPROVAL_TTL_HOURS = 72;

    /**
     * The token this send is for, minted here and carried in the job payload.
     *
     * It used to be minted inside toMail() and remembered on a property, with a
     * comment saying a queue retry would reuse it. It cannot: a failed job is
     * re-queued from the payload it was dispatched with, so the worker
     * unserialises the notification exactly as it left the web request and
     * everything toMail() assigned is gone. Every retry therefore minted a new
     * token and superseded the previous one — which is precisely the situation
     * the property was written to prevent, and the one that actually happens,
     * because an SMTP timeout can leave the mail delivered and the job failed.
     * The signatory then clicks the link they were sent and is told it has been
     * replaced.
     *
     * A constructor property is serialised with the job, so it survives the
     * retry, and toMail() can find the row it already created instead of
     * creating a second one.
     */
    private readonly string $token;

    /** Within one attempt, do not go back to the database for the same row. */
    private ?OrderApproval $approval = null;

    public function __construct(
        private readonly Order $order,
    ) {
        $this->token = Str::random(64);
    }

    /**
     * The queue gave up on this after its retries.
     *
     * Everything that can actually go wrong with the mail — SMTP, the signed
     * URLs, the OrderApproval row built inside toMail() — happens on the worker,
     * long after sendApproval() returned. Its try/catch only ever saw the
     * dispatch succeed, so a signatory who never received anything left no trace
     * outside failed_jobs, which nothing reads.
     *
     * Recorded in the audit log, not just the file log: stock shortages already
     * go there, and a signature request that silently never arrived is not a
     * smaller event than a missing sheet of paper.
     */
    public function failed(\Throwable $e): void
    {
        $restored = $this->rollBackUndeliveredApproval();

        Log::error('Approval email failed after retries', [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'error'        => $e->getMessage(),
        ]);

        AuditLog::record(
            eventType: 'approval_email_failed',
            user: null,
            description: "Лист погодження для {$this->order->order_number} не доставлено: {$e->getMessage()}",
            meta: [
                'order_id'           => $this->order->id,
                'authorized_person'  => $this->order->authorized_person,
                'previous_link_kept' => $restored,
            ],
        );
    }

    /**
     * Undo what an attempt that never arrived did to the order's approvals.
     *
     * The supersede happens when the message is *built*, and building happens on
     * the worker — so a resend that dies at SMTP still takes down the link the
     * signatory is holding, and leaves in its place a row marked `pending` that
     * nobody ever received. The order screen then reads "⏳ Очікує погодження"
     * for a request that was never delivered, which is R13-6 wearing a different
     * hat: a screen stating a delivery no one achieved.
     *
     * So the failure path puts the order back where it was: the undelivered row
     * is soft-deleted (the attempt stays on record) and the link it displaced
     * goes back to pending. With nothing to displace, the order is left with no
     * approval at all — which is the truth, and what the operator sees as
     * "Надіслати на погодження".
     *
     * Deliberately does nothing when the row is no longer pending: an SMTP
     * timeout can fail the job after the mail is already out, and if the
     * signatory has answered in the meantime, that answer is the fact here.
     *
     * @return bool whether a previously delivered link was handed back
     */
    private function rollBackUndeliveredApproval(): bool
    {
        $approval = OrderApproval::where('token', $this->token)->first();

        if ($approval === null || $approval->status !== 'pending') {
            return false;
        }

        return (bool) DB::transaction(function () use ($approval) {
            $approval->delete();

            $displaced = OrderApproval::where('order_id', $approval->order_id)
                ->where('status', 'superseded')
                ->where('id', '<', $approval->id)
                ->orderByDesc('id')
                ->first();

            return $displaced?->update(['status' => 'pending']) ?? false;
        });
    }

    /**
     * Delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email message with branded Blade template.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $itemsSummary = $this->formatItems();

        $ttlHours = (int) Setting::getValue('approval_ttl_hours', self::DEFAULT_APPROVAL_TTL_HOURS);
        $expiresAt = now()->addHours($ttlHours);

        // Keyed on the token from the constructor, so a retried attempt of this
        // same send finds the row it already made rather than superseding it.
        // A genuinely new send is a new notification with a new token, and that
        // one does supersede whatever was pending.
        $approval = $this->approval ??= OrderApproval::where('token', $this->token)->first()
            ?? DB::transaction(function () use ($order, $notifiable, $expiresAt) {
                OrderApproval::where('order_id', $order->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'superseded']);

                return OrderApproval::create([
                    'order_id'        => $order->id,
                    'token'           => $this->token,
                    'signatory_email' => $notifiable->email,
                    'signatory_name'  => $order->authorized_person,
                    'status'          => 'pending',
                    'expires_at'      => $expiresAt,
                ]);
            });

        // Signed URLs carry their own expiry so a leaked link stops working
        // even if the DB row is somehow missed — but they die a grace window
        // *after* the row, not with it. Signed to the same instant, the
        // middleware answered 403 before the controller could say «Посилання
        // протухло», and the page written for exactly that minute had never
        // rendered once. See OrderApproval::linkValidUntil().
        $linkValidUntil = $approval->linkValidUntil();

        $approveUrl = URL::temporarySignedRoute('approvals.show', $linkValidUntil, [
            'token'  => $approval->token,
            'action' => 'approve',
        ]);

        $rejectUrl = URL::temporarySignedRoute('approvals.show', $linkValidUntil, [
            'token'  => $approval->token,
            'action' => 'reject',
        ]);

        // Resolved here rather than injected: this notification is ShouldQueue,
        // and anything the constructor holds is serialised into the job payload.
        $presenter = app(ApprovalItemsPresenter::class);

        return (new MailMessage)
            ->subject("Погодження: {$order->order_number} — центр поліграфії")
            ->view('emails.order-approval', [
                'order'           => $order,
                'signatoryName'   => $order->authorized_person,
                'itemsSummary'    => $itemsSummary,
                'itemsByCategory' => $presenter->groupedByCategory($order),
                'approveUrl'      => $approveUrl,
                'rejectUrl'       => $rejectUrl,
                'ttlHours'        => $ttlHours,
            ]);
    }

    /**
     * The one line an email client shows before the message is opened.
     *
     * The composition leads, because that is what a mixed order needs to say in
     * the inbox list; the items and the total follow, as they always did. Mail
     * clients truncate this line, so the order of the three parts is the design.
     */
    private function formatItems(): string
    {
        $this->order->loadMissing('items');

        $parts = [];
        foreach ($this->order->items as $item) {
            $name = $item->service_name ?? 'Послуга';
            $qty = $item->quantity ?? 1;
            $parts[] = "{$name} × {$qty}";
        }

        if ($parts === []) {
            return 'Деталі в системі';
        }

        // No total here either: a preheader is the one line an inbox shows
        // before the message is opened, so a figure in it would reach further
        // than the letter itself (owner's decision, 2026-08-20 — cost belongs
        // to the CRM and the accountants, not to the signatory).
        $summary = app(ApprovalItemsPresenter::class)->categorySummary($this->order);

        return ($summary !== '' ? "{$summary} — " : '').implode(', ', $parts);
    }
}
