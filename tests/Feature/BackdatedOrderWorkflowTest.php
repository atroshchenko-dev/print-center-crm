<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RisoPriceTier;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * BackdatedOrderWorkflowTest — Feature tests for BackdatedOrderController.
 *
 * Covers: index, create, store, edit, update, destroy,
 * reconcile, reconcileBatch, unreconcile, toggleRequest, export.
 *
 * All routes require role:admin middleware.
 */
class BackdatedOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $executor;

    private Service $service;

    private ServiceParameterOption $option;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->executor = User::factory()->executor()->create();

        // Create required reference data for constructors
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.12, 'is_active' => true]);
        Material::factory()->create(['counter_type' => 'color', 'click_cost' => 0.60, 'is_active' => true]);

        $category = ServiceCategory::factory()->create(['name' => 'Друк']);

        $this->service = Service::factory()->create([
            'name'                => 'Чорно-білий друк',
            'type'                => 'constructor',
            'service_category_id' => $category->id,
        ]);

        $group = ServiceParameterGroup::factory()->create([
            'service_id' => $this->service->id,
            'name'       => 'Формат',
        ]);

        $paperCategory = InventoryCategory::factory()->create(['name' => 'Папір']);
        $paper = InventoryItem::factory()->create([
            'name'                  => 'Папір А4 80 г/м²',
            'inventory_category_id' => $paperCategory->id,
            'avg_cost'              => 0.50,
            'current_quantity'      => 1000,
            'is_active'             => true,
        ]);

        $this->option = ServiceParameterOption::factory()->create([
            'group_id'          => $group->id,
            'name'              => 'А4: 1+0',
            'price_markup'      => 1.00,
            'cost_markup'       => 0.50,
            'inventory_item_id' => $paper->id,
            'inventory_qty'     => 1,
        ]);
    }

    // ─── Access Control ─────────────────────────────────

    public function test_executor_cannot_access_backdated_orders(): void
    {
        $response = $this->actingAs($this->executor)
            ->get(route('admin.backdated-orders.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_backdated_orders_index(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.index'));

        $response->assertOk();
    }

    /**
     * The `signatory_cost_centers` assertion is the wiring, not the contents:
     * both retro forms narrow their `CostCenterSelect` with that map, and
     * `assertOk()` alone stays green when the prop is dropped from the render
     * array.
     */
    public function test_admin_can_access_create_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.create'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->has('signatory_cost_centers'));
    }

    public function test_admin_can_access_edit_form(): void
    {
        $order = $this->createBackdatedOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.edit', $order));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->has('signatory_cost_centers'));
    }

    /**
     * A retro order carries the day it was actually taken, and `OrderObserver`
     * dates the signatory ↔ cost-centre pair from `created_at`. That date used
     * to be applied in a second `saveQuietly()` *after* the insert, so the
     * observer saw `now()` — while the backfill, reading the very same orders,
     * computes MAX(created_at) and gets 2025. Two answers to one question, for
     * exactly the orders the backfill exists to read.
     */
    public function test_a_retro_order_dates_its_cost_centre_pair_from_the_day_it_was_taken(): void
    {
        UniversityRef::factory()->create(['full_name' => 'Тестова Особа']);
        Department::factory()->create(['name' => 'БШК']);

        $this->storeBackdatedOrder('2025-08-15');

        $this->assertSame(
            '2025-08-15',
            SignatoryCostCenter::sole()->last_used_at->timezone('Europe/Kyiv')->toDateString(),
        );
    }

    // ─── Store ──────────────────────────────────────────

    public function test_admin_can_create_backdated_order(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.store'), [
                'order_date'        => '2025-08-15',
                'authorized_person' => 'Тестова Особа',
                'cost_center'       => 'БШК',
                'items'             => [
                    [
                        'service_id'          => $this->service->id,
                        'quantity'            => 5,
                        'selected_option_ids' => [$this->option->id],
                    ],
                ],
            ]);

        $response->assertRedirect(route('admin.backdated-orders.create'));
        $response->assertSessionHas('success');

        // Verify order was created with backdated flag
        $order = Order::where('is_backdated', true)->first();
        $this->assertNotNull($order);
        $this->assertTrue($order->is_backdated);
        $this->assertEquals(OrderType::Internal, $order->type);
        $this->assertEquals(OrderStatus::CompletedIssued, $order->status);
        $this->assertNull($order->shift_id);
        $this->assertEquals('Тестова Особа', $order->authorized_person);
        $this->assertEquals('БШК', $order->cost_center);
        $this->assertGreaterThan(0, $order->total_cost);

        // Verify order item was created
        $this->assertCount(1, $order->items);
    }

    /**
     * "Not in the future" means not after today in Kyiv. `before_or_equal:today`
     * resolves `today` in config('app.timezone'), which is UTC — so between
     * midnight and 03:00 Kyiv, the day the admin is living in was rejected as
     * being in the future. That window is not an edge case here: the working
     * day runs to the 03:00 auto-close, which is why it exists at all.
     */
    public function test_todays_kyiv_date_is_accepted_in_the_small_hours(): void
    {
        // 01:00 Kyiv on 30 July — in UTC it is still 22:00 on the 29th.
        Carbon::setTestNow(Carbon::parse('2026-07-30 01:00:00', 'Europe/Kyiv'));

        $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.store'), [
                'order_date'        => '2026-07-30',
                'authorized_person' => 'Тест',
                'items'             => [[
                    'service_id'          => $this->service->id,
                    'quantity'            => 1,
                    'selected_option_ids' => [$this->option->id],
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Order::where('is_backdated', true)->count());

        Carbon::setTestNow();
    }

    // ─── Riso: the number of originals has to survive the request ──

    /**
     * The whole point of R14-1 is that this field exists on the screen and had
     * no way to reach the pricing service: `RisoCalculator.vue` collected
     * "Оригіналів", used it to sum A3 sheets per original, and then the
     * transform dropped it — no rule named it, no builder read it. So the
     * server rounded the whole run and priced a different number of sheets
     * than the operator had just been shown.
     *
     * This test walks the whole boundary — HTTP body → FormRequest →
     * OrderItemBuilder → snapshot — because every one of those links was a
     * place the field could go missing without anything failing.
     */
    public function test_the_number_of_riso_originals_reaches_the_stored_snapshot(): void
    {
        $riso = $this->risoService();

        $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.store'), [
                'order_date'        => '2025-08-15',
                'authorized_person' => 'Тест',
                'items'             => [[
                    'service_id' => $riso->id,
                    // 3 originals × 5 A4 copies each
                    'quantity'       => 15,
                    'riso_format'    => 'A4',
                    'riso_sides'     => 1,
                    'riso_originals' => 3,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $params = OrderItem::where('service_id', $riso->id)->sole()->service_snapshot['riso_params'];

        $this->assertSame(3, $params['originals']);
        $this->assertSame(9, $params['sheets_a3'], '3 × ceil(5/2), not ceil(15/2)');
    }

    public function test_an_item_that_names_no_originals_is_still_priced_as_one(): void
    {
        $riso = $this->risoService();

        $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.store'), [
                'order_date'        => '2025-08-15',
                'authorized_person' => 'Тест',
                'items'             => [[
                    'service_id'  => $riso->id,
                    'quantity'    => 15,
                    'riso_format' => 'A4',
                    'riso_sides'  => 1,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $params = OrderItem::where('service_id', $riso->id)->sole()->service_snapshot['riso_params'];

        $this->assertSame(1, $params['originals']);
        $this->assertSame(8, $params['sheets_a3'], 'One original of 15 copies really is ceil(15/2)');
    }

    /** A riso service with one open-ended tier and A3 paper on the shelf. */
    private function risoService(): Service
    {
        RisoPriceTier::query()->forceDelete();
        RisoPriceTier::create(['min_qty' => 1, 'max_qty' => null, 'cost_per_copy' => 1.00]);

        InventoryItem::factory()->create([
            'name'                  => config('riso.default_paper_a3_name'),
            'inventory_category_id' => InventoryCategory::factory()->create(['name' => 'Папір А3'])->id,
            'avg_cost'              => 1.00,
            'current_quantity'      => 1000,
            'is_active'             => true,
        ]);

        return Service::factory()->create([
            'name'                => 'Тиражування (RISO)',
            'type'                => 'riso',
            'service_category_id' => ServiceCategory::factory()->create(['name' => 'Тиражування'])->id,
        ]);
    }

    public function test_tomorrow_is_still_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 01:00:00', 'Europe/Kyiv'));

        $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.store'), [
                'order_date'        => '2026-07-31',
                'authorized_person' => 'Тест',
                'items'             => [[
                    'service_id'          => $this->service->id,
                    'quantity'            => 1,
                    'selected_option_ids' => [$this->option->id],
                ]],
            ])
            ->assertSessionHasErrors('order_date');

        Carbon::setTestNow();
    }

    public function test_backdated_order_sets_created_at_to_order_date(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.store'), [
                'order_date'        => '2025-08-20',
                'authorized_person' => 'Тест',
                'items'             => [
                    [
                        'service_id'          => $this->service->id,
                        'quantity'            => 1,
                        'selected_option_ids' => [$this->option->id],
                    ],
                ],
            ]);

        $order = Order::where('is_backdated', true)->first();
        $this->assertEquals('2025-08-20', $order->created_at->format('Y-m-d'));
    }

    // ─── Reconciliation ─────────────────────────────────

    public function test_admin_can_reconcile_backdated_order(): void
    {
        $order = $this->createBackdatedOrder();

        $response = $this->actingAs($this->admin)
            ->patch(route('admin.backdated-orders.reconcile', $order));

        $response->assertRedirect();
        $order->refresh();
        $this->assertTrue($order->is_reconciled);
        $this->assertNotNull($order->reconciled_at);
        $this->assertEquals($this->admin->id, $order->reconciled_by);
    }

    public function test_admin_can_unreconcile_backdated_order(): void
    {
        $order = $this->createBackdatedOrder(['is_reconciled' => true, 'reconciled_at' => now(), 'reconciled_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.backdated-orders.unreconcile', $order));

        $response->assertRedirect();
        $order->refresh();
        $this->assertFalse($order->is_reconciled);
        $this->assertNull($order->reconciled_at);
        $this->assertNull($order->reconciled_by);
    }

    public function test_admin_can_batch_reconcile(): void
    {
        $order1 = $this->createBackdatedOrder();
        $order2 = $this->createBackdatedOrder();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.reconcile-batch'), [
                'order_ids' => [$order1->id, $order2->id],
            ]);

        $response->assertRedirect();
        $this->assertTrue($order1->fresh()->is_reconciled);
        $this->assertTrue($order2->fresh()->is_reconciled);
    }

    public function test_reconcile_rejects_non_backdated_order(): void
    {
        $order = Order::factory()->create([
            'is_backdated' => false,
            'status'       => OrderStatus::New->value,
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('admin.backdated-orders.reconcile', $order));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ─── Toggle Request ─────────────────────────────────

    public function test_admin_can_toggle_request_received(): void
    {
        $order = $this->createBackdatedOrder();

        // Set to false
        $response = $this->actingAs($this->admin)
            ->patch(route('admin.backdated-orders.toggle-request', $order), [
                'request_received' => false,
            ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertFalse($order->request_received);

        // Toggle back to true
        $this->actingAs($this->admin)
            ->patch(route('admin.backdated-orders.toggle-request', $order), [
                'request_received' => true,
            ]);

        $order->refresh();
        $this->assertTrue($order->request_received);
        $this->assertNotNull($order->request_received_at);
    }

    // ─── Destroy (Soft Delete) ──────────────────────────

    public function test_admin_can_soft_delete_backdated_order(): void
    {
        $order = $this->createBackdatedOrder();
        $orderId = $order->id;

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.backdated-orders.destroy', $order));

        $response->assertRedirect();
        $this->assertSoftDeleted('orders', ['id' => $orderId]);
    }

    public function test_destroy_rejects_non_backdated_order(): void
    {
        $order = Order::factory()->create([
            'is_backdated' => false,
            'status'       => OrderStatus::New->value,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.backdated-orders.destroy', $order));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_destroy_also_soft_deletes_items(): void
    {
        $order = $this->createBackdatedOrder();
        $item = OrderItem::factory()->create([
            'order_id'         => $order->id,
            'service_id'       => $this->service->id,
            'service_name'     => $this->service->name,
            'service_snapshot' => ['test' => true, 'unit_price_commercial' => 0, 'unit_price_cost' => 1, 'total_price_commercial' => 0, 'total_price_cost' => 5, 'hardware_counters' => ['bw_clicks' => 5, 'color_clicks' => 0, 'riso_clicks' => 0]],
            'quantity'         => 5,
        ]);
        $itemId = $item->id;

        $this->actingAs($this->admin)
            ->delete(route('admin.backdated-orders.destroy', $order));

        $this->assertSoftDeleted('order_items', ['id' => $itemId]);
    }

    // ─── Index Filtering ────────────────────────────────

    public function test_index_filters_by_reconciled_status(): void
    {
        $reconciled = $this->createBackdatedOrder(['is_reconciled' => true]);
        $unreconciled = $this->createBackdatedOrder(['is_reconciled' => false]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.index', ['reconciled' => 'true']));

        $response->assertOk();
    }

    public function test_index_filters_by_cost_center(): void
    {
        $this->createBackdatedOrder(['cost_center' => 'БШК']);
        $this->createBackdatedOrder(['cost_center' => 'ФОП']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.backdated-orders.index', ['cost_center' => 'БШК']));

        $response->assertOk();
    }

    // ─── Update: number follows the date ────────

    /**
     * The number encodes the month it belongs to ({PREFIX}-{YY}{MM}-{SEQ}) and
     * the accounting sheet prints it next to created_at on the same row. Moving
     * an order into another month therefore has to move its number too — before
     * R11-1 the date moved alone and a July export carried June numbers.
     */
    public function test_update_renumbers_the_order_when_the_month_changes(): void
    {
        $order = $this->storeBackdatedOrder('2025-08-15');
        $this->assertStringStartsWith('INT-2508-', $order->order_number);

        $this->actingAs($this->admin)
            ->put(route('admin.backdated-orders.update', $order), $this->updatePayload('2025-09-03'))
            ->assertRedirect(route('admin.backdated-orders.index'));

        $order->refresh();

        $this->assertStringStartsWith('INT-2509-', $order->order_number);
        $this->assertEquals(25, $order->order_year);
        $this->assertEquals(9, $order->order_month);
        $this->assertEquals(
            '2025-09-03',
            $order->created_at->timezone('Europe/Kyiv')->toDateString(),
        );
    }

    /**
     * Within the same month the number must survive untouched — it is what the
     * paper request is filed under.
     */
    public function test_update_keeps_the_number_when_only_the_day_changes(): void
    {
        $order = $this->storeBackdatedOrder('2025-08-15');
        $before = $order->order_number;

        $this->actingAs($this->admin)
            ->put(route('admin.backdated-orders.update', $order), $this->updatePayload('2025-08-27'));

        $order->refresh();

        $this->assertEquals($before, $order->order_number);
        $this->assertEquals(8, $order->order_month);
        $this->assertEquals(
            '2025-08-27',
            $order->created_at->timezone('Europe/Kyiv')->toDateString(),
        );
    }

    // ─── The journal ─────────────────────────────────────
    //
    // This module produces the accounting record of months that have already
    // been reported, and it is the only module where an admin can move an order
    // into another month, renumber it and change what it cost. Until round 17
    // it wrote nothing to the audit journal at all — while `OrderController`,
    // doing strictly less to a live order, has written `order_edited` and
    // `order_deleted` since round 2.

    public function test_editing_a_retro_order_is_written_down(): void
    {
        $order = $this->storeBackdatedOrder('2025-08-15');
        $numberBefore = $order->order_number;

        $this->actingAs($this->admin)
            ->put(route('admin.backdated-orders.update', $order), $this->updatePayload('2025-09-03'))
            ->assertRedirect();

        $log = AuditLog::where('event_type', 'order_edited')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('backdated', $log->meta['source']);
        $this->assertSame($order->id, $log->meta['order_id']);
        $this->assertSame($numberBefore, $log->meta['before']['order_number']);
        $this->assertSame($order->fresh()->order_number, $log->meta['after']['order_number']);
        $this->assertNotSame(
            $log->meta['before']['order_number'],
            $log->meta['after']['order_number'],
            'the month moved, so the number moved — and the journal says so',
        );
    }

    public function test_deleting_a_retro_order_is_written_down(): void
    {
        $order = $this->storeBackdatedOrder('2025-08-15');

        $this->actingAs($this->admin)
            ->delete(route('admin.backdated-orders.destroy', $order))
            ->assertRedirect();

        $log = AuditLog::where('event_type', 'order_deleted')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('backdated', $log->meta['source']);
        $this->assertSame($order->id, $log->meta['order_id']);
        $this->assertStringContainsString($order->order_number, $log->description);
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * Create a retro-order through the real endpoint, so its number is
     * generated the same way production generates it.
     */
    private function storeBackdatedOrder(string $date): Order
    {
        $this->actingAs($this->admin)
            ->post(route('admin.backdated-orders.store'), $this->updatePayload($date));

        return Order::where('is_backdated', true)->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(string $date): array
    {
        return [
            'order_date'        => $date,
            'authorized_person' => 'Тестова Особа',
            'cost_center'       => 'БШК',
            'items'             => [
                [
                    'service_id'          => $this->service->id,
                    'quantity'            => 5,
                    'selected_option_ids' => [$this->option->id],
                ],
            ],
        ];
    }

    private function createBackdatedOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'type'              => OrderType::Internal->value,
            'status'            => OrderStatus::CompletedIssued->value,
            'is_backdated'      => true,
            'user_id'           => $this->admin->id,
            'shift_id'          => null,
            'authorized_person' => 'Тестова Особа',
            'cost_center'       => 'БШК',
            'request_received'  => true,
        ], $overrides));
    }
}
