<?php

namespace App\Domain\Credit;

use App\Models\User;

/**
 * One bureau's way of answering "what does this customer's credit look
 * like".
 *
 * Everything a bureau does differently — authentication, request signing,
 * its own response schema, its own error vocabulary — lives inside an
 * implementation of this and stops there.
 *
 * An adapter fetches. It does not decide whether the customer consented,
 * whether a product's requirement is met, or anything about a FynnScore.
 * Those are separate stages precisely so a bureau's peculiarities cannot
 * reach them.
 */
interface CreditBureauAdapter
{
    public function bureau(): CreditBureau;

    /**
     * Requests this customer's credit information.
     *
     * The caller has already established consent; an adapter must never be
     * reached without it.
     *
     * Returns the bureau's own response shape, which the normalizer turns
     * into a CreditProfile.
     *
     * @return array<string, mixed>
     *
     * @throws CreditBureauUnavailable when the bureau cannot answer
     */
    public function fetch(User $user): array;
}
