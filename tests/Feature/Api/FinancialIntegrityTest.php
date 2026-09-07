<?php

namespace Tests\Feature\Api;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Money;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The figures hold up at the edges, and the same EMI travels the whole way.
 *
 * FynnEdge's promise is that a customer's numbers are deterministic. That is
 * only true if the awkward values behave — zero income, a rupee, ten crore,
 * a negative surplus — and if the EMI quoted by FynnMatch is the one the
 * application records, with nothing recalculated in between.
 */
class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $figures = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update(array_merge([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'other_obligations' => 0,
            'savings' => 600000,
        ], $figures));
        Sanctum::actingAs($user);

        return $user;
    }

    // --- Money at the edges ---------------------------------------------

    #[DataProvider('awkwardFigures')]
    public function test_the_engine_survives_awkward_figures(
        string $name,
        array $figures,
    ): void {
        $this->customer($figures);

        $data = $this->getJson('/api/v1/financial-profile')
            ->assertOk()
            ->json('data');

        $income = (float) $figures['monthly_income'];
        $outgo = (float) $figures['monthly_expenses']
            + (float) $figures['existing_emi']
            + (float) ($figures['other_obligations'] ?? 0);

        // Every identity the engine promises, at every scale.
        $this->assertEqualsWithDelta(
            $outgo,
            $data['derived']['monthly_outgo'],
            0.01,
            $name,
        );
        $this->assertEqualsWithDelta(
            $income - $outgo,
            $data['derived']['surplus'],
            0.01,
            "{$name}: surplus is not income minus outgo",
        );

        // No income means no ratio to state, rather than a division result.
        if ($income <= 0) {
            $this->assertSame(0.0, (float) $data['derived']['emi_ratio'], $name);
            $this->assertNull(
                $this->getJson('/api/v1/fynn-score')->json('data.score'),
                "{$name}: a score was produced without an income",
            );
        }

        // And nothing anywhere is NaN or infinite.
        foreach ($data['derived'] as $key => $value) {
            $this->assertTrue(
                is_finite((float) $value),
                "{$name}: derived.{$key} is not a finite number",
            );
        }
    }

    public static function awkwardFigures(): array
    {
        return [
            'all zero' => ['all zero', [
                'monthly_income' => 0, 'monthly_expenses' => 0,
                'existing_emi' => 0, 'savings' => 0,
            ]],
            'one paisa' => ['one paisa', [
                'monthly_income' => 0.01, 'monthly_expenses' => 0,
                'existing_emi' => 0, 'savings' => 0.01,
            ]],
            'sub rupee' => ['sub rupee', [
                'monthly_income' => 999.99, 'monthly_expenses' => 333.33,
                'existing_emi' => 333.33, 'savings' => 0.99,
            ]],
            'a lakh' => ['a lakh', [
                'monthly_income' => 100000, 'monthly_expenses' => 40000,
                'existing_emi' => 20000, 'savings' => 600000,
            ]],
            'precise crore-scale' => ['precise crore-scale', [
                'monthly_income' => 1234567.89, 'monthly_expenses' => 234567.89,
                'existing_emi' => 100000.01, 'savings' => 9999999.99,
            ]],
            'ten crore' => ['ten crore', [
                'monthly_income' => 100000000, 'monthly_expenses' => 1,
                'existing_emi' => 1, 'savings' => 100000000,
            ]],
            'negative surplus' => ['negative surplus', [
                'monthly_income' => 50000, 'monthly_expenses' => 45000,
                'existing_emi' => 30000, 'savings' => 1000,
            ]],
            'zero outgo' => ['zero outgo', [
                'monthly_income' => 80000, 'monthly_expenses' => 0,
                'existing_emi' => 0, 'savings' => 50000,
            ]],
            'income all committed' => ['income all committed', [
                'monthly_income' => 60000, 'monthly_expenses' => 30000,
                'existing_emi' => 30000, 'savings' => 0,
            ]],
        ];
    }

    public function test_a_negative_surplus_is_never_clamped_away(): void
    {
        $this->customer([
            'monthly_income' => 50000,
            'monthly_expenses' => 45000,
            'existing_emi' => 30000,
        ]);

        foreach ([
            '/api/v1/financial-profile' => 'data.derived.surplus',
            '/api/v1/home' => 'data.financial.surplus',
            '/api/v1/financial-summary' => 'data.derived.surplus',
        ] as $uri => $path) {
            $this->assertSame(
                -25000.0,
                (float) $this->getJson($uri)->json($path),
                "{$uri} rounded a shortfall up to something comfortable",
            );
        }
    }

    public function test_money_never_loses_a_paisa_through_the_round_trip(): void
    {
        foreach ([
            '0', '0.01', '0.99', '999.99', '100000', '1234567.89',
            '100000000', '-25000.55',
        ] as $value) {
            $money = Money::of($value);

            $this->assertSame(
                $value === '0' ? '0.00' : number_format((float) $value, 2, '.', ''),
                $money->toDecimalString(),
                "Money lost precision on {$value}",
            );
            $this->assertSame(
                $money->paise,
                Money::of($money->toDecimalString())->paise,
                "Money did not survive a string round trip at {$value}",
            );
        }
    }

    // --- EMI -------------------------------------------------------------

    #[DataProvider('emiCases')]
    public function test_the_one_emi_formula_is_the_one_used(
        float $principal,
        float $rate,
        int $months,
        float $expected,
    ): void {
        $emi = app(FinanceService::class)->emi($principal, $rate, $months);

        $this->assertEqualsWithDelta($expected, $emi, 0.01);
    }

    public static function emiCases(): array
    {
        // Verified independently against the standard reducing-balance
        // formula, not read back out of the engine.
        return [
            '10L at 13.25% over 60' => [1000000, 13.25, 60, 22881.26],
            '5L at 11.5% over 60' => [500000, 11.5, 60, 10996.30],
            'zero rate splits evenly' => [120000, 0, 12, 10000.0],
            'one month is principal plus one month interest' => [
                100000, 12, 1, 101000.0,
            ],
            'zero principal is zero' => [0, 12, 60, 0.0],
            'zero tenure is zero' => [100000, 12, 0, 0.0],
        ];
    }

    public function test_the_same_emi_travels_from_match_to_application(): void
    {
        $this->customer();

        $match = collect(
            $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=business')
                ->assertOk()
                ->json('data.matches')
        )->firstWhere('product.id', 'prod_bl_b');

        $emi = $match['pricing']['emi'];

        // Loan Detail's own simulation of the same product.
        $detail = $this->getJson('/api/v1/fynn-match/prod_bl_b?amount=1000000&tenure_months=60&purpose=business')
            ->assertOk()->json('data.pricing.emi');

        // FynnTwin, on the same loan.
        $twin = $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'loan',
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertOk()->json('data.loan.emi');

        // And what the application recorded.
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        $this->assertSame($emi, $detail, 'Loan Detail re-priced the loan');
        $this->assertSame($emi, $twin, 'FynnTwin re-priced the loan');
        $this->assertSame($emi, $application['emi'], 'The application re-priced it');
        $this->assertSame(
            $match['pricing']['total_payable'],
            $application['total_payable'],
        );
        $this->assertSame(
            $match['pricing']['processing_fee'],
            $application['processing_fee'],
        );
        $this->assertSame($match['match']['category'], $application['match_category']);
    }

    public function test_the_pricing_snapshot_outlives_a_change_of_figures(): void
    {
        $this->customer();

        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/applications/{$application['id']}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // Everything the pricing was based on changes underneath it.
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 20000,
            'monthly_expenses' => 19000,
            'existing_emi' => 15000,
            'other_obligations' => 0,
            'savings' => 0,
            'employment_type' => 'salaried',
        ])->assertOk();

        $after = $this->getJson("/api/v1/applications/{$application['id']}")
            ->assertOk()->json('data');

        foreach ([
            'amount', 'tenure_months', 'interest_rate', 'emi',
            'total_interest', 'total_payable', 'processing_fee',
            'projected_emi_ratio', 'match_category', 'product_name', 'lender',
        ] as $field) {
            $this->assertSame(
                $application[$field],
                $after[$field],
                "{$field} was rewritten from today's figures",
            );
        }
    }

    public function test_a_submitted_application_cannot_be_re_priced(): void
    {
        $this->customer();

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->json('data.id');

        $before = $this->getJson("/api/v1/applications/{$id}")->json('data');

        $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // 409, the established answer for an operation this state forbids.
        $this->patchJson("/api/v1/applications/{$id}", [
            'amount' => 100,
            'tenure_months' => 12,
        ])->assertStatus(409);

        $after = $this->getJson("/api/v1/applications/{$id}")->json('data');
        $this->assertSame($before['amount'], $after['amount']);
        $this->assertSame($before['emi'], $after['emi']);
    }

    // --- FynnScore thresholds -------------------------------------------

    #[DataProvider('scoreBoundaries')]
    public function test_the_score_holds_its_documented_boundaries(
        array $figures,
        int $expected,
        string $band,
    ): void {
        $this->customer($figures);

        $score = $this->getJson('/api/v1/fynn-score')->assertOk()->json('data');

        $this->assertSame($expected, $score['score']);
        $this->assertSame($band, $score['band']);
        $this->assertCount(3, $score['dimensions']);
    }

    public static function scoreBoundaries(): array
    {
        // EMI burden 100 at <=20%, 0 at >=45%. Spending 100 at <=40%, 0 at
        // >=80%. Buffer 100 at >=6 months, 0 at 0. Equal weights, rounded.
        return [
            'every dimension at full marks' => [
                [
                    'monthly_income' => 100000,
                    'monthly_expenses' => 40000,
                    'existing_emi' => 20000,
                    'savings' => 360000,
                ],
                100,
                'strong',
            ],
            'every dimension at zero' => [
                [
                    'monthly_income' => 100000,
                    'monthly_expenses' => 80000,
                    'existing_emi' => 45000,
                    'savings' => 0,
                ],
                0,
                'at_risk',
            ],
            'the far side of every anchor is still zero' => [
                [
                    'monthly_income' => 100000,
                    'monthly_expenses' => 90000,
                    'existing_emi' => 60000,
                    'savings' => 0,
                ],
                0,
                'at_risk',
            ],
        ];
    }

    public function test_the_score_carries_no_fourth_dimension(): void
    {
        $this->customer();

        $score = $this->getJson('/api/v1/fynn-score')->json('data');
        // The three, in the engine's own order.
        $this->assertSame(
            ['emi_burden', 'spending_control', 'emergency_buffer'],
            array_column($score['dimensions'], 'key'),
        );

        // What the score is *made of*, not the disclaimer, which says what
        // it is not — "not a credit bureau score, not a lender's approval
        // score" is the sentence doing the work and must survive.
        $measured = json_encode([
            $score['dimensions'],
            $score['recommendations'],
            $score['metrics'],
        ]);

        foreach ([
            'cibil', 'bureau', 'credit_history', 'income_stability',
            'approval', 'underwriting', 'goal_score', 'vintage',
        ] as $invention) {
            $this->assertStringNotContainsStringIgnoringCase(
                $invention,
                $measured,
                "FynnScore measures something it should not: {$invention}",
            );
        }

        $this->assertStringContainsString(
            'not a credit bureau score',
            $score['disclaimer'],
        );
    }

    public function test_the_affordability_reference_has_one_definition(): void
    {
        $this->customer();

        $match = $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=business')
            ->json('data.affordability_ceiling_percent');
        $twin = $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'loan',
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->json('data.affordability.reference_percent');

        $this->assertEquals(FinancialEngine::AFFORDABILITY_CEILING_PERCENT, $match);
        $this->assertEquals(FinancialEngine::AFFORDABILITY_CEILING_PERCENT, $twin);
    }
}
