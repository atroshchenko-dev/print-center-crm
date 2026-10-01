<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UniversityRef>
 */
class UniversityRefFactory extends Factory
{
    protected $model = UniversityRef::class;

    public function definition(): array
    {
        return [
            'full_name'          => $this->faker->lastName() . ' ' . $this->faker->firstName(),
            'position'           => $this->faker->jobTitle(),
            'signatory_group_id' => SignatoryGroup::factory(),
            'is_active'          => true,
        ];
    }
}
