<?php

namespace App\Domain\Kyc;

use App\Models\Document;

/**
 * The verification provider FynnEdge has: none.
 *
 * No identity verification service has been selected, so this reports that
 * plainly rather than simulating one. A provider returning an invented
 * "verified" would be indistinguishable, to the customer and to every screen
 * downstream, from a real check of their real document — and a verification
 * is precisely the kind of claim that must never be invented.
 *
 * It also stands in when a driver names a real provider whose adapter or
 * credentials are absent. That case refuses rather than falling back to the
 * fixture, deliberately: silently substituting synthetic results for a
 * misconfigured production integration is the worst failure available here.
 */
class UnavailableKycVerificationProvider implements KycVerificationProvider
{
    public const REASON = 'verification_not_configured';

    public function isAvailable(): bool
    {
        return false;
    }

    public function name(): ?string
    {
        return null;
    }

    public function isSample(): bool
    {
        return false;
    }

    public function supports(VerificationType $type): bool
    {
        return false;
    }

    public function verify(
        Document $document,
        string $bytes,
        VerificationType $type,
    ): array {
        throw new VerificationUnavailable(
            VerificationUnavailable::PROVIDER_NOT_CONFIGURED,
            'no verification provider is configured',
        );
    }
}
