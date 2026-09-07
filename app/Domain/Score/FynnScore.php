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
