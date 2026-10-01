<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User Model
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_active
 * @property array $permissions
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    /**
     * Every grantable module: key => what a person calls it. **The** list.
     *
     * It used to be seven lists. The keys were written out again in
     * `UserController::MODULE_LABELS`, again in `UserRole::Admin->
     * defaultPermissions()`, again as the literal `/8` in the sidebar footer,
     * again as `adminModules` in `AppLayout`. User management itself is
     * `role:admin`, not a grantable module.
     *
     * `users` is gone from here because it granted nothing. No route has ever
     * asked for it: `/admin/users` is `role:admin`, deliberately — the module
     * would let its holder create an account with role=admin and a password of
     * their choosing, which is full access by the back door, and the
     * self-elevation guard does not reach it. But the checkbox was still
     * offered on the users page and the sidebar still drew «Користувачі» for
     * anyone holding it, so the one thing it could do was promise a screen and
     * then answer 403.
     *
     * `PermissionsAreGrantedByOneListTest` holds the keys against the route
     * table, so a module that gates nothing cannot be added back in silence.
     */
    public const MODULES = [
        'orders'     => 'Замовлення',
        'ledger'     => 'Каса',
        'reports'    => 'Звіти',
        'services'   => 'Прайс-лист',
        'equipment'  => 'Обладнання',
        'inventory'  => 'Склад',
        'university' => 'Університет',
    ];

    /**
     * Keys of MODULES — what may be stored in the `permissions` column.
     *
     * @return array<int, string>
     */
    public static function permissionKeys(): array
    {
        return array_keys(self::MODULES);
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'role'              => UserRole::class,
        'is_active'         => 'boolean',
        'permissions'       => 'array',
    ];

    // ─── Role Helpers ────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isExecutor(): bool
    {
        return $this->role === UserRole::Executor;
    }

    /**
     * Check if the user has permission for a given module.
     * Admin always has full access (bypass).
     */
    public function hasPermission(string $module): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($module, $this->permissions ?? [], true);
    }

    public function canViewCosts(): bool
    {
        return $this->isAdmin() || $this->hasPermission('services');
    }

    public function canViewReports(): bool
    {
        return $this->hasPermission('reports');
    }

    // ─── Relationships ───────────────────────────────────

    public function shiftsOpened(): HasMany
    {
        return $this->hasMany(Shift::class, 'opened_by');
    }

    public function shiftsClosed(): HasMany
    {
        return $this->hasMany(Shift::class, 'closed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
