<?php

namespace Database\Seeders;

use App\Domain\Enums\EmploymentType;
use App\Domain\Enums\GoalCategory;
use App\Domain\Enums\Intent;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * DEVELOPMENT SEED — the customer the Flutter app is designed against.
 *
 * These are the same figures the app's own mock data uses, so switching
 * FYNN_USE_MOCK on and off shows the same person either way, which makes any
 * difference between the two paths obvious.
 */
class DemoCustomerSeeder extends Seeder
{
    public function run(): void
    {
        // `migrate --seed` is an ordinary deployment step, and this creates a
        // real account on a known mobile number carrying invented finances.
        // In production that is a customer who does not exist, in the
        // customer table, with figures nobody entered.
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DemoCustomerSeeder is development data and must not run in '
                .'production. Run `php artisan migrate` without --seed.',
            );
        }

        $user = User::updateOrCreate(
            ['mobile' => config('fynnedge.demo_mobile')],
            ['name' => 'Rahul Sharma'],
        );

        $user->customerProfile()->updateOrCreate([], [
            'full_name' => 'Rahul Sharma',
            'age' => 34,
            'city' => 'Pune',
            'email' => 'rahul.sharma@example.com',
            'intent' => Intent::NeedLoan->value,
            'primary_goal' => GoalCategory::Business->value,
            'onboarding_completed_at' => now()->subMonths(2),
        ]);

        $user->financialProfile()->updateOrCreate([], [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
            'other_obligations' => 0,
            'employment_type' => EmploymentType::Business->value,
        ]);

        $goals = [
            [
                'title' => 'Business Expansion',
                'category' => GoalCategory::Business->value,
                'target_amount' => 1500000,
                'target_date' => now()->addDays(540),
                'saved_amount' => 320000,
                'note' => 'Second outlet + inventory',
            ],
            [
                'title' => 'Emergency Fund',
                'category' => GoalCategory::Medical->value,
                'target_amount' => 360000,
                'target_date' => now()->addDays(365),
                'saved_amount' => 180000,
            ],
            [
                'title' => 'Buy a Home',
                'category' => GoalCategory::Home->value,
                'target_amount' => 8000000,
                'target_date' => now()->addDays(1460),
                'saved_amount' => 450000,
            ],
        ];

        foreach ($goals as $goal) {
            $user->goals()->updateOrCreate(['title' => $goal['title']], $goal);
        }
    }
}
