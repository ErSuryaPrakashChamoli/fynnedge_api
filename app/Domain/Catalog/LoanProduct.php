<?php

namespace App\Domain\Catalog;

use App\Domain\Money;

/**
 * One lender product, as FynnEdge holds it.
 *
 * Two fields — minMonthlyIncome and maxAgeAtMaturity — were previously only
 * free text inside `conditions` ("Minimum monthly income ₹40,000", "Age 23–58
 * at loan maturity"). They are structured here so FynnMatch can evaluate them
 * instead of guessing. The values are exactly what the product already
 * documented; nothing was invented. Where a product documents no such
 * requirement the field is null, and the matching criterion reports itself
 * unavailable rather than silently passing.
 */
final class LoanProduct
{
    /**
     * @param  list<string>  $suitedReasons
     * @param  list<string>  $considerations
     * @param  list<string>  $conditions
     * @param  array<string, array{label: string, value: int, explanation: string}>  $trustBreakdown
     */
    public function __construct(
        public readonly string $id,
        public readonly string $lender,
        public readonly string $name,
        public readonly string $lenderTag,
        public readonly string $category,
        public readonly Money $minAmount,
        public readonly Money $maxAmount,
        public readonly float $interestRate,
        public readonly int $minTenureMonths,
        public readonly int $maxTenureMonths,
        public readonly float $processingFeePercent,
        public readonly ?Money $processingFeeCap,
        public readonly int $fynnTrust,
        public readonly string $prepaymentCharge,
        public readonly array $suitedReasons,
        public readonly array $considerations,
        public readonly array $conditions,
        public readonly array $trustBreakdown,
        /** Documented minimum monthly income, or null when unstated. */
        public readonly ?Money $minMonthlyIncome = null,
        /** Documented maximum age at loan maturity, or null when unstated. */
        public readonly ?int $maxAgeAtMaturity = null,
        /** Documented minimum age, or null when unstated. */
        public readonly ?int $minAge = null,

        /**
         * Documented minimum credit score, or null when the product states
         * none. Null is never read as "no minimum" — a product that says
         * nothing about credit simply has no such rule to check.
         */
        public readonly ?int $minCreditScore = null,

        /**
         * Who offers it, and where the record came from.
         *
         * Both default to the sample catalogue, because that is what
         * FynnEdge has: a product that does not say otherwise is invented,
         * and the safe direction is to assume so rather than to assume a
         * real provider stands behind it.
         */
        public readonly ?Provider $provider = null,
        public readonly ?ProductSource $source = null,

        /**
         * Which published version produced these terms.
         *
         * Identity only, so an application can record what it was created
         * from. Deliberately absent from toArray(): it is not a fact about
         * the product a customer needs, and putting it in the payload would
         * invite something downstream to resolve it back to a live price.
         *
         * Null for the sample catalogue, which has no persisted versions.
         */
        public readonly ?int $versionId = null,
        public readonly ?string $versionEffectiveFrom = null,
    ) {
        $this->assertCoherent();
    }

    /**
     * A product FynnEdge is willing to show a customer.
     *
     * Every rule here is internal coherence, not policy: a range that runs
     * backwards, a negative rate, a trust value outside its own scale. None
     * of it says what a good product looks like — only that this record can
     * be reasoned about at all.
     *
     * It throws rather than correcting, because there is no safe correction.
     * A product with min > max would otherwise be quietly shown as matching
     * nothing, or as matching everything, and the customer would be told a
     * conclusion drawn from a record nobody could stand behind. Refusing is
     * how a real catalogue feed's bad row stops at the boundary instead of
     * reaching a decision screen.
     */
    private function assertCoherent(): void
    {
        $problems = [];

        if (trim($this->id) === '') {
            $problems[] = 'id is empty';
        }

        if (trim($this->lender) === '' || trim($this->name) === '') {
            $problems[] = 'lender and name are both required';
        }

        if ($this->minAmount->isNegative() || $this->maxAmount->isNegative()) {
            $problems[] = 'amounts cannot be negative';
        }

        if ($this->minAmount->compareTo($this->maxAmount) > 0) {
            $problems[] = 'min_amount is above max_amount';
        }

        if ($this->minTenureMonths < 1) {
            $problems[] = 'min_tenure_months must be at least one month';
        }

        if ($this->minTenureMonths > $this->maxTenureMonths) {
            $problems[] = 'min_tenure_months is above max_tenure_months';
        }

        // Zero is allowed — an interest-free product is a real thing, and
        // FinanceService already divides the principal evenly for it.
        if ($this->interestRate < 0) {
            $problems[] = 'interest_rate cannot be negative';
        }

        if ($this->processingFeePercent < 0) {
            $problems[] = 'processing_fee_percent cannot be negative';
        }

        if ($this->processingFeeCap !== null
            && $this->processingFeeCap->isNegative()) {
            $problems[] = 'processing_fee_cap cannot be negative';
        }

        if ($this->fynnTrust < 0 || $this->fynnTrust > 100) {
            $problems[] = 'fynn_trust is outside 0–100';
        }

        foreach ($this->trustBreakdown as $key => $factor) {
            $value = $factor['value'] ?? null;
            if (! is_int($value) || $value < 0 || $value > 100) {
                $problems[] = "trust factor '{$key}' is outside 0–100";
            }
        }

        if ($this->minMonthlyIncome !== null
            && $this->minMonthlyIncome->isNegative()) {
            $problems[] = 'min_monthly_income cannot be negative';
        }

        if ($this->minCreditScore !== null
            && ($this->minCreditScore < 1 || $this->minCreditScore > 1000)) {
            $problems[] = 'min_credit_score is outside a plausible scale';
        }

        if ($this->minAge !== null && ($this->minAge < 18 || $this->minAge > 100)) {
            $problems[] = 'min_age is outside 18–100';
        }

        if ($this->maxAgeAtMaturity !== null
            && ($this->maxAgeAtMaturity < 18 || $this->maxAgeAtMaturity > 100)) {
            $problems[] = 'max_age_at_maturity is outside 18–100';
        }

        if ($this->minAge !== null
            && $this->maxAgeAtMaturity !== null
            && $this->minAge > $this->maxAgeAtMaturity) {
            $problems[] = 'min_age is above max_age_at_maturity';
        }

        if ($problems !== []) {
            throw new MalformedProductException($this->id, $problems);
        }
    }

