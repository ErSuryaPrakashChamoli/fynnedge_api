<?php

namespace Tests\Feature\Provider;

use App\Domain\Catalog\DatabaseProductRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProviderType;
use App\Domain\Money;
use App\Domain\Provider\CanonicalProductInput;
use App\Domain\Provider\Fixture\FixtureProviderAdapter;
use App\Domain\Provider\Fixture\FixtureProviderNormalizer;
use App\Domain\Provider\NormalizationFailed;
use App\Domain\Provider\ProviderAdapter;
use App\Domain\Provider\ProviderFeed;
use App\Domain\Provider\ProviderFeedKind;
use App\Domain\Provider\ProviderNormalizer;
use App\Models\CatalogueProduct;
use App\Models\User;
use App\Services\Catalogue\CatalogueService;
use App\Services\Provider\ProviderIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A SECOND fictional provider, sharing nothing with the first.
 *
 * Different field names, tenure in months rather than years, rate as a plain
 * percentage rather than basis points, its own category vocabulary, money as
 * decimal strings. If the boundary were leaking, this could not produce the
 * same canonical product without changes elsewhere.
 */
class SecondFixtureAdapter implements ProviderAdapter
{
    public const PROVIDER_CODE = 'halcyon_credit';

    public function __construct(private readonly ?array $rows = null) {}

    public function providerCode(): string
    {
        return self::PROVIDER_CODE;
    }

    public function fetch(): ProviderFeed
    {
        return new ProviderFeed(
            providerCode: self::PROVIDER_CODE,
            // This one sends only what changed, so absence means nothing.
            kind: ProviderFeedKind::Delta,
            retrievedAt: Carbon::parse('2026-09-01 10:00:00'),
            rows: $this->rows ?? [[
                'sku' => 'HC/PERSONAL/01',
                'label' => 'Halcyon Personal Credit',
                'family' => 'personal-unsecured',
                'annual_rate_pct' => '11.50',
                'fee' => ['percent' => '1.25', 'max_rupees' => '18000.00'],
                'principal' => ['low' => '200000.00', 'high' => '2500000.00'],
                'term_months' => ['low' => 12, 'high' => 72],
                'requires' => [
                    'monthly_income_min' => '35000.00',
                    'age_min' => 21,
                    'age_max_at_close' => 60,
                ],
                'foreclosure' => 'Nil after 6 EMIs',
                'positives' => ['Rate is fixed for the full term'],
                'negatives' => ['The published minimum is high for smaller borrowing'],
                'small_print' => ['Minimum monthly income Rs 35,000'],
            ]],
        );
    }
}

/**
 * Halcyon's terms, in FynnEdge's.
 *
 * Every line here is different from Kestrel's normalizer, and neither knows
 * the other exists. That is the point: a provider's peculiarities live in
 * its own normalizer and nowhere else.
 */
class SecondFixtureNormalizer implements ProviderNormalizer
{
    private const CATEGORIES = [
        'personal-unsecured' => 'personal',
        'business-term' => 'business',
    ];

    public function normalize(array $row): CanonicalProductInput
    {
        $ref = $row['sku'] ?? null;

        if (! isset(self::CATEGORIES[$row['family'] ?? ''])) {
            throw new NormalizationFailed($ref, 'unmapped product family.');
        }

        return new CanonicalProductInput(
            providerProductCode: (string) $row['sku'],
            displayName: (string) $row['label'],
            category: self::CATEGORIES[$row['family']],
            discontinued: ($row['state'] ?? 'live') === 'withdrawn',

            // A plain percentage string, per this provider's contract.
            interestRate: (float) $row['annual_rate_pct'],
            processingFeePercent: (float) $row['fee']['percent'],
            processingFeeCap: isset($row['fee']['max_rupees'])
                ? Money::of($row['fee']['max_rupees'])
                : null,
            prepaymentCharge: (string) ($row['foreclosure'] ?? ''),

            minAmount: Money::of($row['principal']['low']),
            maxAmount: Money::of($row['principal']['high']),
            // Already months here. No conversion, and none invented.
            minTenureMonths: (int) $row['term_months']['low'],
            maxTenureMonths: (int) $row['term_months']['high'],

            minMonthlyIncome: isset($row['requires']['monthly_income_min'])
                ? Money::of($row['requires']['monthly_income_min'])
                : null,
            minAge: $row['requires']['age_min'] ?? null,
            maxAgeAtMaturity: $row['requires']['age_max_at_close'] ?? null,

            suitedReasons: $row['positives'] ?? [],
            considerations: $row['negatives'] ?? [],
            conditions: $row['small_print'] ?? [],
            unsupported: [],
            effectiveFrom: null,
            effectiveUntil: null,
        );
    }
}

