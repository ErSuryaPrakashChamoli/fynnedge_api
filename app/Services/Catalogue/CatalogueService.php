<?php

namespace App\Services\Catalogue;

use App\Domain\Catalog\CatalogueProduct as ProductData;
use App\Domain\Catalog\DatabaseProductRepository;
use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\Provider as ProviderData;
use App\Domain\Catalog\ProviderType;
use App\Domain\Catalog\UnsupportedProductClaims;
use App\Models\CatalogueProduct;
use App\Models\ProductVersion;
use App\Models\Provider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every operation that can change what a customer sees in the catalogue.
 *
 * The rules that run through all of it:
 *
 *  - A published version is never edited. Correcting one means cloning it
 *    into a draft, changing the draft, and publishing that. The old version
 *    stays exactly as it was, because applications were made against it.
 *
 *  - Validation runs again at publication. A draft may have been written
 *    weeks ago, and the product, the provider or the neighbouring versions
 *    may have moved since.
 *
 *  - Every change is one transaction and one audit record. A half-published
 *    version is worse than no version at all.
 */
class CatalogueService
{
    public function __construct(
        private readonly CatalogueAuditor $audit,
        private readonly ProductRepository $products,
    ) {}

    // --- Providers --------------------------------------------------------

    public function createProvider(
        string $code,
        string $name,
        ProviderType $type,
        bool $isSample = true,
    ): Provider {
        // Constructing the domain object validates the code and the name
        // before anything is written.
        new ProviderData(code: $code, name: $name, type: $type, isSample: $isSample);

        if (Provider::where('code', $code)->exists()) {
            throw new CatalogueException("A provider with code '{$code}' already exists.");
        }

        return DB::transaction(function () use ($code, $name, $type, $isSample) {
            $provider = Provider::create([
                'code' => $code,
                'name' => $name,
                'type' => $type->value,
                'status' => Provider::STATUS_ACTIVE,
                'is_sample' => $isSample,
            ]);

            $this->audit->record(
                CatalogueAuditor::PROVIDER_CREATED,
                'provider',
                $provider->id,
                after: ['code' => $code, 'name' => $name, 'type' => $type->value],
            );

            return $provider;
        });
    }

    public function setProviderStatus(Provider $provider, string $status): Provider
    {
        if (! in_array($status, [Provider::STATUS_ACTIVE, Provider::STATUS_INACTIVE], true)) {
            throw new CatalogueException("Unknown provider status '{$status}'.");
        }

        return DB::transaction(function () use ($provider, $status) {
            $before = $provider->status;
            $provider->update(['status' => $status]);

            $this->audit->record(
                CatalogueAuditor::PROVIDER_UPDATED,
                'provider',
                $provider->id,
                before: ['status' => $before],
                after: ['status' => $status],
            );

            $this->forgetReads();

            return $provider->fresh();
        });
    }

    // --- Products ---------------------------------------------------------

