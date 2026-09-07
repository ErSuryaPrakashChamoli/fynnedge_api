<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            // 10 digits, Indian mobile range, unique per generated user.
            'mobile' => (string) $this->faker->unique()->numberBetween(
                6000000000, 9999999999,
            ),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
        ];
    }
}
