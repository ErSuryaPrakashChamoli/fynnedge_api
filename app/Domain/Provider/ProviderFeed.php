<?php

namespace App\Domain\Provider;

use Carbon\CarbonInterface;

/**
 * What one retrieval from a provider produced.
 *
 * The rows are still in the provider's own shape — this is the last point at
 * which provider terminology exists. A normalizer turns each into a
 * CanonicalProductInput, and nothing further in FynnEdge sees a provider's
 * field names.
 */
final class ProviderFeed
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly string $providerCode,
        public readonly ProviderFeedKind $kind,

        /**
         * When the data was actually obtained. Not a timestamp the provider
         * put in its own payload — that is when they say they prepared it,
         * which is a different fact and often a much older one.
         */
        public readonly CarbonInterface $retrievedAt,

        public readonly array $rows,
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }
}
