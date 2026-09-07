<?php

namespace App\Domain\Score;

/**
 * FynnScore bands, on the product's existing colour breakpoints (75/55/40).
 */
enum ScoreBand: string
{
    case Strong = 'strong';
    case Good = 'good';
    case NeedsAttention = 'needs_attention';
    case AtRisk = 'at_risk';

    public static function forScore(int $score): self
    {
        return match (true) {
            $score >= 75 => self::Strong,
            $score >= 55 => self::Good,
            $score >= 40 => self::NeedsAttention,
            default => self::AtRisk,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Strong',
            self::Good => 'Good',
            self::NeedsAttention => 'Needs Attention',
            self::AtRisk => 'At Risk',
        };
    }

    /** One sentence, decided by band alone so it cannot drift from the score. */
    public function summary(): string
    {
        return match ($this) {
            self::Strong => 'Your financial health looks strong.',
            self::Good => 'Your financial health looks good, with room to improve.',
            self::NeedsAttention => 'A few things need attention before you borrow.',
            self::AtRisk => 'Your finances are stretched. Steady them before taking on new debt.',
        };
    }
}
