<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EquipmentType;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    public function definition(): array
    {
        return [
            'name'            => 'Принтер ' . fake()->unique()->word(),
            'serial_number'   => fake()->unique()->bothify('SN-####-????'),
            'type'            => EquipmentType::Bw,
            'initial_counter' => 0,
            'has_counter'     => true,
            'is_active'       => true,
        ];
    }

    public function bw(): static
    {
        return $this->state(fn () => ['type' => EquipmentType::Bw]);
    }

    public function color(): static
    {
        return $this->state(fn () => ['type' => EquipmentType::Color]);
    }

    public function riso(): static
    {
        return $this->state(fn () => ['type' => EquipmentType::Riso]);
    }

    public function withoutCounter(): static
    {
        return $this->state(fn () => ['has_counter' => false, 'initial_counter' => null]);
    }
}
