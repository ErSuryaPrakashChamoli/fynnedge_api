<?php

namespace App\Domain\Intelligence;

use App\Domain\Score\FynnScoreEngine;

/**
 * What actually changed, between things FynnEdge actually observed.
 *
 * Every item comes from a pair of consecutive stored observations. Today's
 * state is not one end of any comparison here, and that is the rule the
 * whole class exists to hold: a customer's live figures are what is true
 * now, not something FynnEdge wrote down, and comparing the two would be
 * comparing a record with a reading.
 *
 * Silent by default. One observation is a baseline, not a trend; two
 * identical observations are not a change; a score across two model
 * versions is not a comparison; and a buffer measured against no outgo is
 * not a figure. In each case this returns nothing rather than something
 * shaped like an answer.
 *
 * Nothing here predicts, smooths, interpolates or explains why.
 */
final class FinancialProgress
{
    /**
     * How much of a customer's history one response carries.
     *
     * The same figure the timeline uses, because it is the same question.
     * Module 35 introduces no second retention or window policy.
     */
    public const MAX_CHANGES = 20;

    /** @param list<ObservedChange> $changes newest first */
    private function __construct(
        private readonly array $changes,
        private readonly int $observationCount,
    ) {}

    /**
     * @param  list<FinancialObservationReading>  $readings  oldest first
     */
    public static function from(array $readings): self
    {
        $pairs = [];

        for ($i = 1; $i < count($readings); $i++) {
            $pairs[] = self::between($readings[$i - 1], $readings[$i]);
        }

        /*
         * Newest pair first, and within a pair the order the rules produced
         * — the score, then the figures behind it.
         *
         * Reversing the flattened list instead put each pair's items
         * backwards too, so a customer whose score moved read the buffer
         * change first and the score last. The pairs are what have an
         * order in time; what happened inside one moment does not.
         */
        $changes = array_merge(...array_reverse($pairs) ?: [[]]);

        return new self(
            array_slice($changes, 0, self::MAX_CHANGES),
            count($readings),
        );
    }

    /** @return list<ObservedChange> */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Whether FynnEdge has seen enough to compare anything.
     *
     * Two distinct observations. A customer who saved the same figures
     * twice has one observed state, and the recorder's unique index means
     * they have one row — so this counts states, not saves.
     */
    public function hasComparableHistory(): bool
    {
        return $this->observationCount >= 2;
    }

    /** @return list<ObservedChange> */
    private static function between(
        FinancialObservationReading $before,
        FinancialObservationReading $after,
    ): array {
        return array_values(array_filter([
            self::score($before, $after),
            self::money(ObservedMetric::MonthlyIncome, $before, $after),
            self::money(ObservedMetric::MonthlyOutgo, $before, $after),
            self::money(ObservedMetric::Surplus, $before, $after),
            self::buffer($before, $after),
        ]));
    }

    /**
     * A score change, when both scores exist and mean the same thing.
     *
     * Null when either observation had no score — a score becoming
     * available is an arrival, and Module 31 already records that as a
     * moment on the same screen. Null too across model versions, because a
     * score from a different model is a different measurement and
     * subtracting one from the other produces a number that means nothing.
     *
     * Movements smaller than ScoreChangePolicy::POINTS are not reported,
     * which is the threshold that policy already defines for exactly this
     * question: whether a move is worth mentioning on a screen the customer
     * chose to open.
     */
    private static function score(
        FinancialObservationReading $before,
        FinancialObservationReading $after,
    ): ?ObservedChange {
        if ($before->score === null || $after->score === null) {
            return null;
        }

        if ($after->scoreModelVersion === null
            || ! $before->isComparableWith($after->scoreModelVersion)) {
            return null;
        }

        if (! ScoreChangePolicy::isMeaningful($after->score - $before->score)) {
            return null;
        }

        return new ObservedChange(
            metric: ObservedMetric::Score,
            previousValue: $before->score,
            currentValue: $after->score,
            previousObservedAt: $before->observedAt,
            observedAt: $after->observedAt,
            previousBand: $before->scoreBand,
            band: $after->scoreBand,
            components: self::components($before, $after),
        );
    }

