<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * The customer's position now, and the same position with one more EMI on it.
 *
 * FynnEdge shows these side by side on Loan Detail and FynnMirror, so both
 * halves must come from one calculation. Before this existed each screen
 * subtracted the EMI from the surplus itself and divided its own ratio, which
 * is how two screens end up disagreeing by a rupee about the same loan.
 *
 * This is arithmetic on figures the customer gave us. It is not a lender
 * decision, not an approval, and not a promise about the future — every
 * surface that renders it says so.
 */
final class LoanProjection
{
    public function __construct(
        /** The new EMI being modelled. */
        public readonly Money $emi,

        public readonly Money $currentOutgo,
        public readonly Money $projectedOutgo,
        public readonly Money $currentSurplus,
        public readonly Money $projectedSurplus,
        public readonly Ratio $currentEmiRatio,
        public readonly Ratio $projectedEmiRatio,

        /** Total EMI the ceiling allows for this income. */
        public readonly Money $emiCeiling,

        /** What is left under the ceiling before this loan. */
        public readonly Money $headroomBefore,

        /** What would be left under the ceiling after it. */
        public readonly Money $headroomAfter,

        /** False once the projected ratio passes the ceiling. */
        public readonly bool $withinCeiling,

        /** False when there is no income to measure any of this against. */
        public readonly bool $isMeasurable,
    ) {}

    /** True when this loan would leave nothing over each month. */
    public function exhaustsSurplus(): bool
    {
        return $this->isMeasurable && ! $this->projectedSurplus->isPositive();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'emi' => $this->emi->toJsonNumber(),

            'current' => [
                'monthly_outgo' => $this->currentOutgo->toJsonNumber(),
                'surplus' => $this->currentSurplus->toJsonNumber(),
                'emi_ratio' => $this->currentEmiRatio->toJsonNumber(),
            ],
            'projected' => [
                'monthly_outgo' => $this->projectedOutgo->toJsonNumber(),
                'surplus' => $this->projectedSurplus->toJsonNumber(),
                'emi_ratio' => $this->projectedEmiRatio->toJsonNumber(),
            ],

            'ceiling_percent' => FinancialEngine::AFFORDABILITY_CEILING_PERCENT,
            'emi_ceiling' => $this->emiCeiling->toJsonNumber(),
            'headroom_before' => $this->headroomBefore->toJsonNumber(),
            'headroom_after' => $this->headroomAfter->toJsonNumber(),
            'within_ceiling' => $this->withinCeiling,
            'exhausts_surplus' => $this->exhaustsSurplus(),
            'is_measurable' => $this->isMeasurable,

            // Said in the payload, not only in the UI, so no consumer can
            // present these as settled figures by accident.
            'is_estimate' => true,
        ];
    }
}
