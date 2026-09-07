<?php

namespace App\Domain\Kyc;

/**
 * How one identity field compared against the customer's profile.
 *
 * Three outcomes, and the third is the one that keeps the other two honest:
 * FynnEdge cannot compare what it does not hold, and saying so is different
 * from saying the values differ.
 */
enum MatchStatus: string
{
    case Matched = 'matched';
    case Mismatched = 'mismatched';
    case UnableToCompare = 'unable_to_compare';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Matched',
            self::Mismatched => 'Did not match',
            self::UnableToCompare => 'Could not be compared',
        };
    }
}
