<?php

namespace Tests\Unit;

use App\Domain\Catalog\AmbiguousCatalogueException;
use App\Domain\Catalog\CatalogueProduct;
use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\MockProductRepository;
use App\Domain\Catalog\ProductSource;
use App\Domain\Catalog\ProductSourceType;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVersion;
use App\Domain\Catalog\Provider;
use App\Domain\Catalog\ProviderType;
use App\Domain\Money;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The catalogue boundary: who offers a product, what its terms were, and
 * when.
 *
 * The whole point of the structure is that a rate change is a new version of
 * the same product rather than a different product, and that everything
 * already decided against the old terms keeps describing them.
 */
class CatalogueArchitectureTest extends TestCase
{
    private function provider(string $code = 'sample_bank', bool $isSample = true): Provider
    {
        return new Provider(
            code: $code,
            name: ucwords(str_replace('_', ' ', $code)),
            type: ProviderType::Bank,
            isSample: $isSample,
        );
    }

    private function version(
        string $from,
        ?string $until = null,
        float $rate = 11.5,
        float $feePercent = 1.0,
        ?ProductSource $source = null,
    ): ProductVersion {
        return new ProductVersion(
            persistedId: null,
            effectiveFrom: Carbon::parse($from),
            effectiveUntil: $until === null ? null : Carbon::parse($until),
            interestRate: $rate,
            processingFeePercent: $feePercent,
            processingFeeCap: Money::of(15000),
            prepaymentCharge: 'Nil after 12 EMIs',
            minAmount: Money::of(100000),
            maxAmount: Money::of(2000000),
            minTenureMonths: 12,
            maxTenureMonths: 60,
            minMonthlyIncome: Money::of(30000),
            minAge: 23,
            maxAgeAtMaturity: 58,
            fynnTrust: 80,
            trustBreakdown: [],
            lenderTag: '',
            suitedReasons: [],
            considerations: [],
            conditions: [],
            source: $source ?? ProductSource::sample(),
        );
    }

    /** @param list<ProductVersion>|null $versions */
    private function product(
        ?array $versions = null,
        ProductStatus $status = ProductStatus::Active,
        ?Provider $provider = null,
        string $code = 'term_loan',
    ): CatalogueProduct {
        return new CatalogueProduct(
            provider: $provider ?? $this->provider(),
            code: $code,
            name: 'Term Loan',
            category: 'personal',
            status: $status,
            versions: $versions ?? [$this->version('2020-01-01')],
        );
    }

    // --- Identity ---------------------------------------------------------

    public function test_identity_is_the_provider_and_the_product_code(): void
    {
        $this->assertSame('sample_bank:term_loan', $this->product()->id());
    }

    public function test_two_providers_can_offer_the_same_product_code(): void
    {
        $a = $this->product(provider: $this->provider('provider_a'));
        $b = $this->product(provider: $this->provider('provider_b'));

        $this->assertNotSame($a->id(), $b->id());
        $this->assertSame('provider_a:term_loan', $a->id());
        $this->assertSame('provider_b:term_loan', $b->id());
    }

    public function test_a_provider_is_identified_by_code_not_by_name(): void
    {
        // A rebrand must not orphan a single application.
        $before = $this->provider('provider_a');
        $after = new Provider(
            code: 'provider_a',
            name: 'Something Else Entirely',
            type: ProviderType::Nbfc,
            isSample: true,
        );

        $this->assertSame($before->code, $after->code);
        $this->assertNotSame($before->name, $after->name);
    }

