<?php

namespace App\Services\Provider;

use App\Domain\Catalog\ProductSource;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProviderType;
use App\Domain\Money;
use App\Domain\Provider\CanonicalProductInput;
use App\Domain\Provider\IngestionResult;
use App\Domain\Provider\NormalizationFailed;
use App\Domain\Provider\ProviderAdapter;
use App\Domain\Provider\ProviderNormalizer;
use App\Domain\Provider\UnsupportedRequirement;
use App\Models\CatalogueProduct;
use App\Models\ProductVersion;
use App\Models\Provider;
use App\Services\Catalogue\CatalogueException;
use App\Services\Catalogue\CatalogueService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider data, into the FynnEdge catalogue.
 *
 *   fetch → normalize → validate → draft → (publish)
 *
 * Three policies decide how this behaves, and each was a choice:
 *
 * ALL OR NOTHING. If any row in a feed cannot be normalized, nothing is
 * written. A provider whose feed is half wrong is a provider whose feed
 * cannot be trusted in part, and publishing the readable 99 of 100 means
 * quietly serving a catalogue nobody reviewed.
 *
 * DRAFT BY DEFAULT. Provider data lands unpublished and waits for someone to
 * publish it. Automatic publication is available, and when used it runs
 * exactly the same validation — arriving from a provider earns a product no
 * shortcut past the claim guard or the overlap check.
 *
 * OMISSION MEANS NOTHING UNLESS THE FEED SAYS SO. A product withdrawn from a
 * full feed is deactivated. A product missing from a delta feed is simply
 * not in that delta.
 */
class ProviderIngestionService
{
    public function __construct(
        private readonly CatalogueService $catalogue,
    ) {}

