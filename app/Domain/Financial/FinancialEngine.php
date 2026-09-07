<?php

namespace App\Domain\Financial;

use App\Domain\Money;
use App\Models\FinancialGoal;
use App\Models\FinancialProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * THE financial calculation layer for FynnEdge.
 *
 * Every derived money figure the product shows — surplus, ratios, emergency
 * cover, goal progress, what a goal costs per month — is defined here and
 * nowhere else. Controllers, resources, the scoring service and any future
 * intelligence feature consume this; none of them re-implement a formula.
 *
 * Three properties are deliberate:
 *
 *  - Deterministic. Given the same inputs and the same date, the output is
 *    always identical. The clock is injected so tests can pin it.
 *  - Exact. All arithmetic runs on integer paise via Money; no float touches
 *    a monetary value before serialisation.
 *  - Explainable. definitions() states every formula in words, so a future
 *    feature can answer "how did FynnEdge calculate this?" by reading the
 *    same source the number came from.
 *
 * The Flutter engine (lib/data/engine/financial_engine.dart) mirrors this for
 * local estimates while the customer is still editing. A committed fixture
 * keeps the two in step; see tests/fixtures/financial_parity.json.
 */
class FinancialEngine
{
    /** Days treated as one month when converting a deadline to months. */
    public const DAYS_PER_MONTH = 30;

    /** Months of outgoings a healthy emergency fund covers. */
    public const EMERGENCY_FUND_TARGET_MONTHS = 6;

    /**
     * The share of income FynnEdge treats as the affordability ceiling for
     * total EMIs, defined once here.
     *
     * This is FynnEdge's own reference, not a lender's rule and not an
     * eligibility test: no product in the catalogue documents an EMI-ratio
     * requirement. It is also the FynnScore EMI zero-anchor, so a customer
     * cannot be told one number by the score and a different one by a match.
     */
    public const AFFORDABILITY_CEILING_PERCENT = 45.0;

    /**
     * The pure core: plain values in, metrics out.
     *
     * No Eloquent, no container, no clock of its own. This is what makes the
     * engine testable in isolation and portable to any caller.
     *
     * @param  list<array{id: string, title: string, target: Money, saved: Money, target_date: mixed}>  $goals
     */
    public function calculate(
        Money $income,
        Money $expenses,
        Money $existingEmi,
        Money $otherObligations,
        Money $savings,
        array $goals = [],
        ?CarbonInterface $asOf = null,
    ): FinancialSnapshot {
        $asOf = $asOf ?? Carbon::now();

        // totalOutgo = expenses + existingEmi + otherObligations
        $totalOutgo = $expenses->plus($existingEmi)->plus($otherObligations);

        // surplus = income - totalOutgo. Allowed to be negative: a customer
        // spending more than they earn is a fact worth showing, not an error.
        $surplus = $income->minus($totalOutgo);

        $goalMetrics = [];
        $goalsTarget = Money::zero();
        $goalsSaved = Money::zero();
        $goalsRemaining = Money::zero();
        $goalsMonthlyRequired = Money::zero();

        foreach ($goals as $goal) {
            $metrics = $this->metricsFor(
                $goal['id'] ?? '',
                $goal['title'] ?? '',
                $goal['target'],
                $goal['saved'],
                $goal['target_date'] ?? null,
                $asOf,
            );
            $goalMetrics[] = $metrics;

            $goalsTarget = $goalsTarget->plus($metrics->target);
            $goalsSaved = $goalsSaved->plus($metrics->saved);
            $goalsRemaining = $goalsRemaining->plus($metrics->remaining);
            $goalsMonthlyRequired = $goalsMonthlyRequired
                ->plus($metrics->monthlyRequired);
        }

        return new FinancialSnapshot(
            income: $income,
            expenses: $expenses,
            existingEmi: $existingEmi,
            otherObligations: $otherObligations,
            savings: $savings,
            totalOutgo: $totalOutgo,
            surplus: $surplus,
            emiRatio: Ratio::percentOf($existingEmi, $income),
            expenseRatio: Ratio::percentOf($expenses, $income),
            outgoRatio: Ratio::percentOf($totalOutgo, $income),
            emergencyMonths: Ratio::timesOver($savings, $totalOutgo),
            goals: $goalMetrics,
            goalsTarget: $goalsTarget,
            goalsSaved: $goalsSaved,
            goalsRemaining: $goalsRemaining,
            goalsMonthlyRequired: $goalsMonthlyRequired,
            hasIncome: $income->isPositive(),
        );
    }

