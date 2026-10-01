<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        return [
            'name'       => fake()->word() . ' Category',
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active'  => true,
        ];
    }
}
