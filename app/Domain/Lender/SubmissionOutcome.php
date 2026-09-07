<?php

namespace App\Domain\Lender;

/**
 * What actually happened when FynnEdge tried to submit an application.
 *
 * This is a different axis from ApplicationStatus. That one says what the
 * customer did inside FynnEdge; this one says what an external provider did
 * or did not do with it. Collapsing them is the single mistake Module 26
 * exists to prevent: creating FE-2026-000001 is not a lender receiving an
 * application, and FynnEdge answering HTTP 200 is not a provider
 * acknowledging one.
 *
 * The distinction that matters most is Unknown against Failed. A network
 * failure after the request left is not a failure — it is an absence of
 * information, and telling a customer their application failed when it may
 * be sitting with a provider invites them to submit it twice.
 */
enum SubmissionOutcome: string
{
    /** No attempt has been made. */
    case NotAttempted = 'not_attempted';

    /** An attempt is in flight. */
    case Submitting = 'submitting';

    /**
     * A real gateway took it and has not yet confirmed the provider has it.
     *
     * Never set by a fixture: nothing that sends nowhere has accepted
     * anything for submission.
     */
    case AcceptedForSubmission = 'accepted_for_submission';

    /**
     * A provider acknowledged receipt.
     *
     * The only state that means an external party has the application, and
     * settable only from a provider's own acknowledgement.
     */
    case ProviderReceived = 'provider_received';

    /** The gateway refused it outright. Not a lending decision. */
    case RejectedByGateway = 'rejected_by_gateway';

    /** The attempt did not complete, and it is known not to have arrived. */
    case Failed = 'failed';

    /**
     * The request left and FynnEdge does not know what became of it.
     *
     * Never treated as a failure and never silently retried: the provider
     * may hold a copy.
     */
    case Unknown = 'unknown';

    /** No submission channel is configured. */
    case Unavailable = 'unavailable';

    /**
     * A simulated attempt against a fictional provider.
     *
     * Its own state rather than a flag on another, so no query, screen or
     * report can mistake it for a real one by forgetting to check a boolean.
     */
    case Simulated = 'simulated';

    public function label(): string
    {
        return match ($this) {
            self::NotAttempted => 'Not yet sent',
            self::Submitting => 'Sending',
            self::AcceptedForSubmission => 'Accepted for submission',
            self::ProviderReceived => 'Received by the provider',
            self::RejectedByGateway => 'Not accepted for submission',
            self::Failed => 'Could not be sent',
            self::Unknown => 'Delivery not confirmed',
            self::Unavailable => 'Submission not available',
            self::Simulated => 'Simulated — not sent to any provider',
        };
    }

    /**
     * Whether an external party demonstrably has this application.
     *
     * Exactly one state qualifies. Nothing else may be presented to a
     * customer as a provider having their application.
     */
    public function providerHasIt(): bool
    {
        return $this === self::ProviderReceived;
    }

    /** Whether another attempt would be a second external submission. */
    public function isInFlightOrSettled(): bool
    {
        return in_array($this, [
            self::Submitting,
            self::AcceptedForSubmission,
            self::ProviderReceived,
            self::Unknown,
            self::Simulated,
        ], true);
    }

    /**
     * Whether it is safe for the customer to try again.
     *
     * Unknown is deliberately absent. A retry after an unknown outcome may
     * create a second application at the provider, so it needs a
     * reconciliation path rather than a button.
     */
    public function isSafeToRetry(): bool
    {
        return in_array($this, [
            self::NotAttempted,
            self::Failed,
            self::RejectedByGateway,
            self::Unavailable,
        ], true);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $o) => $o->value, self::cases());
    }
}
