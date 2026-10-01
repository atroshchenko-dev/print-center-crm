<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $type     = $this->faker->randomElement(['internal', 'commercial']);
        $prefix   = $type === 'internal' ? 'INT' : 'COM';
        $year     = (int) date('y');
        $month    = (int) date('m');
        $sequence = $this->faker->unique()->numberBetween(1, 9999);

        return [
            'order_number'     => "{$prefix}-{$year}{$month}-{$sequence}",
            'order_prefix'     => $prefix,
            'order_year'       => $year,
            'order_month'      => $month,
            'order_sequence'   => $sequence,
            'type'             => $type,
            'status'           => OrderStatus::New->value,
            'user_id'          => User::factory(),
            // Reuse the shift that is open, the way a real order does. Minting
            // a fresh one per order gave every test as many open shifts as it
            // had orders — a state the system cannot actually be in.
            'shift_id'         => fn () => Shift::current()->first()?->id
                ?? Shift::factory()->create()->id,
            'total_commercial' => $this->faker->randomFloat(2, 10, 500),
            'total_cost'       => $this->faker->randomFloat(2, 5, 200),
        ];
    }

    /**
     * Internal order state.
     */
    public function internal(): static
    {
        return $this->state(fn () => [
            'type'         => OrderType::Internal->value,
            'order_prefix' => 'INT',
        ]);
    }

    /**
     * Commercial order state.
     */
    public function commercial(): static
    {
        return $this->state(fn () => [
            'type'         => OrderType::Commercial->value,
            'order_prefix' => 'COM',
        ]);
    }

    /**
     * Order in specific status.
     */
    public function withStatus(OrderStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
        ]);
    }

    /**
     * Order attached to a specific shift.
     */
    public function forShift(Shift $shift): static
    {
        return $this->state(fn () => [
            'shift_id' => $shift->id,
        ]);
    }
}
