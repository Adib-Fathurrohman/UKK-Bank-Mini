<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Customer;
use App\Models\Major;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * The current PIN hash being used by the factory.
     */
    protected static ?string $pin;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_number' => fake()->unique()->numerify('TST-2026-####'),
            'nis' => fake()->unique()->numerify('2026####'),
            'full_name' => fake()->name(),
            'class' => fake()->randomElement(['X', 'XI', 'XII']),
            'major_id' => Major::factory(),
            'group' => fake()->randomElement(['1', '2', '3', '4']),
            'account_status' => AccountStatus::ACTIVE,
            'pin' => static::$pin ??= Hash::make('123456'),
            'balance' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['account_status' => AccountStatus::INACTIVE]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['account_status' => AccountStatus::SUSPENDED]);
    }
}
