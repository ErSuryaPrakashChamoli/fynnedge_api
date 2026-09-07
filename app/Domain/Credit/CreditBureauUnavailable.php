<?php

namespace App\Domain\Credit;

use RuntimeException;
use Throwable;

/**
 * The bureau could not answer.
 *
 * Raised rather than returning a score, because there is no safe stand-in: a
 * previous report is not this one, a default is a fabrication, and assuming
 * either good or bad credit decides something about a customer on no
 * evidence.
 *
 * The message is for FynnEdge's own diagnosis. What a customer sees is that
 * their credit information could not be retrieved — never a provider's error
 * code, never a fault string, never anything the bureau put in a body.
 */
class CreditBureauUnavailable extends RuntimeException
{
    public function __construct(
        public readonly CreditBureau $bureau,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Bureau '{$bureau->value}' could not answer: {$reason}.",
            previous: $previous,
        );
    }
}
