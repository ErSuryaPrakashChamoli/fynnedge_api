<?php

namespace App\Services\Decisions;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionType;
use App\Domain\Twin\TwinProjection;
use App\Models\User;

/**
 * What the rest of FynnEdge calls when a customer explores something.
 *
 * Two methods, because there are two things a customer does that reach the
 * server as an action rather than as a page view. Both are called from the
 * endpoint the customer's own tap produced; neither is ever called from a
 * read.
 *
 * Nothing here computes. The figures come from the engines that already
 * produced them for the response the customer saw, which is what makes the
 * record evidence of what was shown rather than a second calculation that
 * might disagree with it.
 */
class CustomerDecisions
{
    public function __construct(private readonly DecisionRecorder $recorder) {}

    /**
     * The customer ran a simulation.
     *
     * Recorded as a simulation and marked as one everywhere it is read. A
     * what-if is true of nothing that has happened, and nothing here writes
     * a financial observation, moves a score or touches a profile.
     */
    public function simulated(
        User $user,
        TwinProjection $projection,
        ?LoanProduct $product,
    ): void {
        $loan = $projection->loan;
        $scenario = $projection->scenario;
        $current = $projection->current;
        $projected = $projection->projected;

        $this->recorder->record(
            user: $user,
            type: DecisionType::Simulation,
            snapshot: DecisionSnapshot::of([
                'scenario' => $scenario->type->value,

                // What they entered.
                'amount' => $loan['amount'] ?? $scenario->amount?->toJsonNumber(),
                'tenure_months' => $loan['tenure_months'] ?? $scenario->tenureMonths,
                'monthly_change' => $scenario->monthlyChange?->toJsonNumber(),
                'monthly_amount' => $scenario->monthlyAmount?->toJsonNumber(),
                'months' => $scenario->months,

                // What the catalogue published.
                'interest_rate' => $loan['interest_rate'] ?? null,

                // What FynnEdge worked out, from the response they saw.
                'emi' => $loan['emi'] ?? null,
                'total_interest' => $loan['total_interest'] ?? null,
                'total_payable' => $loan['total_payable'] ?? null,
                'processing_fee' => $loan['processing_fee'] ?? null,

                // Enough of their state to explain the ratio beside it.
                'monthly_income' => $current->income->toJsonNumber(),
                'monthly_outgo' => $current->totalOutgo->toJsonNumber(),
                'existing_emi' => $current->existingEmi->toJsonNumber(),
                'emi_ratio' => $current->emiRatio->toJsonNumber(),
                'projected_emi_ratio' => $projected->emiRatio->toJsonNumber(),
                'emergency_months' => $current->emergencyMonths->toJsonNumber(),
                'fynn_score' => $projection->currentScore?->score,
                'fynn_score_band' => $projection->currentScore?->band?->value,
            ]),
            productCode: $product?->id,
            productVersionId: $product?->versionId,
        );
    }

    /**
     * The customer put products side by side.
     *
     * The codes they chose, and nothing else. There is deliberately no
     * winner, no best and no recommendation: FynnEdge showed them together
     * and the customer read them. Inventing a preference from that would be
     * inventing the decision this module exists not to invent.
     *
     * @param  list<string>  $productCodes
     */
    public function compared(User $user, array $productCodes): void
    {
        if (count($productCodes) < 2) {
            // One product beside nothing is not a comparison.
            return;
        }

        // Ordered, so the same two products chosen in either order are the
        // same comparison.
        sort($productCodes);

        $this->recorder->record(
            user: $user,
            type: DecisionType::Comparison,
            snapshot: DecisionSnapshot::of(['product_codes' => $productCodes]),
        );
    }
}
