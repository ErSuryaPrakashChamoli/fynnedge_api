<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Carbon;

/**
 * One figure that actually moved between two things FynnEdge wrote down.
 *
 * Both ends are stored observations. Neither is today's state, neither is a
 * simulation, and neither was reconstructed — which is the whole reason
 * this object exists rather than a comparison being made wherever it was
 * convenient.
 *
 * It states what changed and when. It does not say whether that is good,
 * what caused it, or what it means for next month. "Your recorded monthly
 * surplus changed from -₹8,000 to ₹12,000" is a fact about two rows; "your
 * finances recovered" is an opinion about somebody's life, and FynnEdge has
 * no standing to hold one.
 */
final class ObservedChange
{
    /**
     * @param  list<ObservedScoreComponent>  $components  the figures that were
     *                                                    true when each score
     *                                                    was calculated
     */
    public function __construct(
        public readonly ObservedMetric $metric,
        public readonly int|float $previousValue,
        public readonly int|float $currentValue,
        public readonly Carbon $previousObservedAt,
        public readonly Carbon $observedAt,
        public readonly ?string $previousBand = null,
        public readonly ?string $band = null,
        public readonly array $components = [],
    ) {}

    /**
     * Which way it went, as a direction and nothing more.
     *
     * "Increased" is a description of two numbers. "Improved" would be a
     * judgement about which direction is better, and for a figure like
     * recorded monthly outgo the answer depends on the customer's life
     * rather than on arithmetic.
     */
    public function direction(): string
    {
        return $this->currentValue > $this->previousValue
            ? 'increased'
            : 'decreased';
    }

    /**
     * The change as a sentence, in the shape every other FynnEdge sentence
     * about a customer's money takes: what was recorded, and what it is now.
     */
    public function summary(): string
    {
        return $this->metric->label().' changed from '
            .$this->metric->format($this->previousValue).' to '
            .$this->metric->format($this->currentValue).'.';
    }

    /**
     * The same fact with the direction spelled out, for a screen reader.
     *
     * "61 to 74" beside an arrow is legible on a screen and silent when
     * read aloud, so the direction is a word here rather than a glyph.
     */
    public function spokenSummary(): string
    {
        return $this->metric->label().' '.$this->direction().' from '
            .$this->metric->format($this->previousValue).' to '
            .$this->metric->format($this->currentValue).'.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'metric' => $this->metric->value,
            'label' => $this->metric->label(),
            'unit' => $this->metric->unit(),

            // One source per metric, because both ends of a change are the
            // same kind of figure recorded the same way. A history that
            // labelled one end customer-provided and the other calculated
            // would be describing two different measurements.
            'source' => $this->metric->source(),

            'previous_value' => $this->previousValue,
            'current_value' => $this->currentValue,
            'previous_display' => $this->metric->format($this->previousValue),
            'current_display' => $this->metric->format($this->currentValue),

            'direction' => $this->direction(),
            'summary' => $this->summary(),
            'spoken_summary' => $this->spokenSummary(),

            'previous_band' => $this->previousBand,
            'band' => $this->band,

            'previous_observed_at' => $this->previousObservedAt->toIso8601String(),
            'observed_at' => $this->observedAt->toIso8601String(),

            'components' => array_map(
                fn (ObservedScoreComponent $c) => $c->toArray(),
                $this->components,
            ),
        ], fn ($value) => $value !== null && $value !== []);
    }
}
