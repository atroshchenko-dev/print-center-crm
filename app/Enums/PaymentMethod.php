<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';

    /**
     * What a person calls it.
     *
     * Every screen in the app writes «Готівка»/«Картка» — `Reports/Commercial.vue`
     * and `Orders/Show.vue` both spell the pair out by hand. The two XLSX files
     * an accountant actually receives wrote `cash` and `card`, which is R16-5
     * («сирий enum на екрані») in the one place nobody looks until month end.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Готівка',
            self::Card => 'Картка',
        };
    }

    /**
     * Ledger transaction type for this payment method.
     */
    public function ledgerType(): LedgerTransactionType
    {
        return match ($this) {
            self::Cash => LedgerTransactionType::PaymentCash,
            self::Card => LedgerTransactionType::PaymentCard,
        };
    }

    /**
     * Only cash payments affect the physical cash balance.
     */
    public function affectsCashBalance(): bool
    {
        return $this === self::Cash;
    }
}
