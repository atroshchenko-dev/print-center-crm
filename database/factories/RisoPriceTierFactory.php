<?php

namespace Database\Factories;

use App\Models\RisoPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

class RisoPriceTierFactory extends Factory
{
    protected $model = RisoPriceTier::class;

    public function definition(): array
    {
        return [
            'min_qty'        => fake()->numberBetween(1, 1000),
            'max_qty'        => fake()->numberBetween(1001, 5000),
            'cost_per_copy'  => fake()->randomFloat(2, 0.10, 5.00),
        ];
    }
}
