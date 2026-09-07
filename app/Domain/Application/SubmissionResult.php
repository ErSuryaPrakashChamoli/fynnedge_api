<?php

namespace App\Domain\Application;

use Carbon\CarbonInterface;

/**
 * What a submission gateway reported back.
 *
 * [isSimulated] is not decoration. A submission that never left FynnEdge must
 * be distinguishable from one a provider genuinely received, everywhere it is
 * displayed, stored or tested.
 */
final class SubmissionResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ApplicationStatus $status,
        public readonly string $gateway,
        public readonly bool $isSimulated,
        public readonly CarbonInterface $at,
        /** The provider's own reference, where a provider gave one. */
        public readonly ?string $providerReference = null,
        public readonly string $detail = '',
        /** Why it was refused, when it was. */
        public readonly ?string $failureReason = null,
    ) {}

    public static function failed(
        string $gateway,
        bool $isSimulated,
        CarbonInterface $at,
        string $reason,
    ): self {
        return new self(
            accepted: false,
            // A refused submission leaves the application where it was.
            status: ApplicationStatus::Started,
            gateway: $gateway,
            isSimulated: $isSimulated,
            at: $at,
            detail: '',
            failureReason: $reason,
        );
    }
}
