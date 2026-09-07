<?php

namespace App\Domain\ProviderEvents;

use RuntimeException;

/**
 * The delivery did not prove it came from the provider.
 *
 * The reason stays server-side. A caller learns that authentication failed
 * and nothing about which check failed or why — an attacker probing a
 * signature scheme learns nothing from the error either.
 */
class ProviderEventAuthenticationFailed extends RuntimeException
{
    public function __construct(public readonly string $reason = 'invalid_signature')
    {
        parent::__construct('Provider event authentication failed.');
    }
}
