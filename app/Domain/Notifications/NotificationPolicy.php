<?php

namespace App\Domain\Notifications;

use App\Domain\Lender\SubmissionOutcome;
use App\Domain\ProviderEvents\ProviderEventType;
use App\Domain\Score\ScoreBand;
use App\Domain\Support\SupportCaseStatus;
use App\Models\Document;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;
use App\Models\SupportCase;

/**
 * Whether a thing that happened is a thing to tell a customer, and what to
 * say.
 *
 *     INTERNAL EVENT  ≠  CUSTOMER NOTIFICATION
 *
 * FynnEdge records far more than a customer needs to read. Every submission
 * attempt, every provider event including the ones it ignores, every scan
 * field, every profile edit. A notification for each would be a log, and a
 * log is not communication.
 *
 * So this class is the only place that decides. It returns a decision or
 * null, and null is the ordinary answer: a stale provider event, a score
 * that moved three points, a goal edited by a rupee. Nothing else in
 * FynnEdge composes notification copy — not a controller, not a model, not
 * a job — because copy scattered across call sites is copy nobody can
 * review, and this is the text a customer reads about their own money.
 *
 * Three rules shape the wording.
 *
 * A provider's decision is attributed to the provider. FynnEdge never
 * approves, declines or disburses anything, and a sentence that reads as if
 * it did is wrong even when the state behind it is right.
 *
 * Nothing is claimed that FynnEdge cannot show. No disbursal date, no
 * amount it was not told, no processing time no lender published, no
 * reason a provider did not give.
 *
 * An unknown submission is stated as unknown. It is the one case where the
 * honest sentence is uncomfortable, and it is the case where a reassuring
 * one would cause a customer to submit a second application.
 */
final class NotificationPolicy
{
    // --- Applications -----------------------------------------------------

    /**
     * The customer started an application.
     *
     * Worth saying once: it is the record that FynnEdge holds an
     * application, and the link back to it. It is not a submission and the
     * copy must not read like one.
     */
    public function applicationStarted(LoanApplication $application): NotificationDecision
    {
        return NotificationDecision::of(
            type: NotificationType::ApplicationStarted,
            title: 'Application started',
            body: 'Your application for '.$this->productName($application)
                .' is saved in FynnEdge. It has not been sent anywhere yet.',
            idempotencyKey: 'application:'.$application->getKey().':started',
            deepLink: DeepLink::application($application->getKey()),
        );
    }

    public function applicationCancelled(LoanApplication $application): NotificationDecision
    {
        return NotificationDecision::of(
            type: NotificationType::ApplicationCancelled,
            title: 'Application cancelled',
            body: 'Your application for '.$this->productName($application)
                .' has been cancelled. Nothing further will happen with it.',
            idempotencyKey: 'application:'.$application->getKey().':cancelled',
            deepLink: DeepLink::application($application->getKey()),
        );
    }

    /**
     * The result of FynnEdge trying to send an application to a provider.
     *
     * Only two outcomes are worth a notification. Unknown, because the
     * customer must not act on it as though it failed. And a gateway
     * rejection, because it is a definite answer that nothing was received
     * and it is FynnEdge's to fix.
     *
     * Everything else is either in flight — the provider's own events will
     * say what happened — or already covered. AcceptedForSubmission
     * deliberately produces nothing: "we handed it over" is FynnEdge's
     * internal milestone, and telling a customer their application was
     * received when only a gateway acknowledged it is the exact confusion
     * Module 26 exists to prevent.
     */
    public function submissionSettled(
        LoanApplication $application,
        SubmissionOutcome $outcome,
        int $attempt,
    ): ?NotificationDecision {
        $key = 'application:'.$application->getKey().':submission:'.$attempt.':';

        return match ($outcome) {
            SubmissionOutcome::Unknown => NotificationDecision::of(
                type: NotificationType::SubmissionNeedsConfirmation,
                title: 'Your submission needs confirming',
                /*
                 * The sentence Module 26 requires and Module 28 can
                 * eventually resolve. It says what is not known, tells them
                 * the one thing they might otherwise get wrong — sending it
                 * again — and offers support. It does not apologise for a
                 * failure, because no failure has been established.
                 */
                body: 'We could not confirm whether the provider received '
                    .'your application. Please do not submit it again — '
                    .'that could create a second application. We are '
                    .'checking, and support can help if you need it.',
                idempotencyKey: $key.'unknown',
                deepLink: DeepLink::application($application->getKey()),
            ),

            SubmissionOutcome::RejectedByGateway => NotificationDecision::of(
                type: NotificationType::SubmissionStatusUpdated,
                title: 'Your application was not sent',
                body: 'The provider\'s systems did not accept the '
                    .'submission, so nothing was received. Your application '
                    .'is safe in FynnEdge and you can try again.',
                idempotencyKey: $key.'rejected',
                deepLink: DeepLink::application($application->getKey()),
            ),

            default => null,
        };
    }

