<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Factory password, for tests and local demo data only.
     */
    public const PASSWORD = 'Factory-Passw0rd-Only!';

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'role' => Role::Sales,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => Role::Manager]);
    }

    public function sales(): static
    {
        return $this->state(fn () => ['role' => Role::Sales]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
