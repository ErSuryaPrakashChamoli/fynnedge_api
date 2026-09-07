<?php

namespace App\Domain\Lender;

/**
 * The submission provider FynnEdge has: none.
 *
 * No lender, gateway, aggregator or DSA integration has been arranged, so
 * this reports that plainly rather than simulating one.
 *
 * It also stands in when a driver names a real provider whose adapter or
 * credentials are absent. That case refuses rather than falling back to the
 * fixture, deliberately: a customer told their application reached a lender
 * when it reached nothing is the worst failure this product could produce.
 */
class UnavailableLenderSubmissionProvider implements LenderSubmissionProvider
{
    public const REASON = 'lender_provider_unavailable';

    public function isAvailable(): bool
    {
        return false;
    }

    public function name(): ?string
    {
        return null;
    }

    public function displayName(): ?string
    {
        return null;
    }

    public function role(): string
    {
        return 'none';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function submit(
        LenderApplicationPayload $payload,
        string $idempotencyKey,
    ): ProviderSubmissionResult {
        throw new LenderSubmissionUnavailable(
            LenderSubmissionUnavailable::NOT_CONFIGURED,
            'no lender submission provider is configured',
        );
    }
}