    public function test_a_display_name_cannot_be_used_as_a_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Provider(
            code: 'Meridian Bank',
            name: 'Meridian Bank',
            type: ProviderType::Bank,
        );
    }

    // --- Versions ---------------------------------------------------------

    public function test_the_terms_in_force_depend_on_the_day(): void
    {
        $product = $this->product([
            $this->version('2020-01-01', '2026-01-01', rate: 11.5),
            $this->version('2026-01-01', null, rate: 12.25),
        ]);

        $this->assertSame(
            11.5,
            $product->resolve(Carbon::parse('2025-06-01'))->interestRate,
        );
        $this->assertSame(
            12.25,
            $product->resolve(Carbon::parse('2026-06-01'))->interestRate,
        );
    }

    public function test_a_day_before_the_first_version_has_no_terms(): void
    {
        $product = $this->product([$this->version('2026-01-01')]);

        // Not "the nearest version" and not a default. The product simply
        // was not offered then.
        $this->assertNull($product->resolve(Carbon::parse('2025-01-01')));
        $this->assertFalse($product->isDiscoverable(Carbon::parse('2025-01-01')));
    }

    public function test_a_withdrawn_version_leaves_the_product_undiscoverable(): void
    {
        $product = $this->product([
            $this->version('2020-01-01', '2026-01-01'),
        ]);

        $this->assertNull($product->resolve(Carbon::parse('2026-06-01')));
    }

    public function test_two_versions_in_force_at_once_are_refused(): void
    {
        // There is no safe choice between them: picking the newest, or the
        // first in the list, would price a loan from a record nobody chose.
        $this->expectException(AmbiguousCatalogueException::class);

        $this->product([
            $this->version('2020-01-01', rate: 11.5),
            $this->version('2024-01-01', rate: 12.5),
        ]);
    }

    public function test_versions_that_meet_exactly_do_not_overlap(): void
    {
        // One ends the instant the next begins. The boundary belongs to the
        // newer version.
        $product = $this->product([
            $this->version('2020-01-01', '2026-01-01', rate: 11.5),
            $this->version('2026-01-01', null, rate: 12.25),
        ]);

        $this->assertSame(
            12.25,
            $product->resolve(Carbon::parse('2026-01-01'))->interestRate,
        );
    }

    public function test_a_version_cannot_end_before_it_begins(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->version('2026-01-01', '2025-01-01');
    }

    public function test_a_product_with_no_versions_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->product([]);
    }

    // --- Status -----------------------------------------------------------

    #[DataProvider('statuses')]
    public function test_only_an_active_product_is_offered(
        ProductStatus $status,
        bool $discoverable,
    ): void {
        $product = $this->product(status: $status);

        $this->assertSame($discoverable, $product->isDiscoverable());
        // Its terms remain readable either way: an application made against
        // it has to keep making sense.
        $this->assertNotNull($product->resolve());
    }

    public static function statuses(): array
    {
        return [
            'draft' => [ProductStatus::Draft, false],
            'active' => [ProductStatus::Active, true],
            'inactive' => [ProductStatus::Inactive, false],
        ];
    }

    // --- Sample and live --------------------------------------------------

    public function test_a_sample_provider_cannot_publish_live_pricing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->product([
            $this->version('2020-01-01', source: ProductSource::manual('rate card')),
        ]);
    }

    public function test_a_live_source_serialises_as_live(): void
    {
        $product = $this->product(
            provider: $this->provider('real_provider', isSample: false),
            versions: [
                $this->version(
                    '2020-01-01',
                    source: ProductSource::fromProvider(
                        ProductSourceType::ProviderFeed,
                        'rate-card-2026-01',
                        Carbon::parse('2026-01-01'),
                    ),
                ),
            ],
        );

        $array = $product->resolve()->toArray();

        $this->assertFalse($array['is_sample_data']);
        $this->assertSame('real_provider', $array['provider_code']);
    }

    public function test_a_sample_source_serialises_as_sample(): void
    {
        $this->assertTrue($this->product()->resolve()->toArray()['is_sample_data']);
    }

    public function test_sample_data_references_nothing_because_it_is_nothing(): void
    {
        $source = ProductSource::sample();

        $this->assertNull($source->reference);
        $this->assertNull($source->retrievedAt);
        $this->assertFalse($source->isLive());
    }

    public function test_a_provider_source_cannot_be_created_as_sample(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProductSource::fromProvider(
            ProductSourceType::Sample,
            'anything',
            Carbon::now(),
        );
    }

    public function test_only_the_sample_flag_crosses_to_the_customer(): void
    {
        // A feed identifier and a retrieval time belong to whoever maintains
        // the catalogue. On a decision screen they are noise at best.
        $source = ProductSource::fromProvider(
            ProductSourceType::ProviderApi,
            'internal-feed-reference',
            Carbon::now(),
        );

        $this->assertSame(
            ['is_sample_data' => false],
            $source->toCustomerArray(),
        );
    }

    // --- What FynnMatch receives ------------------------------------------

    public function test_a_resolved_version_is_an_ordinary_loan_product(): void
    {
        // Nothing downstream needs to know versions exist.
        $product = $this->product()->resolve();

        $this->assertInstanceOf(LoanProduct::class, $product);
        $this->assertTrue($product->supportsAmount(Money::of(500000)));
        $this->assertTrue($product->supportsTenure(36));
        $this->assertSame('sample_bank:term_loan', $product->id);
    }

    public function test_the_shipped_catalogue_is_six_sample_products(): void
    {
        $repository = app(MockProductRepository::class);
        $administered = $repository->catalogueProducts();

        $this->assertCount(6, $administered);

        foreach ($administered as $product) {
            $this->assertTrue($product->provider->isSample, $product->id());
            $this->assertSame(ProductStatus::Active, $product->status);
            $this->assertCount(1, $product->versions);
            $this->assertFalse($product->versions[0]->source->isLive());
            // The identity every existing application already records.
            $this->assertStringStartsWith('prod_', $product->id());
        }
    }

    public function test_every_sample_provider_has_a_distinct_code(): void
    {
        $codes = array_map(
            fn (CatalogueProduct $p) => $p->provider->code,
            app(MockProductRepository::class)->catalogueProducts(),
        );

        $this->assertSame($codes, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]+$/', $code);
        }
    }
}
