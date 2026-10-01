<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderType: string
{
    case Internal   = 'internal';   // University orders (INT-YYMM-NNN)
    case Commercial = 'commercial'; // External paying clients (COM-YYMM-NNN)

    public function prefix(): string
    {
        return match ($this) {
            self::Internal   => 'INT',
            self::Commercial => 'COM',
        };
    }

    /**
     * The final status for a completed order of this type.
     */
    public function completionStatus(): OrderStatus
    {
        return match ($this) {
            self::Internal   => OrderStatus::CompletedIssued,
            self::Commercial => OrderStatus::PaidIssued,
        };
    }
}
