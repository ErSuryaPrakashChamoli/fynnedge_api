<?php

namespace App\Domain\Catalog;

use RuntimeException;

/**
 * A product record FynnEdge cannot reason about.
 *
 * Raised at the catalogue boundary rather than anywhere downstream. Once a
 * LoanProduct exists, every engine that reads it — FynnMatch, FynnTwin, the
 * application snapshot — can rely on its ranges running the right way and
 * its scales being within their own bounds.
 *
 * The message names the product and every problem found, because whoever
 * reads it is fixing a feed, not a customer seeing a screen.
 */
class MalformedProductException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(
        public readonly string $productId,
        public readonly array $problems,
    ) {
        parent::__construct(sprintf(
            'Product "%s" cannot be used: %s.',
            $productId === '' ? '(no id)' : $productId,
            implode('; ', $problems),
        ));
    }
}
