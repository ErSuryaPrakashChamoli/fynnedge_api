<?php

namespace App\Domain\Notifications;

/**
 * Every kind of thing FynnEdge tells a customer, and nothing it cannot.
 *
 * Each case here is backed by a state FynnEdge genuinely holds. There is no
 * case for "your loan is nearly approved", because nothing computes that;
 * none for a lender's processing time, because no lender publishes one; and
 * none invented to give the notification centre something to display.
 *
 * Two properties are derived here rather than passed in by a caller, so
 * they cannot drift per call site:
 *
 *   category()       — where the notification belongs
 *   isTransactional() — whether it is service communication
 *
 * The second matters most. A transactional notification is one a customer
 * needs in order to understand or act on something they started; it is not
 * optional, and no preference may switch it off. Letting a caller decide
 * that per notification is how an "action required" message ends up
 * classified as promotional and silently dropped.
 */
enum NotificationType: string
{
    // --- Applications -----------------------------------------------------
    case ApplicationStarted = 'application_started';
    case SubmissionNeedsConfirmation = 'submission_needs_confirmation';
    case SubmissionStatusUpdated = 'submission_status_updated';
    case ApplicationReceived = 'application_received';
    case ApplicationUnderReview = 'application_under_review';
    case ActionRequired = 'action_required';
    case ApplicationApproved = 'application_approved';
    case ApplicationDeclined = 'application_declined';
    case ApplicationDisbursed = 'application_disbursed';
    case ApplicationCancelled = 'application_cancelled';

    // --- Documents --------------------------------------------------------
    case DocumentNeedsReview = 'document_needs_review';
    case DocumentReady = 'document_ready';
    case DocumentSafetyIssue = 'document_safety_issue';

    // --- Financial health -------------------------------------------------
    case FynnScoreChanged = 'fynnscore_changed';

    // --- Goals ------------------------------------------------------------
    case GoalMilestone = 'goal_milestone';

    /*
     * No system cases yet.
     *
     * The category exists because a service message — a change to terms, a
     * capability withdrawn — is a real thing FynnEdge may one day have to
     * send. Nothing in Modules 1–28 produces one, and inventing a case so
     * the enum looks complete would be inventing an event.
     */

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::ApplicationStarted,
            self::SubmissionNeedsConfirmation,
            self::SubmissionStatusUpdated,
            self::ApplicationReceived,
            self::ApplicationUnderReview,
            self::ActionRequired,
            self::ApplicationApproved,
            self::ApplicationDeclined,
            self::ApplicationDisbursed,
            self::ApplicationCancelled => NotificationCategory::Application,

            self::DocumentNeedsReview,
            self::DocumentReady,
            self::DocumentSafetyIssue => NotificationCategory::Document,

            self::FynnScoreChanged => NotificationCategory::FinancialHealth,

            self::GoalMilestone => NotificationCategory::Goal,
        };
    }

    /**
     * Whether this is service communication a customer cannot opt out of.
     *
     * Everything about an application they started is transactional: they
     * asked FynnEdge to do something, and the answer is not promotional
     * material. So are document problems, because a document that cannot be
     * accepted blocks the thing they are trying to do.
     *
     * A FynnScore change and a goal milestone are the two that are not.
     * Neither blocks anything, neither needs a decision, and a customer who
     * does not want them is not left unable to understand their own
     * application.
     */
    public function isTransactional(): bool
    {
        return match ($this) {
            self::FynnScoreChanged, self::GoalMilestone => false,
            default => true,
        };
    }

    /**
     * Whether a provider — not FynnEdge — is the author of what this says.
     *
     * Used to keep attribution honest in copy and to stop FynnEdge ever
     * appearing to have made a lending decision.
     */
    public function isProviderDecision(): bool
    {
        return in_array($this, [
            self::ApplicationApproved,
            self::ApplicationDeclined,
            self::ApplicationDisbursed,
        ], true);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
