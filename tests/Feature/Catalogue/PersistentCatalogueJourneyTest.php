<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalog\DatabaseProductRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\ProductSourceType;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProviderType;
use App\Models\CatalogueProduct;
use App\Models\Provider;
use App\Models\User;
use App\Services\Catalogue\CatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Module 21 proof: a product administered into existence, decided
 * against by a customer, and then repriced.
 *
 * Everything the customer already agreed to must keep saying what it said,
 * while everyone arriving afterwards sees the new terms — and none of the
 * decision journey knows the catalogue is a database at all.
 */
class PersistentCatalogueJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_ID = 'sample_bank:term_loan';

    private CatalogueService $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        // The persistent catalogue answers customer requests for this test.
        config(['fynnedge.catalogue.driver' => 'database']);
        $this->app->forgetInstance(ProductRepository::class);
        $this->app->singleton(ProductRepository::class, DatabaseProductRepository::class);

        $this->catalogue = app(CatalogueService::class);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Catalogue Customer', 'age' => 34]);
        $user->financialProfile->update([
            'monthly_income' => 200000,
            'monthly_expenses' => 50000,
            'existing_emi' => 10000,
            'other_obligations' => 0,
            'savings' => 900000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function terms(float $rate, float $fee, string $from, ?string $until = null): array
    {
        return [
            'effective_from' => $from,
            'effective_until' => $until,
            'interest_rate' => $rate,
            'processing_fee_percent' => $fee,
            'processing_fee_cap' => null,
            'prepayment_charge' => 'Nil after 12 EMIs',
            'min_amount' => '100000.00',
            'max_amount' => '5000000.00',
            'min_tenure_months' => 12,
            'max_tenure_months' => 84,
            'min_monthly_income' => '30000.00',
            'min_age' => 23,
            'max_age_at_maturity' => 60,
            'fynn_trust' => 84,
            'trust_breakdown' => [
                'cost' => ['label' => 'Cost', 'value' => 84, 'explanation' => 'The published rate holds for the full term.'],
            ],
            'lender_tag' => '',
            'suited_reasons' => ['The published rate holds for the full term'],
            'considerations' => ['Sample product used to demonstrate FynnEdge'],
            'conditions' => ['Minimum monthly income ₹30,000'],
            'source_type' => ProductSourceType::Sample->value,
            'source_reference' => null,
            'source_retrieved_at' => null,
        ];
    }

    /** The §64 workflow, start to finish. */
    private function administerProduct(): CatalogueProduct
    {
        $provider = $this->catalogue->createProvider(
            code: 'sample_bank',
            name: 'Sample Bank',
            type: ProviderType::Bank,
            isSample: true,
        );

        $product = $this->catalogue->createProduct(
            provider: $provider,
            productCode: 'term_loan',
            displayName: 'Term Loan',
            category: 'personal',
        );

        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->terms(11.5, 1.0, '2020-01-01'),
        );

        $this->catalogue->publishVersion($draft);

        return $this->catalogue->setProductStatus($product, ProductStatus::Active);
    }

    public function test_the_administered_product_travels_the_whole_journey(): void
    {
        $this->customer();
        $product = $this->administerProduct();

        // --- FynnMatch --------------------------------------------------
        $match = collect(
            $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=personal')
                ->assertOk()->json('data.matches')
        )->firstWhere('product.id', self::PRODUCT_ID);

        $this->assertNotNull($match, 'the administered product did not reach FynnMatch');
        $this->assertSame(11.5, $match['product']['interest_rate']);
        $this->assertTrue($match['product']['is_sample_data']);
        $this->assertSame('sample_bank', $match['product']['provider_code']);

        $emi = $match['pricing']['emi'];
        $fee = $match['pricing']['processing_fee'];

        // --- Loan Detail and FynnTrust ----------------------------------
        $detail = $this->getJson('/api/v1/products/'.self::PRODUCT_ID)
            ->assertOk()->json('data');
        $this->assertSame(11.5, $detail['interest_rate']);
        $this->assertSame(84, $detail['fynn_trust']);

        // --- FynnTwin ----------------------------------------------------
        $twin = $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'loan',
            'product_id' => self::PRODUCT_ID,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertOk()->json('data');
        $this->assertSame($emi, $twin['loan']['emi']);

        // --- Application --------------------------------------------------
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => self::PRODUCT_ID,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        $this->assertSame($emi, $application['emi']);
        $this->assertSame($fee, $application['processing_fee']);

        $this->postJson("/api/v1/applications/{$application['id']}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // --- The provider reprices: clone, close, publish ------------------
        $current = $product->publishedVersions()->first();
        $this->catalogue->closeVersion($current, '2026-09-01');
        $newDraft = $this->catalogue->cloneToDraft($current, [
            'effective_from' => '2026-09-01',
            'effective_until' => null,
            'interest_rate' => 12.25,
            'processing_fee_percent' => 1.5,
        ]);
        $this->catalogue->publishVersion($newDraft);

        // Everyone arriving now sees the new terms.
        $repriced = $this->getJson('/api/v1/products/'.self::PRODUCT_ID)
            ->assertOk()->json('data');
        $this->assertSame(12.25, $repriced['interest_rate']);
        $this->assertSame(1.5, $repriced['processing_fee_percent']);

        // --- And the application still describes what it was made under ---
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
                "{$field} was rewritten by a catalogue change",
            );
        }
    }

    public function test_deactivating_a_product_leaves_its_applications_readable(): void
    {
        $this->customer();
        $product = $this->administerProduct();

        $application = $this->postJson('/api/v1/applications', [
            'product_id' => self::PRODUCT_ID,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        $this->catalogue->setProductStatus($product, ProductStatus::Inactive);

        $matches = $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=personal')
            ->assertOk()->json('data.matches');
        $this->assertNull(collect($matches)->firstWhere('product.id', self::PRODUCT_ID));

        $after = $this->getJson("/api/v1/applications/{$application['id']}")
            ->assertOk()->json('data');
        $this->assertSame($application['emi'], $after['emi']);
        $this->assertSame('Sample Bank', $after['lender']);
    }

    public function test_deactivating_a_provider_removes_its_products_from_discovery(): void
    {
        $this->customer();
        $this->administerProduct();

        $this->assertNotNull(
            $this->getJson('/api/v1/products/'.self::PRODUCT_ID)->json('data'),
        );

        $this->catalogue->setProviderStatus(
            Provider::where('code', 'sample_bank')->first(),
            Provider::STATUS_INACTIVE,
        );

        $this->getJson('/api/v1/products/'.self::PRODUCT_ID)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/products')->json('data'));
    }

    // --- Security ------------------------------------------------------------

    public function test_no_admin_route_is_reachable_from_a_customer_token(): void
    {
        $this->customer();
        $this->administerProduct();

        // Administration is a console surface. FynnEdge issues tokens only
        // by verifying an OTP, and everyone who holds one is a customer, so
        // there is no administrator identity for an admin endpoint to
        // authorize against — and therefore no admin endpoint.
        foreach ([
            ['GET', '/api/v1/admin/catalogue/providers'],
            ['POST', '/api/v1/admin/catalogue/products'],
            ['POST', '/api/v1/admin/catalogue/products/1/publish'],
            ['POST', '/api/v1/admin/catalogue/products/1/activate'],
            ['GET', '/api/v1/catalogue/audit'],
            ['POST', '/api/v1/providers'],
            ['POST', '/api/v1/products'],
            ['PATCH', '/api/v1/products/'.self::PRODUCT_ID],
            ['DELETE', '/api/v1/products/'.self::PRODUCT_ID],
        ] as [$method, $uri]) {
            $this->assertContains(
                $this->json($method, $uri, ['interest_rate' => 0.1])->getStatusCode(),
                [404, 405],
                "{$method} {$uri} is reachable from a customer token",
            );
        }

        // And nothing moved.
        $this->assertSame(
            11.5,
            $this->getJson('/api/v1/products/'.self::PRODUCT_ID)->json('data.interest_rate'),
        );
    }

    public function test_the_customer_response_carries_no_catalogue_machinery(): void
    {
        $this->customer();
        $this->administerProduct();

        $body = $this->getJson('/api/v1/products/'.self::PRODUCT_ID)->getContent();

        foreach ([
            'published_at', 'effective_from', 'effective_until', 'version',
            'source_reference', 'source_retrieved_at', 'source_type',
            'catalogue_product_id', 'actor', 'audit',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $body, $internal);
        }

        // What the customer does get: the sample/live distinction.
        $this->assertTrue(json_decode($body, true)['data']['is_sample_data']);
    }
}
