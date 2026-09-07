<?php

namespace App\Domain\Score;

use App\Domain\Financial\FinancialSnapshot;
use App\Domain\Money;

/**
 * FynnScore v1 — FynnEdge's financial-health and readiness indicator.
 *
 * WHAT THIS IS NOT: a CIBIL or bureau score, a lender's approval score, an
 * approval probability, or a credit rating. It reads only the figures the
 * customer has given us, and says what they indicate about financial health.
 *
 * Division of responsibility:
 *   FinancialEngine  answers "what are the customer's numbers?"
 *   FynnScoreEngine  answers "what do those numbers indicate?"
 * Nothing here recomputes a financial metric, and nothing in FinancialEngine
 * knows this class exists.
 *
 * THE MODEL (v1, approved):
 *
 *   Three equally weighted dimensions, each a piecewise-linear curve between
 *   two anchors taken from definitions already in the product:
 *
 *     EMI Burden        emi_ratio          100 at <=20%   0 at >=45%
 *     Spending Control  expense_ratio      100 at <=40%   0 at >=80%
 *     Emergency Buffer  emergency_months   100 at >=6     0 at 0
 *
 *   overall = round((d1 + d2 + d3) / 3), clamped to 0..100
 *
 *   The 45% EMI anchor is the affordability ceiling already used by the
 *   product; the 6-month emergency anchor is
 *   FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS. Neither is redefined here.
 *
 * DETERMINISM: the score depends only on income, expenses, existing EMI,
 * other obligations and savings. Goals are deliberately excluded — a goal
 * deadline moving one day closer must never change a customer's score. All
 * arithmetic is integer, so the result is reproducible to the point.
 */
class FynnScoreEngine
{
    public const MODEL_VERSION = 'fynnscore-v1';

    public const DISCLAIMER = 'FynnScore is FynnEdge\'s own view of your '
        .'financial health. It is not a credit bureau score, not a lender\'s '
        .'approval score, and no lender sees it.';

    // --- Anchors -----------------------------------------------------------
    // Expressed in hundredths so the whole model is integer arithmetic.

    /** emi_ratio: full marks at or below 20%, zero at or above 45%. */
    public const EMI_FULL_MARKS_AT = 2000;

    public const EMI_ZERO_AT = 4500;

    /** expense_ratio: full marks at or below 40%, zero at or above 80%. */
    public const EXPENSE_FULL_MARKS_AT = 4000;

    public const EXPENSE_ZERO_AT = 8000;

    /** emergency_months: full marks at or above 6 months, zero at 0. */
    public const EMERGENCY_FULL_MARKS_AT = 600;

    public const EMERGENCY_ZERO_AT = 0;

    /** Every dimension carries the same weight. */
    public const DIMENSION_COUNT = 3;

    public function score(FinancialSnapshot $snapshot, ?string $inputsUpdatedAt = null): FynnScore
    {
        // v1 requires exactly one thing: an income to measure against. Without
        // it every ratio is undefined, and a score would be invented.
        if (! $snapshot->income->isPositive()) {
            return FynnScore::insufficientData(
                missing: ['monthly_income'],
                reason: 'Add your monthly income and we can work out your '
                    .'FynnScore. Everything else follows from it.',
                metrics: $this->metrics($snapshot),
            );
        }

        $dimensionScores = [
            'emi_burden' => $this->emiBurdenHundredths($snapshot),
            'spending_control' => $this->spendingControlHundredths($snapshot),
            'emergency_buffer' => $this->emergencyBufferHundredths($snapshot),
        ];

        $overall = $this->overallFrom($dimensionScores);

        $dimensions = [
            $this->emiBurdenDimension($snapshot, $dimensionScores['emi_burden'], $overall),
            $this->spendingControlDimension($snapshot, $dimensionScores['spending_control'], $overall),
            $this->emergencyBufferDimension($snapshot, $dimensionScores['emergency_buffer'], $overall),
        ];

        return FynnScore::calculated(
            score: $overall,
            dimensions: $dimensions,
            recommendations: $this->recommendations($snapshot, $dimensionScores, $overall),
            metrics: $this->metrics($snapshot),
            inputsUpdatedAt: $inputsUpdatedAt,
        );
    }

