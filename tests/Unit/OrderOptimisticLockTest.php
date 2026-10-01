<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * OrderOptimisticLockTest — verifies concurrent access protection.
 * Covers TZ §4.3 + §6: Optimistic Locking via version field.
 */
class OrderOptimisticLockTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['role' => 'executor']);
        $shift = Shift::factory()->create(['status' => 'open', 'opened_by' => $user->id]);

        $this->order = Order::create([
            'order_number'     => 'INT-2604-001',
            'order_prefix'     => 'INT',
            'order_year'       => 26,
            'order_month'      => 4,
            'order_sequence'   => 1,
            'type'             => OrderType::Internal->value,
            'status'           => OrderStatus::New->value,
            'user_id'          => $user->id,
            'shift_id'         => $shift->id,
            'total_commercial' => 0,
            'total_cost'       => 0,
        ]);
        $this->order->refresh(); // Load DB defaults (version = 1)
    }

    public function test_save_with_correct_version_succeeds(): void
    {
        $this->order->status = OrderStatus::InProgress;
        $result = $this->order->saveWithOptimisticLock(1);

        $this->assertTrue($result);
        $this->assertEquals(2, $this->order->version);
        $this->assertEquals(OrderStatus::InProgress, $this->order->fresh()->status);
    }

    public function test_save_with_wrong_version_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/optimistic lock failed/i');

        $this->order->status = OrderStatus::InProgress;
        $this->order->saveWithOptimisticLock(999); // wrong version
    }

    public function test_version_increments_atomically(): void
    {
        $this->assertEquals(1, $this->order->version);

        $this->order->status = OrderStatus::InProgress;
        $this->order->saveWithOptimisticLock(1);
        $this->assertEquals(2, $this->order->version);

        $this->order->status = OrderStatus::Ready;
        $this->order->saveWithOptimisticLock(2);
        $this->assertEquals(3, $this->order->version);

        // Verify in DB
        $this->assertEquals(3, $this->order->fresh()->version);
    }

    public function test_concurrent_modification_detected(): void
    {
        // Simulate two operators fetching the same order (version = 1)
        $orderCopy1 = Order::findOrFail($this->order->id);
        $orderCopy2 = Order::findOrFail($this->order->id);

        // Operator 1 saves successfully
        $orderCopy1->status = OrderStatus::InProgress;
        $orderCopy1->saveWithOptimisticLock(1);

        // Operator 2 tries to save with stale version — must fail
        $this->expectException(RuntimeException::class);
        $orderCopy2->status = OrderStatus::Ready;
        $orderCopy2->saveWithOptimisticLock(1); // stale version
    }

    /**
     * Метод пише повз Model::save(), і до цієї правки викидав лише
     * `updated`: слухач, що мав би ветувати збереження через `updating`,
     * на цьому шляху мовчки не спрацьовував би.
     */
    public function test_optimistic_save_fires_the_full_event_cycle(): void
    {
        $fired = [];

        Order::saving(function () use (&$fired): void {
            $fired[] = 'saving';
        });
        Order::updating(function () use (&$fired): void {
            $fired[] = 'updating';
        });
        Order::updated(function () use (&$fired): void {
            $fired[] = 'updated';
        });
        Order::saved(function () use (&$fired): void {
            $fired[] = 'saved';
        });

        $this->order->status = OrderStatus::InProgress;
        $this->order->saveWithOptimisticLock(1);

        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $fired);
    }

    public function test_a_listener_vetoing_updating_stops_the_write(): void
    {
        Order::updating(fn () => false);

        $this->order->status = OrderStatus::InProgress;
        $result = $this->order->saveWithOptimisticLock(1);

        $this->assertFalse($result);
        $this->assertEquals(OrderStatus::New, $this->order->fresh()->status);
        $this->assertEquals(1, $this->order->fresh()->version);
    }
}
