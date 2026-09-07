<?php

namespace Database\Factories;

use App\Domain\Enums\GoalCategory;
use App\Domain\Enums\Intent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => $this->faker->name(),
            'age' => $this->faker->numberBetween(23, 58),
            'city' => $this->faker->city(),
            'email' => $this->faker->safeEmail(),
            'intent' => $this->faker->randomElement(Intent::ids()),
            'primary_goal' => $this->faker->randomElement(GoalCategory::ids()),
        ];
    }
}
