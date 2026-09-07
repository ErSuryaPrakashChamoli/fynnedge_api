<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalog\AmbiguousCatalogueException;
use App\Domain\Catalog\DatabaseProductRepository;
use App\Domain\Catalog\ProductSourceType;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProviderType;
use App\Models\CatalogueAuditEvent;
use App\Models\CatalogueProduct;
use App\Models\ProductVersion;
use App\Models\Provider;
use App\Models\User;
use App\Services\Catalogue\CatalogueAuditor;
use App\Services\Catalogue\CatalogueException;
use App\Services\Catalogue\CatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Catalogue control: who may change what a customer sees, and what the
 * system refuses to let them do.
 */
class CatalogueOperationsTest extends TestCase
{
    use RefreshDatabase;

    private CatalogueService $catalogue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue = app(CatalogueService::class);
    }

    private function provider(string $code = 'sample_bank', bool $isSample = true): Provider
    {
        return $this->catalogue->createProvider(
            code: $code,
            name: ucwords(str_replace('_', ' ', $code)),
            type: ProviderType::Bank,
            isSample: $isSample,
        );
    }

    private function product(?Provider $provider = null, string $code = 'term_loan'): CatalogueProduct
    {
        return $this->catalogue->createProduct(
            provider: $provider ?? $this->provider(),
            productCode: $code,
            displayName: 'Term Loan',
            category: 'personal',
        );
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

    private function publishedProduct(array $overrides = []): CatalogueProduct
    {
        $product = $this->product();
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes($overrides),
        );
        $this->catalogue->publishVersion($draft);

        return $this->catalogue->setProductStatus($product, ProductStatus::Active);
    }

    private function repository(): DatabaseProductRepository
    {
        // A fresh instance: the repository caches its reads, as a request
        // would, so a test that mutates has to ask again.
        return new DatabaseProductRepository;
    }

    // --- Identity ---------------------------------------------------------

    public function test_two_providers_can_offer_the_same_product_code(): void
    {
        $a = $this->product($this->provider('provider_a'));
        $b = $this->product($this->provider('provider_b'));

        $this->assertSame('provider_a:term_loan', $a->public_id);
        $this->assertSame('provider_b:term_loan', $b->public_id);
    }

    public function test_a_provider_cannot_have_two_products_with_one_code(): void
    {
        $provider = $this->provider();
        $this->product($provider);

        $this->expectException(CatalogueException::class);
        $this->product($provider);
    }

    public function test_a_duplicate_provider_code_is_refused(): void
    {
        $this->provider('provider_a');

        $this->expectException(CatalogueException::class);
        $this->provider('provider_a');
    }

    public function test_identity_survives_every_change_to_the_terms(): void
    {
        $product = $this->publishedProduct();
        $identity = $product->public_id;
        $published = $product->publishedVersions()->first();

        $this->catalogue->closeVersion($published, '2026-10-01');
        $draft = $this->catalogue->cloneToDraft($published, [
            'effective_from' => '2026-10-01',
            'effective_until' => null,
            'interest_rate' => 13.9,
            'processing_fee_percent' => 2.0,
            'fynn_trust' => 60,
            'min_monthly_income' => '60000.00',
            'suited_reasons' => ['Different copy entirely'],
        ]);
        $this->catalogue->publishVersion($draft);

        $this->assertSame($identity, $product->fresh()->public_id);
        $this->assertSame(2, $product->versions()->count());
    }

    // --- Draft and published ----------------------------------------------

    public function test_a_draft_is_invisible_to_customers(): void
    {
        $product = $this->product();
        $this->catalogue->createDraftVersion($product, $this->versionAttributes());

        // Not published, so there is nothing to offer and the product
        // cannot even be activated.
        $this->expectException(CatalogueException::class);
        $this->catalogue->setProductStatus($product, ProductStatus::Active);
    }

    public function test_a_published_version_cannot_be_edited(): void
    {
        $product = $this->publishedProduct();
        $version = $product->publishedVersions()->first();

        foreach ([
            ['interest_rate' => 20.0],
            ['processing_fee_percent' => 5.0],
            ['fynn_trust' => 10],
            ['min_monthly_income' => '999999.00'],
            ['effective_from' => '2021-01-01'],
        ] as $change) {
            try {
                $this->catalogue->updateDraftVersion($version, $change);
                $this->fail('A published version accepted '.json_encode($change));
            } catch (CatalogueException $e) {
                $this->assertStringContainsString('Clone it into a draft', $e->getMessage());
            }
        }

        $this->assertSame(11.5, (float) $version->fresh()->interest_rate);
    }

    public function test_publishing_twice_is_refused(): void
    {
        $product = $this->publishedProduct();

        $this->expectException(CatalogueException::class);
        $this->catalogue->publishVersion($product->publishedVersions()->first());
    }

    public function test_a_clone_carries_everything_and_changes_only_what_is_named(): void
    {
        $product = $this->publishedProduct();
        $source = $product->publishedVersions()->first();

        $draft = $this->catalogue->cloneToDraft($source, [
            'effective_from' => '2026-10-01',
            'interest_rate' => 13.9,
        ]);

        $this->assertNotSame($source->id, $draft->id);
        $this->assertNull($draft->published_at);
        $this->assertSame(13.9, (float) $draft->interest_rate);
        $this->assertSame(80, (int) $draft->fynn_trust);
        $this->assertSame($source->suited_reasons, $draft->suited_reasons);
        $this->assertSame(
            (float) $source->processing_fee_percent,
            (float) $draft->processing_fee_percent,
        );
    }

    // --- Effective dates ---------------------------------------------------

    public function test_overlapping_published_versions_are_refused(): void
    {
        $product = $this->publishedProduct();

        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes(['effective_from' => '2024-01-01']),
        );

        try {
            $this->catalogue->publishVersion($draft);
            $this->fail('Two versions were allowed to be in force at once');
        } catch (CatalogueException $e) {
            $this->assertStringContainsString('same time', $e->getMessage());
        }

        $this->assertNull($draft->fresh()->published_at);
    }

    public function test_identical_dates_are_refused(): void
    {
        $product = $this->publishedProduct();
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes(),
        );

        $this->expectException(CatalogueException::class);
        $this->catalogue->publishVersion($draft);
    }

    public function test_versions_that_meet_exactly_are_allowed(): void
    {
        $product = $this->publishedProduct();
        $first = $product->publishedVersions()->first();

        $this->catalogue->closeVersion($first, '2026-10-01');
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes([
                'effective_from' => '2026-10-01',
                'interest_rate' => 13.9,
            ]),
        );

        $this->catalogue->publishVersion($draft);
        $this->assertSame(2, $product->publishedVersions()->count());
    }

    public function test_a_future_version_does_not_displace_todays(): void
    {
        $product = $this->publishedProduct();
        $first = $product->publishedVersions()->first();

        $this->catalogue->closeVersion($first, '2099-01-01');
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes([
                'effective_from' => '2099-01-01',
                'interest_rate' => 20.0,
            ]),
        );
        $this->catalogue->publishVersion($draft);

        $this->assertSame(
            11.5,
            $this->repository()->find($product->public_id)->interestRate,
        );
    }

    public function test_a_version_cannot_end_before_it_begins(): void
    {
        $product = $this->publishedProduct();

        $this->expectException(CatalogueException::class);
        $this->catalogue->closeVersion(
            $product->publishedVersions()->first(),
            '2019-01-01',
        );
    }

    public function test_closing_a_version_changes_no_term(): void
    {
        $product = $this->publishedProduct();
        $version = $product->publishedVersions()->first();
        $before = $version->only([
            'interest_rate', 'processing_fee_percent', 'fynn_trust',
            'min_monthly_income',
        ]);

        $closed = $this->catalogue->closeVersion($version, '2026-10-01');

        foreach ($before as $field => $value) {
            $this->assertEquals($value, $closed->$field, $field.' changed');
        }
    }

    // --- Validation at publication -----------------------------------------

    public function test_validation_runs_again_when_a_draft_is_published(): void
    {
        $product = $this->product();
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes([
                'min_amount' => '2000000.00',
                'max_amount' => '100000.00',
            ]),
        );

        $this->expectException(CatalogueException::class);
        $this->catalogue->publishVersion($draft);
    }

    public function test_a_malformed_version_cannot_be_published(): void
    {
        $provider = $this->provider();

        foreach ([
            'ranges reversed' => ['min_amount' => '9000000.00', 'max_amount' => '1000.00'],
            'tenures reversed' => ['min_tenure_months' => 84, 'max_tenure_months' => 12],
            'trust off scale' => ['fynn_trust' => 140],
            'impossible age' => ['min_age' => 90, 'max_age_at_maturity' => 30],
        ] as $name => $overrides) {
            $product = $this->catalogue->createProduct(
                provider: $provider,
                productCode: 'p_'.substr(md5($name), 0, 8),
                displayName: 'Test',
                category: 'personal',
            );
            $draft = $this->catalogue->createDraftVersion(
                $product,
                $this->versionAttributes($overrides),
            );

            try {
                $this->catalogue->publishVersion($draft);
                $this->fail("A version with {$name} was published");
            } catch (CatalogueException) {
                $this->assertNull($draft->fresh()->published_at, $name);
            }
        }
    }

    public function test_unsupported_copy_cannot_be_published(): void
    {
        $product = $this->product();
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes([
                'suited_reasons' => ['Guaranteed approval for salaried customers'],
            ]),
        );

        try {
            $this->catalogue->publishVersion($draft);
            $this->fail('A product claiming guaranteed approval was published');
        } catch (CatalogueException $e) {
            $this->assertStringContainsString('guaranteed approval', $e->getMessage());
        }
    }

    public function test_a_sample_provider_cannot_publish_live_pricing(): void
    {
        $product = $this->product();
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes([
                'source_type' => ProductSourceType::ProviderFeed->value,
                'source_reference' => 'rate-card-2026',
                'source_retrieved_at' => Carbon::now(),
            ]),
        );

        $this->expectException(CatalogueException::class);
        $this->catalogue->publishVersion($draft);
    }

    public function test_a_version_under_an_inactive_provider_cannot_be_published(): void
    {
        $provider = $this->provider();
        $product = $this->product($provider);
        $draft = $this->catalogue->createDraftVersion($product, $this->versionAttributes());

        $this->catalogue->setProviderStatus($provider, Provider::STATUS_INACTIVE);

        $this->expectException(CatalogueException::class);
        $this->catalogue->publishVersion($draft);
    }

    public function test_nothing_is_written_when_publication_fails(): void
    {
        $product = $this->product();
        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes(['fynn_trust' => 200]),
        );

        $auditBefore = CatalogueAuditEvent::count();

        try {
            $this->catalogue->publishVersion($draft);
        } catch (CatalogueException) {
            // expected
        }

        $this->assertNull($draft->fresh()->published_at);
        $this->assertSame(
            $auditBefore,
            CatalogueAuditEvent::count(),
            'a failed publication left an audit record',
        );
    }

    // --- Lifecycle ----------------------------------------------------------

    public function test_an_inactive_product_leaves_discovery(): void
    {
        $product = $this->publishedProduct();
        $this->assertNotNull($this->repository()->find($product->public_id));

        $this->catalogue->setProductStatus($product, ProductStatus::Inactive);

        $this->assertNull($this->repository()->find($product->public_id));
        $this->assertNotNull($product->fresh());
        $this->assertSame(1, $product->publishedVersions()->count());
    }

    public function test_an_inactive_provider_takes_its_products_out_of_discovery(): void
    {
        $provider = $this->provider();
        $product = $this->product($provider);
        $draft = $this->catalogue->createDraftVersion($product, $this->versionAttributes());
        $this->catalogue->publishVersion($draft);
        $this->catalogue->setProductStatus($product, ProductStatus::Active);

        $this->assertNotNull($this->repository()->find($product->public_id));

        $this->catalogue->setProviderStatus($provider, Provider::STATUS_INACTIVE);

        $this->assertNull($this->repository()->find($product->public_id));
        $this->assertNotNull($product->fresh(), 'the product was deleted');
    }

    // --- Audit ---------------------------------------------------------------

    public function test_every_change_is_recorded_once_with_before_and_after(): void
    {
        $product = $this->publishedProduct();

        $actions = CatalogueAuditEvent::orderBy('id')->pluck('action')->all();

        $this->assertSame([
            'provider_created',
            'product_created',
            'version_created',
            'version_published',
            'product_activated',
        ], $actions);

        $this->catalogue->setProductStatus($product, ProductStatus::Inactive);

        $last = CatalogueAuditEvent::latest('id')->first();
        $this->assertSame('product_deactivated', $last->action);
        $this->assertSame(['status' => 'active'], $last->before);
        $this->assertSame(['status' => 'inactive'], $last->after);
        $this->assertSame($product->id, $last->entity_id);
    }

    public function test_the_actor_is_the_authenticated_user_when_there_is_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->provider('actor_test');

        $event = CatalogueAuditEvent::latest('id')->first();
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('user:'.$user->id, $event->actor_label);
    }

    public function test_a_console_change_is_recorded_as_such(): void
    {
        app(CatalogueAuditor::class)
            ->actingFromConsole('console:operator');

        $this->provider('console_test');

        $event = CatalogueAuditEvent::latest('id')->first();
        $this->assertNull($event->actor_user_id);
        $this->assertSame('console:operator', $event->actor_label);
    }

    public function test_audit_records_cannot_be_changed_or_removed(): void
    {
        $this->provider();
        $event = CatalogueAuditEvent::first();

        try {
            $event->update(['action' => 'something_else']);
            $this->fail('An audit record was edited');
        } catch (RuntimeException) {
            // expected
        }

        try {
            $event->delete();
            $this->fail('An audit record was deleted');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('provider_created', $event->fresh()->action);
    }

    public function test_audit_records_carry_no_secrets_or_customer_money(): void
    {
        $this->publishedProduct();

        $body = CatalogueAuditEvent::all()->toJson();

        // Catalogue data belongs here — a product's published minimum
        // income is exactly the kind of change worth auditing. What must
        // never appear is a credential or one customer's own money.
        foreach ([
            'password', 'token', 'secret', 'otp', 'code_hash',
            'storage_key', 'remember_token', 'api_key',
            'customer_income', 'savings', 'existing_emi', 'surplus',
            'fynn_score',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, $forbidden);
        }

        // The product's own published rule is present, and should be.
        $this->assertStringContainsString('min_monthly_income', $body);
    }

    // --- Ambiguity ------------------------------------------------------------

    public function test_a_catalogue_that_cannot_price_a_product_says_so(): void
    {
        // Forced past the service, as a bad migration or a direct write
        // would. The repository must not pick one.
        $product = $this->publishedProduct();
        ProductVersion::create($this->versionAttributes([
            'catalogue_product_id' => $product->id,
            'effective_from' => '2024-01-01',
            'published_at' => Carbon::now(),
        ]));

        $this->expectException(AmbiguousCatalogueException::class);
        $this->repository()->all();
    }
}
