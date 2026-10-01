<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CounterType;
use App\Enums\ServiceType;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name'                  => 'Друк ' . fake()->unique()->word(),
            'type'                  => ServiceType::Static,
            'base_price_commercial' => fake()->randomFloat(2, 1, 50),
            'base_price_cost'       => fake()->randomFloat(2, 0.5, 20),
            'counter_type'          => CounterType::Bw,
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ];
    }

    public function constructor(): static
    {
        return $this->state(fn () => [
            'type'         => ServiceType::Constructor,
            'counter_type' => CounterType::None,
            'clicks_per_unit' => 0,
        ]);
    }
}
