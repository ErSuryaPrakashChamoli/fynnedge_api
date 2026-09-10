<?php

namespace App\Services\Notifications;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Lender\SubmissionOutcome;
use App\Domain\Notifications\NotificationPolicy;
use App\Domain\ProviderEvents\ProviderEventType;
use App\Domain\Score\FynnScoreEngine;
use App\Domain\Score\ScoreBand;
use App\Domain\Support\SupportCaseStatus;
use App\Models\Document;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;
use App\Models\NotificationWatermark;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Support\Facades\DB;

/**
 * What the rest of FynnEdge calls when something happened.
 *
 * Call sites say what occurred — an application was started, a provider
 * said something, a document finished being read — and nothing else. They
 * do not decide whether it is worth telling anybody, do not write copy and
 * do not know that a notification table exists. The policy decides and the
 * recorder writes.
 *
 * That is the whole point of this class being thin: every method here is
 * two lines, so there is nowhere for a second opinion about what a customer
 * should be told to accumulate.
 */
class CustomerNotifications
{
    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly NotificationRecorder $recorder,
    ) {}

    // --- Applications -----------------------------------------------------

    public function applicationStarted(LoanApplication $application): void
    {
        $this->tell($application->user, $this->policy->applicationStarted($application));
    }

    public function applicationCancelled(LoanApplication $application): void
    {
        $this->tell($application->user, $this->policy->applicationCancelled($application));
    }

    public function submissionSettled(
        LoanApplication $application,
        SubmissionOutcome $outcome,
        int $attempt,
    ): void {
        $this->tell(
            $application->user,
            $this->policy->submissionSettled($application, $outcome, $attempt),
        );
    }

    /**
     * A provider event Module 28 actually applied.
     *
     * Called from inside the reconciliation transaction, which is what
     * gives this the ordering guarantees for free: an event that was stale
     * or contradicted never reaches here, so no notification can announce
     * "under review" after a disbursal, and no ordering logic is duplicated
     * in the notification layer.
     *
     * $idempotencySource is the provider event's own key, so a redelivered
     * event produces the same notification key and therefore one row.
     */
    public function providerEvent(
        ProviderEventType $type,
        LoanApplication $application,
        string $idempotencySource,
    ): void {
        $this->tell(
            $application->user,
            $this->policy->providerEvent($type, $application, $idempotencySource),
        );
    }

    // --- Documents --------------------------------------------------------

    public function documentReady(Document $document): void
    {
        $this->tell($document->user, $this->policy->documentReady($document));
    }

    public function documentNeedsReview(Document $document): void
    {
        $this->tell($document->user, $this->policy->documentNeedsReview($document));
    }

    public function documentSafetyIssue(Document $document): void
    {
        $this->tell($document->user, $this->policy->documentSafetyIssue($document));
    }

    // --- Financial health -------------------------------------------------

    /**
     * The customer's financial details changed, so their FynnScore may have.
     *
     * The band the customer was last told about is held in a watermark, and
     * the transition is decided under a row lock. That lock is what makes
     * two concurrent profile saves produce one notification rather than
     * two: both would otherwise read the old band and both would decide the
     * band had changed.
     *
     * The first time a score exists, nothing is sent. "Your FynnScore
     * changed" is not true of a score that has just come into being, and a
     * customer completing their profile does not need to be notified about
     * the screen they are looking at.
     */
    public function financialProfileChanged(User $user): void
    {
        // Scored exactly the way the score screen scores it, from the same
        // snapshot. A second scoring path here would be a second FynnScore.
        $profile = app(CustomerService::class)->financialProfileFor($user);
        $score = app(FynnScoreEngine::class)->score(
            app(FinancialEngine::class)->snapshot($profile),
        );

        if (! $score->hasScore || $score->band === null) {
            // No score, nothing to have changed. A profile that stops
            // producing a score does not produce a notification either:
            // the customer removed the details themselves.
            return;
        }

        $decision = DB::transaction(function () use ($user, $score) {
            $watermark = $this->lockedWatermark($user, NotificationWatermark::FYNN_SCORE_BAND);
            $band = $score->band;

            if ($watermark->value === $band->value) {
                return null;
            }

            $previous = ScoreBand::tryFrom((string) $watermark->value);

            $watermark->version = $watermark->version + 1;
            $watermark->value = $band->value;
            $watermark->save();

            if ($previous === null) {
                // First score. The watermark is now set, so the next real
                // move is a change FynnEdge can honestly describe.
                return null;
            }

            return $this->policy->scoreBandChanged($previous, $band, $watermark->version);
        });

        $this->tell($user, $decision);
    }

    // --- Goals ------------------------------------------------------------

    /**
     * A goal moved, so it may have passed a milestone.
     *
     * Only ever upward. A customer who reaches 50% and then edits their
     * saved amount down to 49% must not be told they are "25% of the way"
     * — they have gone backwards, and congratulating them on a milestone
     * they passed long ago reads as a system that is not paying attention.
     *
     * So the highest milestone already announced is held in a watermark and
     * only a strictly higher one is announced, decided under a row lock so
     * two concurrent edits cannot both decide the same one is new. The
     * unique index still backs it: the milestone is part of the key, so
     * even if this logic were wrong the customer could not be told twice.
     */
    public function goalProgressChanged(FinancialGoal $goal): void
    {
        $milestone = NotificationPolicy::milestoneFor($goal->progress());

        if ($milestone === null || $goal->user === null) {
            return;
        }

        $user = $goal->user;

        $decision = DB::transaction(function () use ($user, $goal, $milestone) {
            $watermark = $this->lockedWatermark(
                $user,
                'goal_milestone:'.$goal->getKey(),
            );

            if ((int) $watermark->value >= $milestone) {
                return null;
            }

            $watermark->value = (string) $milestone;
            $watermark->version = $watermark->version + 1;
            $watermark->save();

            return $this->policy->goalMilestone($goal, $milestone);
        });

        $this->tell($user, $decision);
    }

    // --- Support ----------------------------------------------------------

    /**
     * A support request moved.
     *
     * The policy decides whether that is worth saying — most transitions
     * are not. This says only that it moved and from where.
     */
    public function supportCaseMoved(SupportCase $case, SupportCaseStatus $from): void
    {
        $this->tell($case->user, $this->policy->supportCaseMoved($case, $from));
    }

    // --- Shared -----------------------------------------------------------

    private function lockedWatermark(User $user, string $key): NotificationWatermark
    {
        $existing = $user->notificationWatermarks()
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var NotificationWatermark $created */
        $created = $user->notificationWatermarks()->firstOrCreate(
            ['key' => $key],
            ['value' => '', 'version' => 0],
        );

        return $created;
    }

    private function tell(?User $user, $decision): void
    {
        if ($user === null || $decision === null) {
            return;
        }

        $this->recorder->recordAndDeliver($user, $decision);
    }
}
