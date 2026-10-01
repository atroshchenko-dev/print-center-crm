<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;

enum UserRole: string
{
    case Admin = 'admin';
    case Executor = 'executor';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Admin    => 'Адміністратор',
            self::Executor => 'Виконавець',
            self::Manager  => 'Менеджер',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function isExecutor(): bool
    {
        return $this === self::Executor;
    }

    /**
     * Default permissions granted when creating a user with this role.
     *
     * @return string[]
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            // Not a copy of the list: an admin gets whatever the list holds
            // today. The hand-written copy that used to stand here still
            // carried `users`, a module no route has ever asked for.
            self::Admin    => User::permissionKeys(),
            self::Executor => ['orders', 'ledger'],
            self::Manager  => ['orders', 'ledger', 'reports'],
        };
    }
}
