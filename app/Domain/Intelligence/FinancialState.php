<?php

namespace App\Domain\Intelligence;

use App\Domain\Financial\FinancialSnapshot;
use App\Domain\Score\FynnScore;
use App\Domain\Score\FynnScoreEngine;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;
use Illuminate\Support\Collection;

/**
 * Everything the intelligence layer knows, read once.
 *
 * Not a calculation. Every figure in here was produced by the engine that
 * owns it — FinancialEngine for the ratios, FynnScoreEngine for the score,
 * FinancialGoal's own metrics for progress — and this object carries them
 * rather than recomputing them. There is exactly one way to get an EMI
 * ratio in FynnEdge and this is not a second one.
 *
 * It is also read once per request and passed down. Home, insights and the
 * next action all want the same snapshot, and computing it three times
 * would be three chances for them to disagree with each other about the
 * customer's own money.
 *
 * `previous` is the honest part. It is null for every customer who has not
 * yet been observed twice, which today is every customer — and every rule
 * that needs a comparison checks it rather than falling back to something
 * that looks like history.
 */
final class FinancialState
{
    /**
     * @param  Collection<int, FinancialGoal>  $goals
     * @param  Collection<int, LoanApplication>  $applications
     */
    public function __construct(
        public readonly FinancialSnapshot $snapshot,
        public readonly FynnScore $score,
        public readonly Collection $goals,
        public readonly Collection $applications,

        /**
         * The most recent observation before now, or null.
         *
         * Null means FynnEdge has nothing to compare against — not that
         * nothing changed. Every rule that would state a change has to
         * distinguish those two, and the difference is the whole of this
         * module's honesty about trends.
         */
        public readonly ?FinancialObservationReading $previous = null,

        /** How many observations exist at all. */
        public readonly int $observationCount = 0,

        /**
         * Whether FynnEdge has a name to greet them by.
         *
         * A missing input like an absent income, and it belongs here for
         * the same reason: the next action ladder has to be able to ask for
         * it rather than a name being assumed.
         */
        public readonly bool $hasName = true,

        /**
         * Whether Module 32 recorded anything the customer explored.
         *
         * A boolean rather than the history itself, deliberately. The
         * planner needs to know only whether there is something to go back
         * to; carrying the entries would put a customer's explorations in
         * front of every rule that reads this state, for no benefit.
         *
         * False until they have actually explored something. Nothing here
         * is reconstructed from a current application or product.
         */
        public readonly bool $hasRecordedDecision = false,
    ) {}

    /** Whether enough was entered to work anything out. */
    public function hasFinancialProfile(): bool
    {
        return $this->snapshot->hasIncome;
    }

    public function hasScore(): bool
    {
        return $this->score->hasScore;
    }

    /**
     * Whether FynnEdge can honestly compare anything to anything.
     *
     * One earlier observation, which is exactly what a comparison needs.
     *
     * This originally demanded two, on the reasoning that "two points make
     * a line" — and it was wrong in a way worth recording. At the moment a
     * comparison is made, the new reading has not been written yet, so
     * there is one observation in the table and the check refused every
     * one. Score changes were suppressed silently and for ever, which is
     * the failure mode this whole module is about: the system quietly
     * saying nothing rather than saying something false, and nobody
     * noticing because silence looks like correctness.
     *
     * A customer who has changed nothing still has no history, because
     * `previous` is null. That is the honest part and it is unaffected.
     */
    public function hasComparableHistory(): bool
    {
        return $this->previous !== null;
    }

    /**
     * Whether there is enough history to show a customer anything about
     * change over time.
     *
     * A different question from hasComparableHistory(), and the difference
     * is easy to get wrong. That one is asked at the instant a change is
     * being recorded, when the incoming reading has not been stored yet and
     * one earlier observation is exactly what a comparison needs.
     *
     * This one is asked when a customer is looking at a screen, and there
     * the single stored observation IS their current state — so comparing
     * it with itself proves nothing, and a screen offered a trend on that
     * basis would be showing a line drawn through one point.
     */
    public function canShowTrends(): bool
    {
        return $this->observationCount >= 2;
    }

    /**
     * The score's movement since it was last observed, or null.
     *
     * Null whenever there is nothing to compare — no previous observation,
     * no score then, or no score now. A caller cannot accidentally read
     * "no change" out of "no idea".
     */
    public function scoreDelta(): ?int
    {
        if (! $this->hasComparableHistory()
            || $this->previous?->score === null
            || $this->score->score === null) {
            return null;
        }

        /*
         * And only across the same model.
         *
         * A score from a different FynnScore version is a different
         * measurement; subtracting one from the other produces a number
         * that means nothing, and a customer told their score moved
         * because FynnEdge changed how it counts has been misled.
         */
        if (! $this->previous->isComparableWith(FynnScoreEngine::MODEL_VERSION)) {
            return null;
        }

        return $this->score->score - $this->previous->score;
    }

    /** @return Collection<int, LoanApplication> */
    public function activeApplications(): Collection
    {
        return $this->applications->filter(
            fn (LoanApplication $a) => ! $a->status()->isTerminal()
        );
    }
}
