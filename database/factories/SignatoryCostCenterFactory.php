<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\SignatoryCostCenter;
use App\Models\UniversityRef;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatoryCostCenter>
 */
class SignatoryCostCenterFactory extends Factory
{
    protected $model = SignatoryCostCenter::class;

    public function definition(): array
    {
        return [
            'university_ref_id' => UniversityRef::factory(),
            'department_id'     => Department::factory(),
            'orders_count'      => $this->faker->numberBetween(1, 20),
            'last_used_at'      => now()->subDays($this->faker->numberBetween(0, 60)),
        ];
    }
}
