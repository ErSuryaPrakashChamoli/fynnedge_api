<?php

namespace App\Domain\Match;

/**
 * The FynnMatch test matrix, defined once so the PHP engine and its Dart
 * mirror are measured against the same customers and the same requests.
 *
 * Each case names the behaviour it pins down. `determinism_repeat` is a
 * byte-for-byte duplicate of `multiple_business_matches`: the fixture holding
 * two identical expected results is the proof that the same inputs produce
 * the same ordering, criteria and pricing every run.
 */
final class MatchCases
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        // Shared by multiple_business_matches and determinism_repeat.
        $businessCustomer = self::profile('100000.00', '40000.00', '20000.00', '0.00', '180000.00');
        $businessRequest = self::request(1000000, 60, 'business');

        return [
            [
                'name' => 'strong_fit_personal',
                'note' => 'Every documented rule checked and passed: Strong Match.',
                'profile' => self::profile('120000.00', '45000.00', '8000.00', '0.00', '400000.00'),
                'age' => 34,
                'request' => self::request(800000, 48, 'personal'),
            ],
            [
                'name' => 'amount_outside_product_range',
                'note' => 'Below every personal product\'s minimum; the amount is '
                    .'reported as a blocker, never silently raised to fit.',
                'profile' => self::profile('120000.00', '45000.00', '8000.00', '0.00', '400000.00'),
                'age' => 34,
                'request' => self::request(200000, 48, 'personal'),
            ],
            [
                'name' => 'income_below_requirement',
                'note' => 'Under the published minimum income on both personal products.',
                'profile' => self::profile('25000.00', '15000.00', '0.00', '0.00', '50000.00'),
                'age' => 30,
                'request' => self::request(600000, 60, 'personal'),
            ],
            [
                'name' => 'emi_beyond_affordability_ceiling',
                'note' => 'Projected EMI ratio past 45%: a caution, not a rejection, '
                    .'because no product documents an EMI-ratio rule.',
                'profile' => self::profile('60000.00', '20000.00', '15000.00', '0.00', '100000.00'),
                'age' => 35,
                'request' => self::request(600000, 24, 'personal'),
            ],
            [
                'name' => 'multiple_business_matches',
                'note' => 'Two business products viable; ordering must be stable.',
                'profile' => $businessCustomer,
                'age' => 34,
                'request' => $businessRequest,
            ],
            [
                'name' => 'determinism_repeat',
                'note' => 'Identical to multiple_business_matches. Same inputs, '
                    .'same results — the two expected blocks must be identical.',
                'profile' => $businessCustomer,
                'age' => 34,
                'request' => $businessRequest,
            ],
            [
                'name' => 'no_matching_products',
                'note' => 'A home loan far below the only home product\'s minimum.',
                'profile' => self::profile('150000.00', '50000.00', '10000.00', '0.00', '600000.00'),
                'age' => 34,
                'request' => self::request(500000, 120, 'home'),
            ],
            [
                'name' => 'incomplete_financial_profile',
                'note' => 'No income on file: income and affordability report '
                    .'themselves unavailable rather than passing.',
                'profile' => self::profile('0.00', '0.00', '0.00', '0.00', '0.00'),
                'age' => null,
                'request' => self::request(800000, 48, 'personal'),
            ],
            [
                'name' => 'age_at_maturity_exceeded',
                'note' => 'Blocked on the one product documenting an age limit; '
                    .'the product that documents none must not be blocked with it.',
                'profile' => self::profile('120000.00', '45000.00', '8000.00', '0.00', '400000.00'),
                'age' => 56,
                'request' => self::request(800000, 60, 'personal'),
            ],
            [
                'name' => 'tenure_outside_product_range',
                'note' => 'A 6-month term no product offers: clamped for pricing, '
                    .'and the adjustment is stated.',
                'profile' => self::profile('200000.00', '50000.00', '5000.00', '0.00', '900000.00'),
                'age' => 34,
                'request' => self::request(800000, 6, 'personal'),
            ],
            [
                'name' => 'purpose_not_stated',
                'note' => 'No purpose given: nothing is assumed on the customer\'s behalf.',
                'profile' => self::profile('150000.00', '50000.00', '10000.00', '0.00', '600000.00'),
                'age' => 34,
                'request' => self::request(1200000, 60, null),
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
    private static function request(int $amount, int $tenure, ?string $purpose): array
    {
        return [
            'amount' => (string) $amount.'.00',
            'tenure_months' => $tenure,
            'purpose' => $purpose,
        ];
    }
}
