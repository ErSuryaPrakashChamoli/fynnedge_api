<?php

namespace App\Domain\Provider;

/**
 * What a feed claims to contain.
 *
 * This distinction decides whether a product's absence means anything. In a
 * full feed, a product that is not there is a product the provider no longer
 * offers. In a delta feed it means nothing at all — most products are absent
 * from most deltas.
 *
 * Getting it wrong in the permissive direction would withdraw a customer's
 * options because a provider sent a partial update.
 */
enum ProviderFeedKind: string
{
    /**
     * Everything the provider currently offers. Omission is meaningful:
     * a product not in the feed is no longer offered.
     */
    case Full = 'full';

    /**
     * Only what changed. Omission means nothing, so nothing is withdrawn
     * on the strength of it.
     */
    case Delta = 'delta';

    public function guaranteesCompleteness(): bool
    {
        return $this === self::Full;
    }
}
