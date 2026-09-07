<?php

namespace App\Domain\Lender;

use RuntimeException;

/**
 * The product has no mapping to anything the provider recognises.
 *
 * An operational error, deliberately not a customer-facing failure of their
 * application. Submitting anyway with a guessed product code would create an
 * application at a provider for a product nobody chose.
 */
class ProviderMappingMissing extends RuntimeException
{
    public function __construct(string $productId)
    {
        parent::__construct(
            "No provider product mapping exists for [{$productId}]."
        );
    }
}
