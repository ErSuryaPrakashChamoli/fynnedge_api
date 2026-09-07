<?php

namespace App\Domain\Match;

/**
 * How well a product appears to fit — a category, not a score.
 *
 * FynnEdge does not define weights for the matching criteria, so inventing a
 * numeric ranking would be dressing a guess up as arithmetic. The category is
 * derived from criterion outcomes alone, which is reproducible and explicable.
 */
enum MatchCategory: string
{
    /** Every documented rule checked, all satisfied. */
    case Strong = 'strong_match';

    /** Documented rules satisfied, but something could not be checked. */
    case Good = 'good_match';

    /** Rules satisfied, with something the customer should weigh first. */
    case Review = 'review';

    /** A documented rule is not satisfied. */
    case NotAMatch = 'not_a_match';

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Strong Match',
            self::Good => 'Good Match',
            self::Review => 'Worth a Look',
            self::NotAMatch => 'Not a Match',
        };
    }

    /** Ordering rank; lower sorts first. */
    public function rank(): int
    {
        return match ($this) {
            self::Strong => 0,
            self::Good => 1,
            self::Review => 2,
            self::NotAMatch => 3,
        };
    }

    public function isViable(): bool
    {
        return $this !== self::NotAMatch;
    }
}
