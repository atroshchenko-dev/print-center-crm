<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => 'password',
            'remember_token'    => Str::random(10),
            'role'              => UserRole::Executor,
            'is_active'         => true,
            'permissions'       => ['orders', 'ledger'],
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role'        => UserRole::Admin,
            'permissions' => UserRole::Admin->defaultPermissions(),
        ]);
    }

    public function executor(): static
    {
        return $this->state(fn () => [
            'role'        => UserRole::Executor,
            'permissions' => UserRole::Executor->defaultPermissions(),
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn () => [
            'role'        => UserRole::Manager,
            'permissions' => UserRole::Manager->defaultPermissions(),
        ]);
    }
}
