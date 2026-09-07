<?php

namespace App\Domain\ProviderEvents;

/**
 * What a provider is telling FynnEdge.
 *
 * Provider-neutral by design: a real provider's own event names are
 * translated into these by its normalizer, so no vendor vocabulary reaches
 * the application domain.
 *
 * The rank is the ordering policy. Provider events arrive out of order, and
 * FynnEdge has no sequence number to rely on because no provider contract
 * exists to supply one — so the strongest mechanism actually available is
 * the meaning of the events themselves. An event applies only when its rank
 * is strictly higher than what the application has already reached.
 *
 * That direction is deliberate: it can only ever move an application
 * forward. A late "under review" cannot un-approve anything, and a stray
 * "declined" cannot overturn a disbursal.
 */
enum ProviderEventType: string
{
    /** The provider confirms it has the application. */
    case SubmissionReceived = 'submission_received';

    /** The provider refused to take it. Not a lending decision. */
    case SubmissionRejected = 'submission_rejected';

    case ApplicationUnderReview = 'application_under_review';

    /** The provider needs something from the customer. */
    case ActionRequired = 'action_required';

    case ApplicationApproved = 'application_approved';
    case ApplicationDeclined = 'application_declined';
    case ApplicationDisbursed = 'application_disbursed';

    /** The provider closed it at their end. */
    case ApplicationCancelled = 'application_cancelled';

    /**
     * How far along the application this event says things are.
     *
     * Equal ranks do not displace each other. Two contradictory terminal
     * decisions — approved and declined — cannot both be true, and the
     * safe answer is to keep the one already recorded and mark the second
     * ignored for an operator to look at, rather than to flip a customer's
     * outcome on delivery order.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SubmissionReceived => 10,
            self::SubmissionRejected => 20,
            self::ApplicationUnderReview => 30,
            self::ActionRequired => 40,
            self::ApplicationApproved, self::ApplicationDeclined => 50,
            self::ApplicationCancelled => 50,
            self::ApplicationDisbursed => 60,
        };
    }

    /**
     * Whether this event is a provider stating it holds the application.
     *
     * Receipt is the obvious one. The rest are not inference from unrelated
     * events: a provider that says it is reviewing an application, or has
     * decided on one, is telling FynnEdge it has that application. That is
     * the only thing read into them, and it is what lets an unknown
     * submission outcome be resolved by any of these.
     */
    public function impliesProviderHasApplication(): bool
    {
        return $this !== self::SubmissionRejected;
    }

    /** What the customer's timeline says when this arrives. */
    public function timelineLabel(): string
    {
        return match ($this) {
            self::SubmissionReceived => 'Application received',
            self::SubmissionRejected => 'Not accepted for submission',
            self::ApplicationUnderReview => 'Application under review',
            self::ActionRequired => 'Action needed from you',
            self::ApplicationApproved => 'Application approved',
            self::ApplicationDeclined => 'Application declined',
            self::ApplicationDisbursed => 'Loan disbursed',
            self::ApplicationCancelled => 'Application closed by the provider',
        };
    }

    /**
     * The detail line. States who is speaking, because every one of these
     * is a provider's word and not FynnEdge's assessment.
     */
    public function timelineDetail(): string
    {
        return match ($this) {
            self::SubmissionReceived => 'The provider confirmed it has your '
                .'application.',
            self::SubmissionRejected => 'The provider did not accept this '
                .'application for submission. This is not a lending '
                .'decision.',
            self::ApplicationUnderReview => 'The provider has told us your '
                .'application is being reviewed.',
            self::ActionRequired => 'The provider needs something from you '
                .'before it can continue.',
            self::ApplicationApproved => 'The provider has approved your '
                .'application.',
            self::ApplicationDeclined => 'The provider has declined your '
                .'application.',
            self::ApplicationDisbursed => 'The provider has told us the loan '
                .'has been disbursed.',
            self::ApplicationCancelled => 'The provider has closed this '
                .'application at their end.',
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