/**
 * Two providers, one catalogue, and a customer who cannot tell.
 */
class ProviderIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fynnedge.catalogue.driver' => 'database']);
        $this->app->forgetInstance(ProductRepository::class);
        $this->app->singleton(ProductRepository::class, DatabaseProductRepository::class);
    }

    private function ingestBoth(): void
    {
        $ingestion = app(ProviderIngestionService::class);

        $ingestion->ingest(
            adapter: new FixtureProviderAdapter,
            normalizer: new FixtureProviderNormalizer,
            providerName: FixtureProviderAdapter::providerName(),
            providerType: ProviderType::Nbfc,
        );

        $ingestion->ingest(
            adapter: new SecondFixtureAdapter,
            normalizer: new SecondFixtureNormalizer,
            providerName: 'Halcyon Credit (sample)',
            providerType: ProviderType::Bank,
        );
    }

    private function assessAndPublish(): void
    {
        $catalogue = app(CatalogueService::class);

        foreach (CatalogueProduct::all() as $product) {
            $version = $product->versions()->orderByDesc('id')->first();

            $catalogue->updateDraftVersion($version, [
                'fynn_trust' => 82,
                'trust_breakdown' => ['cost' => [
                    'label' => 'Cost',
                    'value' => 82,
                    'explanation' => 'The published rate holds for the term.',
                ]],
            ]);
            $catalogue->publishVersion($version->fresh());
            $catalogue->setProductStatus($product, ProductStatus::Active);
        }
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Journey Person', 'age' => 34]);
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

    public function test_two_providers_describing_the_same_product_produce_the_same_thing(): void
    {
        $this->ingestBoth();

        $kestrel = CatalogueProduct::where('provider_product_code', 'KF-PL-001')
            ->firstOrFail()->versions()->first();
        $halcyon = CatalogueProduct::where('provider_product_code', 'HC/PERSONAL/01')
            ->firstOrFail()->versions()->first();

        // One provider quotes basis points and years; the other quotes
        // percentages and months. FynnEdge holds one answer.
        foreach ([
            'interest_rate', 'processing_fee_percent', 'processing_fee_cap',
            'min_amount', 'max_amount', 'min_tenure_months',
            'max_tenure_months', 'min_monthly_income', 'min_age',
            'max_age_at_maturity', 'prepayment_charge',
        ] as $field) {
            $this->assertEquals(
                $kestrel->$field,
                $halcyon->$field,
                "the two providers disagree about {$field} after normalization",
            );
        }
    }

    public function test_a_second_provider_needed_no_change_anywhere_downstream(): void
    {
        $this->ingestBoth();
        $this->assessAndPublish();
        $this->customer();

        $matches = $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=48&purpose=personal')
            ->assertOk()->json('data.matches');

        $ids = array_column(array_column($matches, 'product'), 'id');

        $this->assertContains('kestrel_financial:kf_pl_001', $ids);
        $this->assertContains('halcyon_credit:hc_personal_01', $ids);
    }

    public function test_nothing_downstream_knows_which_provider_a_product_came_from(): void
    {
        // The decision layer has no provider-specific branch, and this is
        // what would break first if one appeared.
        $sources = array_merge(
            glob(app_path('Domain/Match/*.php')) ?: [],
            glob(app_path('Domain/Financial/*.php')) ?: [],
            glob(app_path('Domain/Twin/*.php')) ?: [],
            glob(app_path('Domain/Score/*.php')) ?: [],
            glob(app_path('Services/LoanApplicationService.php')) ?: [],
            glob(app_path('Http/Controllers/Api/*.php')) ?: [],
        );

        foreach ($sources as $file) {
            $source = file_get_contents($file);

            foreach ([
                'kestrel', 'halcyon', 'Kestrel', 'Halcyon',
                'FixtureProvider', 'roiBasisPoints', 'provider_product_code',
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $source,
                    basename($file)." knows about a provider: {$leak}",
                );
            }
        }
    }

    // --- The whole journey ------------------------------------------------------

    public function test_an_ingested_product_travels_the_entire_customer_journey(): void
    {
        $this->ingestBoth();
        $this->assessAndPublish();
        $this->customer();

        $id = 'kestrel_financial:kf_pl_001';

        // FynnMatch
        $match = collect(
            $this->getJson('/api/v1/fynn-match?amount=1000000&tenure_months=48&purpose=personal')
                ->assertOk()->json('data.matches')
        )->firstWhere('product.id', $id);

        $this->assertNotNull($match, 'the ingested product did not reach FynnMatch');
        $this->assertSame(11.5, $match['product']['interest_rate']);
        // No live provider is connected, so it is labelled sample.
        $this->assertTrue($match['product']['is_sample_data']);

        $emi = $match['pricing']['emi'];

        // Loan Detail and FynnTrust
        $detail = $this->getJson('/api/v1/products/'.$id)->assertOk()->json('data');
        $this->assertSame(11.5, $detail['interest_rate']);
        $this->assertSame(82, $detail['fynn_trust']);

        // FynnTwin
        $twin = $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'loan',
            'product_id' => $id,
            'amount' => 1000000,
            'tenure_months' => 48,
        ])->assertOk()->json('data');
        $this->assertSame($emi, $twin['loan']['emi']);

        // Application
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => $id,
            'amount' => 1000000,
            'tenure_months' => 48,
        ])->assertCreated()->json('data');

        $this->assertSame($emi, $application['emi']);
        $this->assertSame('Kestrel Financial (sample)', $application['lender']);

        $this->postJson("/api/v1/applications/{$application['id']}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // --- The provider reprices --------------------------------------
        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['roiBasisPoints'] = 1350;

        app(ProviderIngestionService::class)->ingest(
            adapter: new FixtureProviderAdapter($rows, Carbon::now()->addDay()),
            normalizer: new FixtureProviderNormalizer,
            providerName: FixtureProviderAdapter::providerName(),
            providerType: ProviderType::Nbfc,
            publish: true,
        );

        // The new terms take effect tomorrow, so today still resolves to
        // the terms in force today. The catalogue answers for a day, not for
        // whatever was most recently written.
        $today = $this->getJson('/api/v1/products/'.$id)->assertOk()->json('data');
        $this->assertSame(11.5, $today['interest_rate']);

        // And the new version is really there, published and dated.
        $product = CatalogueProduct::where('provider_product_code', 'KF-PL-001')
            ->firstOrFail();
        $newest = $product->publishedVersions()->orderByDesc('id')->first();
        $this->assertSame(13.5, (float) $newest->interest_rate);
        $this->assertSame(
            Carbon::now()->addDay()->toDateString(),
            $newest->effective_from->toDateString(),
        );
        // The version it replaces was closed, not edited: its terms are
        // exactly what they were.
        $previous = $product->publishedVersions()->orderBy('id')->first();
        $this->assertSame(11.5, (float) $previous->interest_rate);

        // --- And the application still says what it said ------------------
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
                "{$field} was rewritten by a provider update",
            );
        }
    }

    public function test_a_delta_feed_that_omits_a_product_withdraws_nothing(): void
    {
        $this->ingestBoth();
        $this->assessAndPublish();

        $halcyon = CatalogueProduct::where('provider_product_code', 'HC/PERSONAL/01')
            ->firstOrFail();
        $this->assertTrue($halcyon->status()->isDiscoverable());

        // An empty delta says nothing changed, not "we offer nothing".
        app(ProviderIngestionService::class)->ingest(
            adapter: new SecondFixtureAdapter([]),
            normalizer: new SecondFixtureNormalizer,
            providerName: 'Halcyon Credit (sample)',
            providerType: ProviderType::Bank,
        );

        $this->assertTrue(
            $halcyon->fresh()->status()->isDiscoverable(),
            'a delta feed withdrew a product merely by not mentioning it',
        );
    }
}
