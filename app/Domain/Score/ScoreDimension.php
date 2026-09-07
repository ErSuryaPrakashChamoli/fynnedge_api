<?php

namespace App\Domain\Score;

/**
 * One scored dimension, with everything needed to explain it.
 */
final class ScoreDimension
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        /** The FinancialEngine metric this reads. */
        public readonly string $metric,
        /**
         * Serialised exactly as the same metric appears in the `metrics`
         * block: whole values as ints, otherwise floats. One value must not
         * arrive as 20 in one place and 20.0 in another.
         */
        public readonly int|float $metricValue,
        public readonly string $metricUnit,
        /** Metric value scoring 100, and the value scoring 0. */
        public readonly int|float $fullMarksAt,
        public readonly int|float $zeroAt,
        /** 0..100, held to two decimals as hundredths. */
        public readonly int $scoreHundredths,
        public readonly float $weight,
        public readonly string $explanation,
        /**
         * True when this dimension sits at or above the overall score, i.e.
         * it is pulling the result up rather than down.
         */
        public readonly bool $isHelping,
    ) {}

    public function score(): int
    {
        return (int) round($this->scoreHundredths / 100);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'score' => $this->score(),
            'weight' => $this->weight,
            'metric' => $this->metric,
            'metric_value' => $this->metricValue,
            'metric_unit' => $this->metricUnit,
            'full_marks_at' => $this->fullMarksAt,
            'zero_at' => $this->zeroAt,
            'explanation' => $this->explanation,
            'is_helping' => $this->isHelping,
        ];
    }
}
