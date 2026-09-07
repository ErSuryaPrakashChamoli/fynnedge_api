<?php

namespace App\Domain\ProviderEvents;

use RuntimeException;

/**
 * The provider authenticated and sent something FynnEdge cannot read.
 *
 * Refused rather than guessed at. The plausible reading of a half-understood
 * loan-application event is that something advanced, and advancing a
 * customer's application on a guess is the failure this whole module exists
 * to avoid.
 */
class ProviderEventMalformed extends RuntimeException
{
    public function __construct(public readonly string $reason = 'malformed_event')
    {
        parent::__construct('Provider event could not be read.');
    }
}
