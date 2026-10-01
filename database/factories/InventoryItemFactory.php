<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_category_id' => InventoryCategory::factory(),
            'name'                  => fake()->unique()->words(3, true),
            'unit'                  => 'шт',
            'current_quantity'      => fake()->numberBetween(10, 500),
            'avg_cost'              => fake()->randomFloat(4, 0.10, 50.00),
            'min_quantity'          => 10,
            'is_active'             => true,
            'sort_order'            => 0,
        ];
    }
}
