<?php

namespace App\Domain\Kyc;

use App\Models\CustomerProfile;

/**
 * Provider vocabulary in, FynnEdge vocabulary out.
 *
 * The only place a provider's field names, result codes and outcome
 * semantics are known. It also performs the comparison against the
 * customer's profile, because deciding what "matched" means is part of
 * translating a provider's answer into one FynnEdge can stand behind.
 */
interface KycNormalizer
{
    public function type(): VerificationType;

    /**
     * @param  array<string, mixed>  $response
     *
     * @throws VerificationNormalizationFailed
     */
    public function normalize(
        array $response,
        ?CustomerProfile $profile,
    ): CanonicalVerification;
}
