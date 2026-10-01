<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Department;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OrderCreationServiceTest — verifies the full order creation pipeline.
 * Covers: order numbering, item snapshot, totals, cost center auto-save, limits.
 */
class OrderCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderCreationService $service;
    private User $user;
    private Shift $shift;
    private Service $constructorService;
    private ServiceParameterOption $option1;
    private ServiceParameterOption $option2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrderCreationService::class);
        $this->user    = User::factory()->create(['role' => 'admin']);
        $this->shift   = Shift::factory()->create(['opened_by' => $this->user->id]);

        // Seed material for cost computation
        Material::factory()->create([
            'name'         => 'BW Click',
            'click_cost'   => 0.12,
            'counter_type' => 'bw',
        ]);

        // Build a constructor service with 2 parameter groups + options
        $category = ServiceCategory::factory()->create();

        $this->constructorService = Service::factory()->constructor()->create([
            'service_category_id'   => $category->id,
            'base_price_commercial' => 0,
            'base_price_cost'       => 0,
        ]);

        $group1 = ServiceParameterGroup::factory()->create([
            'service_id' => $this->constructorService->id,
            'name'       => 'Формат',
            'sort_order' => 0,
        ]);

        $group2 = ServiceParameterGroup::factory()->create([
            'service_id' => $this->constructorService->id,
            'name'       => 'Папір',
            'sort_order' => 1,
        ]);

        $this->option1 = ServiceParameterOption::factory()->create([
            'group_id'       => $group1->id,
            'name'           => 'А4',
            'price_markup'   => 2.50,
            'counter_type'   => 'bw',
            'clicks_per_unit'=> 1,
        ]);

        $this->option2 = ServiceParameterOption::factory()->create([
            'group_id'       => $group2->id,
            'name'           => '80г',
            'price_markup'   => 0.50,
            'counter_type'   => 'none',
            'clicks_per_unit'=> 0,
        ]);
    }

    // ─── Happy path ─────────────────────────────────────

    public function test_creates_internal_order_with_correct_number(): void
    {
        $order = $this->service->createOrder([
            'type'              => 'internal',
            'authorized_person' => 'Іваненко І.І.',
            'cost_center'       => 'Кафедра фізики',
            'items'             => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 10,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
            ],
        ], $this->shift, $this->user);

        $this->assertNotNull($order->id);
        $this->assertStringStartsWith('INT-', $order->order_number);
        $this->assertEquals(OrderStatus::New, $order->status);
        $this->assertEquals(OrderType::Internal, $order->type);
    }

    public function test_creates_commercial_order_with_items(): void
    {
        $order = $this->service->createOrder([
            'type'  => 'commercial',
            'items' => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 5,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
            ],
        ], $this->shift, $this->user);

        $this->assertEquals(OrderType::Commercial, $order->type);
        $this->assertCount(1, OrderItem::where('order_id', $order->id)->get());
    }

    public function test_order_items_have_jsonb_snapshot(): void
    {
        $order = $this->service->createOrder([
            'type'  => 'commercial',
            'items' => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 1,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
            ],
        ], $this->shift, $this->user);

        $item = OrderItem::where('order_id', $order->id)->first();
        $snapshot = $item->service_snapshot;

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('constructor_snapshot', $snapshot);
        $this->assertArrayHasKey('hardware_counters', $snapshot);
        $this->assertArrayHasKey('unit_price_commercial', $snapshot);
        $this->assertArrayHasKey('unit_price_cost', $snapshot);
    }

    public function test_totals_are_sum_of_item_totals(): void
    {
        $order = $this->service->createOrder([
            'type'  => 'commercial',
            'items' => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 10,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
            ],
        ], $this->shift, $this->user);

        $itemsTotal = OrderItem::where('order_id', $order->id)->sum('total_price_commercial');

        $order->refresh();
        $this->assertEquals((float) $itemsTotal, (float) $order->total_commercial);
        $this->assertGreaterThan(0, (float) $order->total_commercial);
    }

    // ─── Business rules ─────────────────────────────────

    public function test_auto_saves_new_cost_center_to_departments(): void
    {
        $costCenter = 'Кафедра інформатики ' . uniqid();

        $this->service->createOrder([
            'type'              => 'internal',
            'authorized_person' => 'Тестова Особа',
            'cost_center'       => $costCenter,
            'items'             => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 1,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
            ],
        ], $this->shift, $this->user);

        $this->assertDatabaseHas('departments', ['name' => $costCenter]);
    }

    public function test_does_not_duplicate_existing_cost_center(): void
    {
        $costCenter = 'Існуючий підрозділ';
        Department::create(['name' => $costCenter, 'type' => 'department', 'is_active' => true]);

        $initialCount = Department::where('name', $costCenter)->count();

        $this->service->createOrder([
            'type'              => 'internal',
            'authorized_person' => 'Тестова Особа',
            'cost_center'       => $costCenter,
            'items'             => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 1,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
            ],
        ], $this->shift, $this->user);

        $this->assertEquals($initialCount, Department::where('name', $costCenter)->count());
    }

    public function test_skips_limits_for_commercial_orders(): void
    {
        $order = $this->service->createOrder([
            'type'  => 'commercial',
            'items' => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 1000,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
            ],
        ], $this->shift, $this->user);

        $order->refresh();
        $this->assertFalse($order->limit_exceeded);
    }

    // ─── Edge cases ─────────────────────────────────────

    public function test_handles_material_description_per_item(): void
    {
        $order = $this->service->createOrder([
            'type'              => 'internal',
            'authorized_person' => 'Тестова Особа',
            'cost_center'       => 'Тестовий підрозділ',
            'items'             => [
                [
                    'service_id'           => $this->constructorService->id,
                    'quantity'             => 5,
                    'selected_option_ids'  => [$this->option1->id, $this->option2->id],
                    'material_description' => 'Дипломна робота',
                ],
            ],
        ], $this->shift, $this->user);

        $item = OrderItem::where('order_id', $order->id)->first();
        $this->assertEquals('Дипломна робота', $item->material_description);
    }

    public function test_throws_if_service_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->service->createOrder([
            'type'  => 'commercial',
            'items' => [
                [
                    'service_id'         => 99999,
                    'quantity'           => 1,
                    'selected_option_ids'=> [],
                ],
            ],
        ], $this->shift, $this->user);
    }

    public function test_creates_multiple_items_in_single_order(): void
    {
        $order = $this->service->createOrder([
            'type'  => 'commercial',
            'items' => [
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 5,
                    'selected_option_ids'=> [$this->option1->id, $this->option2->id],
                ],
                [
                    'service_id'         => $this->constructorService->id,
                    'quantity'           => 3,
                    'selected_option_ids'=> [$this->option1->id],
                ],
            ],
        ], $this->shift, $this->user);

        $this->assertCount(2, OrderItem::where('order_id', $order->id)->get());
    }
}
