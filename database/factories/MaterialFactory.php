<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaterialFactory extends Factory
{
    protected $model = Material::class;

    public function definition(): array
    {
        return [
            'name'                 => fake()->word() . ' Material',
            'counter_type'         => fake()->randomElement(['bw', 'color', 'riso']),
            'click_cost'           => fake()->randomFloat(2, 0.01, 2.00),
            'pending_click_cost'   => null,
            'pending_activated_at' => null,
            'is_active'            => true,
        ];
    }
}
