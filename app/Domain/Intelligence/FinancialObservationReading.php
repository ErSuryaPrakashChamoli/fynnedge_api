<?php

namespace App\Domain\Intelligence;

use App\Domain\Money;
use Illuminate\Support\Carbon;

/**
 * What was true, once.
 *
 * A plain reading of one stored observation. Deliberately not the Eloquent
 * model: an intelligence rule comparing today with the past must not be
 * able to write to the past, and a value object cannot.
 */
final class FinancialObservationReading
{
    public function __construct(
        public readonly ?int $score,
        public readonly ?string $scoreBand,
        public readonly ?string $scoreModelVersion,
        public readonly float $emiRatio,
        public readonly float $expenseRatio,
        public readonly float $emergencyMonths,
        public readonly Carbon $observedAt,

        /*
         * The money that was recorded, as it was recorded.
         *
         * Carried in the same integer paise the rest of the product uses,
         * so a figure read back out of history and a figure read off the
         * profile are the same kind of thing. These columns have existed
         * since Module 31; nothing read them until a customer needed to be
         * shown what their income had actually been.
         */
        public readonly Money $monthlyIncome,
        public readonly Money $monthlyOutgo,
        public readonly Money $surplus,

        /**
         * Whether an income was recorded at all when this was observed.
         *
         * Zero and absent are different facts, and Module 34 made that
         * distinction load-bearing. A customer who had entered nothing must
         * not appear in their own history as somebody who earned nothing.
         */
        public readonly bool $hasIncome,
    ) {}

    /**
     * Whether this score can be compared with one produced now.
     *
     * A score from a different model version is a different measurement,
     * and subtracting one from the other would produce a number that means
     * nothing. FynnScore has had one model version so far; this is here so
     * that the day it has two, no customer is told their score moved
     * because FynnEdge changed how it counts.
     */
    public function isComparableWith(string $modelVersion): bool
    {
        return $this->scoreModelVersion === null
            || $this->scoreModelVersion === $modelVersion;
    }
}
