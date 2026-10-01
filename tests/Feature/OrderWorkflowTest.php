<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OrderWorkflowTest — Full order lifecycle feature tests.
 * Covers TZ §4.2 + §4.3: status transitions, cancellation.
 *
 * Controllers use ShiftService::getCurrentShift() directly.
 * CSRF is disabled in testing via bootstrap/app.php.
 * Route model binding (SubstituteBindings) remains active.
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $executor;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin    = User::factory()->create(['role' => 'admin']);
        $this->executor = User::factory()->create(['role' => 'executor']);
        $equipment      = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        $shiftService = app(ShiftService::class);
        $this->shift  = $shiftService->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10000],
        ]);
    }

    /**
     * Verify that the order creation form is accessible.
     */
    public function test_order_create_page_is_accessible(): void
    {
        $response = $this->actingAs($this->executor)->get(route('orders.create'));
        $response->assertOk();
    }

    /**
     * Status transition: new → in_progress.
     */
    public function test_status_transition_new_to_in_progress(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
        ]);
        $order->refresh();

        $response = $this->actingAs($this->executor)
            ->patch(route('orders.status', $order), [
                'status'  => OrderStatus::InProgress->value,
                'version' => $order->version,
            ]);

        $response->assertRedirect();
        $this->assertEquals(OrderStatus::InProgress, $order->fresh()->status);
    }

    // ─── Stale writes ────────────────────────────────────────────────
    //
    // `orders.version` exists so that a second operator acting on a page they
    // loaded before someone else changed the order is told about it instead of
    // overwriting. Nothing covered the controller side of that.

    public function test_a_stale_version_is_refused(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
        ]);
        $order->refresh();
        $staleVersion = $order->version;

        // Someone else moves it on.
        $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status'  => OrderStatus::InProgress->value,
            'version' => $staleVersion,
        ]);

        $this->assertEquals(OrderStatus::InProgress, $order->fresh()->status);

        // The second operator still holds the version from before.
        $response = $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status'  => OrderStatus::Ready->value,
            'version' => $staleVersion,
        ]);

        $response->assertRedirect();
        $this->assertEquals(
            OrderStatus::InProgress,
            $order->fresh()->status,
            'A write against a stale version must not be applied.',
        );
    }

    public function test_repeating_a_transition_that_already_happened_is_refused(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
        ]);
        $order->refresh();

        $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status'  => OrderStatus::InProgress->value,
            'version' => $order->version,
        ]);

        $order->refresh();
        $historyBefore = $order->statusHistory()->count();

        // Second tab clicks the same button again.
        $response = $this->actingAs($this->executor)->patch(route('orders.status', $order), [
            'status'  => OrderStatus::InProgress->value,
            'version' => $order->version,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(
            $historyBefore,
            $order->fresh()->statusHistory()->count(),
            'A repeated transition must not add a second history row.',
        );
    }

    /**
     * Cancel order without reason must fail validation.
     */
    public function test_cancel_order_requires_reason(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
        ]);
        $order->refresh();

        $response = $this->actingAs($this->executor)
            ->post(route('orders.cancel', $order), [
                'version' => $order->version,
            ]);

        $response->assertSessionHasErrors('reason');
    }

    /**
     * Cancel order WITH reason succeeds.
     */
    public function test_cancel_order_with_reason_succeeds(): void
    {
        $order = Order::factory()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
        ]);
        $order->refresh();

        $response = $this->actingAs($this->executor)
            ->post(route('orders.cancel', $order), [
                'version' => $order->version,
                'reason'  => 'Клієнт передумав',
            ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertEquals(OrderStatus::Cancelled, $order->status);
        $this->assertEquals('Клієнт передумав', $order->cancellation_reason);
    }
}
