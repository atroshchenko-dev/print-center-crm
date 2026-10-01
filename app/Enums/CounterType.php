<?php

declare(strict_types=1);

namespace App\Enums;

enum CounterType: string
{
    case Bw      = 'bw';
    case Color   = 'color';
    case Riso = 'riso';
    case None    = 'none';

    public function label(): string
    {
        return match($this) {
            self::Bw      => 'Ч/Б',
            self::Color   => 'Колір',
            self::Riso => 'Плоттер',
            self::None    => 'Без лічильника',
        };
    }

    /**
     * There was an orderItemColumn() here — a third copy of "counter type to
     * clicks column", called from nowhere. ConstructorPricingService counts
     * each type into its own variable and never needs the column name;
     * EquipmentType::counterColumn() is the one place that does.
     */
}
