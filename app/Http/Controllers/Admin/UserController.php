<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users'            => User::withTrashed()->latest()->get(),
            'availableModules' => User::MODULES,
            'roleDefaults'     => [
                'admin'    => UserRole::Admin->defaultPermissions(),
                'executor' => UserRole::Executor->defaultPermissions(),
                'manager'  => UserRole::Manager->defaultPermissions(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Password is auto-hashed by User model's 'hashed' cast

        // Apply role defaults if no permissions explicitly provided
        if (! isset($data['permissions']) || empty($data['permissions'])) {
            $data['permissions'] = UserRole::from($data['role'])->defaultPermissions();
        }

        User::create($data);

        return back()->with('success', 'Користувача додано.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        // Password is auto-hashed by User model's 'hashed' cast
        if (empty($data['password'])) {
            unset($data['password']);
        }

        // Self-elevation protection: cannot change own role or permissions
        if ($user->id === auth()->id()) {
            unset($data['role'], $data['permissions']);
        } else {
            $data['permissions'] = $data['permissions'] ?? [];
        }

        // Track changes for audit.
        // `role` is cast to a UserRole enum but arrives as a string, and the
        // permission arrays carry no meaningful order — so both sides have to
        // be reduced to the same shape before asking whether anything moved.
        // These entries are immutable once written; a false one cannot be
        // taken back.
        $oldPermissions = $user->permissions ?? [];
        $oldRole = $user->role;

        $permissionsChanged = isset($data['permissions'])
            && $this->normalisePermissions($oldPermissions) !== $this->normalisePermissions($data['permissions']);

        $roleChanged = isset($data['role']) && $oldRole->value !== $data['role'];

        $user->update($data);

        // Audit log if permissions changed
        if ($permissionsChanged) {
            AuditLog::record(
                'permissions_changed',
                auth()->user(),
                "Змінено доступ для {$user->name}",
                meta: [
                    'target_user_id'  => $user->id,
                    'old_permissions' => $oldPermissions,
                    'new_permissions' => $data['permissions'],
                ]
            );
        }

        if ($roleChanged) {
            AuditLog::record(
                'role_changed',
                auth()->user(),
                "Змінено роль {$user->name}: {$oldRole->value} → {$data['role']}",
                meta: [
                    'target_user_id' => $user->id,
                    'old_role'       => $oldRole->value,
                    'new_role'       => $data['role'],
                ]
            );
        }

        return back()->with('success', 'Користувача оновлено.');
    }

    /**
     * Order-independent, duplicate-free view of a permission list.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private function normalisePermissions(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->id === auth()->id(), 403, 'Не можна деактивувати себе.');

        $user->delete(); // Soft delete

        AuditLog::record(
            'user_deactivated',
            auth()->user(),
            "Деактивовано користувача {$user->name}",
            meta: ['target_user_id' => $user->id]
        );

        return back()->with('success', 'Користувача деактивовано.');
    }

    public function restore(int $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();

        AuditLog::record(
            'user_restored',
            auth()->user(),
            "Відновлено користувача {$user->name}",
            meta: ['target_user_id' => $user->id]
        );

        return back()->with('success', 'Користувача відновлено.');
    }
}
