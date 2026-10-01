<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id'               => Order::factory(),
            'service_id'             => Service::factory(),
            'service_name'           => $this->faker->words(3, true),
            'service_snapshot'       => [
                'service_type'           => 'constructor',
                'unit_price_commercial'  => 10.00,
                'unit_price_cost'        => 5.00,
                'total_price_commercial' => 10.00,
                'total_price_cost'       => 5.00,
                'hardware_counters'      => [
                    'bw_clicks'    => 1,
                    'color_clicks' => 0,
                    'riso_clicks'  => 0,
                ],
            ],
            'quantity'               => 1,
            'unit_price_commercial'  => 10.00,
            'unit_price_cost'        => 5.00,
            'total_price_commercial' => 10.00,
            'total_price_cost'       => 5.00,
            'bw_clicks'              => 1,
            'color_clicks'           => 0,
            'riso_clicks'            => 0,
        ];
    }
}
