<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceParameterGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceParameterGroup>
 */
class ServiceParameterGroupFactory extends Factory
{
    protected $model = ServiceParameterGroup::class;

    public function definition(): array
    {
        return [
            'service_id'  => Service::factory(),
            'name'        => 'Параметр ' . fake()->unique()->word(),
            'ui_type'     => 'radio',
            'is_required' => true,
            'sort_order'  => 0,
        ];
    }

    public function checkbox(): static
    {
        return $this->state(fn () => ['ui_type' => 'checkbox']);
    }
}
