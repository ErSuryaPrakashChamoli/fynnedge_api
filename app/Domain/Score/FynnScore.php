<?php

namespace App\Domain\Score;

/**
 * The complete FynnScore result.
 *
 * Either a score with its dimensions, or an explicit insufficient-data state.
 * There is no third possibility, and never a fabricated number.
 */
final class FynnScore
{
    /**
     * @param  list<ScoreDimension>  $dimensions
     * @param  list<ScoreRecommendation>  $recommendations
     * @param  list<string>  $missingRequirements
     */
    private function __construct(
        public readonly bool $hasScore,
        public readonly ?int $score,
        public readonly ?ScoreBand $band,
        public readonly array $dimensions,
        public readonly array $recommendations,
        public readonly array $missingRequirements,
        public readonly string $insufficientDataReason,
        /** @var array<string, mixed> */
        public readonly array $metrics,
        public readonly ?string $inputsUpdatedAt,
    ) {}

    /**
     * @param  list<ScoreDimension>  $dimensions
     * @param  list<ScoreRecommendation>  $recommendations
     * @param  array<string, mixed>  $metrics
     */
    public static function calculated(
        int $score,
        array $dimensions,
        array $recommendations,
        array $metrics,
        ?string $inputsUpdatedAt = null,
    ): self {
        return new self(
            hasScore: true,
            score: $score,
            band: ScoreBand::forScore($score),
            dimensions: $dimensions,
            recommendations: $recommendations,
            missingRequirements: [],
            insufficientDataReason: '',
            metrics: $metrics,
            inputsUpdatedAt: $inputsUpdatedAt,
        );
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $metrics
     */
    public static function insufficientData(
        array $missing,
        string $reason,
        array $metrics = [],
    ): self {
        return new self(
            hasScore: false,
            score: null,
            band: null,
            dimensions: [],
            recommendations: [],
            missingRequirements: $missing,
            insufficientDataReason: $reason,
            metrics: $metrics,
            inputsUpdatedAt: null,
        );
    }

    /** Dimensions pulling the score up. */
    public function helping(): array
    {
        return array_values(array_filter(
            $this->dimensions,
            fn (ScoreDimension $d) => $d->isHelping,
        ));
    }

    /** Dimensions pulling the score down. */
    public function hurting(): array
    {
        return array_values(array_filter(
            $this->dimensions,
            fn (ScoreDimension $d) => ! $d->isHelping,
        ));
    }

    /**
     * The score as anything other than the FynnScore screen needs it.
     *
     * The figure, the band, and the band's own sentence. Not the
     * dimensions, the recommendations, the curves or the disclaimer —
     * those are what the FynnScore screen exists to show, and a summary
     * that carried them would be that screen arriving somewhere else.
     *
     * Defined here rather than at each call site so a band cannot be
     * labelled one way on the review and another on the score screen.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'has_score' => $this->hasScore,
            'score' => $this->score,
            'band' => $this->band?->value,
            'band_label' => $this->band?->label(),

            // The band's sentence when there is a score, and the engine's
            // own reason when there is not. A customer without a score is
            // told why rather than shown a blank.
            'summary' => $this->hasScore
                ? $this->band->summary()
                : $this->insufficientDataReason,

            'missing_requirements' => $this->missingRequirements,
            'model_version' => FynnScoreEngine::MODEL_VERSION,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->hasScore ? 'ok' : 'insufficient_data',
            'model_version' => FynnScoreEngine::MODEL_VERSION,
            'scale' => ['min' => 0, 'max' => 100],

            'score' => $this->score,
            'band' => $this->band?->value,
            'band_label' => $this->band?->label(),
            'summary' => $this->hasScore
                ? $this->band->summary()
                : $this->insufficientDataReason,

            'dimensions' => array_map(
                fn (ScoreDimension $d) => $d->toArray(),
                $this->dimensions,
            ),
            'helping' => array_map(
                fn (ScoreDimension $d) => $d->name,
                $this->helping(),
            ),
            'needs_attention' => array_map(
                fn (ScoreDimension $d) => $d->name,
                $this->hurting(),
            ),
            'recommendations' => array_map(
                fn (ScoreRecommendation $r) => $r->toArray(),
                $this->recommendations,
            ),

            'metrics' => $this->metrics,
            'missing_requirements' => $this->missingRequirements,
            'inputs_updated_at' => $this->inputsUpdatedAt,

            'disclaimer' => FynnScoreEngine::DISCLAIMER,
            'bands' => FynnScoreEngine::bandDefinitions(),
        ];
    }
}
