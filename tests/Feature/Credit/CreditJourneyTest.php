<?php

namespace Tests\Feature\Credit;

use App\Domain\Catalog\ProductSourceType;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProviderType;
use App\Domain\Credit\Fixture\FixtureCreditBureauAdapter;
use App\Models\CatalogueProduct;
use App\Models\Provider;
use App\Models\User;
use App\Services\Catalogue\CatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The whole journey, end to end, with a documented credit requirement in it.
 *
 * And — the half that matters more — the same journey for a product that
 * publishes no credit requirement, which must complete without a bureau
 * ever being asked.
 *
 * NO LIVE BUREAU IS CONNECTED. The fixture is fictional.
 */
class CreditJourneyTest extends TestCase
{
    use RefreshDatabase;

    private CatalogueService $catalogue;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fynnedge.credit.driver' => 'fixture',
            'fynnedge.catalogue.driver' => 'database',
        ]);
        $this->catalogue = app(CatalogueService::class);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        $user->customerProfile->update(['age' => 34]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function versionAttributes(array $overrides = []): array
    {
        return array_merge([
            'effective_from' => '2020-01-01',
            'effective_until' => null,
            'interest_rate' => 11.5,
            'processing_fee_percent' => 1.0,
            'processing_fee_cap' => '15000.00',
            'prepayment_charge' => 'Nil after 12 EMIs',
            'min_amount' => '100000.00',
            'max_amount' => '2000000.00',
            'min_tenure_months' => 12,
            'max_tenure_months' => 60,
            'min_monthly_income' => '30000.00',
            'min_age' => 23,
            'max_age_at_maturity' => 58,
            'fynn_trust' => 80,
            'trust_breakdown' => [],
            'lender_tag' => '',
            'suited_reasons' => ['Published rate holds for the full term'],
            'considerations' => ['Sample product'],
            'conditions' => [],
            'source_type' => ProductSourceType::Sample->value,
            'source_reference' => null,
            'source_retrieved_at' => null,
        ], $overrides);
    }

    private function publish(
        string $code,
        ?int $minCreditScore,
        ?Provider $provider = null,
    ): CatalogueProduct {
        $provider ??= $this->catalogue->createProvider(
            code: 'sample_bank',
            name: 'Sample Bank',
            type: ProviderType::Bank,
            isSample: true,
        );

        $product = $this->catalogue->createProduct(
            provider: $provider,
            productCode: $code,
            displayName: ucwords(str_replace('_', ' ', $code)),
            category: 'personal',
        );

        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes(['min_credit_score' => $minCreditScore]),
        );
        $this->catalogue->publishVersion($draft);

        return $this->catalogue->setProductStatus($product, ProductStatus::Active);
    }

    private function grantConsent(): void
    {
        $this->postJson('/api/v1/consents', [
            'type' => 'credit_bureau_check',
            'version' => 'v1',
            'surface' => 'privacy_screen',
        ])->assertSuccessful();
    }

    private function matchFor(string $productId): array
    {
        $response = $this->getJson(
            '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal'
        )->assertOk();

        $match = collect($response->json('data.matches'))
            ->firstWhere('product.id', $productId);

        $this->assertNotNull($match, "no match returned for {$productId}");

        return $match;
    }

    private function creditCriterion(array $match): ?array
    {
        return collect($match['match']['criteria'])->firstWhere('key', 'credit_score');
    }

    // --- The credit journey -------------------------------------------------

    public function test_the_full_journey_with_a_documented_credit_requirement(): void
    {
        $this->customer();
        $product = $this->publish('secured_loan', 700);
        $id = $product->public_id;

        // 1. Before consent, the customer can see the requirement exists and
        //    that FynnEdge has not checked it. Nothing is claimed either way.
        $status = $this->getJson('/api/v1/credit/status')->assertOk();
        $status->assertJsonPath('data.status', 'consent_required')
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.is_fynn_score', false);

        $before = $this->creditCriterion($this->matchFor($id));
        $this->assertSame('unavailable', $before['status']);

        // 2. Consent.
        $this->grantConsent();
        $this->getJson('/api/v1/credit/status')
            ->assertJsonPath('data.status', 'not_requested');

        // 3. The check the customer asked for.
        $check = $this->postJson('/api/v1/credit/check')->assertOk();
        $check->assertJsonPath('data.profile.score', FixtureCreditBureauAdapter::HEALTHY)
            ->assertJsonPath('data.profile.score_model', 'SAMPLE-SCORE-V1')
            ->assertJsonPath('data.profile.scale.min', 300)
            ->assertJsonPath('data.profile.scale.max', 900)
            ->assertJsonPath('data.profile.is_sample', true)
            ->assertJsonPath('data.is_fynn_score', false);

        // 4. Credit information, held and readable without another pull.
        $this->getJson('/api/v1/credit/status')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.profile.score', FixtureCreditBureauAdapter::HEALTHY);

        // 5. Explore still lists the product.
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonFragment(['id' => $id]);

        // 6. FynnMatch now evaluates the requirement instead of skipping it.
        $match = $this->matchFor($id);
        $criterion = $this->creditCriterion($match);
        $this->assertSame('passed', $criterion['status']);
        $this->assertSame('strong_match', $match['match']['category']);

        // 7. Detail agrees with the list. (Two screens disagreeing about one
        //    documented requirement is the failure this asserts against.)
        $detail = $this->getJson(
            "/api/v1/fynn-match/{$id}?amount=500000&tenure_months=48&purpose=personal"
        )->assertOk();
        $detailCriterion = collect($detail->json('data.match.criteria'))
            ->firstWhere('key', 'credit_score');
        $this->assertSame('passed', $detailCriterion['status']);
        $this->assertSame($criterion['detail'], $detailCriterion['detail']);

        // 8. Trust, Twin, Mirror, Score — all still answer, none of them
        //    changed by the existence of a bureau report.
        $this->getJson('/api/v1/fynn-score')->assertOk();
        $this->getJson('/api/v1/fynn-twin')->assertOk();
        $this->getJson('/api/v1/financial-summary')->assertOk();
        $this->getJson('/api/v1/home')->assertOk();

        // 9. Application.
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => $id,
            'amount' => 500000,
            'tenure_months' => 48,
        ])->assertCreated();

        $applicationId = $application->json('data.id');
        $this->postJson("/api/v1/applications/{$applicationId}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // The application is a record of what the customer chose. It does
        // not carry a credit score, and it is not an approval.
        $body = $this->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->getContent();

        foreach (['score_model', 'credit_score', 'bureau', '742'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "application leaked: {$leak}");
        }
    }

    // --- The journey that must not need a bureau ----------------------------

    public function test_a_product_without_a_credit_requirement_needs_no_bureau(): void
    {
        $this->customer();
        $product = $this->publish('open_loan', null);
        $id = $product->public_id;

        // No consent. No check. Nothing about credit anywhere in this test.
        $match = $this->matchFor($id);

        $this->assertNull(
            $this->creditCriterion($match),
            'a product with no documented credit requirement grew one',
        );
        $this->assertSame('strong_match', $match['match']['category']);

        $detail = $this->getJson(
            "/api/v1/fynn-match/{$id}?amount=500000&tenure_months=48&purpose=personal"
        )->assertOk();
        $this->assertNull(
            collect($detail->json('data.match.criteria'))->firstWhere('key', 'credit_score'),
        );

        $applicationId = $this->postJson('/api/v1/applications', [
            'product_id' => $id,
            'amount' => 500000,
            'tenure_months' => 48,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/applications/{$applicationId}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // And the whole way through, FynnEdge never obtained one.
        $this->assertDatabaseCount('credit_reports', 0);
        $this->getJson('/api/v1/credit/status')
            ->assertJsonPath('data.profile', null);
    }

    public function test_browsing_never_triggers_a_bureau_request(): void
    {
        $user = $this->customer();
        $this->publish('secured_loan', 700);
        $this->grantConsent();

        // Consent granted is permission to ask, not an instruction to.
        foreach ([
            '/api/v1/home',
            '/api/v1/products',
            '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal',
            '/api/v1/fynn-score',
            '/api/v1/credit/status',
        ] as $uri) {
            $this->getJson($uri)->assertOk();
        }

        $this->assertSame(0, $user->creditReports()->count());
    }

    // --- A requirement that changes after the fact --------------------------

    public function test_a_raised_requirement_does_not_rewrite_a_past_application(): void
    {
        $this->customer();
        $product = $this->publish('secured_loan', 700);
        $id = $product->public_id;

        $this->grantConsent();
        $this->postJson('/api/v1/credit/check')->assertOk();

        $this->assertSame('passed', $this->creditCriterion($this->matchFor($id))['status']);

        $applicationId = $this->postJson('/api/v1/applications', [
            'product_id' => $id,
            'amount' => 500000,
            'tenure_months' => 48,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/applications/{$applicationId}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        $snapshot = $this->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->json('data');

        // The provider raises the bar past this customer's score, on a new
        // version of the same product.
        $published = $product->publishedVersions()->first();
        $draft = $this->catalogue->cloneToDraft($published, [
            'min_credit_score' => 780,
            'effective_from' => now()->addDay()->toDateString(),
        ]);
        $this->catalogue->closeVersion($published, now()->toDateString());
        $this->catalogue->publishVersion($draft);

        $this->travelTo(now()->addDays(2));

        // New customers see the new requirement, and this customer no
        // longer meets it.
        $this->assertSame('failed', $this->creditCriterion($this->matchFor($id))['status']);

        // The application they already submitted is unchanged.
        $after = $this->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->json('data');

        $this->assertSame($snapshot, $after);
    }

    // --- Nothing bureau-shaped escapes the boundary -------------------------

    public function test_no_bureau_field_names_reach_the_customer_surfaces(): void
    {
        $this->customer();
        $product = $this->publish('secured_loan', 700);
        $this->grantConsent();
        $this->postJson('/api/v1/credit/check')->assertOk();

        // The fixture's own vocabulary — what a provider's payload calls
        // things. None of it is FynnEdge's vocabulary and none of it may
        // travel past the normalizer.
        $providerFields = [
            'consumerName', 'dateOfBirth', 'pan', 'mobile', 'addresses',
            'employment', 'accounts', 'enquiries', 'creditScore',
            'scoreType', 'scoreRange', 'reportDate', 'bureau_reference',
        ];

        foreach ([
            '/api/v1/home',
            '/api/v1/products',
            "/api/v1/products/{$product->public_id}",
            '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal',
            "/api/v1/fynn-match/{$product->public_id}?amount=500000&tenure_months=48&purpose=personal",
            '/api/v1/fynn-score',
            '/api/v1/financial-summary',
            '/api/v1/fynn-twin',
            '/api/v1/applications',
            '/api/v1/credit/status',
        ] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();

            foreach ($providerFields as $field) {
                $this->assertStringNotContainsString(
                    $field,
                    $body,
                    "{$uri} leaked the provider field: {$field}",
                );
            }
        }
    }
}
