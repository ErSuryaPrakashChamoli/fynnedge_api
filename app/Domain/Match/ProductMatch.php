<?php

namespace App\Domain\Match;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Financial\LoanProjection;
use App\Domain\Money;

/**
 * One product evaluated against one customer's request and finances.
 */
final class ProductMatch
{
    /**
     * @param  list<MatchCriterion>  $criteria
     */
    public function __construct(
        public readonly LoanProduct $product,
        public readonly MatchCategory $category,
        public readonly array $criteria,
        /** The customer's position now and with this loan on top. */
        public readonly LoanProjection $projection,
        /** The amount and tenure actually priced, which may be clamped. */
        public readonly Money $pricedAmount,
        public readonly int $pricedTenureMonths,
        public readonly Money $emi,
        public readonly Money $totalInterest,
        public readonly Money $totalPayable,
        public readonly Money $processingFee,
        public readonly bool $tenureWasAdjusted,
    ) {}

    /**
     * EMI ratio if this product were taken, existing EMIs included. Null when
     * there is no income to measure it against — never zero, which would read
     * as "no burden at all".
     */
    public function projectedEmiRatio(): ?float
    {
        return $this->projection->isMeasurable
            ? (float) $this->projection->projectedEmiRatio->toJsonNumber()
            : null;
    }

    /** @return list<MatchCriterion> */
    public function withStatus(CriterionStatus $status): array
    {
        return array_values(array_filter(
            $this->criteria,
            fn (MatchCriterion $c) => $c->status === $status,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product' => $this->product->toArray(),

            'match' => [
                'category' => $this->category->value,
                'label' => $this->category->label(),
                'is_viable' => $this->category->isViable(),
                'criteria' => array_map(
                    fn (MatchCriterion $c) => $c->toArray(),
                    $this->criteria,
                ),
                'fits' => array_map(
                    fn (MatchCriterion $c) => $c->detail,
                    $this->withStatus(CriterionStatus::Passed),
                ),
                'check' => array_map(
                    fn (MatchCriterion $c) => $c->detail,
                    $this->withStatus(CriterionStatus::Caution),
                ),
                'blockers' => array_map(
                    fn (MatchCriterion $c) => $c->detail,
                    $this->withStatus(CriterionStatus::Failed),
                ),
                'not_assessed' => array_map(
                    fn (MatchCriterion $c) => $c->label,
                    $this->withStatus(CriterionStatus::Unavailable),
                ),
            ],

            'pricing' => [
                'amount' => $this->pricedAmount->toJsonNumber(),
                'tenure_months' => $this->pricedTenureMonths,
                'tenure_was_adjusted' => $this->tenureWasAdjusted,
                'interest_rate' => $this->product->interestRate,
                'emi' => $this->emi->toJsonNumber(),
                'total_interest' => $this->totalInterest->toJsonNumber(),
                'total_payable' => $this->totalPayable->toJsonNumber(),
                'processing_fee' => $this->processingFee->toJsonNumber(),
                'projected_emi_ratio' => $this->projectedEmiRatio(),
            ],

            // Current vs projected position, from the Financial Engine.
            'projection' => $this->projection->toArray(),
        ];
    }
}
