<?php

namespace App\Domain\Credit;

/**
 * Turns one bureau's response into FynnEdge's own terms.
 *
 * The place where a bureau's vocabulary ends. It may rename fields, resolve
 * a score model and read a timestamp. It may not decide whether a customer
 * qualifies for anything, and it must not carry anything through that
 * FynnEdge has no use for: a report's addresses, employers, account numbers
 * and enquiry lists are read and dropped here, never stored.
 */
interface CreditNormalizer
{
    /**
     * @param  array<string, mixed>  $response
     *
     * @throws CreditNormalizationFailed when the response cannot be read
     */
    public function normalize(array $response): CreditProfile;
}