    /**
     * What each component was worth when each of the two scores was
     * calculated.
     *
     * From the ratios stored on the observations and FynnScore's own
     * published curves — the same functions that produced the scores in the
     * first place, applied to the figures that were true at the time.
     * Nothing is read from the customer's current profile, which is what
     * makes this explanation stay put when they change it tomorrow.
     *
     * @return list<ObservedScoreComponent>
     */
    private static function components(
        FinancialObservationReading $before,
        FinancialObservationReading $after,
    ): array {
        $engine = app(FynnScoreEngine::class);

        $hundredths = fn (float $value) => (int) round($value * 100);
        $points = fn (int $h) => (int) round($h / 100);

        $emi = fn (float $ratio) => $points($engine->lowerIsBetter(
            $hundredths($ratio),
            FynnScoreEngine::EMI_FULL_MARKS_AT,
            FynnScoreEngine::EMI_ZERO_AT,
        ));

        $expense = fn (float $ratio) => $points($engine->lowerIsBetter(
            $hundredths($ratio),
            FynnScoreEngine::EXPENSE_FULL_MARKS_AT,
            FynnScoreEngine::EXPENSE_ZERO_AT,
        ));

        $buffer = fn (float $months) => $points($engine->higherIsBetter(
            $hundredths($months),
            FynnScoreEngine::EMERGENCY_FULL_MARKS_AT,
            FynnScoreEngine::EMERGENCY_ZERO_AT,
        ));

        $components = [
            new ObservedScoreComponent(
                key: 'emi_burden',
                label: 'EMI Burden',
                metric: 'emi_ratio',
                previousValue: $before->emiRatio,
                currentValue: $after->emiRatio,
                unit: 'percent_of_income',
                previousScore: $emi($before->emiRatio),
                currentScore: $emi($after->emiRatio),
            ),
            new ObservedScoreComponent(
                key: 'spending_control',
                label: 'Spending Control',
                metric: 'expense_ratio',
                previousValue: $before->expenseRatio,
                currentValue: $after->expenseRatio,
                unit: 'percent_of_income',
                previousScore: $expense($before->expenseRatio),
                currentScore: $expense($after->expenseRatio),
            ),
            new ObservedScoreComponent(
                key: 'emergency_buffer',
                label: 'Emergency Buffer',
                metric: 'emergency_months',
                previousValue: $before->emergencyMonths,
                currentValue: $after->emergencyMonths,
                unit: 'months_of_outgoings',
                previousScore: $buffer($before->emergencyMonths),
                currentScore: $buffer($after->emergencyMonths),
            ),
        ];

        // Only the ones that moved. A component identical at both ends is
        // not part of what changed, and listing it invites the reader to
        // connect it to the score movement anyway.
        return array_values(array_filter(
            $components,
            fn (ObservedScoreComponent $c) => $c->hasChanged(),
        ));
    }

    /**
     * A money figure that moved, when both ends were measured.
     *
     * Suppressed when either observation had no recorded income. Zero and
     * absent are different facts: a customer who had entered nothing must
     * not appear in their own history as somebody who earned nothing, and
     * their first recorded income is a baseline rather than a rise from
     * zero.
     */
    private static function money(
        ObservedMetric $metric,
        FinancialObservationReading $before,
        FinancialObservationReading $after,
    ): ?ObservedChange {
        if (! $before->hasIncome || ! $after->hasIncome) {
            return null;
        }

        $previous = match ($metric) {
            ObservedMetric::MonthlyIncome => $before->monthlyIncome,
            ObservedMetric::MonthlyOutgo => $before->monthlyOutgo,
            default => $before->surplus,
        };

        $current = match ($metric) {
            ObservedMetric::MonthlyIncome => $after->monthlyIncome,
            ObservedMetric::MonthlyOutgo => $after->monthlyOutgo,
            default => $after->surplus,
        };

        if ($previous->paise === $current->paise) {
            return null;
        }

        return new ObservedChange(
            metric: $metric,
            previousValue: $previous->toJsonNumber(),
            currentValue: $current->toJsonNumber(),
            previousObservedAt: $before->observedAt,
            observedAt: $after->observedAt,
        );
    }

    /**
     * The emergency buffer, when there was something for it to cover.
     *
     * Ratio::timesOver returns zero for a zero denominator, and Module 34
     * found what happens when that is read as a fact: a customer with
     * nothing recorded going out is told their savings would not last a
     * month. The same rule applies looking backwards — a buffer measured
     * against no outgo is not a figure that can have changed.
     */
    private static function buffer(
        FinancialObservationReading $before,
        FinancialObservationReading $after,
    ): ?ObservedChange {
        if (! $before->monthlyOutgo->isPositive()
            || ! $after->monthlyOutgo->isPositive()) {
            return null;
        }

        if ($before->emergencyMonths === $after->emergencyMonths) {
            return null;
        }

        return new ObservedChange(
            metric: ObservedMetric::EmergencyMonths,
            previousValue: $before->emergencyMonths,
            currentValue: $after->emergencyMonths,
            previousObservedAt: $before->observedAt,
            observedAt: $after->observedAt,
        );
    }
}
