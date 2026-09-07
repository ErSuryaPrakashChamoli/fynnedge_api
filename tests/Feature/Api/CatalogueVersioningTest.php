<?php

namespace Tests\Feature\Api;

use App\Domain\Catalog\AmbiguousCatalogueException;
use App\Domain\Catalog\CatalogueProduct;
use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\MockProductRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\ProductSource;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVersion;
use App\Domain\Catalog\Provider;
use App\Domain\Catalog\ProviderType;
use App\Domain\Money;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A catalogue whose products are given, so a version can be published
 * partway through a customer's journey.
 */
class VersionedCatalogue implements ProductRepository
{
    /** @param list<CatalogueProduct> $products */
    public function __construct(public array $products) {}

    /** @return list<LoanProduct> */
    public function all(?string $category = null): array
    {
        $resolved = array_values(array_filter(array_map(
            fn (CatalogueProduct $p) => $p->isDiscoverable() ? $p->resolve() : null,
            $this->products,
        )));

        return $category === null
            ? $resolved
            : array_values(array_filter(
                $resolved,
                fn (LoanProduct $p) => $p->category === $category,
            ));
    }

    public function find(string $id): ?LoanProduct
    {
        foreach ($this->products as $product) {
            if ($product->id() === $id) {
                return $product->isDiscoverable() ? $product->resolve() : null;
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
 * The Module 20 proof.
 *
 * A customer decides against one set of terms and applies. The provider then
 * republishes at a worse rate. Everything the customer already agreed to
 * must keep saying what it said, while everyone arriving afterwards sees the
 * new terms.
 */
class CatalogueVersioningTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_ID = 'sample_bank:term_loan';

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Versioned Person', 'age' => 34]);
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

    private function version(
        string $from,
        ?string $until,
        float $rate,
        float $feePercent,
    ): ProductVersion {
        return new ProductVersion(
            persistedId: null,
            effectiveFrom: Carbon::parse($from),
            effectiveUntil: $until === null ? null : Carbon::parse($until),
            interestRate: $rate,
            processingFeePercent: $feePercent,
            processingFeeCap: null,
            prepaymentCharge: 'Nil after 12 EMIs',
            minAmount: Money::of(100000),
            maxAmount: Money::of(5000000),
            minTenureMonths: 12,
            maxTenureMonths: 84,
            minMonthlyIncome: Money::of(30000),
            minAge: 23,
            maxAgeAtMaturity: 60,
            fynnTrust: 84,
            trustBreakdown: [],
            lenderTag: '',
            suitedReasons: ['Published rate held for the full term'],
            considerations: ['Sample product used to demonstrate FynnEdge'],
            conditions: [],
            source: ProductSource::sample(),
        );
    }

    private VersionedCatalogue $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        // Bound once and then mutated, rather than rebound per change: the
        // catalogue is one thing whose contents change, and a fresh binding
        // would not reach the container a request is served from.
        $this->catalogue = new VersionedCatalogue([]);
        $this->app->instance(ProductRepository::class, $this->catalogue);
    }

    /** @param list<ProductVersion> $versions */
    private function publish(array $versions): void
    {
        $this->catalogue->products = [
            new CatalogueProduct(
                provider: new Provider(
                    code: 'sample_bank',
                    name: 'Sample Bank',
                    type: ProviderType::Bank,
                ),
                code: 'term_loan',
                name: 'Term Loan',
                category: 'personal',
                status: ProductStatus::Active,
                versions: $versions,
            ),
        ];
    }

    /** Version 1: 11.5%, 1% fee, in force until the day version 2 begins. */
    private function publishVersionOne(): void
    {
        $this->publish([$this->version('2020-01-01', null, 11.5, 1.0)]);
    }

    /** Version 2: repriced. Version 1 is closed, not deleted. */
    private function publishVersionTwo(): void
    {
        $this->publish([
            $this->version('2020-01-01', '2026-09-06', 11.5, 1.0),
            $this->version('2026-09-06', null, 12.25, 1.5),
        ]);
    }

    public function test_the_whole_journey_runs_on_one_version_then_survives_the_next(): void
    {
        $this->customer();
        $this->publishVersionOne();

        // --- Discovery, on version 1 -----------------------------------
        $match = collect(
            $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=personal')
                ->assertOk()->json('data.matches')
        )->firstWhere('product.id', self::PRODUCT_ID);

        $this->assertNotNull($match, 'the versioned product did not reach FynnMatch');
        $this->assertSame(11.5, $match['product']['interest_rate']);

        $emiOnVersionOne = $match['pricing']['emi'];
        $feeOnVersionOne = $match['pricing']['processing_fee'];

        // --- Loan Detail, FynnTrust ------------------------------------
        $detail = $this->getJson('/api/v1/products/'.self::PRODUCT_ID)
            ->assertOk()->json('data');
        $this->assertSame(11.5, $detail['interest_rate']);
        $this->assertSame(84, $detail['fynn_trust']);
        $this->assertTrue($detail['is_sample_data']);

        // --- FynnTwin, on the same version ------------------------------
        $twin = $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'loan',
            'product_id' => self::PRODUCT_ID,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertOk()->json('data');
        $this->assertSame($emiOnVersionOne, $twin['loan']['emi']);

        // --- Application, snapshotting version 1 ------------------------
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => self::PRODUCT_ID,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        $this->assertSame(11.5, (float) $application['interest_rate']);
        $this->assertSame($emiOnVersionOne, $application['emi']);
        $this->assertSame($feeOnVersionOne, $application['processing_fee']);

        $this->postJson("/api/v1/applications/{$application['id']}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // --- The provider reprices --------------------------------------
        $this->publishVersionTwo();

        // Everyone arriving now sees version 2.
        $repriced = $this->getJson('/api/v1/products/'.self::PRODUCT_ID)
            ->assertOk()->json('data');
        $this->assertSame(12.25, $repriced['interest_rate']);
        $this->assertSame(1.5, $repriced['processing_fee_percent']);

        $newMatch = collect(
            $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=personal')
                ->json('data.matches')
        )->firstWhere('product.id', self::PRODUCT_ID);
        $this->assertSame(12.25, $newMatch['product']['interest_rate']);
        $this->assertGreaterThan(
            $emiOnVersionOne,
            $newMatch['pricing']['emi'],
            'the repriced version should cost more',
        );

        // --- And the application still describes version 1 --------------
        $after = $this->getJson("/api/v1/applications/{$application['id']}")
            ->assertOk()->json('data');

        $this->assertSame(11.5, (float) $after['interest_rate']);
        $this->assertSame($emiOnVersionOne, $after['emi']);
        $this->assertSame($feeOnVersionOne, $after['processing_fee']);
        $this->assertSame($application['total_payable'], $after['total_payable']);
        $this->assertSame($application['match_category'], $after['match_category']);
    }

    public function test_a_withdrawn_product_leaves_its_applications_readable(): void
    {
        $this->customer();
        $this->publishVersionOne();

        $application = $this->postJson('/api/v1/applications', [
            'product_id' => self::PRODUCT_ID,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        // The provider withdraws it.
        $this->catalogue->products = [
            new CatalogueProduct(
                provider: new Provider(
                    code: 'sample_bank',
                    name: 'Sample Bank',
                    type: ProviderType::Bank,
                ),
                code: 'term_loan',
                name: 'Term Loan',
                category: 'personal',
                status: ProductStatus::Inactive,
                versions: [$this->version('2020-01-01', null, 11.5, 1.0)],
            ),
        ];

        // Gone from discovery.
        $matches = $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=personal')
            ->assertOk()->json('data.matches');
        $this->assertNull(
            collect($matches)->firstWhere('product.id', self::PRODUCT_ID),
        );

        // The application is untouched, and still says what it always said.
        $after = $this->getJson("/api/v1/applications/{$application['id']}")
            ->assertOk()->json('data');
        $this->assertSame($application['emi'], $after['emi']);
        $this->assertSame('Sample Bank', $after['lender']);
        $this->assertSame('Term Loan', $after['product_name']);
    }

    public function test_the_shipped_catalogue_still_answers_as_it_always_did(): void
    {
        $this->app->instance(ProductRepository::class, new MockProductRepository);
        $this->customer();

        // The six sample products travel the same road as any versioned one.
        $products = $this->getJson('/api/v1/products')->assertOk()->json('data');
        $this->assertCount(6, $products);

        foreach ($products as $product) {
            $this->assertTrue($product['is_sample_data']);
            $this->assertArrayHasKey('provider_code', $product);
            $this->assertStringStartsWith('prod_', $product['id']);
        }
    }

    public function test_a_product_whose_versions_collide_is_kept_from_customers(): void
    {
        // Building it is refused outright, so an ambiguous product cannot
        // exist to be shown.
        $this->expectException(AmbiguousCatalogueException::class);

        new CatalogueProduct(
            provider: new Provider(
                code: 'sample_bank',
                name: 'Sample Bank',
                type: ProviderType::Bank,
            ),
            code: 'term_loan',
            name: 'Term Loan',
            category: 'personal',
            status: ProductStatus::Active,
            versions: [
                $this->version('2020-01-01', null, 11.5, 1.0),
                $this->version('2024-01-01', null, 12.5, 1.5),
            ],
        );
    }

    public function test_customers_cannot_write_to_the_catalogue(): void
    {
        $this->app->instance(ProductRepository::class, new MockProductRepository);
        $this->customer();

        // Products are read-only over the API, and nothing else is routed.
        foreach ([
            ['POST', '/api/v1/products'],
            ['PUT', '/api/v1/products/prod_bl_b'],
            ['PATCH', '/api/v1/products/prod_bl_b'],
            ['DELETE', '/api/v1/products/prod_bl_b'],
        ] as [$method, $uri]) {
            $this->assertContains(
                $this->json($method, $uri, ['interest_rate' => 0.1])->getStatusCode(),
                [404, 405],
                "{$method} {$uri} is reachable",
            );
        }

        // And the catalogue is unchanged.
        $this->assertSame(
            12.4,
            $this->getJson('/api/v1/products/prod_bl_b')->json('data.interest_rate'),
        );
    }
}
