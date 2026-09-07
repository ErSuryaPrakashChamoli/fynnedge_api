<?php

namespace Tests\Feature\Api;

use App\Domain\Financial\FinancialEngine;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FynnTwinTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update(array_merge([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'other_obligations' => 0,
            'savings' => 180000,
        ], $financials));
        Sanctum::actingAs($user);

        return $user;
    }

    private function simulate(array $scenario): TestResponse
    {
        return $this->postJson('/api/v1/fynn-twin/simulate', $scenario);
    }

    private function loan(array $overrides = []): TestResponse
    {
        return $this->simulate(array_merge([
            'type' => 'loan',
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ], $overrides));
    }

    // --- The starting point -------------------------------------------------

    public function test_it_starts_from_the_authenticated_customers_state(): void
    {
        $rich = User::factory()->create();
        $rich->ensureProfiles();
        $rich->financialProfile->update(['monthly_income' => 5000000]);

        $this->customer();

        $current = $this->loan()->assertOk()->json('data.current');

        // Straight from FinancialEngine, for this customer.
        $this->assertSame(100000, $current['monthly_income']);
        $this->assertSame(60000, $current['monthly_outgo']);
        $this->assertSame(40000, $current['surplus']);
        $this->assertSame(20, $current['emi_ratio']);
        $this->assertSame(3, $current['emergency_months']);
    }

    public function test_without_an_income_nothing_is_projected(): void
    {
        $this->customer(['monthly_income' => 0, 'monthly_expenses' => 0]);

        $this->getJson('/api/v1/fynn-twin')
            ->assertOk()
            ->assertJsonPath('data.has_financial_profile', false);

        $data = $this->loan()->assertOk()->json('data');

        $this->assertFalse($data['is_measurable']);
        $this->assertNull($data['affordability']['projected_emi_ratio']);
        $this->assertNull($data['fynn_score']['current']);
        $this->assertSame('no_income', $data['observations'][0]['key']);
    }

    // --- The loan scenario --------------------------------------------------

    public function test_a_loan_is_priced_by_the_product_and_the_finance_service(): void
    {
        $this->customer();

        $data = $this->loan()->assertOk()->json('data');

        // 10L at United Credit Bank's published 12.4% over 60 months.
        $this->assertSame('prod_bl_b', $data['loan']['product_id']);
        $this->assertSame('United Credit Bank', $data['loan']['lender']);
        $this->assertSame(12.4, $data['loan']['interest_rate']);
        $this->assertSame(22447.11, $data['loan']['emi']);
        $this->assertSame(10000, $data['loan']['processing_fee']);
    }

    public function test_the_loan_emi_lands_on_the_projected_state(): void
    {
        $this->customer();
        $data = $this->loan()->assertOk()->json('data');

        // Outgo picks up exactly the EMI; income, expenses and savings do not
        // move, because a loan does not move them.
        $this->assertSame(82447.11, $data['projected']['monthly_outgo']);
        $this->assertSame(17552.89, $data['projected']['surplus']);
        $this->assertSame(42447.11, $data['projected']['existing_emi']);
        $this->assertSame(180000, $data['projected']['savings']);

        $this->assertSame(22447.11, $data['difference']['monthly_outgo']);
        $this->assertSame(-22447.11, $data['difference']['surplus']);
        $this->assertSame(42.45, $data['projected']['emi_ratio']);
        $this->assertSame(22.45, $data['difference']['emi_ratio']);
    }

    public function test_it_reuses_the_existing_affordability_reference(): void
    {
        $this->customer();
        $data = $this->loan()->assertOk()->json('data');

        $this->assertEquals(
            FinancialEngine::AFFORDABILITY_CEILING_PERCENT,
            $data['affordability']['reference_percent'],
        );
        $this->assertSame(42.45, $data['affordability']['projected_emi_ratio']);
        $this->assertTrue($data['affordability']['within_reference']);
    }

    public function test_a_loan_past_the_reference_is_flagged_not_refused(): void
    {
        $this->customer(['monthly_income' => 60000, 'existing_emi' => 15000]);

        $data = $this->loan()->assertOk()->json('data');

        $this->assertFalse($data['affordability']['within_reference']);

        $keys = array_column($data['observations'], 'key');
        $this->assertContains('above_affordability_reference', $keys);

        // FynnEdge's reference, never a lender's decision.
        $text = json_encode($data['observations']);
        $this->assertStringContainsString('not a lender rule', $text);
        foreach (['approved', 'rejected', 'you qualify'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $text);
        }
    }

    public function test_a_negative_surplus_is_shown_not_softened(): void
    {
        $this->customer(['monthly_income' => 50000, 'monthly_expenses' => 30000]);

        $data = $this->loan()->assertOk()->json('data');

        // 50,000 income against 30,000 expenses, 20,000 EMIs and a new
        // 22,447.11 EMI: the customer would be under water, and it says so.
        $this->assertSame(-22447.11, $data['projected']['surplus']);

        $keys = array_column($data['observations'], 'key');
        $this->assertContains('negative_surplus', $keys);
    }

    // --- The other scenarios ------------------------------------------------

    public function test_spending_more_moves_outgo_and_surplus(): void
    {
        $this->customer();

        $data = $this->simulate([
            'type' => 'expense_change',
            'monthly_change' => 8000,
        ])->assertOk()->json('data');

        $this->assertSame(48000, $data['projected']['monthly_expenses']);
        $this->assertSame(68000, $data['projected']['monthly_outgo']);
        $this->assertSame(32000, $data['projected']['surplus']);
        // EMIs did not change, so neither did the EMI ratio.
        $this->assertSame(20, $data['projected']['emi_ratio']);
        $this->assertSame(-8000, $data['difference']['surplus']);
    }

    public function test_spending_less_is_a_valid_scenario(): void
    {
        $this->customer();

        $data = $this->simulate([
            'type' => 'expense_change',
            'monthly_change' => -5000,
        ])->assertOk()->json('data');

        $this->assertSame(35000, $data['projected']['monthly_expenses']);
        $this->assertSame(45000, $data['projected']['surplus']);

        $keys = array_column($data['observations'], 'key');
        $this->assertContains('emergency_cover_rises', $keys);
    }

    public function test_setting_money_aside_grows_savings_and_cover(): void
    {
        $this->customer();

        $data = $this->simulate([
            'type' => 'saving_change',
            'monthly_amount' => 10000,
            'months' => 12,
        ])->assertOk()->json('data');

        // 1,80,000 plus 12 x 10,000. Outgo is untouched: setting money aside
        // is not spending it.
        $this->assertSame(300000, $data['projected']['savings']);
        $this->assertSame(60000, $data['projected']['monthly_outgo']);
        $this->assertSame(120000, $data['difference']['savings']);
        $this->assertSame(5, $data['projected']['emergency_months']);

        // The assumption is stated back rather than left implied.
        $keys = array_column($data['observations'], 'key');
        $this->assertContains('saving_assumption', $keys);
    }

    // --- What it must never do ----------------------------------------------

    public function test_a_simulation_writes_nothing(): void
    {
        $user = $this->customer();
        $before = $user->financialProfile->only([
            'monthly_income', 'monthly_expenses', 'existing_emi', 'savings',
        ]);

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $queries++;
            }
        });

        $this->loan()->assertOk();
        $this->simulate(['type' => 'expense_change', 'monthly_change' => 5000])
            ->assertOk();

        // Two simulations, no writes of any kind.
        $this->assertSame(0, $queries);

        $this->assertSame(
            $before,
            $user->financialProfile->fresh()->only([
                'monthly_income', 'monthly_expenses', 'existing_emi', 'savings',
            ]),
        );

        // And in particular, no application was created.
        $this->assertSame(0, LoanApplication::count());
    }

    public function test_the_same_scenario_gives_the_same_projection(): void
    {
        $this->customer();

        $this->assertSame(
            $this->loan()->json('data'),
            $this->loan()->json('data'),
        );
    }

    public function test_it_carries_no_invented_score(): void
    {
        $this->customer();
        $data = $this->loan()->assertOk()->json('data');

        // A projected FynnScore is the existing engine over the projected
        // snapshot, and is labelled a simulation.
        $expected = $this->getJson('/api/v1/fynn-score')->json('data.score');
        $this->assertSame($expected, $data['fynn_score']['current']);
        $this->assertTrue($data['fynn_score']['is_simulated']);

        // No invented composite of any kind.
        $body = $this->loan()->getContent();
        foreach (['impact_score', 'risk_score', 'probability', 'approval_odds'] as $invented) {
            $this->assertStringNotContainsString($invented, $body);
        }
    }

    public function test_every_projection_says_what_it_is(): void
    {
        $this->customer();
        $data = $this->loan()->assertOk()->json('data');

        $this->assertTrue($data['is_projection']);
        $this->assertStringContainsString('not a prediction', $data['disclaimer']);
    }

    // --- Validation and tampering -------------------------------------------

    public function test_it_requires_a_session(): void
    {
        $this->getJson('/api/v1/fynn-twin')->assertStatus(401);
        $this->postJson('/api/v1/fynn-twin/simulate', ['type' => 'loan'])
            ->assertStatus(401);
    }

    public function test_a_user_id_in_the_payload_cannot_change_whose_money_it_is(): void
    {
        $rich = User::factory()->create();
        $rich->ensureProfiles();
        $rich->financialProfile->update(['monthly_income' => 5000000]);

        $this->customer();

        $current = $this->loan([
            'user_id' => $rich->id,
            'customer_id' => $rich->id,
            'owner_id' => $rich->id,
        ])->assertOk()->json('data.current');

        $this->assertSame(100000, $current['monthly_income']);
    }

    public function test_client_supplied_figures_are_ignored(): void
    {
        $this->customer();

        $tampered = $this->loan([
            // All of these are the server's to compute.
            'emi' => 1,
            'interest_rate' => 0.1,
            'total_payable' => 1,
            'monthly_outgo' => 1,
            'surplus' => 999999,
            'emi_ratio' => 1,
            'score' => 100,
            'monthly_income' => 5000000,
            'savings' => 99999999,
        ])->assertOk()->json('data');

        $honest = $this->loan()->json('data');

        $this->assertSame($honest, $tampered);
        $this->assertSame(22447.11, $tampered['loan']['emi']);
        $this->assertSame(12.4, $tampered['loan']['interest_rate']);
        $this->assertSame(17552.89, $tampered['projected']['surplus']);
    }

    public function test_a_product_outside_the_catalogue_is_refused(): void
    {
        $this->customer();

        $this->loan(['product_id' => 'prod_imaginary'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That product is not in the catalogue.');
    }

    public function test_an_unknown_scenario_is_refused(): void
    {
        $this->customer();

        $this->simulate(['type' => 'predict_my_salary'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_it_validates_the_assumptions(): void
    {
        $this->customer();

        $this->simulate(['type' => 'loan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'amount', 'tenure_months']);

        $this->loan(['amount' => -1])->assertStatus(422);
        $this->loan(['amount' => 0])->assertStatus(422);
        // Money is paise; a third decimal is not a rupee amount.
        $this->loan(['amount' => 100000.001])->assertStatus(422);
        $this->loan(['tenure_months' => 0])->assertStatus(422);
        $this->loan(['tenure_months' => 12.5])->assertStatus(422);
        $this->loan(['tenure_months' => 5000])->assertStatus(422);

        $this->simulate(['type' => 'saving_change', 'monthly_amount' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('months');
    }

    public function test_paise_survive_the_round_trip(): void
    {
        $this->customer(['monthly_expenses' => 40000.55]);

        $data = $this->simulate([
            'type' => 'expense_change',
            'monthly_change' => 1234.45,
        ])->assertOk()->json('data');

        $this->assertSame(41235, $data['projected']['monthly_expenses']);
        $this->assertSame(38765, $data['projected']['surplus']);
    }
}
