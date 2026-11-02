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
     * Define the model's default state: an established customer who has
     * activated the app and created PIN 123456. Use pending() for a new account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_number' => fake()->unique()->numerify('TST-2026-####'),
            'nis' => fake()->unique()->numerify('2026####'),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'firebase_uid' => 'firebase-'.fake()->unique()->bothify('????????????'),
            'email_verified_at' => now(),
            'activated_at' => now(),
            'class' => fake()->randomElement(['X', 'XI', 'XII']),
            'major_id' => Major::factory(),
            'group' => fake()->randomElement(['1', '2', '3', '4']),
            'account_status' => AccountStatus::ACTIVE,
            'pin' => static::$pin ??= Hash::make('123456'),
            'balance' => 0,
        ];
    }

    /**
     * Activated with a given Firebase UID.
     */
    public function activated(?string $firebaseUid = null): static
    {
        return $this->state(fn (array $attributes) => [
            'firebase_uid' => $firebaseUid ?? 'firebase-'.fake()->unique()->bothify('????????????'),
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
    }

    /**
     * A new account: not activated in the app yet, so no Firebase account and no PIN.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'firebase_uid' => null,
            'email_verified_at' => null,
            'activated_at' => null,
            'pin' => null,
        ]);
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
