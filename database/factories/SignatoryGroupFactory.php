<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SignatoryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatoryGroup>
 */
class SignatoryGroupFactory extends Factory
{
    protected $model = SignatoryGroup::class;

    public function definition(): array
    {
        return [
            'name'        => $this->faker->words(2, true),
            'daily_limit' => null,
            'sort_order'  => 0,
            'is_active'   => true,
        ];
    }
}
