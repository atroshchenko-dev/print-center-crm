<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case New             = 'new';
    case InProgress      = 'in_progress';
    case Ready           = 'ready';
    case PaidIssued      = 'paid_issued';       // Commercial: paid + issued
    case CompletedIssued = 'completed_issued';  // Internal: completed + issued
    case Cancelled       = 'cancelled';

    /**
     * Statuses that allow editing the order cart.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::New, self::InProgress], true);
    }

    /**
     * Terminal statuses — no further transitions allowed.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::PaidIssued, self::CompletedIssued, self::Cancelled], true);
    }

    /**
     * Valid next statuses from a given current status.
     *
     * @return array<OrderStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New             => [self::InProgress, self::CompletedIssued, self::Cancelled],
            self::InProgress      => [self::Ready, self::CompletedIssued, self::Cancelled],
            self::Ready           => [self::PaidIssued, self::CompletedIssued, self::Cancelled],
            self::PaidIssued,
            self::CompletedIssued,
            self::Cancelled       => [],  // Terminal
        };
    }
}
