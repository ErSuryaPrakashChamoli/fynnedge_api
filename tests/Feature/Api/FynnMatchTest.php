<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FynnMatchTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = [], ?int $age = 34): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update(array_merge([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
        ], $financials));
        $user->customerProfile->update(['age' => $age]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function match(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/fynn-match?'.http_build_query(array_merge([
            'amount' => 1000000,
            'tenure_months' => 60,
            'purpose' => 'business',
        ], $query)));
    }

    // --- Security ----------------------------------------------------------

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/fynn-match?amount=100000&tenure_months=12')
            ->assertStatus(401);
    }

    public function test_products_require_authentication(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->getJson('/api/v1/products/prod_bl_a')->assertStatus(401);
    }

    public function test_it_evaluates_the_authenticated_customers_finances(): void
    {
        $other = User::factory()->create();
        $other->ensureProfiles();
        $other->financialProfile->update([
            'monthly_income' => 5000000,
            'existing_emi' => 0,
            'monthly_expenses' => 10000,
        ]);

        $this->customer(['monthly_income' => 100000, 'existing_emi' => 20000]);

        $this->match()
            ->assertOk()
            ->assertJsonPath('data.customer_metrics.monthly_income', 100000)
            ->assertJsonPath('data.customer_metrics.existing_emi', 20000);
    }

    public function test_a_customer_id_in_the_query_is_ignored(): void
    {
        $victim = User::factory()->create();
        $victim->ensureProfiles();
        $victim->financialProfile->update(['monthly_income' => 9999999]);

        $this->customer(['monthly_income' => 100000]);

        // Whose finances are evaluated comes from the token, never the query.
        $this->match([
            'user_id' => $victim->id,
            'customer_id' => $victim->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.customer_metrics.monthly_income', 100000);
    }

    public function test_the_catalogue_cannot_be_modified_through_these_endpoints(): void
    {
        $this->customer();

        // Only GET is routed; writes must not exist.
        $this->postJson('/api/v1/products', ['name' => 'Injected'])->assertStatus(405);
        $this->putJson('/api/v1/products/prod_bl_a', ['interest_rate' => 0.1])->assertStatus(405);
        $this->deleteJson('/api/v1/products/prod_bl_a')->assertStatus(405);

        $this->getJson('/api/v1/products/prod_bl_a')
            ->assertOk()
            ->assertJsonPath('data.interest_rate', 13.25);
    }

    // --- Matching ----------------------------------------------------------

    public function test_it_returns_matches_for_a_business_request(): void
    {
        $this->customer();

        $response = $this->match()->assertOk();

        $this->assertSame(2, $response->json('data.match_count'));
        $this->assertSame(6, $response->json('data.evaluated_count'));

        foreach ($response->json('data.matches') as $match) {
            if ($match['match']['is_viable']) {
                $this->assertSame('business', $match['product']['category']);
            }
        }
    }

    public function test_the_response_carries_everything_the_ui_needs(): void
    {
        $this->customer();

        $this->match()
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'request' => ['amount', 'tenure_months', 'purpose'],
                    'matches' => [[
                        'product' => ['id', 'lender', 'name', 'is_sample_data'],
                        'match' => [
                            'category', 'label', 'is_viable', 'criteria',
                            'fits', 'check', 'blockers', 'not_assessed',
                        ],
                        'pricing' => [
                            'amount', 'tenure_months', 'tenure_was_adjusted',
                            'emi', 'total_interest', 'total_payable',
                            'processing_fee', 'projected_emi_ratio',
                        ],
                    ]],
                    'match_count', 'evaluated_count', 'customer_metrics',
                    'has_financial_profile', 'affordability_ceiling_percent',
                    'is_sample_catalogue', 'disclaimer',
                ],
            ]);
    }

    public function test_no_matching_products_is_an_empty_result_not_an_error(): void
    {
        $this->customer();

        $response = $this->match(['amount' => 900000000])->assertOk();

        $this->assertSame(0, $response->json('data.match_count'));
        // Every product is still explained, so the app can say why.
        $this->assertSame(6, $response->json('data.evaluated_count'));
    }

    public function test_an_incomplete_financial_profile_is_flagged(): void
    {
        $this->customer(['monthly_income' => 0]);

        $response = $this->match()->assertOk();

        $this->assertFalse($response->json('data.has_financial_profile'));

        // Affordability could not be assessed, so it is not claimed.
        $first = $response->json('data.matches.0');
        $this->assertContains('Affordability', $first['match']['not_assessed']);
        $this->assertNull($first['pricing']['projected_emi_ratio']);
    }

    public function test_the_result_is_deterministic(): void
    {
        $this->customer();

        $first = $this->match()->json('data.matches');
        $second = $this->match()->json('data.matches');

        $this->assertSame($first, $second);
    }

    public function test_the_catalogue_is_labelled_as_sample_data(): void
    {
        $this->customer();

        $this->match()
            ->assertOk()
            ->assertJsonPath('data.is_sample_catalogue', true)
            ->assertJsonPath('data.matches.0.product.is_sample_data', true);
    }

    public function test_the_disclaimer_states_the_lender_decides(): void
    {
        $this->customer();

        $disclaimer = $this->match()->json('data.disclaimer');

        $this->assertStringContainsString('not an approval', $disclaimer);
        $this->assertStringContainsString('provider', $disclaimer);
    }

    public function test_a_changed_financial_profile_changes_the_result(): void
    {
        $this->customer(['existing_emi' => 0]);
        $before = $this->match()->json('data.matches.0.pricing.projected_emi_ratio');

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 35000,
            'savings' => 180000,
            'employment_type' => 'business',
        ])->assertOk();

        $after = $this->match()->json('data.matches.0.pricing.projected_emi_ratio');

        $this->assertGreaterThan($before, $after);
    }

    // --- Validation --------------------------------------------------------

    public function test_it_requires_an_amount_and_a_tenure(): void
    {
        $this->customer();

        $this->getJson('/api/v1/fynn-match')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'tenure_months']);
    }

    public function test_it_rejects_an_unknown_purpose(): void
    {
        $this->customer();

        $this->match(['purpose' => 'spaceship'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');
    }

    // --- Catalogue ---------------------------------------------------------

    public function test_products_can_be_filtered_by_category_and_amount(): void
    {
        $this->customer();

        $business = $this->getJson('/api/v1/products?category=business')
            ->assertOk()->json('data');
        $this->assertCount(2, $business);

        $large = $this->getJson('/api/v1/products?amount=30000000')
            ->assertOk()->json('data');
        foreach ($large as $product) {
            $this->assertLessThanOrEqual(30000000, $product['min_amount']);
            $this->assertGreaterThanOrEqual(30000000, $product['max_amount']);
        }
    }

    public function test_a_missing_product_is_a_404(): void
    {
        $this->customer();

        $this->getJson('/api/v1/products/does_not_exist')->assertNotFound();
    }

    public function test_documented_requirements_are_structured_not_free_text(): void
    {
        $this->customer();

        // Meridian documents both; the engine can evaluate them.
        $this->getJson('/api/v1/products/prod_pl_a')
            ->assertOk()
            ->assertJsonPath('data.min_monthly_income', 40000)
            ->assertJsonPath('data.min_age', 23)
            ->assertJsonPath('data.max_age_at_maturity', 58);

        // Arcus documents neither, so they are explicitly null.
        $this->getJson('/api/v1/products/prod_bl_a')
            ->assertOk()
            ->assertJsonPath('data.min_monthly_income', null)
            ->assertJsonPath('data.max_age_at_maturity', null);
    }
}
