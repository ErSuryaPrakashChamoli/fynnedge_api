<?php

namespace App\Domain\Provider;

use RuntimeException;

/**
 * A provider row that cannot be turned into FynnEdge's terms.
 *
 * Always a refusal, never a repair. The reason names the field and what was
 * wrong with it, so whoever owns the provider relationship can take it up
 * with them; it never carries the row itself, which may hold anything the
 * provider chose to send.
 */
class NormalizationFailed extends RuntimeException
{
    public function __construct(
        /** The provider's product identifier, when the row had a readable one. */
        public readonly ?string $productReference,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}
