<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;
    private User $user;
    private InventoryCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = app(InventoryService::class);
        $this->user     = User::factory()->create(['role' => 'admin']);
        $this->category = InventoryCategory::factory()->create();
    }

    // ─── receiveStock ────────────────────────────────────

    public function test_receive_stock_increases_quantity(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 100,
            'avg_cost'              => 1.00,
        ]);

        $this->service->receiveStock($item, 50, 75.00, $this->user, 'Test receipt');

        $item->refresh();
        $this->assertEquals(150, (float) $item->current_quantity);
    }

    public function test_receive_stock_calculates_avco_correctly(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 100,
            'avg_cost'              => 1.00,
        ]);

        // AVCO: (100*1.00 + 100) / (100 + 50) = 200 / 150 = 1.3333
        $this->service->receiveStock($item, 50, 100.00, $this->user);

        $item->refresh();
        $this->assertEquals(1.3333, (float) $item->avg_cost);
    }

    public function test_receive_stock_creates_movement(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 0,
            'avg_cost'              => 0,
        ]);

        $movement = $this->service->receiveStock($item, 200, 400.00, $this->user, 'Delivery');

        $this->assertEquals('in', $movement->type);
        $this->assertEquals(200, (float) $movement->quantity);
        $this->assertEquals(2.00, (float) $movement->unit_cost);
        $this->assertEquals(400.00, (float) $movement->total_cost);
    }

    public function test_receive_stock_on_empty_item_sets_initial_avco(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 0,
            'avg_cost'              => 0,
        ]);

        $this->service->receiveStock($item, 100, 250.00, $this->user);

        $item->refresh();
        $this->assertEquals(100, (float) $item->current_quantity);
        $this->assertEquals(2.50, (float) $item->avg_cost);
    }

    // ─── Movement Immutability ───────────────────────────

    public function test_movement_cannot_be_updated(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 0,
            'avg_cost'              => 0,
        ]);

        $movement = $this->service->receiveStock($item, 10, 50.00, $this->user);

        $this->expectException(\RuntimeException::class);
        $movement->update(['quantity' => 999]);
    }

    public function test_movement_cannot_be_deleted(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 0,
            'avg_cost'              => 0,
        ]);

        $movement = $this->service->receiveStock($item, 10, 50.00, $this->user);

        $this->expectException(\RuntimeException::class);
        $movement->delete();
    }

    public function test_movement_cannot_be_force_deleted(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 0,
            'avg_cost'              => 0,
        ]);

        $movement = $this->service->receiveStock($item, 10, 50.00, $this->user);

        $this->expectException(\RuntimeException::class);
        $movement->forceDelete();
    }

    // ─── returnStockForOrder ─────────────────────────────

    public function test_return_stock_restores_quantity(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 50,
            'avg_cost'              => 2.00,
        ]);

        $order = \App\Models\Order::factory()->create();

        // Create an OrderItem with snapshot so returnStockForOrder knows what to return
        \App\Models\OrderItem::create([
            'order_id'               => $order->id,
            'service_name'           => 'Test Service',
            'quantity'               => 1,
            'unit_price_commercial'  => 40.00,
            'unit_price_cost'        => 40.00,
            'total_price_commercial' => 40.00,
            'total_price_cost'       => 40.00,
            'bw_clicks'              => 0,
            'color_clicks'           => 0,
            'riso_clicks'            => 0,
            'service_snapshot'       => [
                'constructor_snapshot' => [
                    [
                        'inventory_item_id' => $item->id,
                        'inventory_qty'     => 20,
                    ],
                ],
            ],
        ]);

        // Create a manual auto_deduct movement to simulate a previous deduction
        InventoryMovement::forceCreate([
            'inventory_item_id' => $item->id,
            'type'              => 'auto_deduct',
            'quantity'          => -20,
            'unit_cost'         => 2.00,
            'total_cost'        => 40.00,
            'reference_type'    => \App\Models\Order::class,
            'reference_id'      => $order->id,
            'user_id'           => $this->user->id,
            'notes'             => 'Test deduction',
            'created_at'        => now(),
        ]);

        $this->service->returnStockForOrder($order, $this->user);

        $item->refresh();
        $this->assertEquals(70, (float) $item->current_quantity);
    }


    // ─── Multiple Receipts AVCO ──────────────────────────

    public function test_multiple_receipts_accumulate_avco(): void
    {
        $item = InventoryItem::factory()->create([
            'inventory_category_id' => $this->category->id,
            'current_quantity'      => 0,
            'avg_cost'              => 0,
        ]);

        // Receipt 1: 100 units @ 1.00 each = 100.00
        $this->service->receiveStock($item, 100, 100.00, $this->user);
        $item->refresh();
        $this->assertEquals(100, (float) $item->current_quantity);
        $this->assertEquals(1.00, (float) $item->avg_cost);

        // Receipt 2: 100 units @ 3.00 each = 300.00
        // AVCO: (100*1.00 + 300) / (100 + 100) = 400 / 200 = 2.00
        $this->service->receiveStock($item, 100, 300.00, $this->user);
        $item->refresh();
        $this->assertEquals(200, (float) $item->current_quantity);
        $this->assertEquals(2.00, (float) $item->avg_cost);
    }
}
