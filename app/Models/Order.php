<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Observers\OrderInitiatorObserver;
use App\Observers\OrderObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Order Model
 *
 * Represents a printing order (internal or commercial).
 * Uses Optimistic Locking via `version` field for concurrent access protection.
 * Uses Soft Deletes — physical deletion is strictly forbidden.
 *
 * @property int $id
 * @property string $order_number
 * @property string $order_prefix
 * @property OrderType $type
 * @property OrderStatus $status
 * @property int $version
 * @property int $user_id
 * @property int|null $shift_id
 * @property string|null $authorized_person
 * @property string|null $initiator
 * @property string|null $cost_center
 * @property bool $limit_exceeded
 * @property PaymentMethod|null $payment_method
 * @property float $total_commercial
 * @property float $total_cost
 * @property string|null $cancellation_reason
 * @property bool $is_technical_defect
 * @property bool $is_reconciled
 * @property bool $is_backdated
 * @property bool $is_at_cost
 * @property Carbon|null $reconciled_at
 * @property int|null $reconciled_by
 * @property bool $request_received
 * @property Carbon|null $request_received_at
 * @property int|null $request_received_by
 * @property-read bool|null $has_email_approval Only present under scopeWithEmailApprovalFlag()
 */
// Порядок значущий: OrderObserver заводить центр витрат у довідник,
// OrderInitiatorObserver потім шукає його там.
#[ObservedBy([OrderObserver::class, OrderInitiatorObserver::class])]
class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'order_prefix',
        'order_year',
        'order_month',
        'order_sequence',
        'type',
        'status',
        'user_id',
        'shift_id',
        'authorized_person',
        'initiator',
        'cost_center',
        'limit_exceeded',
        'payment_method',
        'total_commercial',
        'total_cost',
        'cancellation_reason',
        'is_technical_defect',
        'is_reconciled',
        'is_backdated',
        'reconciled_at',
        'reconciled_by',
        'request_received',
        'request_received_at',
        'request_received_by',
        'is_at_cost',
        // NOTE: 'version' intentionally excluded — managed only by saveWithOptimisticLock()
    ];

    protected $casts = [
        'status'              => OrderStatus::class,
        'type'                => OrderType::class,
        'payment_method'      => PaymentMethod::class,
        'total_commercial'    => 'decimal:2',
        'total_cost'          => 'decimal:2',
        'limit_exceeded'      => 'boolean',
        'is_technical_defect' => 'boolean',
        'is_reconciled'       => 'boolean',
        'is_backdated'        => 'boolean',
        'is_at_cost'          => 'boolean',
        'reconciled_at'       => 'datetime',
        'request_received'    => 'boolean',
        'request_received_at' => 'datetime',
        'version'             => 'integer',
        'order_year'          => 'integer',
        'order_month'         => 'integer',
        'order_sequence'      => 'integer',
    ];

    // ─── Optimistic Locking ──────────────────────────────

    /**
     * Check version and increment atomically on save.
     * Throws exception if version mismatch (another user modified the record).
     *
     * This bypasses `Model::save()` and writes through the query builder, so
     * Eloquent never fires anything on its own — no observer or listener
     * would ever see an edit made through this method, including
     * `OrderObserver` recording the signatory/cost-centre pair. The calls
     * below reproduce the relevant slice of `performUpdate()`/`finishSave()`
     * (vendor Model.php) by hand: the halting `saving`/`updating` events
     * before the write (a listener returning `false` aborts, same as
     * `Model::save()`), then `syncChanges()` before `syncOriginal()` so
     * `wasChanged()` still reports the columns just written, then the
     * `updated` and `saved` events, then `syncOriginal()` as before. Order
     * matters — `syncOriginal()` first would empty `wasChanged()` and the
     * observer would see no change.
     *
     * The reproduction is not exact: `updated_at` goes straight into the
     * query builder's `update()` array and is never assigned back onto the
     * model, unlike `Model::save()`. Inside an `updated`/`saved` listener
     * fired from this same call, `$order->updated_at` therefore still reads
     * the pre-save value and `wasChanged('updated_at')` is false, even
     * though the column changed in the database.
     *
     * Halting is silent, not exceptional: a `saving`/`updating` listener
     * that returns `false` makes this method return `false` too, the same
     * way `Model::save()` would — it does not throw. That is different from
     * the version conflict below, which does throw. No `saving`/`updating`
     * listener is registered on `Order` today, so this never happens in
     * practice, and none of the three call sites in OrderController check
     * the return value — each sits inside a `DB::transaction` that goes on
     * to write status history, ledger, inventory and audit rows and flash
     * success regardless of what this method returned. That is safe only as
     * long as nothing halts the save. A caller that ever registers a
     * `saving`/`updating` listener on `Order` must check this return value
     * itself before trusting that the write happened.
     */
    public function saveWithOptimisticLock(int $expectedVersion): bool
    {
        // The same halting semantics as Model::save(): a listener returning
        // false stops the write before it reaches the builder.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        $updated = static::where('id', $this->id)
            ->where('version', $expectedVersion)
            ->update(array_merge(
                $this->getDirty(),
                ['version' => $expectedVersion + 1, 'updated_at' => now()]
            ));

        if ($updated === 0) {
            throw new \RuntimeException(
                "Optimistic lock failed for Order #{$this->id}. ".
                "Expected version {$expectedVersion}, but record was modified by another user."
            );
        }

        $this->version = $expectedVersion + 1;
        $this->syncChanges();
        $this->fireModelEvent('updated', false);
        $this->fireModelEvent('saved', false);
        $this->syncOriginal();

        return true;
    }

    // ─── Relationships ───────────────────────────────────

    /** Who took the order — see AuditLog::user() for why `withTrashed()`. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class);
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by')->withTrashed();
    }

    public function requestReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'request_received_by')->withTrashed();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function latestApproval(): HasOne
    {
        return $this->hasOne(OrderApproval::class)->latestOfMany();
    }

    /**
     * Every approval attempt, latest or not — the source of truth for
     * "was this request confirmed by letter". latestApproval() cannot
     * answer that: a fresh pending letter sent after an approved one
     * would hide the approval that already arrived.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(OrderApproval::class);
    }

    public function hasEmailApproval(): bool
    {
        return $this->approvals()->where('status', 'approved')->exists();
    }

    // ─── Scopes ──────────────────────────────────────────

    /**
     * Scope to exclude backdated (retro) orders from operational queries.
     * Use in dashboards, reports, and reconciliation to isolate retro data.
     */
    public function scopeOperational($query)
    {
        return $query->where('is_backdated', false);
    }

    /**
     * Adds a boolean `has_email_approval` column to the query.
     *
     * withExists, not with(): the screens only need "paper or letter",
     * and eager-loading the row would carry signatory_email into every
     * list — personal data PrunePersonalData exists to remove.
     */
    public function scopeWithEmailApprovalFlag($query)
    {
        return $query->withExists([
            'approvals as has_email_approval' => fn ($q) => $q->where('status', 'approved'),
        ]);
    }

    /**
     * The «Заявка» filter, which now has five values instead of three.
     *
     * 'paper' and 'email' must not reach filter_var(): FILTER_VALIDATE_BOOLEAN
     * answers false for both, so the screen would quietly list the orders whose
     * request never arrived at all.
     */
    public function scopeFilterRequestSource($query, ?string $value)
    {
        if ($value === null || $value === '') {
            return $query;
        }

        $approved = fn ($q) => $q->where('status', 'approved');

        return match ($value) {
            'email' => $query->where('request_received', true)->whereHas('approvals', $approved),
            'paper' => $query->where('request_received', true)->whereDoesntHave('approvals', $approved),
            default => $query->where('request_received', filter_var($value, FILTER_VALIDATE_BOOLEAN)),
        };
    }

    // ─── Helpers ─────────────────────────────────────────

    public function isInternal(): bool
    {
        return $this->type === OrderType::Internal;
    }

    public function isCommercial(): bool
    {
        return $this->type === OrderType::Commercial;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }

    public function isBackdated(): bool
    {
        return $this->is_backdated;
    }

    public function isAtCost(): bool
    {
        return $this->is_at_cost;
    }

    /**
     * Take the «Звірено» mark off an order whose contents have just changed.
     *
     * A reconciliation is a statement about the order somebody held the paper
     * request next to (ТЗ §5). Both edit paths replace every item underneath it,
     * so keeping the mark leaves the accountant's «Звірено 41/41» describing a
     * set that was never checked — and `reconciled_at` still naming the moment
     * it supposedly was.
     *
     * Taking the mark off rather than refusing the edit: the edit is usually the
     * correction the accountant came to make, and both screens have one click to
     * reconcile again once they have looked.
     *
     * Called from `OrderController::update()` and
     * `BackdatedOrderController::update()`; it lives here because it is one rule
     * and both of those had their own idea of it, which was none.
     *
     * @return bool whether there was a mark to take off
     */
    public function clearReconciliationAfterEdit(): bool
    {
        if (! $this->is_reconciled) {
            return false;
        }

        $this->update([
            'is_reconciled' => false,
            'reconciled_at' => null,
            'reconciled_by' => null,
        ]);

        return true;
    }

    /**
     * Get the amount that should be charged/recorded in the ledger.
     * Internal = 0, Commercial at_cost = total_cost, Commercial = total_commercial.
     */
    public function getPayableAmount(): float
    {
        if ($this->isInternal()) {
            return 0;
        }

        return $this->is_at_cost
            ? (float) $this->total_cost
            : (float) $this->total_commercial;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Whether this order may move into $target.
     *
     * Two rules, not one. `allowedTransitions()` knows the shape of the flow
     * but not the type of order walking it, so on its own it lets a commercial
     * order finish as `completed_issued` — which issues the goods and deducts
     * the stock without ever booking a payment. The way an order of this type
     * is allowed to finish is what OrderType::completionStatus() says it is.
     */
    public function canTransitionTo(OrderStatus $target): bool
    {
        if (! in_array($target, $this->status->allowedTransitions(), true)) {
            return false;
        }

        // Cancellation is open to both types; completion is not.
        if ($target === OrderStatus::Cancelled) {
            return true;
        }

        if ($target->isTerminal()) {
            return $target === $this->type->completionStatus();
        }

        return true;
    }
}
