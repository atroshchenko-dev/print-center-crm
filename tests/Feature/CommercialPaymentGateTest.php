<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Equipment;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A commercial order is only finished by being paid for.
 *
 * SPECIFICATION.md §119 gives commercial orders exactly one way out:
 * New → InProgress → Ready → PaidIssued. `completed_issued` is the internal
 * flow's terminal status — it issues the goods and deducts the stock without
 * ever touching the cash register.
 *
 * OrderType::completionStatus() encodes that rule, so the only thing missing
 * was somewhere that asks it.
 */
class CommercialPaymentGateTest extends TestCase
{
    use RefreshDatabase;

    private User $executor;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = User::factory()->create(['role' => 'executor']);
        $equipment      = Equipment::factory()->create(['type' => 'bw', 'is_active' => true]);

        $this->shift = app(ShiftService::class)->openShift($this->executor, [
            ['equipment_id' => $equipment->id, 'counter_value' => 10000],
        ]);
    }

    private function commercialOrder(OrderStatus $status): Order
    {
        $order = Order::factory()->commercial()->forShift($this->shift)->create([
            'user_id'          => $this->executor->id,
            'status'           => $status->value,
            'total_commercial' => 250.00,
            'total_cost'       => 100.00,
        ]);

        return $order->refresh();
    }

    /**
     * The route the UI offers: "Готово" → "✓ Завершено/Видано".
     * It hands over the job and leaves the register empty.
     */
    public function test_a_commercial_order_cannot_be_completed_without_payment(): void
    {
        $order = $this->commercialOrder(OrderStatus::Ready);

        $this->actingAs($this->executor)
            ->patch(route('orders.status', $order), [
                'status'  => OrderStatus::CompletedIssued->value,
                'version' => $order->version,
            ]);

        $this->assertEquals(
            OrderStatus::Ready,
            $order->fresh()->status,
            'A commercial order must not reach completed_issued — it skips the cash register.',
        );

        $this->assertSame(
            0,
            LedgerTransaction::where('order_id', $order->id)->count(),
            'Nothing should have been booked for a refused transition.',
        );
    }

    /**
     * The same hole one step earlier: new → completed_issued is the internal
     * "quick complete", hidden from commercial orders in the client only.
     */
    public function test_a_commercial_order_cannot_be_quick_completed_from_new(): void
    {
        $order = $this->commercialOrder(OrderStatus::New);

        $this->actingAs($this->executor)
            ->patch(route('orders.status', $order), [
                'status'  => OrderStatus::CompletedIssued->value,
                'version' => $order->version,
            ]);

        $this->assertEquals(OrderStatus::New, $order->fresh()->status);
    }

    /**
     * Batch is the wider door: it takes up to 50 ids and never looked at type.
     */
    public function test_batch_completion_skips_commercial_orders(): void
    {
        $commercial = $this->commercialOrder(OrderStatus::Ready);
        $internal   = Order::factory()->internal()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::Ready->value,
        ]);

        $this->actingAs($this->executor)
            ->patch(route('orders.batch-status'), [
                'order_ids' => [$commercial->id, $internal->id],
                'status'    => OrderStatus::CompletedIssued->value,
            ]);

        $this->assertEquals(
            OrderStatus::Ready,
            $commercial->fresh()->status,
            'Batch must not push a commercial order past the cash register.',
        );

        $this->assertEquals(
            OrderStatus::CompletedIssued,
            $internal->fresh()->status,
            'The internal order in the same batch must still go through.',
        );
    }

    /**
     * The supported route still has to work.
     */
    public function test_a_commercial_order_is_still_finished_by_paying(): void
    {
        $order = $this->commercialOrder(OrderStatus::Ready);

        $this->actingAs($this->executor)
            ->patch(route('orders.status', $order), [
                'status'         => OrderStatus::PaidIssued->value,
                'version'        => $order->version,
                'payment_method' => 'cash',
            ]);

        $this->assertEquals(OrderStatus::PaidIssued, $order->fresh()->status);
        $this->assertSame(
            1,
            LedgerTransaction::where('order_id', $order->id)->count(),
            'Paying an order books it in the register.',
        );
    }

    /**
     * Internal orders keep their one-click completion.
     */
    public function test_an_internal_order_still_completes_in_one_click(): void
    {
        $order = Order::factory()->internal()->forShift($this->shift)->create([
            'user_id' => $this->executor->id,
            'status'  => OrderStatus::New->value,
        ]);
        $order->refresh();

        $this->actingAs($this->executor)
            ->patch(route('orders.status', $order), [
                'status'  => OrderStatus::CompletedIssued->value,
                'version' => $order->version,
            ]);

        $this->assertEquals(OrderStatus::CompletedIssued, $order->fresh()->status);
    }
}