    /**
     * The average of the three dimensions, rounded half up to a whole point.
     *
     * @param  array<string, int>  $hundredths
     */
    private function overallFrom(array $hundredths): int
    {
        $sum = array_sum($hundredths);

        // Hundredths of a point, averaged over three dimensions, then rounded
        // to a whole point: divide by 100 x 3.
        $overall = Money::divideHalfUp($sum, 100 * self::DIMENSION_COUNT);

        return max(0, min(100, $overall));
    }

    // --- Dimension curves --------------------------------------------------

    private function emiBurdenHundredths(FinancialSnapshot $s): int
    {
        return $this->lowerIsBetter(
            $s->emiRatio->hundredths,
            self::EMI_FULL_MARKS_AT,
            self::EMI_ZERO_AT,
        );
    }

    private function spendingControlHundredths(FinancialSnapshot $s): int
    {
        return $this->lowerIsBetter(
            $s->expenseRatio->hundredths,
            self::EXPENSE_FULL_MARKS_AT,
            self::EXPENSE_ZERO_AT,
        );
    }

    private function emergencyBufferHundredths(FinancialSnapshot $s): int
    {
        return $this->higherIsBetter(
            $s->emergencyMonths->hundredths,
            self::EMERGENCY_FULL_MARKS_AT,
            self::EMERGENCY_ZERO_AT,
        );
    }

    /**
     * Piecewise-linear, full marks at or below [$fullMarksAt], zero at or
     * above [$zeroAt]. Returns hundredths of a point (0..10000).
     */
    public function lowerIsBetter(int $value, int $fullMarksAt, int $zeroAt): int
    {
        if ($value <= $fullMarksAt) {
            return 10000;
        }
        if ($value >= $zeroAt) {
            return 0;
        }

        return Money::divideHalfUp(
            10000 * ($zeroAt - $value),
            $zeroAt - $fullMarksAt,
        );
    }

    /**
     * Piecewise-linear, full marks at or above [$fullMarksAt], zero at or
     * below [$zeroAt]. Returns hundredths of a point (0..10000).
     */
    public function higherIsBetter(int $value, int $fullMarksAt, int $zeroAt): int
    {
        if ($value >= $fullMarksAt) {
            return 10000;
        }
        if ($value <= $zeroAt) {
            return 0;
        }

        return Money::divideHalfUp(
            10000 * ($value - $zeroAt),
            $fullMarksAt - $zeroAt,
        );
    }

    // --- Dimension assembly ------------------------------------------------

    private function emiBurdenDimension(
        FinancialSnapshot $s,
        int $hundredths,
        int $overall,
    ): ScoreDimension {
        $ratio = $s->emiRatio->toJsonNumber();

        return new ScoreDimension(
            key: 'emi_burden',
            name: 'EMI Burden',
            metric: 'emi_ratio',
            metricValue: $ratio,
            metricUnit: 'percent_of_income',
            fullMarksAt: 20,
            zeroAt: 45,
            scoreHundredths: $hundredths,
            weight: round(1 / self::DIMENSION_COUNT, 4),
            explanation: match (true) {
                $hundredths >= 10000 => "Existing EMIs take {$ratio}% of your income, at or under the 20% mark. That leaves real room to borrow.",
                // No lender has seen these figures, so nothing here may say
                // what one would do. 45% is FynnEdge's own reference for
                // what stays comfortable to service.
                $hundredths <= 0 => "Existing EMIs take {$ratio}% of your income, at or past the 45% FynnEdge uses as its affordability reference. Little of your income is left to service anything more.",
                default => "Existing EMIs take {$ratio}% of your income. Under 20% scores full marks; 45% is FynnEdge's affordability reference.",
            },
            isHelping: $this->isHelping($hundredths, $overall),
        );
    }

