<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Loan Options, Detail, Compare, FynnTrust and FynnMirror all describe one
 * product and one customer. These tests hold the API to that: the same
 * product data, the same FynnTrust, and the same arithmetic, whichever
 * endpoint a screen asked.
 */
class DecisionExperienceTest extends TestCase
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
            'savings' => 180000,
        ], $financials));
        $user->customerProfile->update(['age' => 34]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function matchData(array $query = []): array
    {
        return $this->getJson('/api/v1/fynn-match?'.http_build_query(array_merge([
            'amount' => 1000000,
            'tenure_months' => 60,
            'purpose' => 'business',
        ], $query)))->assertOk()->json('data');
    }

    private function simulate(string $product, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/fynn-match/'.$product.'?'.http_build_query(array_merge([
            'amount' => 1000000,
            'tenure_months' => 60,
            'purpose' => 'business',
        ], $query)));
    }

    // --- One product, described the same way everywhere ---------------------

    public function test_the_catalogue_and_a_match_describe_the_same_product(): void
    {
        $this->customer();

        $catalogue = $this->getJson('/api/v1/products/prod_bl_b')->assertOk()->json('data');
        $matched = collect($this->matchData()['matches'])
            ->firstWhere('product.id', 'prod_bl_b')['product'];

        $this->assertSame($catalogue, $matched);
    }

    public function test_fynn_trust_is_identical_across_every_endpoint(): void
    {
        $this->customer();

        $catalogue = $this->getJson('/api/v1/products/prod_bl_b')->assertOk()->json('data');
        $matched = collect($this->matchData()['matches'])
            ->firstWhere('product.id', 'prod_bl_b')['product'];
        $simulated = $this->simulate('prod_bl_b')->assertOk()->json('data.product');

        $this->assertSame(89, $catalogue['fynn_trust']);
        $this->assertSame($catalogue['fynn_trust'], $matched['fynn_trust']);
        $this->assertSame($catalogue['fynn_trust'], $simulated['fynn_trust']);
        $this->assertSame(
            $catalogue['trust_breakdown'],
            $simulated['trust_breakdown'],
        );
    }

    public function test_fynn_trust_is_published_not_derived_from_its_factors(): void
    {
        $this->customer();

        // The five factors do not average to the published value, and
        // FynnEdge does not pretend otherwise: it reports what the catalogue
        // publishes rather than recomputing a score it has no model for.
        foreach ($this->getJson('/api/v1/products')->assertOk()->json('data') as $product) {
            $factors = array_column($product['trust_breakdown'], 'value');
            $this->assertCount(5, $factors);
            $this->assertIsInt($product['fynn_trust']);
        }

        $northline = collect($this->getJson('/api/v1/products')->json('data'))
            ->firstWhere('id', 'prod_pl_b');
        $mean = array_sum(array_column($northline['trust_breakdown'], 'value')) / 5;

        $this->assertSame(78, $northline['fynn_trust']);
        $this->assertNotEquals($mean, $northline['fynn_trust']);
    }

    // --- Affordability ------------------------------------------------------

    public function test_the_match_carries_the_customers_standing_affordability(): void
    {
        $this->customer();
        $data = $this->matchData();

        $this->assertEquals(45, $data['affordability_ceiling_percent']);
        $this->assertSame(45000, $data['affordability']['emi_ceiling']);
        $this->assertSame(25000, $data['affordability']['headroom_before']);
        $this->assertSame(20, $data['affordability']['current']['emi_ratio']);
        $this->assertTrue($data['affordability']['is_estimate']);
    }

    public function test_every_match_carries_a_before_and_after_projection(): void
    {
        $this->customer();

        foreach ($this->matchData()['matches'] as $match) {
            $p = $match['projection'];

            $this->assertSame(60000, $p['current']['monthly_outgo']);
            $this->assertSame(40000, $p['current']['surplus']);
            $this->assertSame(20, $p['current']['emi_ratio']);

            // The EMI shown is the EMI projected. One number, not two.
            $this->assertSame($match['pricing']['emi'], $p['emi']);
            $this->assertSame(
                $match['pricing']['projected_emi_ratio'],
                $p['projected']['emi_ratio'],
            );
            $this->assertTrue($p['is_estimate']);
        }
    }

    public function test_the_projection_agrees_with_the_criterion_text(): void
    {
        $this->customer();

        foreach ($this->matchData()['matches'] as $match) {
            $affordability = collect($match['match']['criteria'])
                ->firstWhere('key', 'affordability');

            if (! $match['projection']['is_measurable']) {
                $this->assertSame('unavailable', $affordability['status']);

                continue;
            }

            // The sentence a customer reads must carry the same number the
            // before/after panel shows them.
            $this->assertStringContainsString(
                (string) $match['projection']['projected']['emi_ratio'],
                $affordability['detail'],
            );
            $this->assertSame(
                $match['projection']['within_ceiling'],
                $affordability['status'] === 'passed',
            );
        }
    }

    public function test_without_an_income_nothing_is_projected(): void
    {
        $this->customer(['monthly_income' => 0]);
        $data = $this->matchData();

        $this->assertFalse($data['affordability']['is_measurable']);
        foreach ($data['matches'] as $match) {
            $this->assertFalse($match['projection']['is_measurable']);
            $this->assertNull($match['pricing']['projected_emi_ratio']);
            $this->assertFalse($match['projection']['within_ceiling']);
        }
    }

    // --- Simulation ---------------------------------------------------------

    public function test_a_simulation_prices_the_amount_and_tenure_asked_for(): void
    {
        $this->customer();

        $data = $this->simulate('prod_bl_b', [
            'amount' => 2000000,
            'tenure_months' => 84,
        ])->assertOk()->json('data');

        $this->assertSame(2000000, $data['pricing']['amount']);
        $this->assertSame(84, $data['pricing']['tenure_months']);
        $this->assertFalse($data['pricing']['tenure_was_adjusted']);
        // 20L at 12.4% over 84 months, by the reducing-balance formula.
        $this->assertSame(35734.72, $data['pricing']['emi']);

        // Outgo picks up exactly that EMI: 40,000 expenses + 20,000 existing
        // EMI + this one.
        $this->assertSame(
            95734.72,
            $data['projection']['projected']['monthly_outgo'],
        );
    }

    public function test_a_simulation_agrees_with_the_match_at_the_same_numbers(): void
    {
        $this->customer();

        $fromList = collect($this->matchData()['matches'])
            ->firstWhere('product.id', 'prod_bl_b');
        $simulated = $this->simulate('prod_bl_b')->assertOk()->json('data');

        $this->assertSame($fromList['pricing'], $simulated['pricing']);
        $this->assertSame($fromList['projection'], $simulated['projection']);
        $this->assertSame($fromList['match'], $simulated['match']);
    }

    public function test_a_simulation_changes_nothing_the_customer_has_saved(): void
    {
        $user = $this->customer();
        $before = $user->financialProfile->only([
            'monthly_income', 'monthly_expenses', 'existing_emi', 'savings',
        ]);

        $this->simulate('prod_bl_b', ['amount' => 5000000, 'tenure_months' => 84])
            ->assertOk();

        $this->assertSame(
            $before,
            $user->financialProfile->fresh()->only([
                'monthly_income', 'monthly_expenses', 'existing_emi', 'savings',
            ]),
        );
    }

    public function test_a_simulation_evaluates_the_authenticated_customer_only(): void
    {
        $rich = User::factory()->create();
        $rich->ensureProfiles();
        $rich->financialProfile->update(['monthly_income' => 5000000]);

        $this->customer(['monthly_income' => 100000]);

        $data = $this->simulate('prod_bl_b', [
            'user_id' => $rich->id,
            'customer_id' => $rich->id,
        ])->assertOk()->json('data');

        $this->assertSame(45000, $data['projection']['emi_ceiling']);
    }

    public function test_a_simulation_requires_a_session(): void
    {
        $this->getJson('/api/v1/fynn-match/prod_bl_b?amount=1000000&tenure_months=60')
            ->assertStatus(401);
    }

    public function test_a_simulation_of_a_missing_product_is_a_404(): void
    {
        $this->customer();

        $this->simulate('prod_does_not_exist')->assertNotFound();
    }

    public function test_a_simulation_validates_its_input(): void
    {
        $this->customer();

        $this->getJson('/api/v1/fynn-match/prod_bl_b')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'tenure_months']);

        $this->simulate('prod_bl_b', ['amount' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_simulation_cannot_write(): void
    {
        $this->customer();

        $this->postJson('/api/v1/fynn-match/prod_bl_b')->assertStatus(405);
        $this->putJson('/api/v1/fynn-match/prod_bl_b')->assertStatus(405);
        $this->deleteJson('/api/v1/fynn-match/prod_bl_b')->assertStatus(405);
    }

    public function test_a_simulation_says_what_it_is(): void
    {
        $this->customer();
        $data = $this->simulate('prod_bl_b')->assertOk()->json('data');

        $this->assertTrue($data['is_sample_data']);
        $this->assertStringContainsString('not an approval', $data['disclaimer']);
        $this->assertTrue($data['projection']['is_estimate']);
    }

    // --- Missing fields -----------------------------------------------------

    public function test_undocumented_product_fields_stay_null(): void
    {
        $this->customer();

        $arcus = $this->simulate('prod_bl_a')->assertOk()->json('data');

        $this->assertNull($arcus['product']['min_monthly_income']);
        $this->assertNull($arcus['product']['max_age_at_maturity']);
        $this->assertNull($arcus['product']['processing_fee_cap']);
        // And the criteria say so rather than passing them.
        $this->assertContains('Minimum income', $arcus['match']['not_assessed']);
        $this->assertContains('Age', $arcus['match']['not_assessed']);
    }
}
