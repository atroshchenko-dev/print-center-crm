<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConstructorControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Service $constructorService;

    private Service $staticService;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => UserRole::Admin,
            'permissions' => User::permissionKeys(),
        ]);

        // Open shift for today
        $this->shift = Shift::create([
            'date'       => today('Europe/Kyiv'),
            'status'     => 'open',
            'opened_by'  => $this->admin->id,
            'cash_start' => 0,
        ]);

        $category = ServiceCategory::create(['name' => 'Ризограф', 'sort_order' => 1]);

        $this->constructorService = Service::create([
            'name'        => 'Ризограф Тест',
            'type'        => ServiceType::Constructor,
            'category_id' => $category->id,
            'base_price'  => 0,
            'is_active'   => true,
        ]);

        $this->staticService = Service::create([
            'name'        => 'Друк А4',
            'type'        => ServiceType::Static,
            'category_id' => $category->id,
            'base_price'  => 5.00,
            'is_active'   => true,
        ]);
    }

    public function test_returns_template_for_constructor_service(): void
    {
        $group = ServiceParameterGroup::create([
            'service_id' => $this->constructorService->id,
            'name'       => 'Формат',
            'sort_order' => 1,
            'ui_style'   => 'chips',
        ]);

        ServiceParameterOption::create([
            'group_id'            => $group->id,
            'name'                => 'A3',
            'cost_modifier'       => 0,
            'commercial_modifier' => 0,
            'is_active'           => true,
            'sort_order'          => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('constructor.template', $this->constructorService));

        // Constructor/Panel Vue page may not exist in CI (no frontend build).
        // Verify route is accessible and returns Inertia response.
        $response->assertStatus(200);
    }

    public function test_returns_404_for_non_constructor_service(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('constructor.template', $this->staticService));

        $response->assertStatus(404);
    }

    public function test_calculates_price_for_valid_request(): void
    {
        $group = ServiceParameterGroup::create([
            'service_id' => $this->constructorService->id,
            'name'       => 'Папір',
            'sort_order' => 1,
            'ui_style'   => 'chips',
        ]);

        $option = ServiceParameterOption::create([
            'group_id'            => $group->id,
            'name'                => '80г/м²',
            'cost_modifier'       => 1.50,
            'commercial_modifier' => 3.00,
            'is_active'           => true,
            'sort_order'          => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('constructor.calculate'), [
                'service_id'          => $this->constructorService->id,
                'quantity'            => 10,
                'selected_option_ids' => [$option->id],
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'unit_price_commercial',
            'unit_price_cost',
            'total_price_commercial',
            'total_price_cost',
        ]);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('constructor.calculate'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['service_id', 'quantity']);
    }

    public function test_rejects_non_constructor_service_for_calculate(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('constructor.calculate'), [
                'service_id'          => $this->staticService->id,
                'quantity'            => 10,
                'selected_option_ids' => [],
            ]);

        $response->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('constructor.template', $this->constructorService));
        $response->assertRedirect(route('login'));
    }

    /**
     * The calculate response carries cost price (unit_price_cost), so it must not be
     * reachable by a logged-in user who has no business seeing the orders module.
     */
    public function test_calculate_is_denied_without_orders_permission(): void
    {
        $accountant = User::factory()->create([
            'role'        => UserRole::Executor,
            'permissions' => ['reports'],
        ]);

        $response = $this->actingAs($accountant)
            ->postJson(route('constructor.calculate'), [
                'service_id'          => $this->constructorService->id,
                'quantity'            => 10,
                'selected_option_ids' => [],
            ]);

        $response->assertStatus(403);
    }

    /**
     * Retro orders are admin-only and are entered with no shift open. An admin who
     * does not hold the 'orders' module must still get through — EnsurePermission
     * lets admins pass — otherwise gating this route would break the retro constructor.
     */
    public function test_calculate_still_open_to_admin_without_orders_module(): void
    {
        $admin = User::factory()->create([
            'role'        => UserRole::Admin,
            'permissions' => ['reports'],
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('constructor.calculate'), [
                'service_id'          => $this->constructorService->id,
                'quantity'            => 10,
                'selected_option_ids' => [],
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['unit_price_cost']);
    }

    public function test_calculate_allowed_for_operator_with_orders_permission(): void
    {
        $operator = User::factory()->create([
            'role'        => UserRole::Executor,
            'permissions' => ['orders'],
        ]);

        $response = $this->actingAs($operator)
            ->postJson(route('constructor.calculate'), [
                'service_id'          => $this->constructorService->id,
                'quantity'            => 10,
                'selected_option_ids' => [],
            ]);

        $response->assertOk();
    }
}
