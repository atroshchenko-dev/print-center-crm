<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\ShiftStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shift Model
 *
 * Represents a working day shift.
 * One shift per calendar date, potentially opened by multiple executors.
 *
 * @property int $id
 * @property Carbon $date
 * @property int $opened_by
 * @property int|null $closed_by
 * @property ShiftStatus $status
 * @property float $cash_start
 * @property float|null $cash_calculated
 * @property float|null $cash_actual
 * @property string|null $cash_discrepancy_reason
 * @property bool $auto_closed
 * @property bool $settlement_required
 * @property bool $settlement_done
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 */
class Shift extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'date',
        'opened_by',
        'closed_by',
        'status',
        'cash_start',
        'cash_calculated',
        'cash_actual',
        'cash_discrepancy_reason',
        'auto_closed',
        'settlement_required',
        'settlement_done',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'date'                => 'date',
        'status'              => ShiftStatus::class,
        'cash_start'          => 'decimal:2',
        'cash_calculated'     => 'decimal:2',
        'cash_actual'         => 'decimal:2',
        'auto_closed'         => 'boolean',
        'settlement_required' => 'boolean',
        'settlement_done'     => 'boolean',
        'opened_at'           => 'datetime',
        'closed_at'           => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────

    /** Who opened and closed it — see AuditLog::user() for why `withTrashed()`. */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function counterReadings(): HasMany
    {
        return $this->hasMany(ShiftCounterReading::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ─── Scopes ──────────────────────────────────────────

    /**
     * The shift that is currently open, whatever date it was opened on.
     *
     * Deliberately not keyed on `date`: a shift opened in the evening is still
     * the current one after midnight, right up to the 03:00 auto-close
     * (TZ §4.4). Matching on `date = today()` made it vanish at midnight —
     * the operator was locked out mid-shift and could open a second shift on
     * top of the first. At most one row can match; the `unique_open_shift`
     * partial index enforces that.
     */
    public function scopeCurrent($query)
    {
        return $query->where('status', ShiftStatus::Open->value);
    }

    // ─── Helpers ─────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [ShiftStatus::Closed, ShiftStatus::AutoClosed], true);
    }

    // `needsSettlement()` stood here and nothing called it (audit R37, round 36's
    // class): `settlement_required && ! settlement_done`, a second definition of
    // a question `ShiftOpenService::getPreviousUnsettledShift()` already answers
    // — and answers differently, by `cash_actual IS NULL` across closed and
    // auto-closed shifts. `Shift/Open.vue` has a third spelling of the name for
    // its own purposes: `!! props.previous_shift`, which just mirrors the query.
    //
    // Measured before removing, because a dead predicate can still be the only
    // written record of what two columns mean: on production 116 shifts, 25
    // auto-closed, 25 with `settlement_required`, and **all 25** with
    // `settlement_done` too. So the columns are maintained correctly —
    // `required` set at the 03:00 auto-close (ShiftCloseService::autoCloseShift),
    // `done` set when the next opening settles it (ShiftOpenService, in
    // `settlePreviousShift`) — and the predicate would have answered correctly
    // had anyone asked. It was removed for being a second definition, not a
    // wrong one; the columns themselves are live and stay.

    public function hasMorningReadings(): bool
    {
        return $this->counterReadings()->where('reading_type', 'morning')->exists();
    }

    /**
     * Calculate expected cash balance.
     * Formula: cash_start + Σ(cash payments) + Σ(withdrawals) + Σ(cash reversals)
     * Note: withdrawal and reversal amounts are stored as NEGATIVE values in the ledger.
     * IMPORTANT: Only reversals of CASH transactions affect the physical cash balance.
     * Card reversals are tracked but do NOT change the cash register.
     */
    public function calculateExpectedBalance(): float
    {
        $paymentCash = LedgerTransactionType::PaymentCash->value;
        $withdrawal = LedgerTransactionType::Withdrawal->value;
        $reversal = LedgerTransactionType::Reversal->value;
        $cashMethod = PaymentMethod::Cash->value;

        // A reversal carries the payment method of the entry it undoes, and a
        // withdrawal has none — so `payment_method = 'cash'` alone dropped
        // reversed withdrawals, and the till came out short by the amount that
        // had been put back. LedgerService counts them as cash-affecting; the
        // two have to agree, or balance_after and this sum drift apart.
        $sums = $this->ledgerTransactions()
            ->selectRaw('
                COALESCE(SUM(CASE WHEN type = ? THEN amount END), 0) as payments,
                COALESCE(SUM(CASE WHEN type = ? THEN amount END), 0) as withdrawals,
                COALESCE(SUM(CASE WHEN type = ? AND (payment_method = ? OR payment_method IS NULL) THEN amount END), 0) as reversals
            ', [$paymentCash, $withdrawal, $reversal, $cashMethod])->first();

        return (float) ($this->cash_start + $sums->payments + $sums->withdrawals + $sums->reversals);
    }
}
