<?php

declare(strict_types=1);

namespace App\Enums;

enum EquipmentType: string
{
    case Bw      = 'bw';
    case Color   = 'color';
    case Riso = 'riso';

    public function label(): string
    {
        return match($this) {
            self::Bw      => 'Чорно-білий',
            self::Color   => 'Кольоровий',
            self::Riso => 'Плоттер',
        };
    }

    public function counterColumn(): string
    {
        return match($this) {
            self::Bw      => 'bw_clicks',
            self::Color   => 'color_clicks',
            self::Riso => 'riso_clicks',
        };
    }
}
