<?php

namespace App\Services\Intelligence;

use App\Domain\Intelligence\FinancialMomentType;
use App\Domain\Intelligence\ObservationReason;
use App\Domain\Intelligence\ScoreChangePolicy;
use App\Domain\Notifications\CustomerText;
use App\Domain\ProviderEvents\ProviderEventType;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;
use App\Models\User;

/**
 * What the rest of FynnEdge calls when something happened to a customer's
 * money.
 *
 * Call sites say what occurred. This decides whether it is a moment, writes
 * the sentence from figures that are true now, and records it once.
 *
 * Two rules run through it.
 *
 * The copy is written at the time and stored. It would be tidier to store
 * only the type and derive the sentence on read — and it would be wrong,
 * because deriving it later means deriving it from today's state, which is
 * exactly the rewriting of history this module forbids. A moment saying a
 * score rose from 64 to 71 has to keep saying that after the score falls.
 *
 * And a moment is not a notification. Module 29 decides that separately,
 * from the event rather than from whether a timeline chose to draw it —
 * so nothing here creates one.
 */
class FinancialMoments
{
    public function __construct(
        private readonly MomentRecorder $recorder,
        private readonly ObservationRecorder $observations,
    ) {}

    /**
     * The customer changed their financial details.
     *
     * Two things can follow, and they are different. A score existing for
     * the first time is an arrival; a score moving is a change, and it can
     * only be stated against one FynnEdge actually observed.
     *
     * The observation is taken AFTER the comparison, so the reading being
     * compared against is genuinely the previous one.
     */
    public function financialProfileChanged(User $user, FinancialIntelligence $intelligence): void
    {
        $state = $intelligence->stateFor($user);

        if ($state->hasScore()) {
            $delta = $state->scoreDelta();

            if ($delta === null) {
                /*
                 * No previous observation to compare with.
                 *
                 * If this is the first score, that is worth recording as an
                 * arrival. If it is not — the customer was observed before
                 * without a score, or across model versions — nothing is
                 * claimed, because nothing is known.
                 */
                if ($state->previous === null || $state->previous->score === null) {
                    $this->recorder->record(
                        user: $user,
                        type: FinancialMomentType::ScoreAvailable,
                        title: 'Your FynnScore is ready',
                        description: 'It is '.$state->score->score.', in the '
                            .strtolower((string) $state->score->band?->label())
                            .' range. It is FynnEdge\'s own reading of the '
                            .'details you entered, not a credit score.',
                        // Once, ever. A customer's score becomes available
                        // once however many times they edit their profile.
                        idempotencyKey: 'score:available',
                        rule: 'first observed score',
                        evidence: 'score '.$state->score->score,
                    );
                }
            } elseif (ScoreChangePolicy::isMeaningful($delta)) {
                $previous = $state->previous->score;
                $now = $state->score->score;

                $this->recorder->record(
                    user: $user,
                    type: $delta > 0
                        ? FinancialMomentType::ScoreImproved
                        : FinancialMomentType::ScoreDeclined,
                    title: $delta > 0
                        ? 'Your FynnScore improved'
                        : 'Your FynnScore fell',
                    // The figures as they were. This sentence is the reason
                    // moments store their copy rather than deriving it.
                    description: 'It went from '.$previous.' to '.$now.'.',
                    // Keyed on the transition, so the same move recorded
                    // twice is one moment and a later move is its own.
                    idempotencyKey: 'score:'.$previous.'->'.$now.':'
                        .$state->previous->observedAt->timestamp,
                    rule: 'abs(score - previous_observed_score) >= '
                        .ScoreChangePolicy::POINTS,
                    evidence: 'score '.$previous.' -> '.$now,
                );
            }
        }

        // Only now. Comparing against an observation taken a line earlier
        // would be comparing the customer with themselves.
        $this->observations->observe($user, ObservationReason::FinancialProfileChanged);
    }

    /** A goal was created. */
    public function goalCreated(FinancialGoal $goal): void
    {
        if ($goal->user === null) {
            return;
        }

        $this->recorder->record(
            user: $goal->user,
            type: FinancialMomentType::GoalCreated,
            title: 'You set a goal',
            description: 'You are aiming at '.$this->goalName($goal).'.',
            idempotencyKey: 'goal:'.$goal->getKey().':created',
            rule: 'goal created',
        );

        $this->observations->observe($goal->user, ObservationReason::GoalChanged);
    }

