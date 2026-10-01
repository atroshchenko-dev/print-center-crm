<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CostCenterInitiator;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCenterInitiator>
 */
class CostCenterInitiatorFactory extends Factory
{
    protected $model = CostCenterInitiator::class;

    public function definition(): array
    {
        $name = $this->faker->lastName().' '.mb_substr($this->faker->firstName(), 0, 1).'.';

        return [
            'department_id' => Department::factory(),
            'name'          => $name,
            'name_key'      => CostCenterInitiator::normalizeKey($name),
            'orders_count'  => $this->faker->numberBetween(1, 20),
            'last_used_at'  => now()->subDays($this->faker->numberBetween(0, 60)),
        ];
    }
}
