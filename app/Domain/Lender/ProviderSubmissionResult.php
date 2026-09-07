<?php

namespace App\Domain\Lender;

use Carbon\CarbonInterface;

/**
 * What a provider actually reported.
 *
 * There is deliberately no `approved` here, and no way to express one. An
 * approval is an underwriting decision; this object describes a delivery.
 * Module 28 will carry provider decisions when a provider exists that can
 * make them.
 */
final class ProviderSubmissionResult
{
    public function __construct(
        public readonly SubmissionOutcome $outcome,
        public readonly string $provider,
        public readonly bool $isSimulated,
        public readonly CarbonInterface $at,

        /**
         * The provider's own reference, when it gave one.
         *
         * Operational metadata. Not a secret, not a token, and never the
         * FynnEdge reference — which it does not replace.
         */
        public readonly ?string $providerReference = null,

        /** Why the gateway refused, when it did. Canonical, not a vendor code. */
        public readonly ?string $rejectionReason = null,

        /** What a customer can be told, in the provider's own terms. */
        public readonly string $detail = '',
    ) {}

    /**
     * A simulated result.
     *
     * Locked to SubmissionOutcome::Simulated and a null provider reference
     * by construction. A fixture cannot express provider receipt, because
     * a fictional provider has received nothing.
     */
    public static function simulated(
        string $provider,
        CarbonInterface $at,
        string $detail,
    ): self {
        return new self(
            outcome: SubmissionOutcome::Simulated,
            provider: $provider,
            isSimulated: true,
            at: $at,
            providerReference: null,
            detail: $detail,
        );
    }

    public function providerHasIt(): bool
    {
        return $this->outcome->providerHasIt() && ! $this->isSimulated;
    }
}
