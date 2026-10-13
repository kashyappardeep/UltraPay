<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Testing ke liye dummy users (php artisan db:seed).
 * Sabka password: Ultra@2026, PIN: 4826
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'mobile' => (string) fake()->unique()->numberBetween(6000000000, 9999999999),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Ultra@2026',
            'pin' => '4826',
            'wallet_id' => 'ultra' . fake()->unique()->numberBetween(1000, 999999),
            'referral_code' => strtoupper(Str::random(8)),
        ];
    }
}