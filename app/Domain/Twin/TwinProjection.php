<?php

namespace App\Domain\Twin;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\FinancialSnapshot;
use App\Domain\Money;
use App\Domain\Score\FynnScore;

/**
 * Where the customer is, and where this scenario would put them.
 *
 * Both sides are real FinancialSnapshots produced by FinancialEngine, so
 * every metric on both — outgo, surplus, the ratios, emergency months — is
 * the engine's own definition rather than something recomputed here. The
 * differences are subtraction of those figures and nothing more.
 *
 * It is a projection of one decision, not a forecast: nothing here assumes
 * a raise, an inflation rate, a market, or an event the customer did not
 * type in.
 */
final class TwinProjection
{
    /**
     * @param  list<TwinObservation>  $observations
     */
    public function __construct(
        public readonly TwinScenario $scenario,
        public readonly FinancialSnapshot $current,
        public readonly FinancialSnapshot $projected,
        public readonly array $observations,
        /** Loan scenarios only: the priced offer being modelled. */
        public readonly ?array $loan = null,
        public readonly ?FynnScore $currentScore = null,
        public readonly ?FynnScore $projectedScore = null,
    ) {}

    /** False when there is no income to measure anything against. */
    public function isMeasurable(): bool
    {
        return $this->current->hasIncome;
    }

    public function projectedEmiRatio(): float
    {
        return (float) $this->projected->emiRatio->toJsonNumber();
    }

    /** Whether the projection sits inside FynnEdge's own reference. */
    public function withinAffordabilityReference(): bool
    {
        return $this->isMeasurable()
            && $this->projectedEmiRatio() <= FinancialEngine::AFFORDABILITY_CEILING_PERCENT;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scenario' => $this->scenario->toArray(),

            'current' => $this->stateArray($this->current),
            'projected' => $this->stateArray($this->projected),
            'difference' => $this->differenceArray(),

            'affordability' => [
                'reference_percent' => FinancialEngine::AFFORDABILITY_CEILING_PERCENT,
                'projected_emi_ratio' => $this->isMeasurable()
                    ? $this->projectedEmiRatio()
                    : null,
                'within_reference' => $this->withinAffordabilityReference(),
            ],

            'loan' => $this->loan,

            // Scored by the existing engine from the projected snapshot. A
            // simulation of the score, labelled as one — not a promise about
            // a future score.
            'fynn_score' => [
                'current' => $this->currentScore?->score,
                'projected' => $this->projectedScore?->score,
                'is_simulated' => true,
            ],

            'observations' => array_map(
                fn (TwinObservation $o) => $o->toArray(),
                $this->observations,
            ),

            'is_measurable' => $this->isMeasurable(),

            // Said in the payload, not only on a screen.
            'is_projection' => true,
            'disclaimer' => 'This is a projection based on the assumptions '
                .'you entered and the figures on your profile. It is not a '
                .'prediction, and not a guarantee.',
        ];
    }

    /** @return array<string, mixed> */
    private function stateArray(FinancialSnapshot $state): array
    {
        return [
            'monthly_income' => $state->income->toJsonNumber(),
            'monthly_expenses' => $state->expenses->toJsonNumber(),
            'existing_emi' => $state->existingEmi->toJsonNumber(),
            'other_obligations' => $state->otherObligations->toJsonNumber(),
            'savings' => $state->savings->toJsonNumber(),

            'monthly_outgo' => $state->totalOutgo->toJsonNumber(),
            // Kept as it comes: a negative surplus is a fact, and clamping
            // it would hide the one number the customer most needs.
            'surplus' => $state->surplus->toJsonNumber(),
            'emi_ratio' => $state->emiRatio->toJsonNumber(),
            'expense_ratio' => $state->expenseRatio->toJsonNumber(),
            'emergency_months' => $state->emergencyMonths->toJsonNumber(),
        ];
    }

    /** @return array<string, mixed> */
    private function differenceArray(): array
    {
        return [
            'monthly_outgo' => $this->money(
                $this->projected->totalOutgo,
                $this->current->totalOutgo,
            ),
            'surplus' => $this->money(
                $this->projected->surplus,
                $this->current->surplus,
            ),
            'savings' => $this->money(
                $this->projected->savings,
                $this->current->savings,
            ),
            'emi_ratio' => $this->ratio(
                (float) $this->projected->emiRatio->toJsonNumber(),
                (float) $this->current->emiRatio->toJsonNumber(),
            ),
            'emergency_months' => $this->ratio(
                (float) $this->projected->emergencyMonths->toJsonNumber(),
                (float) $this->current->emergencyMonths->toJsonNumber(),
            ),
        ];
    }

    private function money(Money $projected, Money $current): int|float
    {
        return Money::fromPaise($projected->paise - $current->paise)
            ->toJsonNumber();
    }

    private function ratio(float $projected, float $current): int|float
    {
        $difference = round($projected - $current, 2);

        return $difference == (int) $difference ? (int) $difference : $difference;
    }
}