    private function spendingControlDimension(
        FinancialSnapshot $s,
        int $hundredths,
        int $overall,
    ): ScoreDimension {
        $ratio = $s->expenseRatio->toJsonNumber();

        return new ScoreDimension(
            key: 'spending_control',
            name: 'Spending Control',
            metric: 'expense_ratio',
            metricValue: $ratio,
            metricUnit: 'percent_of_income',
            fullMarksAt: 40,
            zeroAt: 80,
            scoreHundredths: $hundredths,
            weight: round(1 / self::DIMENSION_COUNT, 4),
            explanation: match (true) {
                $hundredths >= 10000 => "You spend {$ratio}% of your income, at or under the 40% mark. That is a strong ratio.",
                $hundredths <= 0 => "You spend {$ratio}% of your income, at or beyond 80%. Very little is left over each month.",
                default => "You spend {$ratio}% of your income. Under 40% scores full marks; 80% scores nothing.",
            },
            isHelping: $this->isHelping($hundredths, $overall),
        );
    }

    private function emergencyBufferDimension(
        FinancialSnapshot $s,
        int $hundredths,
        int $overall,
    ): ScoreDimension {
        $months = $s->emergencyMonths->toJsonNumber();

        return new ScoreDimension(
            key: 'emergency_buffer',
            name: 'Emergency Buffer',
            metric: 'emergency_months',
            metricValue: $months,
            metricUnit: 'months_of_outgoings',
            fullMarksAt: 6,
            zeroAt: 0,
            scoreHundredths: $hundredths,
            weight: round(1 / self::DIMENSION_COUNT, 4),
            explanation: match (true) {
                $hundredths >= 10000 => "Your savings cover {$months} months of outgoings, at or beyond the 6-month target.",
                $hundredths <= 0 => 'You have no savings buffer. One unexpected month would have to be borrowed for.',
                default => "Your savings cover {$months} months of outgoings. The target is 6 months.",
            },
            isHelping: $this->isHelping($hundredths, $overall),
        );
    }

    /**
     * A dimension helps when it sits at or above the overall score, because
     * that is precisely what pulls the average up.
     */
    private function isHelping(int $hundredths, int $overall): bool
    {
        return Money::divideHalfUp($hundredths, 100) >= $overall;
    }

    // --- Recommendations ---------------------------------------------------

    /**
     * One recommendation per dimension short of full marks, ordered by the
     * size of the improvement.
     *
     * The impact is not a guess: the model is re-run with that one dimension
     * at full marks and the difference reported. current -> changed -> delta.
     *
     * @param  array<string, int>  $scores
     * @return list<ScoreRecommendation>
     */
    private function recommendations(
        FinancialSnapshot $s,
        array $scores,
        int $overall,
    ): array {
        $recommendations = [];

        foreach ($scores as $key => $hundredths) {
            if ($hundredths >= 10000) {
                continue;
            }

            $projected = $this->overallFrom(
                array_merge($scores, [$key => 10000]),
            );

            $recommendations[] = $this->recommendationFor(
                $key,
                $s,
                $overall,
                $projected,
            );
        }

        // Biggest gain first; ties broken by dimension key so the order is
        // deterministic rather than dependent on array ordering.
        usort(
            $recommendations,
            fn (ScoreRecommendation $a, ScoreRecommendation $b) => [$b->delta(), $a->dimensionKey]
                <=> [$a->delta(), $b->dimensionKey],
        );

        return $recommendations;
    }