    /**
     * A goal passed a milestone.
     *
     * The milestone is part of the key, so crossing 50% is one moment
     * however many times the screen is refreshed — and a customer who dips
     * below and returns is not congratulated twice.
     */
    public function goalMilestone(FinancialGoal $goal, int $percent): void
    {
        if ($goal->user === null) {
            return;
        }

        $reached = $percent >= 100;

        $this->recorder->record(
            user: $goal->user,
            type: $reached
                ? FinancialMomentType::GoalReached
                : FinancialMomentType::GoalMilestone,
            title: $reached ? 'You reached your goal' : 'A milestone worth noting',
            description: $reached
                ? 'You have saved everything you set out to for '
                    .$this->goalName($goal).'.'
                : 'You are '.$percent.'% of the way to '.$this->goalName($goal).'.',
            idempotencyKey: 'goal:'.$goal->getKey().':milestone:'.$percent,
            rule: 'goal.progress crossed '.$percent.'%',
            evidence: 'goal '.$goal->getKey().' at '.$percent.'%',
        );
    }

    public function applicationStarted(LoanApplication $application): void
    {
        if ($application->user === null) {
            return;
        }

        $this->recorder->record(
            user: $application->user,
            type: FinancialMomentType::ApplicationStarted,
            title: 'You started an application',
            description: 'For '.$this->productName($application)
                .'. Nothing has been sent anywhere yet.',
            idempotencyKey: 'application:'.$application->getKey().':started',
            rule: 'application created',
            contextType: 'application',
            contextReference: $application->reference,
        );

        $this->observations->observe(
            $application->user,
            ObservationReason::ApplicationMilestone,
        );
    }

    public function applicationCancelled(LoanApplication $application): void
    {
        if ($application->user === null) {
            return;
        }

        $this->recorder->record(
            user: $application->user,
            type: FinancialMomentType::ApplicationCancelled,
            title: 'You cancelled an application',
            description: 'Your application for '.$this->productName($application)
                .' was cancelled.',
            idempotencyKey: 'application:'.$application->getKey().':cancelled',
            rule: 'application cancelled by customer',
            contextType: 'application',
            contextReference: $application->reference,
        );
    }

    /**
     * A provider said something about an application.
     *
     * Reached only from Module 28's applied branch, so a stale or
     * contradicted event never becomes a moment — and no moment here can
     * exist without a provider event behind it. FynnEdge never infers
     * "submitted" into "received".
     */
    public function providerEvent(
        ProviderEventType $type,
        LoanApplication $application,
        string $idempotencySource,
    ): void {
        if ($application->user === null) {
            return;
        }

        $key = 'application:'.$application->getKey().':provider:'.$idempotencySource;
        $product = $this->productName($application);

        [$momentType, $title, $description] = match ($type) {
            ProviderEventType::SubmissionReceived => [
                FinancialMomentType::ApplicationSubmitted,
                'The provider received your application',
                'Your application for '.$product.' reached the provider.',
            ],

            // The decision is the provider's, and so is the wording. No
            // reason is invented, because FynnEdge was not given one.
            ProviderEventType::ApplicationApproved => [
                FinancialMomentType::ApplicationDecided,
                'The provider approved your application',
                'A decision was made on your application for '.$product.'.',
            ],
            ProviderEventType::ApplicationDeclined => [
                FinancialMomentType::ApplicationDecided,
                'The provider declined your application',
                'A decision was made on your application for '.$product.'.',
            ],
            ProviderEventType::ApplicationDisbursed => [
                FinancialMomentType::ApplicationDisbursed,
                'The provider disbursed your loan',
                // Nothing about an amount, a date or an account. FynnEdge
                // was told that it happened and nothing more — and nothing
                // here alters the customer's income, savings or score.
                'The funds for your '.$product.' were released.',
            ],

            // Under review, action required, a gateway rejection and a
            // provider cancellation are all real states, and none of them
            // is a moment in somebody's financial life. They belong on the
            // application's own timeline, where they already are.
            default => [null, null, null],
        };

        if ($momentType === null) {
            return;
        }

        $this->recorder->record(
            user: $application->user,
            type: $momentType,
            title: $title,
            description: $description,
            idempotencyKey: $key,
            rule: 'provider event '.$type->value,
            contextType: 'application',
            contextReference: $application->reference,
        );

        $this->observations->observe(
            $application->user,
            ObservationReason::ApplicationMilestone,
        );
    }

    private function goalName(FinancialGoal $goal): string
    {
        return CustomerText::forCopy(
            $goal->title,
            'your goal',
        );
    }

    private function productName(LoanApplication $application): string
    {
        $name = trim((string) $application->product_name);

        return $name === '' ? 'your loan' : $name;
    }
}
