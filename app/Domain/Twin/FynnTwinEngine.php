<?php

namespace App\Domain\Twin;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\FinancialSnapshot;
use App\Domain\Money;
use App\Domain\Score\FynnScoreEngine;
use App\Services\FinanceService;

/**
 * FynnTwin — what a decision would do to the customer's month.
 *
 * Pure: a snapshot and a scenario in, a projection out. No Eloquent, no
 * request, no clock, no container.
 *
 * It owns no financial definitions. The projected state is produced by
 * calling FinancialEngine::calculate with adjusted inputs, so outgo,
 * surplus, every ratio and the emergency buffer are the same definitions
 * the rest of the product uses — the same numbers a customer sees on Home
 * and on FynnScore, moved by exactly the assumption they entered.
 *
 * It does not predict. Nothing here models inflation, a raise, a market or
 * an event; the only things that change are the ones the customer typed.
 */
class FynnTwinEngine
{
    public function __construct(
        private readonly FinancialEngine $financial,
        private readonly FinanceService $finance,
        private readonly FynnScoreEngine $scoring,
    ) {}

    public function simulate(
        FinancialSnapshot $current,
        TwinScenario $scenario,
        ?LoanProduct $product = null,
    ): TwinProjection {
        [$projected, $loan] = match ($scenario->type) {
            ScenarioType::Loan => $this->loan($current, $scenario, $product),
            ScenarioType::ExpenseChange => [$this->expenses($current, $scenario), null],
            ScenarioType::SavingChange => [$this->saving($current, $scenario), null],
        };

        return new TwinProjection(
            scenario: $scenario,
            current: $current,
            projected: $projected,
            observations: $this->observe($current, $projected, $scenario),
            loan: $loan,

            // The existing score engine, over the projected snapshot. It is
            // not re-implemented here and its model is untouched.
            currentScore: $current->hasIncome ? $this->scoring->score($current) : null,
            projectedScore: $current->hasIncome ? $this->scoring->score($projected) : null,
        );
    }

    /**
     * A loan adds its EMI to what already goes out each month.
     *
     * The EMI comes from FinanceService at the product's own published rate.
     * Nothing here prices anything, and no rate is invented.
     *
     * @return array{0: FinancialSnapshot, 1: array<string, mixed>}
     */
    private function loan(
        FinancialSnapshot $current,
        TwinScenario $scenario,
        ?LoanProduct $product,
    ): array {
        $amount = $scenario->amount ?? Money::zero();
        $tenure = $scenario->tenureMonths ?? 0;
        $rate = $product?->interestRate ?? 0.0;

        $pricing = $this->finance->summary(
            (float) $amount->toDecimalString(),
            $rate,
            $tenure,
        );

        $emi = Money::of($pricing['emi']);
        $fee = $product?->processingFeeFor($amount) ?? Money::zero();

        $projected = $this->withInputs(
            $current,
            existingEmi: $current->existingEmi->plus($emi),
        );

        return [
            $projected,
            [
                'product_id' => $product?->id,
                'lender' => $product?->lender,
                'product_name' => $product?->name,
                'amount' => $amount->toJsonNumber(),
                'tenure_months' => $tenure,
                'interest_rate' => $rate,
                'emi' => $emi->toJsonNumber(),
                'total_interest' => Money::of($pricing['total_interest'])->toJsonNumber(),
                'total_payable' => Money::of($pricing['total_payable'])->toJsonNumber(),
                'processing_fee' => $fee->toJsonNumber(),
            ],
        ];
    }

    /** Spending more, or less, changes what goes out. */
    private function expenses(
        FinancialSnapshot $current,
        TwinScenario $scenario,
    ): FinancialSnapshot {
        $change = $scenario->monthlyChange ?? Money::zero();

        return $this->withInputs(
            $current,
            expenses: $current->expenses->plus($change)->clampToZero(),
        );
    }

    /**
     * Money set aside each month, for a stated number of months.
     *
     * The assumption is the customer's and is stated back to them: that the
     * amount is actually set aside every month and nothing is taken out
     * again. FynnTwin does not assume a saving habit nobody described.
     */
    private function saving(
        FinancialSnapshot $current,
        TwinScenario $scenario,
    ): FinancialSnapshot {
        $monthly = $scenario->monthlyAmount ?? Money::zero();
        $months = max(0, $scenario->months ?? 0);

        return $this->withInputs(
            $current,
            savings: $current->savings->plus($monthly->times($months)),
        );
    }