    public function supportsAmount(Money $amount): bool
    {
        return $amount->compareTo($this->minAmount) >= 0
            && $amount->compareTo($this->maxAmount) <= 0;
    }

    public function supportsTenure(int $months): bool
    {
        return $months >= $this->minTenureMonths
            && $months <= $this->maxTenureMonths;
    }

    /** The tenure this product can actually offer, closest to what was asked. */
    public function clampTenure(int $months): int
    {
        return max($this->minTenureMonths, min($this->maxTenureMonths, $months));
    }

    public function processingFeeFor(Money $amount): Money
    {
        $fee = Money::fromPaise(Money::divideHalfUp(
            (int) round($amount->paise * $this->processingFeePercent),
            100,
        ));

        return $this->processingFeeCap !== null
            && $fee->compareTo($this->processingFeeCap) > 0
                ? $this->processingFeeCap
                : $fee;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lender' => $this->lender,
            'name' => $this->name,
            'lender_tag' => $this->lenderTag,
            'category' => $this->category,
            'min_amount' => $this->minAmount->toJsonNumber(),
            'max_amount' => $this->maxAmount->toJsonNumber(),
            'interest_rate' => $this->interestRate,
            'min_tenure_months' => $this->minTenureMonths,
            'max_tenure_months' => $this->maxTenureMonths,
            'processing_fee_percent' => $this->processingFeePercent,
            'processing_fee_cap' => $this->processingFeeCap?->toJsonNumber(),
            'fynn_trust' => $this->fynnTrust,
            'prepayment_charge' => $this->prepaymentCharge,
            'suited_reasons' => $this->suitedReasons,
            'considerations' => $this->considerations,
            'conditions' => $this->conditions,
            'trust_breakdown' => $this->trustBreakdown,

            // Null means the product documents no such requirement.
            'min_monthly_income' => $this->minMonthlyIncome?->toJsonNumber(),
            'min_age' => $this->minAge,
            'max_age_at_maturity' => $this->maxAgeAtMaturity,
            'min_credit_score' => $this->minCreditScore,

            // From the record, not from a literal. This used to be a
            // hardcoded `true`, which meant a live product would have gone
            // on calling itself sample — and that flipping the literal
            // would have made every sample product claim to be live.
            ...$this->source()->toCustomerArray(),

            'provider_code' => $this->provider()->code,
        ];
    }

    /** The source of this record, defaulting to internally written sample. */
    public function source(): ProductSource
    {
        return $this->source ?? ProductSource::sample();
    }

    /**
     * Who offers it.
     *
     * A product built without one — the fixtures and the older repository
     * shape — is attributed to a sample provider derived from its own
     * lender name, so nothing downstream has to handle a null.
     */
    public function provider(): Provider
    {
        return $this->provider ?? new Provider(
            code: self::codeFor($this->lender),
            name: $this->lender,
            type: ProviderType::Nbfc,
            isSample: true,
        );
    }

    /** A stable code from a display name, for records that predate providers. */
    public static function codeFor(string $name): string
    {
        $code = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $code = trim($code, '_');

        return $code === '' || ! ctype_alpha($code[0])
            ? 'p_'.substr(md5($name), 0, 8)
            : substr($code, 0, 40);
    }
}
