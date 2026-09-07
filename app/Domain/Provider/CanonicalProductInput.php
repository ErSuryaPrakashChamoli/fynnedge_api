<?php

namespace App\Domain\Provider;

use App\Domain\Money;
use Carbon\CarbonInterface;

/**
 * One provider product, in FynnEdge's own terms.
 *
 * This is the boundary. Everything upstream may speak the provider's
 * language; nothing downstream does. A normalizer's whole job is to produce
 * one of these, and if it cannot do so unambiguously it must reject the row
 * rather than guess.
 *
 * Money is Money, percentages are percentages, tenure is months. Eligibility
 * holds only the three rules FynnMatch actually evaluates; anything else the
 * provider requires is kept apart in [unsupported], visible but never
 * mistaken for something that was checked.
 */
final class CanonicalProductInput
{
    /**
     * @param  list<string>  $suitedReasons
     * @param  list<string>  $considerations
     * @param  list<string>  $conditions
     * @param  array<string, array{label: string, value: int, explanation: string}>  $trustBreakdown
     * @param  list<UnsupportedRequirement>  $unsupported
     */
    public function __construct(
        // --- Identity -----------------------------------------------------
        /** The provider's own product identifier. Stable across renames. */
        public readonly string $providerProductCode,

        public readonly string $displayName,

        /** A FynnEdge category, already mapped. Never a provider's word. */
        public readonly string $category,

        /** Whether the provider says it no longer offers this. */
        public readonly bool $discontinued,

        // --- Pricing ------------------------------------------------------
        /** Annual percentage on a reducing balance, e.g. 11.5 for 11.5%. */
        public readonly float $interestRate,
        public readonly float $processingFeePercent,
        public readonly ?Money $processingFeeCap,
        public readonly string $prepaymentCharge,

        // --- What it will lend --------------------------------------------
        public readonly Money $minAmount,
        public readonly Money $maxAmount,
        public readonly int $minTenureMonths,
        public readonly int $maxTenureMonths,

        // --- Eligibility FynnMatch can evaluate ---------------------------
        // Null is "not published", never "no requirement".
        public readonly ?Money $minMonthlyIncome,
        public readonly ?int $minAge,
        public readonly ?int $maxAgeAtMaturity,

        // --- Copy. Explains; never decides. --------------------------------
        public readonly array $suitedReasons,
        public readonly array $considerations,
        public readonly array $conditions,

        // --- Requirements FynnEdge cannot check ----------------------------
        public readonly array $unsupported,

        // --- Effective dates, if the provider states them ------------------
        public readonly ?CarbonInterface $effectiveFrom,
        public readonly ?CarbonInterface $effectiveUntil,
    ) {}

    /**
     * A fingerprint of everything that would make this a different version.
     *
     * Compared against the catalogue's current published version to decide
     * whether ingestion has anything to do. Built from the canonical values
     * rather than the provider's payload, because a provider may reorder
     * fields, add metadata or restate the same rate as `11.50` — none of
     * which is a change to the product.
     *
     * The provider's own identifier and the display name are excluded on
     * purpose: renaming a product does not change its terms.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'category' => $this->category,
            'interest_rate' => $this->interestRate,
            'processing_fee_percent' => $this->processingFeePercent,
            'processing_fee_cap' => $this->processingFeeCap?->paise,
            'prepayment_charge' => $this->prepaymentCharge,
            'min_amount' => $this->minAmount->paise,
            'max_amount' => $this->maxAmount->paise,
            'min_tenure_months' => $this->minTenureMonths,
            'max_tenure_months' => $this->maxTenureMonths,
            'min_monthly_income' => $this->minMonthlyIncome?->paise,
            'min_age' => $this->minAge,
            'max_age_at_maturity' => $this->maxAgeAtMaturity,
            'suited_reasons' => $this->suitedReasons,
            'considerations' => $this->considerations,
            'conditions' => $this->conditions,
            'unsupported' => array_map(
                fn (UnsupportedRequirement $r) => $r->toArray(),
                $this->unsupported,
            ),
            'effective_from' => $this->effectiveFrom?->toDateString(),
            'effective_until' => $this->effectiveUntil?->toDateString(),
        ], JSON_THROW_ON_ERROR));
    }
}
