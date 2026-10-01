<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;


    public function definition(): array
    {
        // Spread across distinct past dates so a test can build a history of
        // closed shifts. Only one shift may be open at a time, though — see
        // the unique_open_shift index — so a test needing several must close
        // all but one with ->closed().
        $offset = $this->faker->unique()->numberBetween(0, 3650);

        return [
            'date'       => now()->subDays($offset)->toDateString(),
            'opened_by'  => User::factory(),
            'status'     => ShiftStatus::Open,
            'cash_start' => 0.00,
            'opened_at'  => now(),
        ];
    }

    /**
     * A shift dated today in Europe/Kyiv.
     *
     * Looking up the current shift no longer depends on its date, so this is
     * only for tests that assert on the date itself or on day-bounded
     * reporting. An open shift from any date is found by Shift::current().
     */
    public function today(): static
    {
        return $this->state(fn () => [
            'date' => today('Europe/Kyiv')->toDateString(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status'          => ShiftStatus::Closed,
            'closed_by'       => User::factory(),
            'closed_at'       => now(),
            'cash_calculated' => 0.00,
            'cash_actual'     => 0.00,
        ]);
    }
}