    /**
     * A product identity. Carries no pricing: that arrives as a version.
     *
     * Created inactive, because a product with no published version has
     * nothing to show and being active would only mean "would appear if it
     * had terms".
     */
    public function createProduct(
        Provider $provider,
        string $productCode,
        string $displayName,
        string $category,
        ?string $publicId = null,
        ?string $providerProductCode = null,
    ): CatalogueProduct {
        // Checked here rather than at publication, so a badly shaped code is
        // refused when it is offered rather than after a version has been
        // built on it.
        if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $productCode)) {
            throw new CatalogueException(
                "Product code '{$productCode}' must be lowercase alphanumeric "
                .'with underscores, 2–40 characters.',
            );
        }

        $exists = CatalogueProduct::where('provider_id', $provider->id)
            ->where('product_code', $productCode)
            ->exists();

        if ($exists) {
            throw new CatalogueException(
                "{$provider->code} already has a product '{$productCode}'.",
            );
        }

        $id = $publicId ?? CatalogueProduct::composeId($provider->code, $productCode);

        if (CatalogueProduct::where('public_id', $id)->exists()) {
            throw new CatalogueException("Product identity '{$id}' is already in use.");
        }

        return DB::transaction(function () use ($provider, $productCode, $providerProductCode, $displayName, $category, $id) {
            $product = CatalogueProduct::create([
                'provider_id' => $provider->id,
                'product_code' => $productCode,
                'provider_product_code' => $providerProductCode,
                'public_id' => $id,
                'display_name' => $displayName,
                'category' => $category,
                'status' => ProductStatus::Draft->value,
            ]);

            $this->audit->record(
                CatalogueAuditor::PRODUCT_CREATED,
                'product',
                $product->id,
                after: [
                    'public_id' => $id,
                    'provider' => $provider->code,
                    'category' => $category,
                ],
            );

            return $product;
        });
    }

    public function setProductStatus(
        CatalogueProduct $product,
        ProductStatus $status,
    ): CatalogueProduct {
        if ($status === ProductStatus::Active && ! $this->hasPublishedVersion($product)) {
            throw new CatalogueException(
                "Product '{$product->public_id}' has no published version to offer.",
            );
        }

        return DB::transaction(function () use ($product, $status) {
            $before = $product->status;
            $product->update(['status' => $status->value]);

            $this->audit->record(
                $status === ProductStatus::Active
                    ? CatalogueAuditor::PRODUCT_ACTIVATED
                    : CatalogueAuditor::PRODUCT_DEACTIVATED,
                'product',
                $product->id,
                before: ['status' => $before],
                after: ['status' => $status->value],
            );

            $this->forgetReads();

            return $product->fresh();
        });
    }

    // --- Versions ---------------------------------------------------------

    /**
     * A draft version. Editable, and invisible to customers until published.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createDraftVersion(
        CatalogueProduct $product,
        array $attributes,
    ): ProductVersion {
        return DB::transaction(function () use ($product, $attributes) {
            $version = $product->versions()->create(
                $attributes + ['published_at' => null],
            );

            $this->audit->record(
                CatalogueAuditor::VERSION_CREATED,
                'version',
                $version->id,
                after: $this->auditableVersion($version),
            );

            return $version;
        });
    }

    /**
     * Changes a draft.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraftVersion(
        ProductVersion $version,
        array $attributes,
    ): ProductVersion {
        $this->assertEditable($version);

        return DB::transaction(function () use ($version, $attributes) {
            $before = $this->auditableVersion($version);
            $version->update($attributes);

            $this->audit->record(
                CatalogueAuditor::VERSION_UPDATED,
                'version',
                $version->id,
                before: $before,
                after: $this->auditableVersion($version->fresh()),
            );

            return $version->fresh();
        });
    }

    /**
     * Copies a published version into a new draft.
     *
     * The correction path. Reconstructing thirty fields by hand to change a
     * rate is how a fee gets mistyped, so the whole version is carried over
     * and only what the caller names is different.
     *
     * @param  array<string, mixed>  $changes
     */
    public function cloneToDraft(ProductVersion $version, array $changes = []): ProductVersion
    {
        // The cast values, not the raw column values: getAttributes() hands
        // back the JSON columns as strings, and a draft carrying a string
        // where an array belongs fails only later, at publication.
        $attributes = collect($version->getAttributes())
            ->except(['id', 'published_at', 'created_at', 'updated_at'])
            ->map(fn ($value, $key) => $version->hasCast($key)
                ? $version->getAttribute($key)
                : $value)
            ->all();

        return $this->createDraftVersion(
            $version->product,
            array_merge($attributes, $changes),
        );
    }

    /**
     * Makes a draft the product's terms.
     *
     * Everything is checked again here, against the catalogue as it is now
     * rather than as it was when the draft was written. On any failure
     * nothing is written at all.
     */
    public function publishVersion(ProductVersion $version): ProductVersion
    {
        if ($version->isPublished()) {
            throw new CatalogueException('That version is already published.');
        }

        return DB::transaction(function () use ($version) {
            // Locked for the check: two administrators publishing versions
            // of the same product at once must not both pass the overlap
            // test and leave the catalogue unable to say what it costs.
            $product = CatalogueProduct::query()
                ->whereKey($version->catalogue_product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPublishable($product, $version);

            $version->update(['published_at' => Carbon::now()]);

            $this->audit->record(
                CatalogueAuditor::VERSION_PUBLISHED,
                'version',
                $version->id,
                after: $this->auditableVersion($version->fresh()),
            );

            $this->forgetReads();

            return $version->fresh();
        });
    }

    /**
     * Ends a published version on a given day.
     *
     * The single exception to published-version immutability, and it is not
     * an exception to what matters: the terms are untouched. Every
     * application made under this version keeps describing exactly the rate,
     * fee and eligibility it was made under. What changes is only the answer
     * to "does this still apply", which is what makes it possible to
     * publish a successor without the two overlapping.
     */
    public function closeVersion(ProductVersion $version, string $until): ProductVersion
    {
        if (! $version->isPublished()) {
            throw new CatalogueException(
                'Only a published version needs closing; a draft can simply '
                .'be given a different date.',
            );
        }

        $end = Carbon::parse($until);

        if ($end->lessThanOrEqualTo($version->effective_from)) {
            throw new CatalogueException(
                'A version cannot end before it begins.',
            );
        }

        return DB::transaction(function () use ($version, $end) {
            $before = ['effective_until' => $version->effective_until?->toDateString()];
            $version->update(['effective_until' => $end]);

            $this->audit->record(
                CatalogueAuditor::VERSION_UPDATED,
                'version',
                $version->id,
                before: $before,
                after: ['effective_until' => $end->toDateString()],
            );

            $this->forgetReads();

            return $version->fresh();
        });
    }

    // --- Guards -----------------------------------------------------------

    private function assertEditable(ProductVersion $version): void
    {
        if ($version->isPublished()) {
            throw new CatalogueException(
                'A published version cannot be changed. Clone it into a draft, '
                .'change that, and publish the draft — the version customers '
                .'already decided against has to keep saying what it said.',
            );
        }
    }

    /**
     * Everything that must hold before a version becomes what customers see.
     */
    private function assertPublishable(
        CatalogueProduct $product,
        ProductVersion $version,
    ): void {
        $provider = $product->provider;

        if (! $provider->isActive()) {
            throw new CatalogueException(
                "Provider '{$provider->code}' is inactive.",
            );
        }

        // The domain does the real work: dates that run backwards, versions
        // that overlap, a sample provider carrying live pricing, and every
        // Module 19 coherence rule as LoanProduct is constructed.
        $siblings = $product->publishedVersions()
            ->where('id', '!=', $version->id)
            ->get()
            ->map(fn (ProductVersion $v) => $v->toData())
            ->all();

        try {
            $data = new ProductData(
                provider: $provider->toData(),
                code: $product->product_code,
                name: $product->display_name,
                category: $product->category,
                status: ProductStatus::Active,
                versions: [...$siblings, $version->toData()],
                legacyId: $product->public_id,
            );

            // Constructing the LoanProduct is what runs the coherence check,
            // so a malformed version is refused here rather than on a
            // customer's screen.
            $resolved = $data->resolve($version->effective_from);

            if ($resolved === null) {
                throw new CatalogueException(
                    'This version is not in force on its own start date.',
                );
            }

            $this->assertClaimsAreSupported($resolved);
            $this->assertTrustHasBeenAssessed($product, $version);
        } catch (CatalogueException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CatalogueException(
                'This version cannot be published: '.$e->getMessage(),
            );
        }
    }

    /**
     * FynnTrust is FynnEdge's own evaluation, and it has to have been made.
     *
     * No provider supplies it, so a version that arrives by ingestion has
     * none: zero with an empty breakdown means "not assessed", not "assessed
     * and worthless". Publishing that would put a nought on a decision
     * screen beside a real product and let a customer read it as a verdict.
     *
     * So a product FynnEdge has not evaluated cannot be offered. That is
     * the human step in provider ingestion: someone looks at the product and
     * says what they think of it before a customer is asked to.
     */
    private function assertTrustHasBeenAssessed(
        CatalogueProduct $product,
        ProductVersion $version,
    ): void {
        $unassessed = (int) $version->fynn_trust === 0
            && ($version->trust_breakdown ?? []) === [];

        if ($unassessed) {
            throw new CatalogueException(
                "Product '{$product->public_id}' has no FynnTrust "
                .'assessment. FynnTrust is FynnEdge\'s own evaluation and no '
                .'provider supplies one; set it on the draft before '
                .'publishing, or a nought reaches the customer as a verdict.',
            );
        }
    }

    /**
     * The Module 19 claim guard, applied before publication rather than
     * after a customer has read it.
     */
    private function assertClaimsAreSupported(LoanProduct $product): void
    {
        $copy = strtolower(json_encode([
            $product->lenderTag,
            $product->suitedReasons,
            $product->considerations,
            $product->conditions,
            $product->trustBreakdown,
        ]) ?: '');

        $claim = UnsupportedProductClaims::firstIn($copy);

        if ($claim !== null) {
            throw new CatalogueException(
                'Product copy claims "'.$claim.'", which nothing in FynnEdge '
                .'can support. No application reaches a provider, so no '
                .'product may describe how one behaves.',
            );
        }
    }

    private function hasPublishedVersion(CatalogueProduct $product): bool
    {
        return $product->publishedVersions()->exists();
    }

    /**
     * What an audit record keeps.
     *
     * The catalogue fields that were changed, and nothing else. Never the
     * request, which is how a token ends up in an audit log.
     *
     * @return array<string, mixed>
     */
    private function auditableVersion(ProductVersion $version): array
    {
        return [
            'effective_from' => $version->effective_from?->toDateString(),
            'effective_until' => $version->effective_until?->toDateString(),
            'interest_rate' => (float) $version->interest_rate,
            'processing_fee_percent' => (float) $version->processing_fee_percent,
            'processing_fee_cap' => $version->processing_fee_cap === null
                ? null
                : (float) $version->processing_fee_cap,
            'min_amount' => (float) $version->min_amount,
            'max_amount' => (float) $version->max_amount,
            'min_tenure_months' => (int) $version->min_tenure_months,
            'max_tenure_months' => (int) $version->max_tenure_months,
            'min_monthly_income' => $version->min_monthly_income === null
                ? null
                : (float) $version->min_monthly_income,
            'min_age' => $version->min_age,
            'max_age_at_maturity' => $version->max_age_at_maturity,
            'fynn_trust' => (int) $version->fynn_trust,
            'source_type' => $version->source_type,
            'published' => $version->isPublished(),
        ];
    }

    /** Catalogue reads are cached per instance; a change invalidates them. */
    private function forgetReads(): void
    {
        if ($this->products instanceof DatabaseProductRepository) {
            $this->products->forget();
        }
    }
}
