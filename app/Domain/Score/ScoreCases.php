<?php

namespace App\Domain\Score;

/**
 * The FynnScore test matrix, defined once so both engines are measured
 * against the same inputs.
 *
 * `strong_goal_progress` and `weak_goal_progress` deliberately share an
 * identical financial profile and differ only in their goals. Their scores
 * must be identical — that is the fixture proving goals do not move the
 * numerical score.
 */
final class ScoreCases
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        // Shared by the two goal-progress cases.
        $goalProfile = self::profile('120000.00', '48000.00', '18000.00', '0.00', '400000.00');

        return [
            [
                'name' => 'healthy_profile',
                'note' => 'Comfortably inside every anchor.',
                'profile' => self::profile('150000.00', '50000.00', '20000.00', '0.00', '600000.00'),
                'goals' => [],
            ],
            [
                'name' => 'high_emi_burden',
                'note' => 'EMIs past the 45% ceiling.',
                'profile' => self::profile('100000.00', '30000.00', '48000.00', '0.00', '400000.00'),
                'goals' => [],
            ],
            [
                'name' => 'high_spending_ratio',
                'note' => 'Spending past the 80% zero anchor.',
                'profile' => self::profile('100000.00', '82000.00', '5000.00', '0.00', '500000.00'),
                'goals' => [],
            ],
            [
                'name' => 'low_emergency_savings',
                'note' => 'Strong ratios, almost no buffer.',
                'profile' => self::profile('100000.00', '35000.00', '15000.00', '0.00', '25000.00'),
                'goals' => [],
            ],
            [
                'name' => 'negative_surplus',
                'note' => 'Spending more than income.',
                'profile' => self::profile('50000.00', '45000.00', '15000.00', '5000.00', '10000.00'),
                'goals' => [],
            ],
            [
                'name' => 'strong_goal_progress',
                'note' => 'Same finances as weak_goal_progress; goals nearly funded.',
                'profile' => $goalProfile,
                'goals' => [
                    self::goal('1', 'Nearly there', '500000.00', '450000.00', 400),
                    self::goal('2', 'Funded', '200000.00', '200000.00', 90),
                ],
            ],
            [
                'name' => 'weak_goal_progress',
                'note' => 'Same finances as strong_goal_progress; goals barely started.',
                'profile' => $goalProfile,
                'goals' => [
                    self::goal('1', 'Barely started', '500000.00', '5000.00', 30),
                    self::goal('2', 'Overdue', '200000.00', '1000.00', -60),
                ],
            ],
            [
                'name' => 'zero_income',
                'note' => 'No income: insufficient data, never a fabricated score.',
                'profile' => self::profile('0.00', '20000.00', '0.00', '0.00', '50000.00'),
                'goals' => [],
            ],
            [
                'name' => 'all_zero_profile',
                'note' => 'Nothing entered at all.',
                'profile' => self::profile('0.00', '0.00', '0.00', '0.00', '0.00'),
                'goals' => [],
            ],
            [
                'name' => 'incomplete_profile',
                'note' => 'Expenses entered but income missing.',
                'profile' => self::profile('0.00', '35000.00', '12000.00', '3000.00', '0.00'),
                'goals' => [],
            ],
            [
                'name' => 'exactly_on_emi_anchor',
                'note' => 'emi_ratio exactly 45%: the EMI dimension must reach 0.',
                'profile' => self::profile('100000.00', '30000.00', '45000.00', '0.00', '450000.00'),
                'goals' => [],
            ],
            [
                'name' => 'exactly_six_months_buffer',
                'note' => 'emergency_months exactly 6: the buffer dimension must reach 100.',
                'profile' => self::profile('100000.00', '40000.00', '10000.00', '0.00', '300000.00'),
                'goals' => [],
            ],
            [
                'name' => 'perfect_profile',
                'note' => 'Every dimension at full marks.',
                'profile' => self::profile('200000.00', '60000.00', '20000.00', '0.00', '1000000.00'),
                'goals' => [],
            ],
            [
                'name' => 'worst_case',
                'note' => 'Every dimension at zero.',
                'profile' => self::profile('50000.00', '45000.00', '30000.00', '0.00', '0.00'),
                'goals' => [],
            ],
        ];
    }

    /** @return array<string, string> */
    private static function profile(
        string $income,
        string $expenses,
        string $emi,
        string $obligations,
        string $savings,
    ): array {
        return [
            'monthly_income' => $income,
            'monthly_expenses' => $expenses,
            'existing_emi' => $emi,
            'other_obligations' => $obligations,
            'savings' => $savings,
        ];
    }

    /** @return array<string, mixed> */
    private static function goal(
        string $id,
        string $title,
        string $target,
        string $saved,
        int $daysFromAsOf,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'target_amount' => $target,
            'saved_amount' => $saved,
            'days_from_as_of' => $daysFromAsOf,
        ];
    }
}
