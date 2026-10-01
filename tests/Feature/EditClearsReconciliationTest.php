<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Equipment;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Звірено» means someone held the paper request next to this order and agreed.
 *
 * It says nothing about any other version of the order — and until round 17
 * both edit paths left the mark standing while replacing every item underneath
 * it. An accountant closing the month saw «Звірено 41/41» over a set that no
 * longer had anything to do with what was checked, and neither the flag nor
 * `reconciled_at` moved to say so.
 *
 * The reach is not theoretical on either side:
 *
 *  - the reconciliation screen itself offers the admin an «Редагувати» link
 *    (`canEditOrders`), and its month-close button reconciles every order the
 *    filter shows regardless of status — `new` and `in_progress` among them,
 *    which are exactly the statuses `OrderController::update()` accepts;
 *  - the retro module exists to be reconciled, and its edit form is the one
 *    place a mis-imported month is corrected.
 *
 * Clearing the mark rather than refusing the edit: the edit is the correction
 * the accountant came to make, and the same screen has one click to reconcile
 * again once they have looked.
 */
class EditClearsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $executor;

    private Shift $shift;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->executor = User::factory()->create(['role' => 'executor']);

        $equipment = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);
        $this->shift = app(ShiftService::class)->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10_000],
        ]);

        $category = ServiceCategory::factory()->create([
            'available_for' => ['internal', 'commercial'],
        ]);

        $this->service = Service::factory()->create([
            'service_category_id' => $category->id,
            'type' => 'static',
            'base_price_cost' => 2.50,
            'base_price_commercial' => 5.00,
            'is_active' => true,
        ]);
    }

    // ─── Live internal orders ────────────────────────────

    public function test_editing_a_reconciled_order_takes_the_mark_off(): void
    {
        $this->actingAs($this->executor)->post(route('orders.store'), [
            'type' => 'internal',
            'authorized_person' => 'Тестовий Підписант',
            'cost_center' => 'Кафедра тестування',
            'items' => [['service_id' => $this->service->id, 'quantity' => 4]],
        ]);

        $order = Order::latest('id')->first();

        $this->actingAs($this->admin)
            ->patch(route('admin.reconciliation.reconcile', $order))
            ->assertRedirect();

        $order->refresh();
        $this->assertTrue($order->is_reconciled);
        $this->assertNotNull($order->reconciled_at);

        $response = $this->actingAs($this->executor)->put(route('orders.update', $order), [
            'type' => 'internal',
            'authorized_person' => 'Тестовий Підписант',
            'cost_center' => 'Кафедра тестування',
            'version' => $order->version,
            'items' => [['service_id' => $this->service->id, 'quantity' => 40]],
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertFalse($order->is_reconciled, 'The check was of the order as it was, not as it now is');
        $this->assertNull($order->reconciled_at);
        $this->assertNull($order->reconciled_by);
        $this->assertStringContainsString('звірк', mb_strtolower((string) session('warning')));
    }

    public function test_editing_an_unreconciled_order_says_nothing_about_reconciliation(): void
    {
        $this->actingAs($this->executor)->post(route('orders.store'), [
            'type' => 'internal',
            'authorized_person' => 'Тестовий Підписант',
            'cost_center' => 'Кафедра тестування',
            'items' => [['service_id' => $this->service->id, 'quantity' => 4]],
        ]);

        $order = Order::latest('id')->first();

        $this->actingAs($this->executor)->put(route('orders.update', $order), [
            'type' => 'internal',
            'authorized_person' => 'Тестовий Підписант',
            'cost_center' => 'Кафедра тестування',
            'version' => $order->version,
            'items' => [['service_id' => $this->service->id, 'quantity' => 5]],
        ])->assertRedirect();

        $this->assertNull(session('warning'));
    }

    // ─── Retro orders ────────────────────────────────────

    public function test_editing_a_reconciled_retro_order_takes_the_mark_off(): void
    {
        Material::factory()->create(['counter_type' => 'bw', 'click_cost' => 0.12, 'is_active' => true]);

        $constructorCategory = ServiceCategory::factory()->create(['name' => 'Друк']);
        $constructor = Service::factory()->create([
            'name' => 'Чорно-білий друк',
            'type' => 'constructor',
            'service_category_id' => $constructorCategory->id,
        ]);
        $group = ServiceParameterGroup::factory()->create([
            'service_id' => $constructor->id,
            'name' => 'Формат',
        ]);
        $paperCategory = InventoryCategory::factory()->create(['name' => 'Папір']);
        $paper = InventoryItem::factory()->create([
            'inventory_category_id' => $paperCategory->id,
            'avg_cost' => 0.50,
            'current_quantity' => 1000,
            'is_active' => true,
        ]);
        $option = ServiceParameterOption::factory()->create([
            'group_id' => $group->id,
            'name' => 'А4: 1+0',
            'price_markup' => 1.00,
            'cost_markup' => 0.50,
            'inventory_item_id' => $paper->id,
            'inventory_qty' => 1,
        ]);

        $order = Order::factory()->create([
            'type' => OrderType::Internal->value,
            'status' => OrderStatus::CompletedIssued->value,
            'is_backdated' => true,
            'user_id' => $this->admin->id,
            'shift_id' => null,
            'authorized_person' => 'Тестова Особа',
            'cost_center' => 'БШК',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.backdated-orders.reconcile', $order))
            ->assertRedirect();

        $this->assertTrue($order->fresh()->is_reconciled);

        $this->actingAs($this->admin)
            ->put(route('admin.backdated-orders.update', $order), [
                'order_date' => '2025-08-14',
                'authorized_person' => 'Тестова Особа',
                'cost_center' => 'БШК',
                'items' => [[
                    'service_id' => $constructor->id,
                    'quantity' => 5,
                    'selected_option_ids' => [$option->id],
                ]],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertFalse($order->is_reconciled);
        $this->assertNull($order->reconciled_at);
        $this->assertNull($order->reconciled_by);
    }
}
