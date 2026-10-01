<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Shift;
use App\Models\User;
use App\Rules\ServiceAvailableForOrderType;
use App\Services\OrderItemBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * OrderPricingIntegrityTest — prices must come from the pricing services,
 * never from whatever the client happens to send.
 *
 * Two audit findings are pinned here:
 *
 * H-7  items.*.existing_snapshot was accepted as an arbitrary array and its
 *      unit_price_* values flowed into the order totals, getPayableAmount()
 *      and the cash ledger. It also carries inventory_deductions.
 *
 * M-9  ServiceCategory::available_for only filtered the operator's UI.
 *      Internal-only categories price commercial orders at 0, so an order
 *      built outside the interface could be booked commercially for nothing.
 */
class OrderPricingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ─── H-7: snapshot may not be dictated by the client ──

    public function test_a_forged_snapshot_is_rejected_and_rebuilt(): void
    {
        $order   = Order::factory()->create();
        $service = Service::factory()->create(['type' => 'static']);

        $stored = OrderItem::factory()->create([
            'order_id'               => $order->id,
            'service_id'             => $service->id,
            'unit_price_cost'        => 100.00,
            'total_price_cost'       => 100.00,
            'unit_price_commercial'  => 200.00,
            'total_price_commercial' => 200.00,
            'service_snapshot'       => [
                'service_id'             => $service->id,
                'service_name'           => $service->name,
                'service_type'           => 'static',
                'quantity'               => 1,
                'unit_price_cost'        => 100.00,
                'total_price_cost'       => 100.00,
                'unit_price_commercial'  => 200.00,
                'total_price_commercial' => 200.00,
                'hardware_counters'      => ['bw_clicks' => 0, 'color_clicks' => 0, 'riso_clicks' => 0],
            ],
        ]);

        // Same shape, but the prices have been edited down to almost nothing.
        $forged = $stored->service_snapshot;
        $forged['unit_price_cost']        = 0.01;
        $forged['total_price_cost']       = 0.01;
        $forged['unit_price_commercial']  = 0.01;
        $forged['total_price_commercial'] = 0.01;

        Log::spy();

        [$totalCommercial, $totalCost] = app(OrderItemBuilder::class)->buildItems(
            $order,
            [[
                'service_id'        => $service->id,
                'quantity'          => 1,
                'existing_snapshot' => $forged,
            ]],
            supportExistingSnapshot: true,
        );

        $this->assertNotEqualsWithDelta(0.01, $totalCost, 0.001, 'A forged snapshot must not set the order total.');
        $this->assertNotEqualsWithDelta(0.01, $totalCommercial, 0.001, 'A forged snapshot must not set the order total.');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'untrusted order item snapshot'))
            ->once();
    }

    public function test_the_orders_own_snapshot_is_still_reused(): void
    {
        $order   = Order::factory()->create();
        $service = Service::factory()->create(['type' => 'static']);

        $snapshot = [
            'service_id'             => $service->id,
            'service_name'           => $service->name,
            'service_type'           => 'static',
            'quantity'               => 1,
            'unit_price_cost'        => 42.50,
            'total_price_cost'       => 42.50,
            'unit_price_commercial'  => 85.00,
            'total_price_commercial' => 85.00,
            'hardware_counters'      => ['bw_clicks' => 0, 'color_clicks' => 0, 'riso_clicks' => 0],
        ];

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $service->id,
            'service_snapshot' => $snapshot,
        ]);

        [$totalCommercial, $totalCost] = app(OrderItemBuilder::class)->buildItems(
            $order,
            [[
                'service_id'        => $service->id,
                'quantity'          => 1,
                'existing_snapshot' => $snapshot,
            ]],
            supportExistingSnapshot: true,
        );

        $this->assertEqualsWithDelta(42.50, $totalCost, 0.001);
        $this->assertEqualsWithDelta(85.00, $totalCommercial, 0.001);
    }

    public function test_reused_snapshot_still_scales_with_quantity(): void
    {
        $order   = Order::factory()->create();
        $service = Service::factory()->create(['type' => 'static']);

        $snapshot = [
            'service_id'             => $service->id,
            'service_name'           => $service->name,
            'service_type'           => 'static',
            'quantity'               => 1,
            'unit_price_cost'        => 10.00,
            'total_price_cost'       => 10.00,
            'unit_price_commercial'  => 20.00,
            'total_price_commercial' => 20.00,
            'hardware_counters'      => ['bw_clicks' => 2, 'color_clicks' => 0, 'riso_clicks' => 0],
        ];

        OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $service->id,
            'service_snapshot' => $snapshot,
        ]);

        [$totalCommercial, $totalCost] = app(OrderItemBuilder::class)->buildItems(
            $order,
            [[
                'service_id'        => $service->id,
                'quantity'          => 5,
                'existing_snapshot' => $snapshot,
            ]],
            supportExistingSnapshot: true,
        );

        $this->assertEqualsWithDelta(50.00, $totalCost, 0.001);
        $this->assertEqualsWithDelta(100.00, $totalCommercial, 0.001);
    }

    // ─── M-9: availability is enforced server-side ────────

    public function test_internal_only_service_cannot_be_ordered_commercially(): void
    {
        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal'],
            'is_active'     => true,
        ]);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'type'                => 'static',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        Shift::factory()->today()->create(['status' => 'open', 'opened_by' => $admin->id]);
        $this->actingAs($admin);

        $this->postJson(route('orders.store'), [
            'type'  => 'commercial',
            'items' => [[
                'service_id' => $service->id,
                'quantity'   => 1,
            ]],
        ])->assertJsonValidationErrors('items.0.service_id');
    }

    public function test_the_same_service_passes_for_an_internal_order(): void
    {
        // Checked at the rule rather than over HTTP: a valid order redirects
        // instead of returning JSON, which would tell us nothing about the
        // rule itself.
        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal'],
            'is_active'     => true,
        ]);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'type'                => 'static',
        ]);

        $failures = [];
        $collect  = function (string $message) use (&$failures): void {
            $failures[] = $message;
        };

        (new ServiceAvailableForOrderType('internal'))
            ->validate('items.0.service_id', $service->id, $collect);
        $this->assertSame([], $failures, 'An internal-only service must pass on an internal order.');

        (new ServiceAvailableForOrderType('commercial'))
            ->validate('items.0.service_id', $service->id, $collect);
        $this->assertCount(1, $failures, 'The same service must fail on a commercial order.');
    }

    public function test_a_service_offered_for_both_types_passes_either_way(): void
    {
        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal', 'commercial'],
            'is_active'     => true,
        ]);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'type'                => 'static',
        ]);

        $failures = [];
        $collect  = function (string $message) use (&$failures): void {
            $failures[] = $message;
        };

        foreach (['internal', 'commercial'] as $type) {
            (new ServiceAvailableForOrderType($type))
                ->validate('items.0.service_id', $service->id, $collect);
        }

        $this->assertSame([], $failures);
    }
}
