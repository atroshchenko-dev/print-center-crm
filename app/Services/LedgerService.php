<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentMethod;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * LedgerService
 *
 * Manages all cash register (ledger) operations.
 * All entries are APPEND-ONLY — errors corrected via reverse transactions.
 *
 * TZ §4.3 + §6 (Ledger):
 * - Only CASH payments are added to physical cash balance
 * - Card payments are tracked in ledger but do NOT affect cash balance
 * - Withdrawals reduce cash balance
 * - Balance = cash_start + Σ(cash_payments) - Σ(withdrawals) + Σ(reversals)
 *
 * Concurrency: Uses pg_advisory_xact_lock(shift_id) to serialize all ledger
 * writes for a given shift, preventing balance_after race conditions.
 */
class LedgerService
{
    /**
     * Get current cash balance for the shift.
     * Calculated mathematically from the snapshot — never stored sum.
     */
    public function getCurrentBalance(Shift $shift): float
    {
        return $shift->calculateExpectedBalance();
    }

    /**
     * Record payment when an order is finalized.
     * Card payments are tracked but do NOT add to the cash balance.
     */
    public function recordPayment(
        Shift $shift,
        Order $order,
        PaymentMethod $method,
        float $amount,
        User $user,
    ): LedgerTransaction {
        return DB::transaction(function () use ($shift, $order, $method, $amount, $user) {
            // Advisory lock serializes all ledger writes for this shift
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$shift->id]);

            // Both rules come from the enum. They were spelled out here as
            // well, which is how a rule ends up with two versions of itself.
            $type         = $method->ledgerType();
            $balanceAfter = $this->getCurrentBalance($shift);

            if ($method->affectsCashBalance()) {
                $balanceAfter += $amount;
            }

            return LedgerTransaction::create([
                'shift_id'       => $shift->id,
                'order_id'       => $order->id,
                'type'           => $type->value,
                'payment_method' => $method->value,
                'amount'         => $amount,
                'balance_after'  => $balanceAfter,
                'comment'        => "Payment for order {$order->order_number}",
                'user_id'        => $user->id,
            ]);
        });
    }

    /**
     * Record a cash withdrawal (incassation, change, supplies, etc.).
     *
     * Refuses more than the drawer holds. Every other resource here already
     * does: installing a toner that is not on the shelf throws, converting
     * more paper than there is throws, a stock deduction is clamped at what
     * exists. Cash accepted anything — a mistyped 5000 for 500 was journalled,
     * the till went negative, and since the journal is append-only the only
     * way back is a reversal. Nobody sees it until the next close, where it is
     * indistinguishable from a real shortfall.
     *
     * The balance is read inside the transaction, after the advisory lock, so
     * two concurrent withdrawals cannot both pass the check.
     *
     * @throws \InvalidArgumentException If the amount is not positive, or exceeds the till
     */
    public function recordWithdrawal(
        Shift $shift,
        float $amount,
        string $comment,
        User $user,
    ): LedgerTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Withdrawal amount must be positive.');
        }

        return DB::transaction(function () use ($shift, $amount, $comment, $user) {
            // Advisory lock serializes all ledger writes for this shift
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$shift->id]);

            $available = $this->getCurrentBalance($shift);

            if ($amount > $available + 0.001) {
                throw new \InvalidArgumentException(sprintf(
                    'У касі %s ₴ — видати %s ₴ неможливо.',
                    number_format($available, 2, ',', ' '),
                    number_format($amount, 2, ',', ' '),
                ));
            }

            $balanceAfter = $available - $amount;

            return LedgerTransaction::create([
                'shift_id'      => $shift->id,
                'order_id'      => null,
                'type'          => LedgerTransactionType::Withdrawal->value,
                'payment_method'=> null,
                'amount'        => -$amount,   // Negative for withdrawals
                'balance_after' => $balanceAfter,
                'comment'       => $comment,
                'user_id'       => $user->id,
            ]);
        });
    }

    /**
     * Create a reverse transaction to cancel a previous entry.
     * This is the ONLY way to undo a ledger entry (TZ §6).
     *
     * IMPORTANT: Only reversals of cash-affecting transactions (cash payments,
     * withdrawals) modify the physical cash balance. Card payment reversals
     * are recorded but do NOT change the cash register balance.
     */
    public function reverseTransaction(
        LedgerTransaction $original,
        Shift $currentShift,
        string $reason,
        User $user,
    ): LedgerTransaction {
        return DB::transaction(function () use ($original, $currentShift, $reason, $user) {
            // Advisory lock serializes all ledger writes for this shift
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$currentShift->id]);

            $currentBalance = $this->getCurrentBalance($currentShift);

            // Asked of the entry itself, so the rule has one home rather than
            // an unnamed copy here — see LedgerTransaction::affectsCashBalance().
            $newBalance = $original->affectsCashBalance()
                ? $currentBalance - $original->amount  // Undo the cash impact
                : $currentBalance;                      // Card reversals don't affect cash

            return LedgerTransaction::createReversal(
                original: $original,
                currentShiftId: $currentShift->id,
                comment: $reason,
                userId: $user->id,
                newBalanceAfter: $newBalance,
            );
        });
    }
}
