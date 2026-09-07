<?php

namespace App\Domain\Score;

/**
 * An improvement the customer could make, with a reproducible impact.
 *
 * The delta is not an estimate or a guess: it is the score recalculated with
 * that one metric moved to its full-marks anchor, minus the current score.
 * Anyone can reproduce it by running the model twice.
 */
final class ScoreRecommendation
{
    public function __construct(
        public readonly string $dimensionKey,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $metric,
        public readonly int|float $currentValue,
        public readonly int|float $targetValue,
        public readonly int $currentScore,
        public readonly int $projectedScore,
    ) {}

    public function delta(): int
    {
        return $this->projectedScore - $this->currentScore;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dimension' => $this->dimensionKey,
            'title' => $this->title,
            'detail' => $this->detail,
            'metric' => $this->metric,
            'current_value' => $this->currentValue,
            'target_value' => $this->targetValue,
            // Shown as "+N points", and reproducible from the two scores.
            'current_score' => $this->currentScore,
            'projected_score' => $this->projectedScore,
            'delta' => $this->delta(),
        ];
    }
}
