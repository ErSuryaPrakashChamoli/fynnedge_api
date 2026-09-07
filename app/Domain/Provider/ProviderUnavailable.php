<?php

namespace App\Domain\Provider;

use RuntimeException;
use Throwable;

/**
 * The provider could not be reached, or answered unusably.
 *
 * Raised rather than returning an empty feed, because the two mean opposite
 * things: an empty full feed says the provider offers nothing and would
 * withdraw its whole catalogue.
 *
 * The message names the provider and the kind of failure. It never carries
 * the response body — a provider error page can contain anything, including
 * the credentials that were sent to it.
 */
class ProviderUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Provider '{$providerCode}' is unavailable: {$reason}.",
            previous: $previous,
        );
    }
}
