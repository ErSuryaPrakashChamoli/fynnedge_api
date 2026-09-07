<?php

namespace App\Domain\Credit;

use Carbon\CarbonInterface;

/**
 * What FynnEdge keeps from a credit report.
 *
 * Deliberately almost nothing. A bureau report carries addresses, employers,
 * account numbers, enquiry histories and identifiers, and FynnEdge needs
 * none of it: the only question the product asks is whether a documented
 * minimum score is met.
 *
 * So this holds a score, the model it was measured on, who supplied it and
 * when. Everything else the bureau sent is read by the normalizer and
 * discarded — not stored, not logged, not passed on.
 *
 * This is NOT a FynnScore. FynnScore is FynnEdge's own view of a customer's
 * financial health, built from three figures the customer supplied. A bureau
 * score is a different number, on a different scale, produced by a different
 * organisation, measuring a different thing. Nothing in FynnEdge converts
 * one into the other.
 */
final class CreditProfile
{
    public function __construct(
        public readonly CreditBureau $bureau,

        /**
         * The bureau's score. Meaningless without its model, which is why
         * the two are never separated.
         */
        public readonly int $score,

        /** The scale this score is on, e.g. `cibil-v3` — 300 to 900. */
        public readonly string $scoreModel,

        public readonly int $scoreMin,
        public readonly int $scoreMax,

        /** When the bureau actually answered. Not when it was displayed. */
        public readonly CarbonInterface $obtainedAt,
    ) {}

    /**
     * Whether this score meets a documented minimum.
     *
     * A comparison, and nothing more. It says the requirement is satisfied,
     * never that anyone would lend.
     */
    public function meets(int $minimumScore): bool
    {
        return $this->score >= $minimumScore;
    }

    /**
     * What a customer may see.
     *
     * The score, labelled with whose it is and what scale it sits on, so it
     * can never be read as a FynnScore.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'score_model' => $this->scoreModel,
            'scale' => ['min' => $this->scoreMin, 'max' => $this->scoreMax],
            'bureau' => $this->bureau->value,
            'bureau_label' => $this->bureau->label(),
            'is_sample' => ! $this->bureau->isLive(),
            'obtained_at' => $this->obtainedAt->toIso8601String(),
        ];
    }
}
