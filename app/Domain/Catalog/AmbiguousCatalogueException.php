<?php

namespace App\Domain\Catalog;

use RuntimeException;

/**
 * A product whose terms on a given day cannot be established.
 *
 * Two versions in force at once is the dangerous case: silently taking the
 * newest, or the first in the list, would price a loan from a record nobody
 * chose. There is no safe default here, so the product is excluded from
 * customer-facing decisions and whoever maintains the catalogue is told
 * exactly which dates collide.
 */
class AmbiguousCatalogueException extends RuntimeException
{
    public function __construct(
        public readonly string $productId,
        string $problem,
    ) {
        parent::__construct("Product \"{$productId}\": {$problem}.");
    }
}
