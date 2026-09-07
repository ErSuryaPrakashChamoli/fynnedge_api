<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * A percentage or multiple held to two decimal places, as an integer number
 * of hundredths — the same reasoning as Money.
 */
final class Ratio
{
    private function __construct(public readonly int $hundredths) {}

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * part / whole as a percentage, rounded half up to two decimals.
     * A zero or negative whole yields zero rather than a division error:
     * "what share of no income is this EMI" has no meaningful answer, and
     * every caller would otherwise have to guard.
     */
    public static function percentOf(Money $part, Money $whole): self
    {
        if ($whole->paise <= 0) {
            return self::zero();
        }

        // ×10000 gives percent to two decimals in one integer step.
        return new self(Money::divideHalfUp($part->paise * 10000, $whole->paise));
    }

    /**
     * How many times `unit` fits into `total`, to two decimals. Used for
     * emergency-fund months.
     */
    public static function timesOver(Money $total, Money $unit): self
    {
        if ($unit->paise <= 0) {
            return self::zero();
        }

        return new self(Money::divideHalfUp($total->paise * 100, $unit->paise));
    }

    public function toJsonNumber(): int|float
    {
        return $this->hundredths % 100 === 0
            ? intdiv($this->hundredths, 100)
            : round($this->hundredths / 100, 2);
    }
}