    /**
     * A provider said something about an application.
     *
     * Reached only for events Module 28 actually applied — a stale or
     * contradicted event never gets here, so there is no ordering logic in
     * this class and no way for it to announce "under review" after a
     * disbursal.
     *
     * SubmissionRejected returns null: it is the gateway outcome above,
     * already told, and saying it twice from two layers is how a customer
     * ends up believing two things went wrong.
     */
    public function providerEvent(
        ProviderEventType $type,
        LoanApplication $application,
        string $idempotencySource,
    ): ?NotificationDecision {
        $link = DeepLink::application($application->getKey());
        $key = 'application:'.$application->getKey().':provider:'.$idempotencySource;
        $product = $this->productName($application);

        return match ($type) {
            ProviderEventType::SubmissionReceived => NotificationDecision::of(
                type: NotificationType::ApplicationReceived,
                title: 'The provider received your application',
                body: 'Your application for '.$product.' has reached the '
                    .'provider. They will review it and let us know.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            ProviderEventType::ApplicationUnderReview => NotificationDecision::of(
                type: NotificationType::ApplicationUnderReview,
                title: 'Your application is being reviewed',
                body: 'The provider is reviewing your application for '
                    .$product.'. There is nothing you need to do right now.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            ProviderEventType::ActionRequired => NotificationDecision::of(
                type: NotificationType::ActionRequired,
                title: 'Your application needs something from you',
                body: 'The provider needs something more before they can '
                    .'continue. Open your application to see what to do '
                    .'next.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            // Attribution first, in the title, so it survives a truncated
            // push notification. FynnEdge approves nothing.
            ProviderEventType::ApplicationApproved => NotificationDecision::of(
                type: NotificationType::ApplicationApproved,
                title: 'The provider approved your application',
                body: 'The provider has approved your application for '
                    .$product.'. Open it to see what they said and what '
                    .'happens next.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            ProviderEventType::ApplicationDeclined => NotificationDecision::of(
                type: NotificationType::ApplicationDeclined,
                title: 'The provider declined your application',
                /*
                 * No reason, because FynnEdge was not given one it could
                 * stand behind, and a guessed reason about somebody's
                 * creditworthiness is the worst thing this file could
                 * invent. No consolation either, and no suggestion to
                 * apply elsewhere: that would be advice nobody computed.
                 */
                body: 'The provider has declined your application for '
                    .$product.'. This is their decision, not FynnEdge\'s.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            ProviderEventType::ApplicationDisbursed => NotificationDecision::of(
                type: NotificationType::ApplicationDisbursed,
                // No amount, no date, no account. FynnEdge is told that it
                // happened, not what was paid or when it lands.
                title: 'The provider has disbursed your loan',
                body: 'The provider has released the funds for your '
                    .$product.'. They will confirm the details with you '
                    .'directly.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            ProviderEventType::ApplicationCancelled => NotificationDecision::of(
                type: NotificationType::ApplicationCancelled,
                title: 'The provider cancelled your application',
                body: 'The provider has cancelled your application for '
                    .$product.'. Support can help if this is unexpected.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            ProviderEventType::SubmissionRejected => null,
        };
    }

    // --- Documents --------------------------------------------------------

    /**
     * A reading of a document finished and the customer can look at it.
     *
     * FynnScan produces evidence, never truth, so the copy asks them to
     * review rather than announcing what was found. Nothing extracted is
     * quoted: a salary figure in a notification is a salary figure on a
     * lock screen.
     */
    public function documentReady(Document $document): NotificationDecision
    {
        return NotificationDecision::of(
            type: NotificationType::DocumentReady,
            title: 'Your document reading is ready',
            body: 'FynnScan has finished reading '
                .$this->documentName($document)
                .'. Open it to check what it found before anything is saved.',
            idempotencyKey: 'document:'.$document->getKey().':scan_ready',
            deepLink: DeepLink::document($document->getKey()),
        );
    }

    /**
     * Something about a document needs the customer.
     *
     * One sentence for two situations — a reading that could not be trusted,
     * and a confirmed document whose file changed — because from where the
     * customer sits they are the same job: look at this document again.
     */
    public function documentNeedsReview(Document $document): NotificationDecision
    {
        return NotificationDecision::of(
            type: NotificationType::DocumentNeedsReview,
            title: 'One of your documents needs attention',
            body: $this->documentName($document)
                .' needs a look before it can be used. Open it to see what '
                .'to check.',
            idempotencyKey: 'document:'.$document->getKey().':needs_review',
            deepLink: DeepLink::document($document->getKey()),
        );
    }

    /**
     * A document could not be accepted on safety grounds.
     *
     * Says that it was not accepted and what to do. Says nothing about
     * scanners, vendors, verdicts, storage or hashes: a customer cannot act
     * on any of it, and an attacker probing what FynnEdge checks learns
     * from every word of it.
     */
    public function documentSafetyIssue(Document $document): NotificationDecision
    {
        return NotificationDecision::of(
            type: NotificationType::DocumentSafetyIssue,
            title: 'A document could not be accepted',
            body: 'FynnEdge could not accept '.$this->documentName($document)
                .'. Please add it again, or use a different file. Support '
                .'can help if it keeps happening.',
            idempotencyKey: 'document:'.$document->getKey().':safety',
            deepLink: DeepLink::vault(),
        );
    }

    // --- Financial health -------------------------------------------------

    /**
     * The customer's FynnScore moved enough to be worth saying.
     *
     * The threshold is a band change, and the rationale is that the band is
     * what FynnEdge actually shows a customer. A score moving 61 → 64 shows
     * them nothing different; moving out of one band into another changes
     * the words on their own score screen. Any numeric threshold would have
     * been a number this file invented; a band is a distinction the product
     * already makes.
     *
     * $version is the watermark's own counter, so a customer who moves down
     * a band and back up is told twice, while a duplicate calculation of the
     * same transition is told once.
     *
     * No figure travels. The customer opens the screen that holds it.
     */
    public function scoreBandChanged(
        ScoreBand $from,
        ScoreBand $to,
        int $version,
    ): NotificationDecision {
        $improved = $this->bandRank($to) > $this->bandRank($from);

        return NotificationDecision::of(
            type: NotificationType::FynnScoreChanged,
            title: $improved ? 'Your FynnScore improved' : 'Your FynnScore changed',
            /*
             * FynnScore is FynnEdge's own reading of the profile the
             * customer entered. It is not a credit score, not a bureau
             * score and not a lender's view, and the copy must never let it
             * be mistaken for one.
             */
            body: 'Your FynnScore is now in the '.strtolower($to->label())
                .' range. It is FynnEdge\'s own reading of the details you '
                .'entered, not a credit score. Open it to see what moved.',
            idempotencyKey: 'fynn_score:v'.$version,
            deepLink: DeepLink::fynnScore(),
        );
    }

    // --- Goals ------------------------------------------------------------

    /**
     * A goal passed a milestone the customer would recognise.
     *
     * Quarters, and reaching it. These come from saved ÷ target, which the
     * customer enters and FinancialEngine already derives — nothing is
     * awarded, nothing is invented, and a goal that is edited back below a
     * milestone and up again is not announced twice.
     */
    public function goalMilestone(FinancialGoal $goal, int $percent): ?NotificationDecision
    {
        if (! in_array($percent, self::GOAL_MILESTONES, true)) {
            return null;
        }

        // Their own label for their own goal, made safe to echo back.
        $named = CustomerText::forCopy($goal->title, 'your goal');

        return NotificationDecision::of(
            type: NotificationType::GoalMilestone,
            title: $percent >= 100 ? 'You reached your goal' : 'Goal milestone reached',
            body: $percent >= 100
                ? 'You have saved everything you set out to for '.$named.'.'
                : 'You are '.$percent.'% of the way to '.$named.'.',
            idempotencyKey: 'goal:'.$goal->getKey().':milestone:'.$percent,
            deepLink: DeepLink::goals(),
        );
    }

    /**
     * The points worth mentioning on the way to a goal.
     *
     * Quarters. Not because a quarter is significant in itself, but because
     * four messages over the life of a goal is a rhythm a customer will
     * tolerate and a message per rupee saved is not.
     *
     * @var list<int>
     */
    public const GOAL_MILESTONES = [25, 50, 75, 100];

    /** The highest milestone a proportion has reached, or null. */
    public static function milestoneFor(float $progress): ?int
    {
        $percent = (int) floor($progress * 100);
        $reached = null;

        foreach (self::GOAL_MILESTONES as $milestone) {
            if ($percent >= $milestone) {
                $reached = $milestone;
            }
        }

        return $reached;
    }

    // --- Support ----------------------------------------------------------

    /**
     * A support request moved, and the customer needs to know.
     *
     * Two states out of six. `waiting_for_customer`, because a request that
     * needs them and does not say so simply stalls; and `resolved`, because
     * they asked a question and the answer arriving is the point.
     *
     * Everything else returns null. A case being acknowledged is FynnEdge
     * confirming something the customer watched happen; a case moving to
     * `in_progress` is internal movement. Neither is an event in their life,
     * and a notification for each would turn the notification centre into a
     * log of somebody else's workflow.
     *
     * Nothing the customer wrote travels. The subject is their own words
     * about their own money, and a notification is read on a lock screen.
     */
    public function supportCaseMoved(
        SupportCase $case,
        SupportCaseStatus $from,
    ): ?NotificationDecision {
        $to = $case->status();
        $link = DeepLink::supportCase($case->getKey());

        // The transition, not the destination: a case that is already
        // resolved and gets re-saved has not resolved twice.
        $key = 'support:'.$case->getKey().':'.$from->value.'->'.$to->value;

        return match ($to) {
            SupportCaseStatus::WaitingForCustomer => NotificationDecision::of(
                type: NotificationType::SupportWaitingForCustomer,
                title: 'Your help request needs you',
                body: 'We need some information before we can continue with '
                    .'request '.$case->reference.'. Open it to see what we '
                    .'have asked.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            SupportCaseStatus::Resolved => NotificationDecision::of(
                type: NotificationType::SupportResolved,
                title: 'Your help request has been resolved',
                body: 'Request '.$case->reference.' has been resolved. Open '
                    .'it to see what we found.',
                idempotencyKey: $key,
                deepLink: $link,
            ),

            default => null,
        };
    }

    // --- Shared -----------------------------------------------------------

    /**
     * Bands, worst to best.
     *
     * Stated explicitly rather than taken from the order of the enum's
     * cases, which runs best-first and would silently invert this.
     */
    private function bandRank(ScoreBand $band): int
    {
        return match ($band) {
            ScoreBand::AtRisk => 0,
            ScoreBand::NeedsAttention => 1,
            ScoreBand::Good => 2,
            ScoreBand::Strong => 3,
        };
    }

    /**
     * The product the customer applied for, from the application's own
     * snapshot rather than today's catalogue.
     */
    private function productName(LoanApplication $application): string
    {
        $name = trim((string) $application->product_name);

        return $name === '' ? 'your loan' : $name;
    }

    /**
     * How a document is referred to in copy.
     *
     * Its kind, not its name. A display name is the customer's own text and
     * a filename is whatever their phone produced; neither is needed to
     * make the sentence useful, and the kind is what tells them which
     * document to go and look at.
     */
    private function documentName(Document $document): string
    {
        return match ($document->type()->value) {
            'identity' => 'your identity document',
            'income' => 'your income proof',
            'bank' => 'your bank statement',
            'loan' => 'your loan statement',
            'property' => 'your property document',
            default => 'your document',
        };
    }
}
