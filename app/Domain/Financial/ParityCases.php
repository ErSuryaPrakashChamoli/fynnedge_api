<?php

namespace App\Domain\Financial;

/**
 * The shared test matrix for Laravel/Flutter parity.
 *
 * Defined once, here, so both engines are measured against the same inputs.
 * Dates are expressed as offsets in days from a pinned "as of" date, which
 * keeps the fixture stable no matter when it is regenerated.
 */
final class ParityCases
{
    /** The pinned clock both engines use when evaluating these cases. */
    public const AS_OF = '2026-09-05T10:30:00+00:00';

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'case_1_normal',
                'profile' => self::profile('100000.00', '40000.00', '20000.00', '0.00', '180000.00'),
                'goals' => [
                    self::goal('1', 'Business Expansion', '1500000.00', '320000.00', 540),
                ],
            ],
            [
                'name' => 'case_2_zero_income',
                'profile' => self::profile('0.00', '15000.00', '0.00', '0.00', '25000.00'),
                'goals' => [],
            ],
            [
                'name' => 'case_3_zero_emi',
                'profile' => self::profile('80000.00', '30000.00', '0.00', '0.00', '150000.00'),
                'goals' => [],
            ],
            [
                'name' => 'case_4_no_other_obligations',
                'profile' => self::profile('100000.00', '40000.00', '20000.00', '0.00', '180000.00'),
                'goals' => [],
            ],
            [
                'name' => 'case_5_high_obligations',
                'profile' => self::profile('60000.00', '35000.00', '18000.00', '12000.00', '20000.00'),
                'goals' => [],
            ],
            [
                'name' => 'case_6_no_goals',
                'profile' => self::profile('120000.00', '50000.00', '10000.00', '5000.00', '400000.00'),
                'goals' => [],
            ],
            [
                'name' => 'case_7_multiple_goals',
                'profile' => self::profile('150000.00', '55000.00', '25000.00', '5000.00', '500000.00'),
                'goals' => [
                    self::goal('1', 'Emergency Fund', '360000.00', '180000.00', 365),
                    self::goal('2', 'Business Expansion', '1500000.00', '320000.00', 540),
                    self::goal('3', 'Buy a Home', '8000000.00', '450000.00', 1460),
                ],
            ],
            [
                'name' => 'case_8_goal_completed',
                'profile' => self::profile('100000.00', '40000.00', '0.00', '0.00', '600000.00'),
                'goals' => [
                    self::goal('1', 'Fully Funded', '500000.00', '500000.00', 200),
                    self::goal('2', 'Overfunded', '100000.00', '150000.00', 200),
                ],
            ],
            [
                'name' => 'case_9_deadlines',
                'profile' => self::profile('90000.00', '30000.00', '10000.00', '0.00', '100000.00'),
                'goals' => [
                    self::goal('1', 'Due tomorrow', '100000.00', '10000.00', 1),
                    self::goal('2', 'Due today', '100000.00', '10000.00', 0),
                    self::goal('3', 'Overdue', '100000.00', '10000.00', -30),
                    self::goal('4', 'Exactly one month', '60000.00', '0.00', 30),
                    self::goal('5', 'Part month rounds up', '60000.00', '0.00', 31),
                ],
            ],
            [
                'name' => 'case_10_large_and_precise',
                'profile' => self::profile('9999999.99', '1234567.89', '987654.32', '111111.11', '87654321.00'),
                'goals' => [
                    self::goal('1', 'Very large', '99999999.99', '11111111.11', 999),
                    self::goal('2', 'Awkward thirds', '100000.00', '0.01', 3),
                ],
            ],
            [
                'name' => 'case_11_negative_surplus',
                'profile' => self::profile('50000.00', '45000.00', '15000.00', '5000.00', '10000.00'),
                'goals' => [],
            ],
            [
                'name' => 'case_12_all_zero',
                'profile' => self::profile('0.00', '0.00', '0.00', '0.00', '0.00'),
                'goals' => [
                    self::goal('1', 'Zero target', '0.00', '0.00', 100),
                ],
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
