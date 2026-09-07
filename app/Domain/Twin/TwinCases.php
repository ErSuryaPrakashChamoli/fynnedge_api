<?php

namespace App\Domain\Twin;

/**
 * The FynnTwin test matrix, defined once so the PHP engine and its Dart
 * mirror are measured against the same customers and the same assumptions.
 */
final class TwinCases
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $comfortable = self::profile('100000.00', '40000.00', '20000.00', '0.00', '180000.00');
        $stretched = self::profile('60000.00', '25000.00', '15000.00', '0.00', '60000.00');

        return [
            [
                'name' => 'loan_within_reference',
                'note' => 'A loan this customer can carry inside the 45% reference.',
                'profile' => $comfortable,
                'scenario' => self::loan('1000000.00', 60, 'prod_bl_b'),
            ],
            [
                'name' => 'loan_past_reference',
                'note' => 'The same loan against a smaller income: past the reference, still not a refusal.',
                'profile' => $stretched,
                'scenario' => self::loan('1000000.00', 60, 'prod_bl_b'),
            ],
            [
                'name' => 'loan_into_negative_surplus',
                'note' => 'A loan that would put the customer under water. The negative surplus is kept.',
                'profile' => self::profile('50000.00', '30000.00', '20000.00', '0.00', '20000.00'),
                'scenario' => self::loan('1000000.00', 60, 'prod_bl_b'),
            ],
            [
                'name' => 'loan_short_tenure',
                'note' => 'A shorter term: a larger EMI on the same amount.',
                'profile' => $comfortable,
                'scenario' => self::loan('500000.00', 24, 'prod_pl_a'),
            ],
            [
                'name' => 'spending_more',
                'note' => 'Outgo and surplus move; the EMI ratio does not.',
                'profile' => $comfortable,
                'scenario' => [
                    'type' => 'expense_change',
                    'monthly_change' => '8000.00',
                ],
            ],
            [
                'name' => 'spending_less',
                'note' => 'A cut in spending, with the emergency buffer improving.',
                'profile' => $comfortable,
                'scenario' => [
                    'type' => 'expense_change',
                    'monthly_change' => '-5000.00',
                ],
            ],
            [
                'name' => 'saving_monthly',
                'note' => 'Savings grow by an explicit amount over an explicit number of months.',
                'profile' => $comfortable,
                'scenario' => [
                    'type' => 'saving_change',
                    'monthly_amount' => '10000.00',
                    'months' => 12,
                ],
            ],
            [
                'name' => 'paise_precision',
                'note' => 'Awkward paise on both the profile and the assumption.',
                'profile' => self::profile('83333.33', '40000.55', '12500.01', '1250.49', '77777.77'),
                'scenario' => [
                    'type' => 'expense_change',
                    'monthly_change' => '1234.45',
                ],
            ],
            [
                'name' => 'no_income',
                'note' => 'Nothing to project against: no ratio, no score, and it says so.',
                'profile' => self::profile('0.00', '0.00', '0.00', '0.00', '0.00'),
                'scenario' => self::loan('1000000.00', 60, 'prod_bl_b'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function loan(string $amount, int $tenure, string $productId): array
    {
        return [
            'type' => 'loan',
            'amount' => $amount,
            'tenure_months' => $tenure,
            'product_id' => $productId,
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
}
