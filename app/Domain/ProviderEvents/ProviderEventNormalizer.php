<?php

namespace App\Domain\ProviderEvents;

/**
 * A provider's own event body into FynnEdge's vocabulary.
 *
 * The only place a provider's event names, status codes and payload shape
 * are known. Everything above this line sees a CanonicalProviderEvent or
 * nothing.
 */
interface ProviderEventNormalizer
{
    public function providerCode(): string;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ProviderEventMalformed
     */
    public function normalize(array $payload): CanonicalProviderEvent;
}