    /**
     * Derived figures for one goal, from plain values.
     */
    public function metricsFor(
        string $id,
        string $title,
        Money $target,
        Money $saved,
        mixed $targetDate,
        ?CarbonInterface $asOf = null,
    ): GoalMetrics {
        $asOf = $asOf ?? Carbon::now();

        // remaining = max(0, target - saved). Overfunding a goal does not
        // create a negative requirement.
        $remaining = $target->minus($saved)->clampToZero();

        $months = $this->monthsUntil($targetDate, $asOf);

        // monthlyRequired = remaining / monthsRemaining, half up to the
        // paise. With no months left the whole remainder is due now, which is
        // what an overdue goal actually means.
        $monthlyRequired = $remaining->isZero()
            ? Money::zero()
            : ($months > 0 ? $remaining->dividedBy($months) : $remaining);

        return new GoalMetrics(
            id: $id,
            title: $title,
            target: $target,
            saved: $saved,
            remaining: $remaining,
            progressTenThousandths: $this->progress($saved, $target),
            monthsRemaining: $months,
            monthlyRequired: $monthlyRequired,
            isComplete: $remaining->isZero() && $target->isPositive(),
            isOverdue: $months === 0 && ! $remaining->isZero(),
        );
    }

    /**
     * The same customer, carrying one more EMI.
     *
     * Both halves of every before/after pair on Loan Detail and FynnMirror
     * come from here, so the two screens cannot drift apart. Nothing is
     * persisted: this is a projection over a snapshot, and the caller decides
     * whether the customer ever acts on it.
     */
    public function project(FinancialSnapshot $current, Money $emi): LoanProjection
    {
        $projectedOutgo = $current->totalOutgo->plus($emi);
        $projectedEmi = $current->existingEmi->plus($emi);

        // max(0, income x ceiling% - existing EMIs), in integer paise.
        $ceiling = Money::fromPaise(Money::divideHalfUp(
            $current->income->paise * (int) round(self::AFFORDABILITY_CEILING_PERCENT * 100),
            10000,
        ));

        $projectedRatio = Ratio::percentOf($projectedEmi, $current->income);

        return new LoanProjection(
            emi: $emi,
            currentOutgo: $current->totalOutgo,
            projectedOutgo: $projectedOutgo,
            currentSurplus: $current->surplus,
            projectedSurplus: $current->income->minus($projectedOutgo),
            currentEmiRatio: $current->emiRatio,
            projectedEmiRatio: $projectedRatio,
            emiCeiling: $ceiling,
            headroomBefore: $ceiling->minus($current->existingEmi)->clampToZero(),
            headroomAfter: $ceiling->minus($projectedEmi)->clampToZero(),
            withinCeiling: $current->hasIncome
                && $projectedRatio->hundredths <= (int) round(self::AFFORDABILITY_CEILING_PERCENT * 100),
            isMeasurable: $current->hasIncome,
        );
    }

    // ---------------------------------------------------------------------
    // Adapters. Thin, so the models stay convenient without the engine
    // depending on Eloquent.
    // ---------------------------------------------------------------------

