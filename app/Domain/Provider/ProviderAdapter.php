<?php

namespace App\Domain\Provider;

/**
 * One provider's way of handing FynnEdge its product data.
 *
 * Deliberately two methods. Everything a provider does differently —
 * authentication, pagination, payload shape, which fields it calls what —
 * lives inside an implementation of this and stops there.
 *
 * An adapter fetches. It does not normalize, validate, persist or decide
 * anything about a customer; those are separate stages precisely so a
 * provider's peculiarities cannot reach them.
 */
interface ProviderAdapter
{
    /**
     * The provider's stable code, matching the catalogue's provider identity.
     */
    public function providerCode(): string;

    /**
     * Retrieves the provider's current product data.
     *
     * Throws ProviderUnavailable when the provider cannot be reached or
     * answers unusably. It must never return an empty successful feed to
     * paper over a failure: in a full feed, empty means "we offer nothing",
     * which would withdraw every product the provider has.
     */
    public function fetch(): ProviderFeed;
}
