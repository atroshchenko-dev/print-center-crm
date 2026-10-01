<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PermissionWorkflowTest — Feature tests for granular module permissions.
 *
 * Covers:
 * - Admin bypass (always full access)
 * - Permission-based route access (403 when denied)
 * - Self-elevation protection
 * - Audit logging on permission/role changes
 * - EnsureActive middleware
 * - User restore (un-delete)
 */
class PermissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);

        $this->executor = User::factory()->create([
            'role'        => 'executor',
            'permissions' => ['orders', 'ledger'],
        ]);
    }

    // ─── Admin Bypass ─────────────────────────────────────

    public function test_admin_can_access_all_routes(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.services.index'))->assertOk();
        $this->get(route('admin.equipment.index'))->assertOk();
        $this->get(route('admin.inventory.index'))->assertOk();
        $this->get(route('admin.university.index'))->assertOk();
        $this->get(route('admin.users.index'))->assertOk();
        $this->get(route('reports.internal'))->assertOk();
    }

    // ─── Permission Enforcement ───────────────────────────

    public function test_executor_without_reports_gets_403(): void
    {
        $this->actingAs($this->executor);

        $this->get(route('reports.internal'))->assertForbidden();
        $this->get(route('reports.commercial'))->assertForbidden();
        $this->get(route('reports.audit'))->assertForbidden();
    }

    public function test_executor_without_services_gets_403(): void
    {
        $this->actingAs($this->executor);

        $this->get(route('admin.services.index'))->assertForbidden();
    }

    /**
     * Renamed in round 36. It used to be `test_executor_without_users_gets_403`
     * — a name that reads as proof of a rule the server never had. There is no
     * `users` module: `/admin/users` is `role:admin`, so an executor is refused
     * whatever their permission column says, and the old name invited the
     * reader to believe granting `users` would have opened it.
     */
    public function test_an_executor_is_refused_user_management_by_role(): void
    {
        $this->actingAs($this->executor);

        $this->get(route('admin.users.index'))->assertForbidden();

        // The same executor, handed every module there is, is still refused.
        $this->executor->update(['permissions' => User::permissionKeys()]);
        $this->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_executor_with_reports_permission_can_access(): void
    {
        $this->executor->update([
            'permissions' => ['orders', 'ledger', 'reports'],
        ]);

        $this->actingAs($this->executor);
        $this->get(route('reports.internal'))->assertOk();
    }

    // ─── Orders/Ledger Permission Enforcement ─────────────

    public function test_executor_without_orders_permission_gets_403(): void
    {
        // Create open shift so EnsureShiftIsOpen passes
        Shift::factory()->today()->create(['status' => 'open', 'opened_by' => $this->executor->id]);

        $this->executor->update(['permissions' => ['ledger']]);
        $this->actingAs($this->executor);

        $this->get(route('orders.index'))->assertForbidden();
    }

    public function test_executor_without_ledger_permission_gets_403(): void
    {
        // Create open shift so EnsureShiftIsOpen passes
        Shift::factory()->today()->create(['status' => 'open', 'opened_by' => $this->executor->id]);

        $this->executor->update(['permissions' => ['orders']]);
        $this->actingAs($this->executor);

        $this->get(route('ledger.history'))->assertForbidden();
    }

    // ─── Self-Elevation Protection ────────────────────────

    public function test_cannot_change_own_role(): void
    {
        $this->actingAs($this->admin);

        $this->patch(route('admin.users.update', $this->admin), [
            'name'        => $this->admin->name,
            'email'       => $this->admin->email,
            'role'        => 'executor',
            'is_active'   => true,
            'permissions' => ['orders'],
        ]);

        $this->admin->refresh();
        $this->assertEquals('admin', $this->admin->role->value);
    }

    public function test_cannot_change_own_permissions(): void
    {
        $this->actingAs($this->admin);
        $originalPermissions = $this->admin->permissions;

        $this->patch(route('admin.users.update', $this->admin), [
            'name'        => $this->admin->name,
            'email'       => $this->admin->email,
            'role'        => 'admin',
            'is_active'   => true,
            'permissions' => ['orders'],
        ]);

        $this->admin->refresh();
        $this->assertEquals($originalPermissions, $this->admin->permissions);
    }

    public function test_cannot_deactivate_self(): void
    {
        $this->actingAs($this->admin);

        $this->delete(route('admin.users.destroy', $this->admin))
            ->assertForbidden();

        $this->admin->refresh();
        $this->assertNull($this->admin->deleted_at);
    }

    // ─── CRUD ─────────────────────────────────────────────

    public function test_admin_can_update_other_user_permissions(): void
    {
        $this->actingAs($this->admin);

        $this->patch(route('admin.users.update', $this->executor), [
            'name'        => $this->executor->name,
            'email'       => $this->executor->email,
            'role'        => 'executor',
            'is_active'   => true,
            'permissions' => ['orders', 'ledger', 'reports', 'equipment'],
        ]);

        $this->executor->refresh();
        $this->assertEquals(['orders', 'ledger', 'reports', 'equipment'], $this->executor->permissions);
    }

    // ─── Audit Logging ────────────────────────────────────

    public function test_permission_change_creates_audit_log(): void
    {
        $this->actingAs($this->admin);

        $this->patch(route('admin.users.update', $this->executor), [
            'name'        => $this->executor->name,
            'email'       => $this->executor->email,
            'role'        => 'executor',
            'is_active'   => true,
            'permissions' => ['orders', 'ledger', 'services'],
        ]);

        $log = AuditLog::where('event_type', 'permissions_changed')->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->admin->id, $log->user_id);
        $this->assertStringContainsString($this->executor->name, $log->description);
    }

    public function test_role_change_creates_audit_log(): void
    {
        $this->actingAs($this->admin);

        $this->patch(route('admin.users.update', $this->executor), [
            'name'        => $this->executor->name,
            'email'       => $this->executor->email,
            'role'        => 'manager',
            'is_active'   => true,
            'permissions' => ['orders', 'ledger', 'reports'],
        ]);

        $log = AuditLog::where('event_type', 'role_changed')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('executor', $log->meta['old_role']);
        $this->assertStringContainsString('manager', $log->meta['new_role']);
    }

    /**
     * The other half of the above, and the half that was missing.
     *
     * `$user->role` is cast to a UserRole enum while the validated input is a
     * plain string, so `$oldRole !== $data['role']` compared an enum to a
     * string and was true every single time. Saving a user's name wrote a
     * permanent "role changed: executor → executor" entry into a table that
     * a database trigger makes it impossible to clean up afterwards.
     */
    public function test_saving_a_user_without_changing_their_role_logs_nothing(): void
    {
        $this->actingAs($this->admin);

        $this->patch(route('admin.users.update', $this->executor), [
            'name'        => 'Нове імʼя',
            'email'       => $this->executor->email,
            'role'        => $this->executor->role->value,
            'is_active'   => true,
            'permissions' => $this->executor->permissions,
        ]);

        $this->assertEquals('Нове імʼя', $this->executor->fresh()->name);

        $this->assertSame(
            0,
            AuditLog::where('event_type', 'role_changed')->count(),
            'The role did not change, so nothing should claim it did.',
        );

        $this->assertSame(
            0,
            AuditLog::where('event_type', 'permissions_changed')->count(),
            'The permissions did not change either.',
        );
    }

    /**
     * The same permissions in a different order are the same permissions.
     */
    public function test_reordering_the_same_permissions_is_not_a_change(): void
    {
        $this->actingAs($this->admin);
        $this->executor->update(['permissions' => ['orders', 'ledger', 'reports']]);

        $this->patch(route('admin.users.update', $this->executor), [
            'name'        => $this->executor->name,
            'email'       => $this->executor->email,
            'role'        => $this->executor->role->value,
            'is_active'   => true,
            'permissions' => ['reports', 'orders', 'ledger'],
        ]);

        $this->assertSame(
            0,
            AuditLog::where('event_type', 'permissions_changed')->count(),
        );
    }

    // ─── hasPermission Model Method ───────────────────────

    public function test_has_permission_returns_true_for_admin(): void
    {
        // Admin should have access to any module, even unlisted ones
        $this->assertTrue($this->admin->hasPermission('reports'));
        $this->assertTrue($this->admin->hasPermission('some_future_module'));
    }

    public function test_has_permission_checks_array_for_non_admin(): void
    {
        $this->assertTrue($this->executor->hasPermission('orders'));
        $this->assertTrue($this->executor->hasPermission('ledger'));
        $this->assertFalse($this->executor->hasPermission('reports'));
        $this->assertFalse($this->executor->hasPermission('users'));
    }

    // ─── EnsureActive Middleware ───────────────────────────

    public function test_deactivated_user_is_logged_out(): void
    {
        $this->executor->update(['is_active' => false]);
        $this->actingAs($this->executor);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    // ─── User Restore ─────────────────────────────────────

    public function test_admin_can_restore_deleted_user(): void
    {
        $this->actingAs($this->admin);

        // Soft delete the executor
        $this->executor->delete();
        $this->assertSoftDeleted($this->executor);

        // Restore via endpoint
        $this->patch(route('admin.users.restore', $this->executor->id))
            ->assertRedirect();

        $this->executor->refresh();
        $this->assertNull($this->executor->deleted_at);

        // Verify audit log
        $log = AuditLog::where('event_type', 'user_restored')->latest()->first();
        $this->assertNotNull($log);
    }
}