    /**
     * @param  iterable<FinancialGoal>  $goals
     */
    public function snapshot(
        ?FinancialProfile $profile,
        iterable $goals = [],
        ?CarbonInterface $asOf = null,
    ): FinancialSnapshot {
        $mapped = [];
        foreach ($goals as $goal) {
            $mapped[] = [
                'id' => (string) $goal->id,
                'title' => (string) $goal->title,
                'target' => Money::of($goal->target_amount),
                'saved' => Money::of($goal->saved_amount),
                'target_date' => $goal->target_date,
            ];
        }

        return $this->calculate(
            income: Money::of($profile?->monthly_income),
            expenses: Money::of($profile?->monthly_expenses),
            existingEmi: Money::of($profile?->existing_emi),
            otherObligations: Money::of($profile?->other_obligations),
            savings: Money::of($profile?->savings),
            goals: $mapped,
            asOf: $asOf,
        );
    }

    public function goalMetrics(
        FinancialGoal $goal,
        ?CarbonInterface $asOf = null,
    ): GoalMetrics {
        return $this->metricsFor(
            (string) $goal->id,
            (string) $goal->title,
            Money::of($goal->target_amount),
            Money::of($goal->saved_amount),
            $goal->target_date,
            $asOf,
        );
    }

    /**
     * progress = saved / target, clamped to 0..1, to four decimal places.
     * A zero target has no meaningful progress, so it reads as zero.
     */
    public function progress(Money $saved, Money $target): int
    {
        if ($target->paise <= 0) {
            return 0;
        }

        $raw = Money::divideHalfUp($saved->paise * 10000, $target->paise);

        return max(0, min(10000, $raw));
    }

    /**
     * Whole months from `asOf` to the deadline, rounded up so a part month
     * still has to be funded. Never negative: a passed deadline is zero
     * months, which the caller reads as "due now".
     */
    public function monthsUntil(mixed $targetDate, ?CarbonInterface $asOf = null): int
    {
        if ($targetDate === null) {
            return 0;
        }

        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $target = Carbon::parse($targetDate)->startOfDay();
        $days = $asOf->diffInDays($target, false);

        return $days <= 0 ? 0 : (int) ceil($days / self::DAYS_PER_MONTH);
    }

    /**
     * What a healthy emergency fund would be for these outgoings.
     */
    public function emergencyFundTarget(Money $totalOutgo): Money
    {
        return $totalOutgo->times(self::EMERGENCY_FUND_TARGET_MONTHS);
    }

    /**
     * Every formula in words, shipped with the data.
     *
     * This is what lets a future feature explain a number instead of
     * reciting it, and it is the same text a developer reads above.
     *
     * @return array<string, string>
     */
    public static function definitions(): array
    {
        return [
            'monthly_outgo' => 'monthly_expenses + existing_emi + other_obligations',
            'surplus' => 'monthly_income - monthly_outgo (may be negative)',
            'emi_ratio' => 'existing_emi / monthly_income x 100 (0 when income is 0)',
            'expense_ratio' => 'monthly_expenses / monthly_income x 100 (0 when income is 0)',
            'outgo_ratio' => 'monthly_outgo / monthly_income x 100 (0 when income is 0)',
            'emergency_months' => 'savings / monthly_outgo (0 when outgo is 0)',
            'projected_emi_ratio' => '(existing_emi + new_emi) / monthly_income x 100 (0 when income is 0)',
            'projected_surplus' => 'monthly_income - (monthly_outgo + new_emi) (may be negative)',
            'emi_ceiling' => 'monthly_income x 45% — FynnEdge\'s affordability reference, not a lender rule',
            'emi_headroom' => 'max(0, emi_ceiling - existing_emi)',
            'goal_remaining' => 'max(0, target_amount - saved_amount)',
            'goal_progress' => 'saved_amount / target_amount, clamped to 0..1 (0 when target is 0)',
            'goal_months_remaining' => 'ceil(days until target_date / 30), never negative',
            'goal_monthly_required' => 'goal_remaining / goal_months_remaining; the full remainder when no months are left',
            'rounding' => 'All money is computed in integer paise. Division rounds half away from zero to the nearest paise. Ratios are held to 2 decimals, goal progress to 4. Rounding happens only at these points and at serialisation.',
        ];
    }
}