    public function ingest(
        ProviderAdapter $adapter,
        ProviderNormalizer $normalizer,
        string $providerName,
        ProviderType $providerType,
        bool $publish = false,
        bool $dryRun = false,
    ): IngestionResult {
        $code = $adapter->providerCode();

        // A fetch failure ends the run: no version, no new retrieval time,
        // and above all no falling back to sample products.
        $feed = $adapter->fetch();

        $normalized = [];
        $failures = [];

        foreach ($feed->rows as $row) {
            try {
                $normalized[] = $normalizer->normalize($row);
            } catch (NormalizationFailed $e) {
                $failures[] = [
                    'reference' => $e->productReference,
                    'stage' => 'normalize',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        if ($failures !== []) {
            $this->logOutcome($code, 'rejected', [
                'received' => $feed->count(),
                'failures' => count($failures),
            ]);

            return new IngestionResult(
                providerCode: $code,
                retrievedAt: $feed->retrievedAt,
                received: $feed->count(),
                normalized: count($normalized),
                failures: $failures,
                applied: false,
                dryRun: $dryRun,
            );
        }

        if ($dryRun) {
            return $this->preview($code, $feed->retrievedAt, $feed->count(), $normalized);
        }

        return $this->apply(
            $code,
            $providerName,
            $providerType,
            $feed->retrievedAt,
            $feed->count(),
            $normalized,
            $feed->kind->guaranteesCompleteness(),
            $publish,
        );
    }

    /**
     * What would change, without changing anything.
     *
     * @param  list<CanonicalProductInput>  $products
     */
    private function preview(
        string $code,
        CarbonInterface $retrievedAt,
        int $received,
        array $products,
    ): IngestionResult {
        $provider = Provider::where('code', $code)->first();
        $created = 0;
        $changed = 0;
        $unchanged = 0;

        foreach ($products as $input) {
            $existing = $provider === null
                ? null
                : CatalogueProduct::where('provider_id', $provider->id)
                    ->where('provider_product_code', $input->providerProductCode)
                    ->first();

            if ($existing === null) {
                $created++;

                continue;
            }

            $this->currentFingerprint($existing) === $input->fingerprint()
                ? $unchanged++
                : $changed++;
        }

        return new IngestionResult(
            providerCode: $code,
            retrievedAt: $retrievedAt,
            received: $received,
            normalized: count($products),
            created: $created,
            versionsCreated: $changed,
            unchanged: $unchanged,
            applied: false,
            dryRun: true,
        );
    }

    /** @param list<CanonicalProductInput> $products */
    private function apply(
        string $code,
        string $providerName,
        ProviderType $providerType,
        CarbonInterface $retrievedAt,
        int $received,
        array $products,
        bool $feedIsComplete,
        bool $publish,
    ): IngestionResult {
        $created = 0;
        $versionsCreated = 0;
        $unchanged = 0;
        $deactivated = 0;

        try {
            DB::transaction(function () use (
                $code, $providerName, $providerType, $retrievedAt, $products,
                $feedIsComplete, $publish,
                &$created, &$versionsCreated, &$unchanged, &$deactivated
            ) {
                $provider = $this->provider($code, $providerName, $providerType);
                $seen = [];

                foreach ($products as $input) {
                    $seen[] = $input->providerProductCode;

                    // Matched on the provider's own reference, which is what
                    // their next feed will use to mean "the same product".
                    $product = CatalogueProduct::where('provider_id', $provider->id)
                        ->where('provider_product_code', $input->providerProductCode)
                        ->first();

                    if ($product === null) {
                        $product = $this->catalogue->createProduct(
                            provider: $provider,
                            productCode: self::productCode($input->providerProductCode),
                            displayName: $input->displayName,
                            category: $input->category,
                            providerProductCode: $input->providerProductCode,
                        );
                        $created++;
                    } elseif ($product->display_name !== $input->displayName) {
                        // A renamed product is the same product. Its identity
                        // and its versions are untouched.
                        $product->update(['display_name' => $input->displayName]);
                    }

                    if ($input->discontinued) {
                        $deactivated += $this->deactivate($product);

                        continue;
                    }

                    if ($this->currentFingerprint($product) === $input->fingerprint()) {
                        $unchanged++;

                        continue;
                    }

                    $this->addVersion($product, $input, $retrievedAt, $publish);
                    $versionsCreated++;
                }

                if ($feedIsComplete) {
                    $deactivated += $this->withdrawMissing($provider, $seen);
                }
            });
        } catch (Throwable $e) {
            $this->logOutcome($code, 'failed', ['stage' => 'persist']);

            return new IngestionResult(
                providerCode: $code,
                retrievedAt: $retrievedAt,
                received: $received,
                normalized: count($products),
                failures: [[
                    'reference' => null,
                    'stage' => 'persist',
                    // The message, never the payload that produced it.
                    'reason' => $e->getMessage(),
                ]],
                applied: false,
            );
        }

        $this->logOutcome($code, 'applied', [
            'created' => $created,
            'versions' => $versionsCreated,
            'unchanged' => $unchanged,
            'deactivated' => $deactivated,
        ]);

        return new IngestionResult(
            providerCode: $code,
            retrievedAt: $retrievedAt,
            received: $received,
            normalized: count($products),
            created: $created,
            versionsCreated: $versionsCreated,
            unchanged: $unchanged,
            deactivated: $deactivated,
            applied: true,
        );
    }

    private function provider(
        string $code,
        string $name,
        ProviderType $type,
    ): Provider {
        $provider = Provider::where('code', $code)->first();

        if ($provider !== null) {
            // A provider that renamed itself is the same provider. Only what
            // the customer reads changes.
            if ($provider->name !== $name) {
                $provider->update(['name' => $name]);
            }

            return $provider;
        }

        return $this->catalogue->createProvider(
            code: $code,
            name: $name,
            type: $type,
            // NO LIVE PROVIDER IS CONNECTED. Everything ingested today comes
            // from a fixture, so the provider it creates is marked fictional
            // and its versions carry a sample provenance. A real integration
            // sets these differently, deliberately, and nowhere else changes.
            isSample: true,
        );
    }

    /**
     * The terms currently held for a product, fingerprinted the same way a
     * normalized input is, so the two can be compared.
     */
    private function currentFingerprint(CatalogueProduct $product): ?string
    {
        $version = $product->publishedVersions()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()
            ?? $product->versions()->orderByDesc('id')->first();

        return $version === null ? null : $this->fingerprintOf($product, $version);
    }

    private function fingerprintOf(
        CatalogueProduct $product,
        ProductVersion $version,
    ): string {
        // Built through the canonical shape rather than from the row, so a
        // version written by an administrator and one written by ingestion
        // compare identically when they say the same thing.
        $stored = $version->unsupported_requirements ?? [];

        $unsupported = array_map(
            fn (array $r) => new UnsupportedRequirement($r['key'], $r['value']),
            is_array($stored) ? array_values($stored) : [],
        );

        return (new CanonicalProductInput(
            providerProductCode: (string) $product->provider_product_code,
            displayName: $product->display_name,
            category: $product->category,
            discontinued: false,
            interestRate: (float) $version->interest_rate,
            processingFeePercent: (float) $version->processing_fee_percent,
            processingFeeCap: $version->processing_fee_cap === null
                ? null
                : Money::of($version->processing_fee_cap),
            prepaymentCharge: (string) $version->prepayment_charge,
            minAmount: Money::of($version->min_amount),
            maxAmount: Money::of($version->max_amount),
            minTenureMonths: (int) $version->min_tenure_months,
            maxTenureMonths: (int) $version->max_tenure_months,
            minMonthlyIncome: $version->min_monthly_income === null
                ? null
                : Money::of($version->min_monthly_income),
            minAge: $version->min_age === null ? null : (int) $version->min_age,
            maxAgeAtMaturity: $version->max_age_at_maturity === null
                ? null
                : (int) $version->max_age_at_maturity,
            suitedReasons: $version->suited_reasons ?? [],
            considerations: $version->considerations ?? [],
            conditions: $version->conditions ?? [],
            unsupported: $unsupported,
            effectiveFrom: null,
            effectiveUntil: null,
        ))->fingerprint();
    }

    private function addVersion(
        CatalogueProduct $product,
        CanonicalProductInput $input,
        CarbonInterface $retrievedAt,
        bool $publish,
    ): void {
        // Where the provider states no dates, the terms take effect from the
        // day they were retrieved. Not a fabricated far-future end date — an
        // open-ended version simply has none.
        $from = $input->effectiveFrom ?? $retrievedAt->copy()->startOfDay();

        // The version this replaces has to stop before it starts, or the
        // catalogue cannot say what the product costs on the changeover day.
        $current = $product->publishedVersions()
            ->whereNull('effective_until')
            ->orderByDesc('effective_from')
            ->first();

        // FynnEdge's standing evaluation of this product, if it has one.
        $latest = $product->versions()->orderByDesc('id')->first();
        $carried = $latest;

        if ($latest !== null && ! $latest->isPublished()) {
            // The last thing ingestion recorded is still a draft nobody has
            // published. Refreshing it is not a new version of the product —
            // it is the same unpublished record, now saying something else.
            $this->catalogue->updateDraftVersion(
                $latest,
                $this->versionAttributes($input, $from, $retrievedAt, $carried),
            );

            if ($publish) {
                $this->catalogue->publishVersion($latest->fresh());
            }

            return;
        }

        if ($latest !== null && ! $from->greaterThan($latest->effective_from)) {
            // FynnEdge dates versions by the day, so two sets of terms
            // starting on the same day cannot both be represented — and
            // choosing between them is exactly the ambiguity the catalogue
            // refuses everywhere else. A provider repricing twice in a day
            // needs an operator to decide which day the change belongs to.
            throw new CatalogueException(sprintf(
                "Product '%s' already has terms effective %s. A further "
                .'change on the same day cannot be represented; date it from '
                .'the following day, or close the current version first.',
                $product->public_id,
                $latest->effective_from->toDateString(),
            ));
        }

        // The version this replaces has to stop before the new one starts,
        // or the catalogue cannot say what the product costs on the
        // changeover day.
        if ($latest !== null && $latest->effective_until === null) {
            $this->catalogue->closeVersion($latest, $from->toDateString());
        }

        $draft = $this->catalogue->createDraftVersion(
            $product,
            $this->versionAttributes($input, $from, $retrievedAt, $carried),
        );

        if ($publish) {
            // The same publication path an administrator uses: full
            // validation, claim guard, overlap check, audit. Arriving from a
            // provider is not a reason to skip any of it.
            $this->catalogue->publishVersion($draft);
        }
    }

    /**
     * The canonical input as catalogue columns.
     *
     * @return array<string, mixed>
     */
    private function versionAttributes(
        CanonicalProductInput $input,
        CarbonInterface $from,
        CarbonInterface $retrievedAt,
        ?ProductVersion $carried,
    ): array {
        return [
            'effective_from' => $from,
            'effective_until' => $input->effectiveUntil,
            'interest_rate' => $input->interestRate,
            'processing_fee_percent' => $input->processingFeePercent,
            'processing_fee_cap' => $input->processingFeeCap?->toDecimalString(),
            'prepayment_charge' => $input->prepaymentCharge,
            'min_amount' => $input->minAmount->toDecimalString(),
            'max_amount' => $input->maxAmount->toDecimalString(),
            'min_tenure_months' => $input->minTenureMonths,
            'max_tenure_months' => $input->maxTenureMonths,
            'min_monthly_income' => $input->minMonthlyIncome?->toDecimalString(),
            'min_age' => $input->minAge,
            'max_age_at_maturity' => $input->maxAgeAtMaturity,

            // FynnTrust is FynnEdge's own evaluation and no provider
            // supplies one. A repricing carries forward the assessment
            // already made of this product; a product nobody has assessed
            // yet gets none, and cannot be published until someone does.
            'fynn_trust' => $carried?->fynn_trust ?? 0,
            'trust_breakdown' => $carried?->trust_breakdown ?? [],

            'lender_tag' => '',
            'suited_reasons' => $input->suitedReasons,
            'considerations' => $input->considerations,
            'conditions' => $input->conditions,

            // Requirements FynnEdge cannot evaluate, kept beside the ones it
            // can rather than mixed in with them.
            'unsupported_requirements' => array_map(
                fn (UnsupportedRequirement $r) => $r->toArray(),
                $input->unsupported,
            ),

            ...$this->sourceColumns($retrievedAt),
        ];
    }

    /** @return array<string, mixed> */
    private function sourceColumns(CarbonInterface $retrievedAt): array
    {
        // NO LIVE PROVIDER IS CONNECTED, so the provenance says sample. A
        // real adapter builds ProductSource::fromProvider(...) here and
        // nothing else in FynnEdge changes.
        $source = ProductSource::sample();

        return [
            'source_type' => $source->type->value,
            'source_reference' => $source->reference,
            'source_retrieved_at' => $retrievedAt,
        ];
    }

    private function deactivate(CatalogueProduct $product): int
    {
        if ($product->status()->isDiscoverable()) {
            $this->catalogue->setProductStatus($product, ProductStatus::Inactive);

            return 1;
        }

        return 0;
    }

    /**
     * Products the provider no longer lists.
     *
     * Only ever called for a feed that claims completeness. Nothing is
     * deleted: an application made against a withdrawn product still has to
     * describe what it was made against.
     *
     * @param  list<string>  $seen
     */
    private function withdrawMissing(Provider $provider, array $seen): int
    {
        $missing = CatalogueProduct::where('provider_id', $provider->id)
            ->whereNotIn('provider_product_code', $seen)
            ->where('status', ProductStatus::Active->value)
            ->get();

        foreach ($missing as $product) {
            $this->catalogue->setProductStatus($product, ProductStatus::Inactive);
        }

        return $missing->count();
    }

    /**
     * A FynnEdge product code from the provider's own identifier.
     *
     * Deterministic, so the same provider reference always resolves to the
     * same code, and in FynnEdge's format rather than theirs — `KF-PL-001`
     * becomes `kf_pl_001`. Their string is kept verbatim alongside it; this
     * is only so their naming conventions do not end up in our public URLs
     * and our application records.
     */
    public static function productCode(string $providerReference): string
    {
        $code = strtolower((string) preg_replace(
            '/[^A-Za-z0-9]+/', '_', $providerReference,
        ));
        $code = trim($code, '_');

        if ($code === '' || ! ctype_alpha($code[0])) {
            $code = 'p_'.$code;
        }

        return substr($code, 0, 40);
    }

    /** @param array<string, mixed> $context */
    private function logOutcome(string $provider, string $outcome, array $context): void
    {
        // Counts and outcomes. Never a payload, never a credential, never
        // anything a provider chose to put in a response body.
        Log::info('Provider ingestion', [
            'provider' => $provider,
            'outcome' => $outcome,
            ...$context,
        ]);
    }
}
