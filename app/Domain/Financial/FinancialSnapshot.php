<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * The complete derived picture of one customer's finances at one moment.
 *
 * Every figure here was produced by FinancialEngine. Nothing downstream —
 * resource, controller, scoring service or future AI tool — recalculates any
 * of it.
 */
final class FinancialSnapshot
{
    /**
     * @param  list<GoalMetrics>  $goals
     */
    public function __construct(
        public readonly Money $income,
        public readonly Money $expenses,
        public readonly Money $existingEmi,
        public readonly Money $otherObligations,
        public readonly Money $savings,
        public readonly Money $totalOutgo,
        public readonly Money $surplus,
        public readonly Ratio $emiRatio,
        public readonly Ratio $expenseRatio,
        public readonly Ratio $outgoRatio,
        public readonly Ratio $emergencyMonths,
        public readonly array $goals,
        public readonly Money $goalsTarget,
        public readonly Money $goalsSaved,
        public readonly Money $goalsRemaining,
        public readonly Money $goalsMonthlyRequired,
        public readonly bool $hasIncome,
    ) {}

    /**
     * The profile-level figures, in the shape the app already parses as the
     * `derived` block.
     *
     * @return array<string, mixed>
     */
    public function toDerivedArray(): array
    {
        return [
            'surplus' => $this->surplus->toJsonNumber(),
            'emi_ratio' => $this->emiRatio->toJsonNumber(),
            'expense_ratio' => $this->expenseRatio->toJsonNumber(),
            'monthly_outgo' => $this->totalOutgo->toJsonNumber(),
            'emergency_months' => $this->emergencyMonths->toJsonNumber(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'inputs' => [
                'monthly_income' => $this->income->toJsonNumber(),
                'monthly_expenses' => $this->expenses->toJsonNumber(),
                'existing_emi' => $this->existingEmi->toJsonNumber(),
                'other_obligations' => $this->otherObligations->toJsonNumber(),
                'savings' => $this->savings->toJsonNumber(),
            ],
            'derived' => array_merge($this->toDerivedArray(), [
                'outgo_ratio' => $this->outgoRatio->toJsonNumber(),
                'has_income' => $this->hasIncome,
            ]),
            'goals' => [
                'items' => array_map(fn (GoalMetrics $g) => $g->toArray(), $this->goals),
                'count' => count($this->goals),
                'total_target' => $this->goalsTarget->toJsonNumber(),
                'total_saved' => $this->goalsSaved->toJsonNumber(),
                'total_remaining' => $this->goalsRemaining->toJsonNumber(),
                'total_monthly_required' => $this->goalsMonthlyRequired->toJsonNumber(),
            ],
            'definitions' => FinancialEngine::definitions(),
        ];
    }
}
