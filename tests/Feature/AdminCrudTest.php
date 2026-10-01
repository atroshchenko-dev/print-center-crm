<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Material;
use App\Models\RisoPriceTier;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AdminCrudTest — Feature tests for Admin panel CRUD operations.
 *
 * Covers:
 * - Permission enforcement (executor denied, admin allowed)
 * - CRUD lifecycle: create, read, update, soft-delete
 * - Validation rules
 */
class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin    = User::factory()->create(['role' => 'admin']);
        $this->executor = User::factory()->create(['role' => 'executor']);
    }

    // ─── Permission Tests ────────────────────────────────

    public function test_executor_cannot_access_services_page(): void
    {
        $response = $this->actingAs($this->executor)->get(route('admin.services.index'));
        $response->assertForbidden();
    }

    public function test_executor_cannot_access_equipment_page(): void
    {
        $response = $this->actingAs($this->executor)->get(route('admin.equipment.index'));
        $response->assertForbidden();
    }

    public function test_executor_cannot_access_inventory_page(): void
    {
        $response = $this->actingAs($this->executor)->get(route('admin.inventory.index'));
        $response->assertForbidden();
    }

    public function test_executor_cannot_access_university_page(): void
    {
        $response = $this->actingAs($this->executor)->get(route('admin.university.index'));
        $response->assertForbidden();
    }

    public function test_executor_cannot_access_users_page(): void
    {
        $response = $this->actingAs($this->executor)->get(route('admin.users.index'));
        $response->assertForbidden();
    }

    public function test_admin_can_access_all_admin_pages(): void
    {
        $pages = [
            'admin.services.index',
            'admin.equipment.index',
            'admin.inventory.index',
            'admin.university.index',
            'admin.users.index',
            'admin.service-categories.index',
            'admin.materials.index',
            'admin.riso-pricing.index',
        ];

        foreach ($pages as $route) {
            $response = $this->actingAs($this->admin)->get(route($route));
            $response->assertOk("Admin should access {$route}");
        }
    }

    // ─── Service Category CRUD ───────────────────────────

    public function test_admin_can_create_service_category(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.service-categories.store'), [
            'name'          => 'Тестова категорія',
            'sort_order'    => 99,
            'is_active'     => true,
            'available_for' => ['internal', 'commercial'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('service_categories', ['name' => 'Тестова категорія']);
    }

    public function test_create_service_category_requires_name(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.service-categories.store'), [
            'sort_order'    => 1,
            'available_for' => ['internal'],
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_admin_can_update_service_category(): void
    {
        $category = ServiceCategory::create([
            'name'          => 'Стара назва',
            'sort_order'    => 1,
            'is_active'     => true,
            'available_for' => ['internal'],
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.service-categories.update', $category),
            [
                'name'          => 'Нова назва',
                'sort_order'    => 2,
                'is_active'     => true,
                'available_for' => ['internal', 'commercial'],
            ]
        );

        $response->assertRedirect();
        $this->assertEquals('Нова назва', $category->fresh()->name);
    }

    public function test_admin_can_soft_delete_service_category(): void
    {
        $category = ServiceCategory::create([
            'name'          => 'Для видалення',
            'sort_order'    => 1,
            'is_active'     => true,
            'available_for' => ['internal'],
        ]);

        $response = $this->actingAs($this->admin)->delete(
            route('admin.service-categories.destroy', $category)
        );

        $response->assertRedirect();
        $this->assertSoftDeleted('service_categories', ['id' => $category->id]);
    }

    // ─── Equipment CRUD ──────────────────────────────────

    public function test_admin_can_create_equipment(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.equipment.store'), [
            'name'            => 'Тестовий принтер',
            'type'            => 'bw',
            'is_active'       => true,
            'initial_counter' => 50000,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('equipment', ['name' => 'Тестовий принтер']);
    }

    public function test_admin_can_update_equipment(): void
    {
        $equipment = Equipment::factory()->create([
            'name' => 'Старий принтер',
            'type' => 'bw',
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.equipment.update', $equipment),
            [
                'name'            => 'Оновлений принтер',
                'type'            => 'color',
                'is_active'       => true,
                'initial_counter' => $equipment->initial_counter,
            ]
        );

        $response->assertRedirect();
        $this->assertEquals('Оновлений принтер', $equipment->fresh()->name);
    }

    public function test_admin_can_soft_delete_equipment(): void
    {
        $equipment = Equipment::factory()->create();

        $response = $this->actingAs($this->admin)->delete(
            route('admin.equipment.destroy', $equipment)
        );

        $response->assertRedirect();
        $this->assertSoftDeleted('equipment', ['id' => $equipment->id]);
    }

    // ─── Riso Pricing CRUD ───────────────────────────────

    public function test_admin_can_create_riso_tier(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.riso-pricing.store'), [
            'min_qty'       => 1000,
            'max_qty'       => 2000,
            'cost_per_copy' => 0.15,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('riso_price_tiers', ['min_qty' => 1000]);
    }

    public function test_admin_can_soft_delete_riso_tier(): void
    {
        $tier = RisoPriceTier::create([
            'min_qty' => 9999,
            'max_qty' => null,
            'cost_per_copy' => 0.01,
        ]);

        $response = $this->actingAs($this->admin)->delete(
            route('admin.riso-pricing.destroy', $tier)
        );

        $response->assertRedirect();
        $this->assertSoftDeleted('riso_price_tiers', ['id' => $tier->id]);
    }

    // ─── Guest Access ────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $routes = [
            'admin.services.index',
            'admin.equipment.index',
            'admin.users.index',
        ];

        foreach ($routes as $route) {
            $response = $this->get(route($route));
            $response->assertRedirect(route('login'));
        }
    }

    // ─── Manager with specific permissions ───────────────

    public function test_manager_with_services_permission_can_access_services(): void
    {
        $manager = User::factory()->create([
            'role'        => 'manager',
            'permissions' => ['orders', 'ledger', 'reports', 'services'],
        ]);

        $response = $this->actingAs($manager)->get(route('admin.services.index'));
        $response->assertOk();
    }

    public function test_manager_without_equipment_permission_is_denied(): void
    {
        $manager = User::factory()->create([
            'role'        => 'manager',
            'permissions' => ['orders', 'ledger', 'reports', 'services'],
        ]);

        $response = $this->actingAs($manager)->get(route('admin.equipment.index'));
        $response->assertForbidden();
    }
}
