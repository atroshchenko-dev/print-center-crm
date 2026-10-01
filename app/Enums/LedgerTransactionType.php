<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * LedgerTransactionType
 *
 * All possible types of ledger (cash register) transactions.
 * Used across LedgerTransaction model, LedgerService, reports, and exports.
 */
enum LedgerTransactionType: string
{
    case PaymentCash = 'payment_cash';
    case PaymentCard = 'payment_card';
    case Withdrawal  = 'withdrawal';
    case Reversal    = 'reversal';

    /**
     * Documented in the ledger migration, written by nothing. The opening till
     * is `shifts.cash_start`, and `Shift::calculateExpectedBalance()` starts
     * from that column rather than from a journal row. Kept because the value
     * is part of the column's documented domain; do not start writing it
     * without teaching the balance formula about it first.
     */
    case ShiftStart  = 'shift_start';

    /**
     * Human-readable Ukrainian label for reports and XLSX exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::PaymentCash => 'Готівка',
            self::PaymentCard => 'Картка',
            self::Withdrawal  => 'Видача',
            self::Reversal    => 'Повернення',
            self::ShiftStart  => 'Старт зміни',
        };
    }

    /**
     * There was an affectsCashBalance() here, called from nowhere, and it could
     * not have been right: it answered `true` for a reversal, whose impact
     * depends entirely on the entry being reversed, and `true` for ShiftStart,
     * which the balance formula does not sum at all.
     *
     * Two places decide this, and they have to agree with each other:
     * LedgerService::reverseTransaction() for a single entry, and
     * Shift::calculateExpectedBalance() for the running total. They disagreed
     * once already, over reversed withdrawals.
     */

    /**
     * Whether this is a payment type (cash or card).
     */
    public function isPayment(): bool
    {
        return in_array($this, [self::PaymentCash, self::PaymentCard], true);
    }
}
