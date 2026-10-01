<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CounterType;
use App\Models\ServiceParameterGroup;
use App\Models\ServiceParameterOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceParameterOption>
 */
class ServiceParameterOptionFactory extends Factory
{
    protected $model = ServiceParameterOption::class;

    public function definition(): array
    {
        return [
            'group_id'          => ServiceParameterGroup::factory(),
            'name'              => 'Опція ' . fake()->unique()->word(),
            'price_markup'      => fake()->randomFloat(2, 0, 10),
            'cost_markup'       => 0,
            'inventory_item_id' => null,
            'inventory_qty'     => 0,
            'counter_type'      => CounterType::Bw,
            'clicks_per_unit'   => 1,
            'sort_order'        => 0,
            'is_active'         => true,
        ];
    }
}
