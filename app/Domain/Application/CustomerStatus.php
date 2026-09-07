<?php

namespace App\Domain\Application;

use App\Domain\Lender\SubmissionOutcome;

/**
 * What the customer is told, from the two states that are actually true.
 *
 * The application's own status and its submission outcome stay separate
 * everywhere else in the system, and deliberately so. But a customer opening
 * their application needs one sentence, and working that sentence out from
 * two enums is exactly the kind of thing that gets done twice, differently,
 * on two screens. So it is done once, here.
 *
 * Two rules run through it.
 *
 * A provider state is only ever shown when a provider established it. The
 * application's status carries under_review, approved, declined and
 * disbursed only after a provider event wrote one, so reading them here is
 * safe — nothing else in the codebase can put them there.
 *
 * And an unconfirmed submission is never described as a failure. FynnEdge
 * not knowing whether an application arrived is a fact about FynnEdge, not
 * about the customer or their application, and the wording says so.
 */
final class CustomerStatus
{
    private function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $description,
    ) {}

    public static function of(
        ApplicationStatus $status,
        SubmissionOutcome $outcome,
    ): self {
        // A provider decision, wherever one exists, is the headline: it is
        // the most recent and most consequential thing anybody has said.
        $fromProvider = match ($status) {
            ApplicationStatus::UnderReview => new self(
                'under_review',
                'Application under review',
                'The provider has told us your application is being '
                    .'reviewed. They decide what happens next.',
            ),
            ApplicationStatus::ActionNeeded => new self(
                'action_needed',
                'Action needed',
                'The provider needs something from you before it can '
                    .'continue.',
            ),
            ApplicationStatus::Approved => new self(
                'approved',
                'Application approved',
                'The provider has approved your application.',
            ),
            ApplicationStatus::Declined => new self(
                'declined',
                'Application declined',
                'The provider has declined your application. This is their '
                    .'decision, not FynnEdge\'s.',
            ),
            ApplicationStatus::Disbursed => new self(
                'disbursed',
                'Loan disbursed',
                'The provider has told us the loan has been disbursed.',
            ),
            default => null,
        };

        if ($fromProvider !== null) {
            return $fromProvider;
        }

        if ($status === ApplicationStatus::Cancelled) {
            return new self(
                'cancelled',
                'Application cancelled',
                'You cancelled this application.',
            );
        }

        // No provider decision yet, so what the customer is told depends on
        // what became of the submission.
        return match ($outcome) {
            SubmissionOutcome::ProviderReceived => new self(
                'provider_received',
                'Application received',
                'The provider has confirmed it has your application. '
                    .'Nothing has been decided yet.',
            ),

            /*
             * The state this module exists to get right.
             *
             * Not failed, not rejected, and no invitation to try again —
             * a second submission could create a second application at a
             * provider that may already hold the first.
             */
            SubmissionOutcome::Unknown => new self(
                'submission_unconfirmed',
                'Submission status needs confirmation',
                'We could not confirm whether the provider received your '
                    .'application. Please do not submit it again until we '
                    .'know — contact support and we will check.',
            ),

            SubmissionOutcome::Simulated => new self(
                'simulated',
                'Simulated — not sent to any provider',
                'This was a simulated submission. No lender or provider '
                    .'received this application.',
            ),

            SubmissionOutcome::RejectedByGateway => new self(
                'rejected_by_gateway',
                'Not accepted for submission',
                'The provider did not accept this application for sending. '
                    .'That is not a decision about lending to you.',
            ),

            SubmissionOutcome::AcceptedForSubmission => new self(
                'accepted_for_submission',
                'Sent to the provider',
                'The provider has taken your application and has not yet '
                    .'confirmed it has it.',
            ),

            SubmissionOutcome::Submitting => new self(
                'submitting',
                'Sending',
                'Your application is being sent.',
            ),

            SubmissionOutcome::Failed => new self(
                'submission_failed',
                'Could not be sent',
                'Your application was not sent. Nothing reached a provider, '
                    .'and it is safe in FynnEdge.',
            ),

            SubmissionOutcome::Unavailable, SubmissionOutcome::NotAttempted => match ($status) {
                ApplicationStatus::Submitted => new self(
                    'ready_in_fynnedge',
                    'Ready in FynnEdge',
                    'Your application is complete here. It has not been '
                        .'sent to a provider.',
                ),
                default => new self(
                    'started',
                    'Application started',
                    'Your application is saved here and is not finished '
                        .'yet.',
                ),
            },
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