    private function recommendationFor(
        string $key,
        FinancialSnapshot $s,
        int $current,
        int $projected,
    ): ScoreRecommendation {
        return match ($key) {
            'emi_burden' => new ScoreRecommendation(
                dimensionKey: $key,
                title: 'Bring your EMIs under 20% of income',
                detail: 'Existing EMIs are '
                    .$s->emiRatio->toJsonNumber().'% of your income. Getting '
                    .'them to '.$this->moneyAt($s->income, 20)
                    .' a month or less would score full marks on this '
                    .'dimension — by prepaying, refinancing or letting a '
                    .'shorter loan finish.',
                metric: 'emi_ratio',
                currentValue: $s->emiRatio->toJsonNumber(),
                targetValue: 20,
                currentScore: $current,
                projectedScore: $projected,
            ),
            'spending_control' => new ScoreRecommendation(
                dimensionKey: $key,
                title: 'Bring monthly spending under 40% of income',
                detail: 'You spend '.$s->expenseRatio->toJsonNumber()
                    .'% of your income. Holding regular expenses to '
                    .$this->moneyAt($s->income, 40).' a month or less would '
                    .'score full marks on this dimension.',
                metric: 'expense_ratio',
                currentValue: $s->expenseRatio->toJsonNumber(),
                targetValue: 40,
                currentScore: $current,
                projectedScore: $projected,
            ),
            default => new ScoreRecommendation(
                dimensionKey: $key,
                title: 'Build your emergency fund to 6 months',
                detail: 'Your savings cover '
                    .$s->emergencyMonths->toJsonNumber().' months of the '
                    .$this->format($s->totalOutgo).' you spend each month. '
                    .'Reaching '.$this->format($s->totalOutgo->times(6))
                    .' would score full marks on this dimension.',
                metric: 'emergency_months',
                currentValue: $s->emergencyMonths->toJsonNumber(),
                targetValue: 6,
                currentScore: $current,
                projectedScore: $projected,
            ),
        };
    }

    /** A rupee figure for a given percentage of income. */
    private function moneyAt(Money $income, int $percent): string
    {
        return $this->format(
            Money::fromPaise(Money::divideHalfUp($income->paise * $percent, 100)),
        );
    }

    /**
     * Indian digit grouping: last three digits, then pairs.
     *
     * number_format() would produce ₹300,000 where this audience — and every
     * other figure in the app — reads ₹3,00,000.
     */
    private function format(Money $money): string
    {
        $whole = (string) abs(Money::divideHalfUp($money->paise, 100));

        if (strlen($whole) <= 3) {
            return '₹'.$whole;
        }

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);

        $parts = [];
        while (strlen($rest) > 2) {
            array_unshift($parts, substr($rest, -2));
            $rest = substr($rest, 0, -2);
        }
        if ($rest !== '') {
            array_unshift($parts, $rest);
        }

        return '₹'.implode(',', $parts).','.$last3;
    }

    /**
     * The FinancialEngine figures the score reads, echoed so a caller can see
     * exactly what produced each dimension.
     *
     * @return array<string, mixed>
     */
    private function metrics(FinancialSnapshot $s): array
    {
        return [
            'monthly_income' => $s->income->toJsonNumber(),
            'monthly_outgo' => $s->totalOutgo->toJsonNumber(),
            'surplus' => $s->surplus->toJsonNumber(),
            'emi_ratio' => $s->emiRatio->toJsonNumber(),
            'expense_ratio' => $s->expenseRatio->toJsonNumber(),
            'emergency_months' => $s->emergencyMonths->toJsonNumber(),
            'savings' => $s->savings->toJsonNumber(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function bandDefinitions(): array
    {
        return [
            ['band' => 'strong', 'label' => 'Strong', 'min' => 75, 'max' => 100],
            ['band' => 'good', 'label' => 'Good', 'min' => 55, 'max' => 74],
            ['band' => 'needs_attention', 'label' => 'Needs Attention', 'min' => 40, 'max' => 54],
            ['band' => 'at_risk', 'label' => 'At Risk', 'min' => 0, 'max' => 39],
        ];
    }
}
