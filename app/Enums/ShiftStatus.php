<?php

declare(strict_types=1);

namespace App\Enums;

enum ShiftStatus: string
{
    case Open       = 'open';
    case Closed     = 'closed';
    case AutoClosed = 'auto_closed';

    public function label(): string
    {
        return match($this) {
            self::Open       => 'Відкрита',
            self::Closed     => 'Закрита',
            self::AutoClosed => 'Закрита автоматично',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function requiresSettlement(): bool
    {
        return $this === self::AutoClosed;
    }
}
