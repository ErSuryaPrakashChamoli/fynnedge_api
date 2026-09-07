<?php

namespace App\Domain\Catalog;

/**
 * Where a product record came from.
 *
 * The distinction that matters to a customer is only sample versus live;
 * the rest matters to whoever has to answer "where did this rate come from"
 * when a figure is disputed.
 */
enum ProductSourceType: string
{
    /** Written by FynnEdge to demonstrate the product. Not a real offer. */
    case Sample = 'sample';

    /** Entered by a person from a provider's published material. */
    case Manual = 'manual';

    /** Delivered by a provider as a file or feed. */
    case ProviderFeed = 'provider_feed';

    /** Read from a provider's API. */
    case ProviderApi = 'provider_api';

    /**
     * Whether a product from this source describes something a customer
     * could really be offered.
     *
     * Only Sample is not live. Manual entry is somebody reading a real
     * provider's real published terms — slower than a feed, not less real.
     */
    public function isLive(): bool
    {
        return $this !== self::Sample;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
