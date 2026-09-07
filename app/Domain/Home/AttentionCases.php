<?php

namespace App\Domain\Home;

/**
 * The attention cases both implementations are held to.
 *
 * Every rule, the boundary on each threshold, the full priority ordering and
 * the silent cases. Goal dates are deliberately far from today so the fixture
 * does not change meaning tomorrow.
 */
final class AttentionCases
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'name' => 'comfortable customer',
                'note' => 'Inside every reference, so nothing is said.',
                'profile' => self::profile(200000, 40000, 10000, 0, 1000000),
                'goals' => [],
            ],
            [
                'name' => 'no income',
                'note' => 'Nothing can be measured, so nothing is claimed.',
                'profile' => self::profile(0, 0, 0, 0, 0),
                'goals' => [self::goal('A goal', 100000, 0, '2020-01-15')],
            ],
            [
                'name' => 'spending more than earned',
                'note' => 'Every rule fires; this is the priority order.',
                'profile' => self::profile(50000, 45000, 30000, 0, 10000),
                'goals' => [self::goal('Overdue goal', 200000, 50000, '2020-06-01')],
            ],
            [
                'name' => 'emi exactly on the reference',
                'note' => '45% is the reference, not past it.',
                'profile' => self::profile(100000, 20000, 45000, 0, 1000000),
                'goals' => [],
            ],
            [
                'name' => 'emi one point past the reference',
                'note' => 'The boundary the other side.',
                'profile' => self::profile(100000, 20000, 46000, 0, 1000000),
                'goals' => [],
            ],
            [
                'name' => 'buffer exactly six months',
                'note' => 'Six months of outgo saved: the target is met.',
                'profile' => self::profile(150000, 40000, 20000, 0, 360000),
                'goals' => [],
            ],
            [
                'name' => 'buffer a rupee short of six months',
                'note' => 'Silent: the rule reads the engine\'s figure, and '
                    .'the engine rounds 5.99998 months to 6.00. The rule does '
                    .'not carry a precision the rest of the product does not.',
                'profile' => self::profile(150000, 40000, 20000, 0, 359999),
                'goals' => [],
            ],
            [
                'name' => 'other obligations count towards outgo',
                'note' => 'Rent is not an EMI, but it still empties the buffer.',
                'profile' => self::profile(150000, 40000, 20000, 30000, 300000),
                'goals' => [],
            ],
            [
                'name' => 'overdue goal only',
                'note' => 'Healthy figures, one goal past its date.',
                'profile' => self::profile(200000, 40000, 10000, 0, 1000000),
                'goals' => [
                    self::goal('On time', 100000, 20000, '2099-01-15'),
                    self::goal('House deposit', 500000, 125000, '2020-03-01'),
                ],
            ],
            [
                'name' => 'goal met but past its date',
                'note' => 'A finished goal is not an overdue one.',
                'profile' => self::profile(200000, 40000, 10000, 0, 1000000),
                'goals' => [self::goal('Done', 100000, 100000, '2020-03-01')],
            ],
        ];
    }

    /** @return array<string, float|int> */
    private static function profile(
        int $income,
        int $expenses,
        int $emi,
        int $other,
        int $savings,
    ): array {
        return [
            'monthly_income' => $income,
            'monthly_expenses' => $expenses,
            'existing_emi' => $emi,
            'other_obligations' => $other,
            'savings' => $savings,
        ];
    }

    /** @return array<string, mixed> */
    private static function goal(
        string $title,
        int $target,
        int $saved,
        string $targetDate,
    ): array {
        return [
            'title' => $title,
            'target_amount' => $target,
            'saved_amount' => $saved,
            'target_date' => $targetDate,
        ];
    }
}
