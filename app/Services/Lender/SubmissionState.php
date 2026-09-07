<?php

namespace App\Services\Lender;

use App\Domain\Lender\LenderSubmissionProvider;
use App\Domain\Lender\LenderSubmissionUnavailable;
use App\Domain\Lender\SubmissionOutcome;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;

/**
 * What FynnEdge can tell the customer about one application's submission.
 *
 * The shape of this payload is the point. `application` describes what the
 * customer did inside FynnEdge; `submission` describes what an external
 * provider did or did not do. They are two objects, not one status, because
 * a single field would eventually be read as though FynnEdge's own
 * acknowledgement were a provider's.
 */
final class SubmissionState
{
    private function __construct(
        public readonly LoanApplication $application,
        public readonly SubmissionOutcome $outcome,
        public readonly LenderSubmissionProvider $provider,
        public readonly ?ApplicationSubmission $submission = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {}

    public const NOT_CONFIGURED = 'lender_provider_unavailable';

    /*
     * One explanation per fact.
     *
     * "FynnEdge cannot send applications" and "your application failed" are
     * the two sentences this module exists to keep apart, so the first is
     * written once, here, and never paraphrased into the second.
     */
    public const NOT_CONFIGURED_MESSAGE = 'FynnEdge is not connected to an '
        .'application provider yet, so this application has not been sent '
        .'anywhere. It is saved here and nothing about it has failed.';

    public static function unavailable(
        LoanApplication $application,
        LenderSubmissionProvider $provider,
    ): self {
        return new self(
            application: $application,
            outcome: SubmissionOutcome::Unavailable,
            provider: $provider,
            reason: self::NOT_CONFIGURED,
            message: self::NOT_CONFIGURED_MESSAGE,
        );
    }

    public static function consentRequired(
        LoanApplication $application,
        LenderSubmissionProvider $provider,
    ): self {
        return new self(
            application: $application,
            outcome: SubmissionOutcome::NotAttempted,
            provider: $provider,
            reason: 'submission_consent_required',
            message: 'FynnEdge has not sent this application anywhere, and '
                .'will not until you allow it.',
        );
    }

    public static function notAttempted(
        LoanApplication $application,
        LenderSubmissionProvider $provider,
    ): self {
        return new self(
            application: $application,
            outcome: SubmissionOutcome::NotAttempted,
            provider: $provider,
        );
    }

    public static function of(
        LoanApplication $application,
        ApplicationSubmission $submission,
        LenderSubmissionProvider $provider,
    ): self {
        return new self(
            application: $application,
            outcome: $submission->outcome(),
            provider: $provider,
            submission: $submission,
            reason: $submission->failure_reason,
            message: $submission->failure_reason === null
                ? null
                : (new LenderSubmissionUnavailable($submission->failure_reason))
                    ->customerMessage(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $submission = $this->submission;
        $simulated = (bool) ($submission?->is_simulated ?? false);

        return [
            // What the customer did in FynnEdge. Their own reference, never
            // replaced by a provider's.
            'application' => [
                'id' => (string) $this->application->getKey(),
                'reference' => $this->application->reference,
                'status' => $this->application->status,
                'status_label' => $this->application->status()->label(),
            ],

            // What an external provider did. A separate object on purpose.
            'submission' => [
                'outcome' => $this->outcome->value,
                'outcome_label' => $this->outcome->label(),

                /*
                 * The single field that means an external party holds this.
                 *
                 * False for every simulated attempt by construction, and
                 * false for every state but one.
                 */
                'provider_received' => $submission?->providerHasIt() ?? false,

                /*
                 * True when nothing left FynnEdge.
                 *
                 * Server-owned, written from the provider adapter, carried
                 * unchanged to the app.
                 */
                'is_simulated' => $simulated,

                'attempt' => $submission?->attempt,
                'attempted_at' => $submission?->attempted_at?->toIso8601String(),

                // The provider's own reference, alongside FynnEdge's rather
                // than instead of it.
                'provider_reference' => $submission?->provider_reference,

                'reason' => $this->reason,
                'message' => $this->message,
                'rejection_reason' => $submission?->rejection_reason,

                /*
                 * Whether the customer may safely send it again.
                 *
                 * False after an unknown outcome: a provider may hold a
                 * copy, so a retry could create a second application.
                 */
                'can_retry' => $this->outcome->isSafeToRetry()
                    && $this->provider->isAvailable(),

                'needs_reconciliation' => $this->outcome === SubmissionOutcome::Unknown,
            ],

            // Where it would go, or went. Described by its actual role: a
            // gateway is not a lender, and saying so would misdescribe the
            // destination.
            'destination' => [
                'provider' => $this->provider->name(),
                'display_name' => $this->provider->displayName(),
                'role' => $this->provider->role(),
                'is_simulated' => $this->provider->isSimulated(),
            ],

            /*
             * The sentence that keeps this feature honest.
             */
            'disclaimer' => $simulated
                ? 'This was a simulated submission. No lender or provider '
                    .'received this application.'
                : 'Sending an application is not the same as it being '
                    .'accepted. FynnEdge will tell you only what the '
                    .'provider actually confirms.',
        ];
    }
}
