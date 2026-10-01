<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * LedgerTransaction Model
 *
 * APPEND-ONLY ledger journal for cash register operations.
 *
 * ⚠️ CRITICAL CONSTRAINTS (per ТЗ п.6):
 * - UPDATE is STRICTLY FORBIDDEN
 * - DELETE is STRICTLY FORBIDDEN (no SoftDeletes either)
 * - Errors are corrected ONLY by creating a reverse (compensating) transaction
 *
 * This model overrides update() and delete() to enforce immutability.
 *
 * @property int $id
 * @property int $shift_id
 * @property int|null $order_id
 * @property int|null $reversed_transaction_id
 * @property LedgerTransactionType $type cast; compare against enum cases
 * @property string|null $payment_method NOT cast; compare against ->value
 * @property float $amount
 * @property float $balance_after
 * @property string|null $comment
 * @property int $user_id
 * @property Carbon $created_at
 */
class LedgerTransaction extends Model
{
    use HasFactory;

    /**
     * NO SoftDeletes — this is an append-only table.
     * NO $table->timestamps() — only created_at exists.
     */
    public $timestamps = false;

    protected $fillable = [
        'shift_id',
        'order_id',
        'reversed_transaction_id',
        'type',
        'payment_method',
        'amount',
        'balance_after',
        'comment',
        'user_id',
    ];

    protected $casts = [
        'type'          => LedgerTransactionType::class,
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at'    => 'datetime',
    ];

    // ─── Auto-fill created_at (timestamps disabled for append-only) ──

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->created_at = $transaction->created_at ?? now();
        });
    }

    // ─── Immutability Guards ─────────────────────────────

    /**
     * FORBIDDEN: save() on existing records = UPDATE. Only INSERT allowed.
     * Use LedgerTransaction::create([...]) for new entries.
     *
     * @throws \RuntimeException If record already persisted
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException(
                'LedgerTransaction records are IMMUTABLE. '.
                'UPDATE via save() is forbidden. Create a reverse transaction instead.'
            );
        }

        return parent::save($options);
    }

    /**
     * FORBIDDEN: Ledger transactions cannot be updated.
     * Create a reverse transaction instead.
     *
     * @throws \RuntimeException Always
     */
    public function update(array $attributes = [], array $options = []): never
    {
        throw new \RuntimeException(
            'LedgerTransaction records are IMMUTABLE. '.
            'UPDATE is forbidden. Create a reverse transaction instead.'
        );
    }

    /**
     * FORBIDDEN: Ledger transactions cannot be deleted.
     *
     * @throws \RuntimeException Always
     */
    public function delete(): never
    {
        throw new \RuntimeException(
            'LedgerTransaction records are IMMUTABLE. '.
            'DELETE is forbidden. Create a reverse transaction instead.'
        );
    }

    /**
     * FORBIDDEN: Force delete not applicable.
     *
     * @throws \RuntimeException Always
     */
    public function forceDelete(): never
    {
        throw new \RuntimeException(
            'LedgerTransaction records are IMMUTABLE. '.
            'DELETE is forbidden. Create a reverse transaction instead.'
        );
    }

    // ─── Relationships ───────────────────────────────────

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Who moved the money — see AuditLog::user() for why `withTrashed()`. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * The transaction this one reverses (null unless this is a reversal).
     */
    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transaction_id');
    }

    /**
     * The reversal that cancelled this transaction, if it has been reversed.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversed_transaction_id');
    }

    public function isReversed(): bool
    {
        return self::where('reversed_transaction_id', $this->id)->exists();
    }

    // ─── Type Helpers ────────────────────────────────────

    public function isReversal(): bool
    {
        return $this->type === LedgerTransactionType::Reversal;
    }

    public function isPayment(): bool
    {
        return $this->type->isPayment();
    }

    public function isWithdrawal(): bool
    {
        return $this->type === LedgerTransactionType::Withdrawal;
    }

    /**
     * Whether this entry moved physical cash.
     *
     * The type alone cannot answer it: a payment may be cash or card, and a
     * withdrawal carries no method at all. `payment_method` is a plain string
     * column, not a cast enum — comparing it to a PaymentMethod case is always
     * false, which is why this reads the value.
     *
     * Shift::calculateExpectedBalance() answers the same question in SQL over
     * the whole shift. The two are the only definitions of it and must agree;
     * they did not, over reversed withdrawals, and
     * LedgerCashImpactTest now walks every type to keep them together.
     */
    public function affectsCashBalance(): bool
    {
        return in_array($this->payment_method, [PaymentMethod::Cash->value, null], true)
            && in_array($this->type, [LedgerTransactionType::PaymentCash, LedgerTransactionType::Withdrawal], true);
    }

    /**
     * Create a reverse (compensating) transaction for this one.
     * This is the ONLY way to "undo" a ledger entry.
     */
    /**
     * A transaction may be reversed once, and a reversal may not itself be
     * reversed. Both rules used to live outside the journal — in the order
     * status machine at the two call sites — so any new caller would have
     * silently gained the ability to refund the same payment twice.
     *
     * The last line of defence is the partial unique index on
     * reversed_transaction_id: two concurrent reversals both pass the check
     * below, and the database rejects the second insert.
     *
     * @param  self  $original  The transaction to reverse
     * @param  int  $currentShiftId  The CURRENT open shift (NOT the original's shift)
     * @param  string  $comment  Reason for reversal
     * @param  int  $userId  Who is performing the reversal
     * @param  float  $newBalanceAfter  Recalculated balance after reversal (from LedgerService)
     *
     * @throws \RuntimeException If the original is itself a reversal, or was already reversed
     */
    public static function createReversal(
        self $original,
        int $currentShiftId,
        string $comment,
        int $userId,
        float $newBalanceAfter,
    ): self {
        if ($original->isReversal()) {
            throw new \RuntimeException(
                "TX #{$original->id} is itself a reversal and cannot be reversed. ".
                'Record a new transaction instead.'
            );
        }

        if ($original->isReversed()) {
            throw new \RuntimeException(
                "TX #{$original->id} has already been reversed. ".
                'A transaction can only be reversed once.'
            );
        }

        return self::create([
            'shift_id'                => $currentShiftId,
            'order_id'                => $original->order_id,
            'reversed_transaction_id' => $original->id,
            'type'                    => LedgerTransactionType::Reversal->value,
            'payment_method'          => $original->payment_method,
            'amount'                  => -$original->amount,
            'balance_after'           => $newBalanceAfter,
            'comment'                 => "Reversal of TX #{$original->id}: {$comment}",
            'user_id'                 => $userId,
        ]);
    }
}