    /**
     * The same customer with one input changed, recalculated by the engine.
     *
     * Every derived figure on the result is therefore the engine's, not a
     * copy of its arithmetic.
     */
    private function withInputs(
        FinancialSnapshot $current,
        ?Money $expenses = null,
        ?Money $existingEmi = null,
        ?Money $savings = null,
    ): FinancialSnapshot {
        return $this->financial->calculate(
            income: $current->income,
            expenses: $expenses ?? $current->expenses,
            existingEmi: $existingEmi ?? $current->existingEmi,
            otherObligations: $current->otherObligations,
            savings: $savings ?? $current->savings,
        );
    }

    /**
     * What is worth saying about the result.
     *
     * Each observation is triggered by a figure the engine produced and
     * quotes it. Where nothing meaningful changed, nothing is said: a
     * manufactured warning teaches customers to ignore real ones.
     *
     * @return list<TwinObservation>
     */
    private function observe(
        FinancialSnapshot $current,
        FinancialSnapshot $projected,
        TwinScenario $scenario,
    ): array {
        if (! $current->hasIncome) {
            return [new TwinObservation(
                'no_income',
                TwinObservation::SEVERITY_INFO,
                'Add your monthly income and FynnTwin can show what this '
                    .'would mean for your month.',
            )];
        }

        $observations = [];

        // The one that matters most: nothing left over.
        if ($projected->surplus->isNegative()) {
            $observations[] = new TwinObservation(
                'negative_surplus',
                TwinObservation::SEVERITY_SERIOUS,
                'Under this scenario you would be spending '
                    .$projected->surplus->toIndianString().' more than you '
                    .'earn each month — your outgo would exceed your income.',
            );
        } elseif ($projected->surplus->isZero()) {
            $observations[] = new TwinObservation(
                'no_surplus',
                TwinObservation::SEVERITY_SERIOUS,
                'Under this scenario you would have nothing left over at the '
                    .'end of the month.',
            );
        }

        $projectedRatio = (float) $projected->emiRatio->toJsonNumber();

        if ($projectedRatio > FinancialEngine::AFFORDABILITY_CEILING_PERCENT) {
            $observations[] = new TwinObservation(
                'above_affordability_reference',
                TwinObservation::SEVERITY_CAUTION,
                'Your EMIs would take '.$this->percent($projectedRatio)
                    .'% of your income, past the '
                    .$this->percent(FinancialEngine::AFFORDABILITY_CEILING_PERCENT)
                    .'% FynnEdge uses as its affordability reference. That is '
                    .'FynnEdge\'s reference, not a lender rule.',
            );
        }

        // Emergency cover is about what today's savings would stretch to
        // under the projected outgo — not a claim about future savings.
        $currentMonths = (float) $current->emergencyMonths->toJsonNumber();
        $projectedMonths = (float) $projected->emergencyMonths->toJsonNumber();

        if ($projectedMonths < $currentMonths) {
            $observations[] = new TwinObservation(
                'emergency_cover_falls',
                TwinObservation::SEVERITY_CAUTION,
                'Your savings would cover about '
                    .$this->percent($projectedMonths).' months of the '
                    .'projected outgo, down from '
                    .$this->percent($currentMonths).' months today.',
            );
        } elseif ($projectedMonths > $currentMonths) {
            $observations[] = new TwinObservation(
                'emergency_cover_rises',
                TwinObservation::SEVERITY_INFO,
                'Your savings would cover about '
                    .$this->percent($projectedMonths).' months of outgo, up '
                    .'from '.$this->percent($currentMonths).' months today.',
            );
        }

        if ($scenario->type === ScenarioType::SavingChange) {
            $observations[] = new TwinObservation(
                'saving_assumption',
                TwinObservation::SEVERITY_INFO,
                'This assumes the amount is set aside every month and nothing '
                    .'is taken back out.',
            );
        }

        if ($observations === []) {
            $observations[] = new TwinObservation(
                'little_change',
                TwinObservation::SEVERITY_INFO,
                'This scenario would not move your monthly position much.',
            );
        }

        return $observations;
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
