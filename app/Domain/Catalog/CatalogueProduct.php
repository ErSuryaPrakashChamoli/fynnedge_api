<?php

namespace App\Domain\Catalog;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A product as the catalogue holds it: an identity, an owner, and its terms
 * over time.
 *
 * This is the administrative shape. What FynnMatch and the decision screens
 * consume is a LoanProduct — one product's terms resolved to a single day —
 * so nothing downstream has to know that versions exist.
 *
 * The identity is provider code plus product code. Neither changes when a
 * rate does, which is what lets an application made last year keep pointing
 * at the product it was made against.
 */
final class CatalogueProduct
{
    /** @param list<ProductVersion> $versions */
    public function __construct(
        public readonly Provider $provider,

        /** Stable within the provider. Never a display name. */
        public readonly string $code,

        public readonly string $name,
        public readonly string $category,
        public readonly ProductStatus $status,
        public readonly array $versions,

        /**
         * The catalogue-wide identity, if it is not the composed one.
         *
         * New products — and anything a real provider brings — should leave
         * this null and take `provider_code:product_code`. It exists because
         * the six sample products were identified as `prod_pl_a` and so on
         * long before providers were modelled, and those strings are written
         * into every application ever made against them. Renaming identity
         * to tidy it up is exactly the kind of change this module exists to
         * make unnecessary.
         */
        private readonly ?string $legacyId = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
            throw new InvalidArgumentException(
                "Product code '{$code}' must be lowercase alphanumeric with "
                .'underscores, 2–40 characters.',
            );
        }

        if ($versions === []) {
            throw new InvalidArgumentException(
                "Product '{$this->id()}' has no versions.",
            );
        }

        // A sample provider cannot publish live terms. Allowing it would
        // make an invented organisation appear to have real pricing.
        if ($provider->isSample) {
            foreach ($versions as $version) {
                if ($version->source->isLive()) {
                    throw new InvalidArgumentException(
                        "Product '{$this->id()}' claims live pricing from a "
                        .'sample provider.',
                    );
                }
            }
        }

        $this->assertVersionsDoNotOverlap();
    }

    /**
     * The catalogue-wide identity: `provider_code:product_code`.
     *
     * Readable, stable, and unique without a central registry — two
     * providers may both have a product called `term_loan` without
     * colliding.
     */
    public function id(): string
    {
        return $this->legacyId ?? $this->provider->code.':'.$this->code;
    }

    /**
     * This product's terms on a given day, as FynnMatch consumes them.
     *
     * Returns null when the product was not offered then — a product before
     * its first version begins, or after its last one ends. Throws when two
     * versions claim the same day, because that is a catalogue fault rather
     * than an answer.
     */
    public function resolve(?CarbonInterface $at = null): ?LoanProduct
    {
        $version = $this->versionOn($at);

        return $version === null ? null : $this->toLoanProduct($version);
    }

    public function versionOn(?CarbonInterface $at = null): ?ProductVersion
    {
        $at ??= Carbon::now();

        $covering = array_values(array_filter(
            $this->versions,
            fn (ProductVersion $v) => $v->coversDate($at),
        ));

        if (count($covering) > 1) {
            throw new AmbiguousCatalogueException(
                $this->id(),
                sprintf(
                    '%d versions are in force on %s',
                    count($covering),
                    $at->toDateString(),
                ),
            );
        }

        return $covering[0] ?? null;
    }

    /** Whether a customer may be offered this product today. */
    public function isDiscoverable(?CarbonInterface $at = null): bool
    {
        return $this->status->isDiscoverable() && $this->versionOn($at) !== null;
    }

    private function toLoanProduct(ProductVersion $v): LoanProduct
    {
        return new LoanProduct(
            id: $this->id(),
            lender: $this->provider->name,
            name: $this->name,
            lenderTag: $v->lenderTag,
            category: $this->category,
            minAmount: $v->minAmount,
            maxAmount: $v->maxAmount,
            interestRate: $v->interestRate,
            minTenureMonths: $v->minTenureMonths,
            maxTenureMonths: $v->maxTenureMonths,
            processingFeePercent: $v->processingFeePercent,
            processingFeeCap: $v->processingFeeCap,
            fynnTrust: $v->fynnTrust,
            prepaymentCharge: $v->prepaymentCharge,
            suitedReasons: $v->suitedReasons,
            considerations: $v->considerations,
            conditions: $v->conditions,
            trustBreakdown: $v->trustBreakdown,
            minMonthlyIncome: $v->minMonthlyIncome,
            maxAgeAtMaturity: $v->maxAgeAtMaturity,
            minAge: $v->minAge,
            minCreditScore: $v->minCreditScore,
            provider: $this->provider,
            source: $v->source,

            // Identity carried through so an application can record exactly
            // which version produced its terms.
            versionId: $v->persistedId,
            versionEffectiveFrom: $v->effectiveFrom->toDateString(),
        );
    }

    private function assertVersionsDoNotOverlap(): void
    {
        $count = count($this->versions);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->versions[$i]->overlaps($this->versions[$j])) {
                    throw new AmbiguousCatalogueException(
                        $this->id(),
                        sprintf(
                            'versions from %s and %s are in force at the '
                            .'same time',
                            $this->versions[$i]->effectiveFrom->toDateString(),
                            $this->versions[$j]->effectiveFrom->toDateString(),
                        ),
                    );
                }
            }
        }
    }
}
