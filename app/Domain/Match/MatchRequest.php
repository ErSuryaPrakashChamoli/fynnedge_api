<?php

namespace App\Domain\Match;

use App\Domain\Money;

/**
 * What the customer asked for on Loan Discovery.
 *
 * Held verbatim. FynnMatch never quietly rewrites the request to make a
 * product fit; where a product cannot serve the amount or tenure asked, that
 * is reported as a criterion, not smoothed over.
 */
final class MatchRequest
{
    public function __construct(
        public readonly Money $amount,
        public readonly int $tenureMonths,
        public readonly ?string $purpose = null,
        public readonly ?Money $preferredEmi = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount->toJsonNumber(),
            'tenure_months' => $this->tenureMonths,
            'purpose' => $this->purpose,
            'preferred_emi' => $this->preferredEmi?->toJsonNumber(),
        ];
    }
}
