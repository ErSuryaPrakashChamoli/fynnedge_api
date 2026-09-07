<?php

namespace Tests\Feature\Provider;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProviderType;
use App\Domain\Provider\Fixture\FixtureProviderAdapter;
use App\Domain\Provider\Fixture\FixtureProviderNormalizer;
use App\Domain\Provider\IngestionResult;
use App\Domain\Provider\ProviderUnavailable;
use App\Models\CatalogueAuditEvent;
use App\Models\CatalogueProduct;
use App\Models\ProductVersion;
use App\Models\Provider;
use App\Services\Catalogue\CatalogueException;
use App\Services\Catalogue\CatalogueService;
use App\Services\Provider\ProviderIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What a provider can and cannot put into FynnEdge.
 *
 * NO LIVE PROVIDER IS CONNECTED. Everything here runs against a fictional
 * one whose feed is deliberately awkward — basis points, tenure in years,
 * its own category names, a requirement FynnEdge cannot evaluate — because a
 * normalizer that is doing nothing would pass a friendlier fixture.
 */
class ProviderIngestionTest extends TestCase
{
    use RefreshDatabase;

    private ProviderIngestionService $ingestion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestion = app(ProviderIngestionService::class);
    }

    /** @param list<array<string, mixed>>|null $rows */
    private function ingest(
        ?array $rows = null,
        bool $publish = false,
        bool $dryRun = false,
        ?Carbon $at = null,
        bool $unavailable = false,
    ): IngestionResult {
        return $this->ingestion->ingest(
            adapter: new FixtureProviderAdapter($rows, $at, $unavailable),
            normalizer: new FixtureProviderNormalizer,
            providerName: FixtureProviderAdapter::providerName(),
            providerType: ProviderType::Nbfc,
            publish: $publish,
            dryRun: $dryRun,
        );
    }

    private function product(string $providerRef = 'KF-PL-001'): CatalogueProduct
    {
        return CatalogueProduct::where('provider_product_code', $providerRef)
            ->firstOrFail();
    }

    // --- Normalization ------------------------------------------------------

    public function test_the_provider_speaks_its_own_language_and_fynnedge_speaks_its_own(): void
    {
        $this->ingest();

        $version = $this->product()->versions()->first();

        // roiBasisPoints 1150 → 11.5%
        $this->assertSame(11.5, (float) $version->interest_rate);
        // tenureYearsMin 1, tenureYearsMax 6 → months
        $this->assertSame(12, (int) $version->min_tenure_months);
        $this->assertSame(72, (int) $version->max_tenure_months);
        // segment CONSUMER_TERM_CREDIT → personal
        $this->assertSame('personal', $this->product()->category);
        // feeCeilingRupees 18000 → Money
        $this->assertSame(18000.0, (float) $version->processing_fee_cap);
    }

    public function test_provider_terminology_reaches_nothing_downstream(): void
    {
        $this->ingest();

        $stored = json_encode([
            CatalogueProduct::all()->toArray(),
            ProductVersion::all()->toArray(),
            Provider::all()->toArray(),
        ]);

        // The provider's field names live in the normalizer and nowhere else.
        foreach ([
            'roiBasisPoints', 'tenureYearsMin', 'tenureYearsMax', 'segment',
            'amountFloor', 'amountCeiling', 'feePct', 'feeCeilingRupees',
            'incomeFloorMonthly', 'applicantAgeMaxAtClosure', 'watchOuts',
            'highlights', 'foreclosureTerms', 'productTitle',
        ] as $providerField) {
            $this->assertStringNotContainsString($providerField, $stored, $providerField);
        }
    }

    public function test_the_provider_identifier_is_kept_but_is_not_our_identity(): void
    {
        $this->ingest();
        $product = $this->product();

        // Theirs, verbatim, because their next feed will use it.
        $this->assertSame('KF-PL-001', $product->provider_product_code);
        // Ours, in our format, because it reaches our URLs and applications.
        $this->assertSame('kf_pl_001', $product->product_code);
        $this->assertSame('kestrel_financial:kf_pl_001', $product->public_id);
    }

    public function test_an_unstated_rule_stays_unknown(): void
    {
        $this->ingest();

        // The second fixture product publishes no income or age rule.
        $version = $this->product('KF-BL-002')->versions()->first();

        $this->assertNull($version->min_monthly_income);
        $this->assertNull($version->min_age);
        $this->assertNull($version->max_age_at_maturity);
    }

    public function test_a_rule_fynnedge_cannot_evaluate_is_kept_apart_not_folded_in(): void
    {
        $this->ingest();

        $version = $this->product()->versions()->first();

        // Recorded, so nobody thinks the product was fully checked.
        $this->assertSame(
            [['key' => 'minimum_credit_score', 'value' => '700']],
            $version->unsupported_requirements,
        );

        // And nowhere near the eligibility FynnMatch reads, which would make
        // it report a verdict on something it never looked at. FynnEdge holds
        // no bureau data; that is Module 23's problem, not something to
        // silently pass.
        $this->assertSame(35000.0, (float) $version->min_monthly_income);
        $this->assertSame(21, (int) $version->min_age);
    }

    // --- Ambiguity is refused, not guessed ----------------------------------

    public function test_an_ambiguous_rate_is_refused(): void
    {
        $rows = FixtureProviderAdapter::catalogue();
        unset($rows[0]['roiBasisPoints']);
        $rows[0]['roi'] = 11.5;

        $result = $this->ingest($rows);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('ambiguous', $result->failures[0]['reason']);
        $this->assertSame('KF-PL-001', $result->failures[0]['reference']);
        $this->assertSame(0, CatalogueProduct::count());
    }

    public function test_an_unmapped_category_is_refused(): void
    {
        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['segment'] = 'EXCITING_NEW_CREDIT_LINE';

        $result = $this->ingest($rows);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('no mapping', $result->failures[0]['reason']);
    }

    public function test_a_tenure_that_is_not_a_number_of_years_is_refused(): void
    {
        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['tenureYearsMax'] = 'short';

        $result = $this->ingest($rows);

        $this->assertFalse($result->succeeded());
        $this->assertSame(0, CatalogueProduct::count());
    }

    // --- All or nothing -------------------------------------------------------

    public function test_one_bad_row_stops_the_whole_feed(): void
    {
        $rows = FixtureProviderAdapter::catalogue();
        $rows[1]['roiBasisPoints'] = -50;

        $result = $this->ingest($rows);

        $this->assertFalse($result->succeeded());
        $this->assertFalse($result->applied);
        $this->assertSame(2, $result->received);
        $this->assertSame(1, $result->normalized);

        // Not "publish the one that was readable". A feed that is wrong in
        // part cannot be trusted in part.
        $this->assertSame(0, CatalogueProduct::count());
        $this->assertSame(0, Provider::count());
    }

    // --- Idempotency and change detection --------------------------------------

    public function test_ingesting_the_same_feed_twice_changes_nothing_the_second_time(): void
    {
        $first = $this->ingest();
        $this->assertSame(2, $first->created);
        $this->assertSame(2, $first->versionsCreated);

        $second = $this->ingest();
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->versionsCreated);
        $this->assertSame(2, $second->unchanged);

        $third = $this->ingest();
        $this->assertSame(0, $third->versionsCreated);

        $this->assertSame(2, CatalogueProduct::count());
        $this->assertSame(2, ProductVersion::count());
    }

    public function test_a_changed_rate_refreshes_a_draft_nobody_has_offered(): void
    {
        $this->ingest();
        $product = $this->product();
        $identity = $product->public_id;

        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['roiBasisPoints'] = 1225;

        $result = $this->ingest($rows);

        $this->assertSame(0, $result->created, 'a second product was created');
        $this->assertSame(1, $result->versionsCreated);

        // Still one version. The first was never published, so it was never
        // anything a customer saw; two unpublished records of the same
        // product would only leave an operator choosing between them.
        $this->assertSame($identity, $product->fresh()->public_id);
        $this->assertSame(1, $product->versions()->count());
        $this->assertSame(
            12.25,
            (float) $product->versions()->first()->interest_rate,
        );
    }

    public function test_a_changed_rate_makes_a_new_version_once_one_is_published(): void
    {
        $this->ingestAndPublish();
        $product = $this->product();
        $identity = $product->public_id;

        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['roiBasisPoints'] = 1225;

        $result = $this->ingest($rows, at: Carbon::now()->addDay());

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->versionsCreated);

        // Two versions now: the published one a customer may have decided
        // against, and its successor.
        $this->assertSame($identity, $product->fresh()->public_id);
        $this->assertSame(2, $product->versions()->count());

        $published = $product->versions()->orderBy('id')->first();
        $this->assertSame(11.5, (float) $published->interest_rate);
        $this->assertTrue($published->isPublished());
        $this->assertNotNull($published->effective_until);

        $this->assertSame(
            12.25,
            (float) $product->versions()->orderByDesc('id')->first()->interest_rate,
        );
    }

    public function test_a_renamed_product_is_still_the_same_product(): void
    {
        $this->ingest();
        $before = $this->product()->public_id;

        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['productTitle'] = 'Kestrel Smart Personal Advance';

        $result = $this->ingest($rows);

        $this->assertSame(0, $result->created);
        // Copy is not versioned terms, so the rename creates no version.
        $this->assertSame(0, $result->versionsCreated);
        $this->assertSame($before, $this->product()->public_id);
        $this->assertSame('Kestrel Smart Personal Advance', $this->product()->display_name);
    }

    public function test_a_renamed_provider_is_still_the_same_provider(): void
    {
        $this->ingest();

        $this->ingestion->ingest(
            adapter: new FixtureProviderAdapter,
            normalizer: new FixtureProviderNormalizer,
            providerName: 'Kestrel Financial Group (sample)',
            providerType: ProviderType::Nbfc,
        );

        $this->assertSame(1, Provider::count());
        $this->assertSame(
            'Kestrel Financial Group (sample)',
            Provider::first()->name,
        );
        $this->assertSame('kestrel_financial', Provider::first()->code);
    }

    // --- Withdrawal ------------------------------------------------------------

    public function test_a_product_the_provider_discontinues_is_deactivated(): void
    {
        $this->ingestAndPublish();
        $this->assertTrue($this->product()->status()->isDiscoverable());

        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['status'] = 'DISCONTINUED';

        $result = $this->ingest($rows);

        $this->assertSame(1, $result->deactivated);
        $this->assertFalse($this->product()->fresh()->status()->isDiscoverable());
        // Nothing is deleted: applications made against it must stay readable.
        $this->assertNotNull($this->product()->fresh());
    }

    public function test_a_full_feed_that_omits_a_product_withdraws_it(): void
    {
        $this->ingestAndPublish();

        // This provider states its whole catalogue every time, so absence
        // means withdrawal.
        $rows = [FixtureProviderAdapter::catalogue()[0]];
        $result = $this->ingest($rows);

        $this->assertSame(1, $result->deactivated);
        $this->assertFalse(
            $this->product('KF-BL-002')->fresh()->status()->isDiscoverable(),
        );
    }

    // --- Provider outage --------------------------------------------------------

    public function test_an_unreachable_provider_changes_nothing(): void
    {
        $this->ingest();
        $before = ProductVersion::first();
        $retrievedBefore = $before->source_retrieved_at;

        $this->expectException(ProviderUnavailable::class);

        try {
            $this->ingest(unavailable: true);
        } finally {
            // No new version, no new retrieval time, and above all no
            // substitution of sample products for the ones that failed.
            $this->assertSame(2, ProductVersion::count());
            $this->assertEquals(
                $retrievedBefore,
                $before->fresh()->source_retrieved_at,
            );
        }
    }

    // --- Dry run -----------------------------------------------------------------

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        $preview = $this->ingest(dryRun: true);

        $this->assertTrue($preview->dryRun);
        $this->assertFalse($preview->applied);
        $this->assertSame(2, $preview->created);
        $this->assertSame(0, CatalogueProduct::count());

        $this->ingest();

        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['roiBasisPoints'] = 1225;
        $second = $this->ingest($rows, dryRun: true);

        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->versionsCreated, 'the change was not previewed');
        $this->assertSame(1, $second->unchanged);
        // And still nothing written.
        $this->assertSame(2, ProductVersion::count());
    }

    // --- Provenance and governance -------------------------------------------------

    public function test_ingested_versions_arrive_as_unpublished_drafts(): void
    {
        $this->ingest();

        foreach (ProductVersion::all() as $version) {
            $this->assertFalse($version->isPublished());
        }

        foreach (CatalogueProduct::all() as $product) {
            $this->assertFalse($product->status()->isDiscoverable());
        }
    }

    public function test_a_product_fynnedge_has_not_assessed_cannot_be_published(): void
    {
        // FynnTrust is FynnEdge's own evaluation and no provider supplies
        // one. Publishing an unassessed product would put a nought on a
        // decision screen where a customer reads it as a verdict.
        $result = $this->ingest(publish: true);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString(
            'no FynnTrust assessment',
            $result->failures[0]['reason'],
        );
        $this->assertSame(0, CatalogueProduct::count());
    }

    public function test_provenance_records_when_the_data_was_retrieved(): void
    {
        $at = Carbon::parse('2026-09-01 09:30:00');
        $this->ingest(at: $at);

        $version = ProductVersion::first();

        $this->assertEquals($at, $version->source_retrieved_at);
        // No live provider is connected, so the provenance says so.
        $this->assertSame('sample', $version->source_type);
        $this->assertNull($version->source_reference);
        // Terms take effect from the day they were retrieved, and run open —
        // not to a fabricated far-future date.
        $this->assertSame('2026-09-01', $version->effective_from->toDateString());
        $this->assertNull($version->effective_until);
    }

    public function test_every_ingested_provider_is_marked_fictional(): void
    {
        $this->ingest();

        $this->assertTrue(Provider::first()->is_sample);
    }

    // --- Boundaries ------------------------------------------------------------------

    public function test_customer_data_in_a_product_feed_is_refused(): void
    {
        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['customerName'] = 'Someone Real';
        $rows[0]['mobile'] = '9876543210';

        $result = $this->ingest($rows);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('customer data', $result->failures[0]['reason']);

        $stored = json_encode([
            CatalogueProduct::all()->toArray(),
            ProductVersion::all()->toArray(),
            CatalogueAuditEvent::all()->toArray(),
        ]);
        $this->assertStringNotContainsString('Someone Real', $stored);
        $this->assertStringNotContainsString('9876543210', $stored);
    }

    public function test_provider_marketing_copy_faces_the_same_claim_guard(): void
    {
        // Copy is not true because a provider wrote it.
        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['highlights'] = ['Instant approval for salaried applicants'];

        $this->ingest($rows);

        // It lands as a draft; the guard runs at publication.
        $product = $this->product();
        $version = $product->versions()->first();
        $version->update(['fynn_trust' => 80, 'trust_breakdown' => ['cost' => [
            'label' => 'Cost', 'value' => 80, 'explanation' => 'Assessed.',
        ]]]);

        $this->expectException(CatalogueException::class);
        app(CatalogueService::class)->publishVersion($version->fresh());
    }

    public function test_a_provider_rating_never_becomes_fynn_trust(): void
    {
        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['providerRating'] = 4.8;
        $rows[0]['trustScore'] = 95;

        $this->ingest($rows);

        // Neither field is in the canonical shape, so neither reaches the
        // catalogue. FynnTrust stays FynnEdge's own to make.
        $version = $this->product()->versions()->first();
        $this->assertSame(0, (int) $version->fynn_trust);
        $this->assertSame([], $version->trust_breakdown);
    }

    public function test_two_sets_of_published_terms_on_one_day_are_refused(): void
    {
        // A published version is one a customer may already have decided
        // against. FynnEdge dates versions by the day, so a second set of
        // terms starting the same day cannot be represented — and picking
        // between them is the ambiguity the catalogue refuses everywhere.
        $this->ingestAndPublish();

        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['roiBasisPoints'] = 1225;

        $result = $this->ingest($rows);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('same day', $result->failures[0]['reason']);

        // Nothing was written, and the published terms are untouched.
        $this->assertSame(2, ProductVersion::count());
        $this->assertSame(
            11.5,
            (float) $this->product()->versions()->first()->interest_rate,
        );
    }

    public function test_a_closed_version_keeps_its_terms_exactly(): void
    {
        $this->ingestAndPublish();

        $rows = FixtureProviderAdapter::catalogue();
        $rows[0]['roiBasisPoints'] = 1225;
        $rows[0]['feePct'] = 2.0;

        $this->ingest($rows, at: Carbon::now()->addDay());

        $first = $this->product()->versions()->orderBy('id')->first();

        // Closed, not edited. Everything a customer decided against still
        // says what it said.
        $this->assertSame(11.5, (float) $first->interest_rate);
        $this->assertSame(1.25, (float) $first->processing_fee_percent);
        $this->assertSame(
            Carbon::now()->addDay()->toDateString(),
            $first->effective_until->toDateString(),
        );
    }

    /** Ingests and does the human step: assess, then publish. */
    private function ingestAndPublish(): void
    {
        $this->ingest();
        $catalogue = app(CatalogueService::class);

        foreach (CatalogueProduct::all() as $product) {
            $version = $product->versions()->first();

            // What an operator does: look at the product and say what they
            // think of it, then offer it.
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
}
