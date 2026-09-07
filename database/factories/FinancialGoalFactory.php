<?php

namespace Database\Factories;

use App\Domain\Enums\GoalCategory;
use App\Models\FinancialGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialGoal>
 */
class FinancialGoalFactory extends Factory
{
    public function definition(): array
    {
        $target = $this->faker->numberBetween(100000, 5000000);

        return [
            'user_id' => User::factory(),
            'title' => $this->faker->words(2, true),
            'category' => $this->faker->randomElement(GoalCategory::ids()),
            'target_amount' => $target,
            'target_date' => now()->addMonths($this->faker->numberBetween(6, 60)),
            'saved_amount' => (int) round($target * $this->faker->randomFloat(2, 0, 0.6)),
            'status' => 'active',
        ];
    }
}
