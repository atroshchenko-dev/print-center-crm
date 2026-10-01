<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceType: string
{
    case Static      = 'static';
    case Constructor = 'constructor';
    case Riso        = 'riso';
    case Brochure    = 'brochure';
    case Diploma     = 'diploma';

    public function label(): string
    {
        return match($this) {
            self::Static      => 'Статична послуга',
            self::Constructor => 'Конструктор',
            self::Riso        => 'Тиражування (ризограф)',
            self::Brochure    => 'Брошури',
            self::Diploma     => 'Дипломи/Додатки',
        };
    }

    public function isConstructor(): bool
    {
        return $this === self::Constructor;
    }

    public function isRiso(): bool
    {
        return $this === self::Riso;
    }

    public function isBrochure(): bool
    {
        return $this === self::Brochure;
    }

    public function isDiploma(): bool
    {
        return $this === self::Diploma;
    }
}

