<?php

namespace App\Domain\ProviderEvents;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Lender\SubmissionOutcome;

/**
 * What a provider event is allowed to change, and when.
 *
 * The one place the mapping lives. A controller or a screen deciding for
 * itself would eventually disagree with this, and the customer would be
 * shown two answers about what a lender had said.
 *
 * Two rules run through everything here.
 *
 * Only a provider event moves an application into a provider state. Nothing
 * FynnEdge does — not submitting, not a 200, not documents becoming ready —
 * can produce under_review, approved, declined or disbursed.
 *
 * And an application only moves forward. Rank must strictly increase, so a
 * late event cannot undo a later one. That is why two contradictory terminal
 * decisions do not fight: the first recorded stands and the second is
 * ignored for an operator, rather than a customer's outcome flipping on
 * delivery order.
 */
final class StatusTransitionPolicy
{
    /**
     * The application status this event establishes, or null when the event
     * says nothing about the application's own progress.
     *
     * SubmissionReceived and SubmissionRejected are about the delivery, not
     * about what a provider decided, so neither moves the application.
     */
    public static function applicationStatusFor(ProviderEventType $type): ?ApplicationStatus
    {
        return match ($type) {
            ProviderEventType::SubmissionReceived,
            ProviderEventType::SubmissionRejected => null,

            ProviderEventType::ApplicationUnderReview => ApplicationStatus::UnderReview,
            ProviderEventType::ActionRequired => ApplicationStatus::ActionNeeded,
            ProviderEventType::ApplicationApproved => ApplicationStatus::Approved,
            ProviderEventType::ApplicationDeclined => ApplicationStatus::Declined,
            ProviderEventType::ApplicationDisbursed => ApplicationStatus::Disbursed,
            ProviderEventType::ApplicationCancelled => ApplicationStatus::Cancelled,
        };
    }

    /**
     * The submission outcome this event establishes, or null when it leaves
     * the delivery record alone.
     *
     * Every event but a rejection means the provider has the application —
     * a provider reviewing or deciding on something is telling FynnEdge it
     * holds it — so any of them can resolve an unknown outcome. That is the
     * only thing read into them.
     */
    public static function submissionOutcomeFor(ProviderEventType $type): ?SubmissionOutcome
    {
        return match ($type) {
            ProviderEventType::SubmissionRejected => SubmissionOutcome::RejectedByGateway,

            default => $type->impliesProviderHasApplication()
                ? SubmissionOutcome::ProviderReceived
                : null,
        };
    }

    /**
     * How far the application has already been taken by provider events.
     *
     * Derived from the application's current status rather than stored, so
     * there is no second copy of progress to fall out of step. Zero means no
     * provider has said anything yet.
     */
    public static function rankOf(ApplicationStatus $status, SubmissionOutcome $outcome): int
    {
        $fromStatus = match ($status) {
            ApplicationStatus::UnderReview => ProviderEventType::ApplicationUnderReview->rank(),
            ApplicationStatus::ActionNeeded => ProviderEventType::ActionRequired->rank(),
            ApplicationStatus::Approved => ProviderEventType::ApplicationApproved->rank(),
            ApplicationStatus::Declined => ProviderEventType::ApplicationDeclined->rank(),
            ApplicationStatus::Disbursed => ProviderEventType::ApplicationDisbursed->rank(),
            default => 0,
        };

        $fromOutcome = match ($outcome) {
            SubmissionOutcome::ProviderReceived => ProviderEventType::SubmissionReceived->rank(),
            SubmissionOutcome::RejectedByGateway => ProviderEventType::SubmissionRejected->rank(),
            default => 0,
        };

        return max($fromStatus, $fromOutcome);
    }

    /**
     * Whether this event moves things on.
     *
     * Strictly greater, deliberately. Equal rank covers two cases and both
     * want the same answer: a redelivery of the same stage, and a
     * contradictory decision at the same stage. Neither should overwrite
     * what is recorded.
     */
    public static function applies(
        ProviderEventType $type,
        ApplicationStatus $status,
        SubmissionOutcome $outcome,
    ): bool {
        return $type->rank() > self::rankOf($status, $outcome);
    }

    /**
     * Why an event was not applied, for the operational record.
     *
     * Never shown to a customer: an event FynnEdge did not act on is not
     * something they need to reason about.
     */
    public static function reasonForIgnoring(
        ProviderEventType $type,
        ApplicationStatus $status,
        SubmissionOutcome $outcome,
    ): string {
        $current = self::rankOf($status, $outcome);

        return match (true) {
            $type->rank() === $current => 'conflicting_or_repeated_stage',
            default => 'stale_event',
        };
    }

    private function __construct() {}
}
