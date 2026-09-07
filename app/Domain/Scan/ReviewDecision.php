<?php

namespace App\Domain\Scan;

/**
 * What a customer said about an extracted value.
 */
enum ReviewDecision: string
{
    /** The document was read correctly. */
    case Confirmed = 'confirmed';

    /** It was read wrongly, and here is the right figure. */
    case Edited = 'edited';

    /** It is wrong, and the customer is not supplying a replacement. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed by you',
            self::Edited => 'Corrected by you',
            self::Rejected => 'Rejected by you',
        };
    }

    /** Whether this decision leaves a value the customer stands behind. */
    public function yieldsValue(): bool
    {
        return $this !== self::Rejected;
    }
}
