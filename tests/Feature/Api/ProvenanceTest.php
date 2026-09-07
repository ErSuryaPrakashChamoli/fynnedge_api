<?php

namespace Tests\Feature\Api;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\ProductRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A catalogue that answers with whatever this test decides, so a product's
 * price can change underneath an application that already exists.
 */
class SwappableCatalogue implements ProductRepository
{
    /** @param list<LoanProduct> $products */
    public function __construct(public array $products) {}

    public function all(?string $category = null): array
    {
        return $category === null
            ? $this->products
            : array_values(array_filter(
                $this->products,
                fn (LoanProduct $p) => $p->category === $category,
            ));
    }

    public function find(string $id): ?LoanProduct
    {
        foreach ($this->products as $product) {
            if ($product->id === $id) {
                return $product;
            }
        }

        return null;
    }

    public function isSampleData(): bool
    {
        return true;
    }
}

/**
 * Where every number comes from, and what it survives.
 *
 * Three boundaries are asserted here, because each is a place a customer
 * could be told something untrue without any single line of code being
 * wrong: what "zero" means, whether every screen agrees about the same fact,
 * and whether a historical application can be rewritten by today's catalogue.
 */
class ProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $figures = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Provenance Person']);
        $user->financialProfile->update(array_merge([
            'monthly_income' => 80000,
            'monthly_expenses' => 30000,
            'existing_emi' => 12000,
            'other_obligations' => 0,
            'savings' => 300000,
        ], $figures));
        Sanctum::actingAs($user);

        return $user;
    }

    // --- Zero is not unknown ---------------------------------------------

    public function test_nothing_numeric_is_asserted_until_there_is_an_income(): void
    {
        // A profile row exists from the moment the account does, with every
        // figure at its column default. Those zeros are not facts the
        // customer gave, so the one gate that matters is income: until it is
        // positive, FynnEdge says it cannot measure rather than reporting
        // zero savings, zero EMIs and a failing buffer.
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        $score = $this->getJson('/api/v1/fynn-score')->assertOk()->json('data');
        $this->assertSame('insufficient_data', $score['status']);
        $this->assertNull($score['score']);
        $this->assertSame([], $score['dimensions']);

        $home = $this->getJson('/api/v1/home')->assertOk()->json('data');
        $this->assertFalse($home['customer']['has_financial_profile']);
        $this->assertSame([], $home['attention']);
    }

    public function test_a_zero_the_customer_entered_is_a_fact_and_is_scored(): void
    {
        // Once income is given, every other figure was required on the same
        // save. A zero here is something they said, so it counts.
        $this->customer(['savings' => 0]);

        $score = $this->getJson('/api/v1/fynn-score')->assertOk()->json('data');

        $this->assertSame('ok', $score['status']);
        $buffer = collect($score['dimensions'])
            ->firstWhere('key', 'emergency_buffer');

        $this->assertSame(0, $buffer['score']);
        $this->assertSame(0.0, (float) $buffer['metric_value']);
    }

    public function test_every_money_field_is_required_together(): void
    {
        // The guarantee the paragraph above depends on: a partial save
        // cannot leave one figure at a default that later reads as a fact.
        $this->customer();

        foreach ([
            'monthly_income', 'monthly_expenses', 'existing_emi', 'savings',
        ] as $field) {
            $body = [
                'monthly_income' => 80000,
                'monthly_expenses' => 30000,
                'existing_emi' => 12000,
                'savings' => 300000,
                'employment_type' => 'salaried',
            ];
            unset($body[$field]);

            $this->putJson('/api/v1/financial-profile', $body)
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_an_unknown_age_leaves_the_age_rule_unchecked(): void
    {
        // Age is nullable, so it has a real unknown. An unchecked rule must
        // not become a passed one.
        $user = $this->customer();
        $user->customerProfile->update(['age' => null]);

        $matches = $this->getJson('/api/v1/fynn-match?amount=600000&tenure_months=48&purpose=personal')
            ->assertOk()->json('data.matches');

        $ageCriteria = collect($matches)
            ->flatMap(fn ($m) => $m['match']['criteria'])
            ->where('key', 'age');

        $this->assertNotEmpty($ageCriteria);
        foreach ($ageCriteria as $criterion) {
            $this->assertSame(
                'unavailable',
                $criterion['status'],
                'An age rule was resolved without knowing the age',
            );
        }
    }

    // --- One fact, every screen -------------------------------------------

    public function test_every_screen_agrees_about_the_same_customer(): void
    {
        $this->customer();

        $profile = $this->getJson('/api/v1/financial-profile')->json('data');
        $summary = $this->getJson('/api/v1/financial-summary')->json('data');
        $home = $this->getJson('/api/v1/home')->json('data');
        $score = $this->getJson('/api/v1/fynn-score')->json('data');
        $match = $this->getJson('/api/v1/fynn-match?amount=600000&tenure_months=48&purpose=personal')
            ->json('data');
        $context = $this->getJson('/api/v1/ai/context')->json('data.context');

        // The customer's own figure.
        $income = 80000;
        $this->assertEquals($income, $profile['monthly_income']);
        $this->assertEquals($income, $home['financial']['monthly_income']);
        $this->assertEquals($income, $match['customer_metrics']['monthly_income']);
        $this->assertEquals($income, $context['financial_profile']['monthly_income']);

        // FynnEdge's calculation of it, everywhere it appears.
        $surplus = $profile['derived']['surplus'];
        $this->assertEquals($surplus, $summary['derived']['surplus']);
        $this->assertEquals($surplus, $home['financial']['surplus']);
        $this->assertEquals($surplus, $match['customer_metrics']['surplus']);

        $ratio = $profile['derived']['emi_ratio'];
        $this->assertEquals($ratio, $home['financial']['emi_ratio']);
        $this->assertEquals($ratio, $match['customer_metrics']['emi_ratio']);

        // FynnEdge's interpretation of it.
        $this->assertSame($score['score'], $home['score']['score']);
        $this->assertSame($score['band'], $home['score']['band']);
        $this->assertSame($score['model_version'], $home['score']['model_version']);
    }

    // --- A snapshot is history --------------------------------------------

    public function test_a_repriced_catalogue_cannot_rewrite_an_application(): void
    {
        $this->customer(['monthly_income' => 200000, 'savings' => 900000]);

        $original = $this->getJson('/api/v1/products/prod_bl_b')->json('data');

        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1500000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/applications/{$application['id']}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // The provider reprices. Everything about the product changes.
        $repriced = app(ProductRepository::class)->all();
        $swapped = array_map(function (LoanProduct $p) {
            if ($p->id !== 'prod_bl_b') {
                return $p;
            }

            return new LoanProduct(
                id: $p->id,
                lender: $p->lender,
                name: $p->name,
                lenderTag: $p->lenderTag,
                category: $p->category,
                minAmount: $p->minAmount,
                maxAmount: $p->maxAmount,
                interestRate: $p->interestRate + 6.0,
                minTenureMonths: $p->minTenureMonths,
                maxTenureMonths: $p->maxTenureMonths,
                processingFeePercent: $p->processingFeePercent * 3,
                processingFeeCap: null,
                fynnTrust: 40,
                prepaymentCharge: $p->prepaymentCharge,
                suitedReasons: $p->suitedReasons,
                considerations: $p->considerations,
                conditions: $p->conditions,
                trustBreakdown: $p->trustBreakdown,
                minMonthlyIncome: $p->minMonthlyIncome,
                maxAgeAtMaturity: $p->maxAgeAtMaturity,
                minAge: $p->minAge,
            );
        }, $repriced);

        $this->app->instance(
            ProductRepository::class,
            new SwappableCatalogue($swapped),
        );

        // The catalogue really has changed.
        $this->assertNotEquals(
            $original['interest_rate'],
            app(ProductRepository::class)->find('prod_bl_b')->interestRate,
            'the test did not actually reprice the product',
        );

        // The application has not. It records what the customer applied with.
        $after = $this->getJson("/api/v1/applications/{$application['id']}")
            ->assertOk()->json('data');

        foreach ([
            'interest_rate', 'emi', 'total_interest', 'total_payable',
            'processing_fee', 'projected_emi_ratio', 'match_category',
            'amount', 'tenure_months', 'product_name', 'lender',
        ] as $field) {
            $this->assertSame(
                $application[$field],
                $after[$field],
                "{$field} was rewritten from today's catalogue",
            );
        }
    }

    // --- No sample customer anywhere near a real one ----------------------

    public function test_no_customer_calculation_reaches_for_seeded_data(): void
    {
        // A customer with figures nobody else has. If anything downstream
        // fell back to a demo profile, these would not survive the round
        // trip intact.
        $this->customer([
            'monthly_income' => 77777,
            'monthly_expenses' => 11111,
            'existing_emi' => 2222,
            'savings' => 33333,
        ]);

        foreach ([
            '/api/v1/home' => 'data.financial.monthly_income',
            '/api/v1/financial-profile' => 'data.monthly_income',
            '/api/v1/financial-summary' => 'data.inputs.monthly_income',
            '/api/v1/ai/context' => 'data.context.financial_profile.monthly_income',
        ] as $uri => $path) {
            $this->assertEquals(
                77777,
                $this->getJson($uri)->json($path),
                "{$uri} did not answer with this customer's own income",
            );
        }

        // And the demo customer's figures appear nowhere.
        $body = $this->getJson('/api/v1/home')->getContent()
            .$this->getJson('/api/v1/ai/context')->getContent();

        $this->assertStringNotContainsString('Rahul', $body);
        $this->assertStringNotContainsString('100000', $body);
    }

    public function test_the_catalogue_is_declared_as_sample_data_where_shown(): void
    {
        $this->customer();

        $match = $this->getJson('/api/v1/fynn-match?amount=600000&tenure_months=48&purpose=personal')
            ->assertOk()->json('data');
        $this->assertTrue($match['is_sample_catalogue']);

        foreach ($this->getJson('/api/v1/products')->json('data') as $product) {
            $this->assertTrue(
                $product['is_sample_data'],
                "{$product['id']} does not declare itself sample data",
            );
        }
    }
}
