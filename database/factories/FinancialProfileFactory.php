<?php

namespace Database\Factories;

use App\Domain\Enums\EmploymentType;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialProfile>
 */
class FinancialProfileFactory extends Factory
{
    public function definition(): array
    {
        $income = $this->faker->numberBetween(30000, 300000);

        return [
            'user_id' => User::factory(),
            'monthly_income' => $income,
            // Kept proportional so generated profiles are plausible rather
            // than random noise that breaks every downstream assertion.
            'monthly_expenses' => (int) round($income * 0.4),
            'existing_emi' => (int) round($income * 0.2),
            'savings' => (int) round($income * 1.8),
            'other_obligations' => 0,
            'employment_type' => $this->faker->randomElement(EmploymentType::ids()),
        ];
    }
}
