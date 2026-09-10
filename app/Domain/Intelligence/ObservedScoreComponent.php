<?php

namespace App\Domain\Intelligence;

/**
 * What one FynnScore component was worth at each end of a score change.
 *
 * This is the answer to "why did my score change?" that FynnEdge is
 * actually entitled to give. The model reads three ratios; both were stored
 * when each score was calculated; so the honest statement is that this
 * figure was X then and Y now, and the component scored this much then and
 * that much now.
 *
 * What it deliberately is not is a cause. "Reducing your expenses raised
 * your score" attributes a later number to one earlier act, and nothing in
 * FynnEdge establishes that relationship — several figures move between two
 * observations, and the model is an average of three.
 *
 * Every value here comes from the two stored observations and FynnScore's
 * own published curves. Nothing is recalculated from today's profile, which
 * is what makes a historical score explanation stay put when the customer
 * changes their income tomorrow.
 */
final class ObservedScoreComponent
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $metric,
        public readonly float $previousValue,
        public readonly float $currentValue,
        public readonly string $unit,

        /** The component's own score, on the same 0..100 scale. */
        public readonly int $previousScore,
        public readonly int $currentScore,
    ) {}

    public function hasChanged(): bool
    {
        return $this->previousValue !== $this->currentValue;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'metric' => $this->metric,
            'unit' => $this->unit,
            'previous_value' => $this->previousValue,
            'current_value' => $this->currentValue,
            'previous_score' => $this->previousScore,
            'current_score' => $this->currentScore,
        ];
    }
}
