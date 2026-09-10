<?php

namespace App\Domain\Intelligence;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\FinancialPosition;
use App\Domain\Lender\SubmissionOutcome;
use App\Domain\Score\ScoreBand;
use App\Domain\Score\ScoreRecommendation;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;

/**
 * Deciding the one thing worth doing next.
 *
 * A ladder, evaluated top to bottom, first match wins:
 *
 *   1. blocked            stuck and told not to retry
 *   2. missing input      nothing can be worked out without it
 *   3. active decision    an application already in flight
 *   4. goal progress      a target with a date approaching
 *   5. financial health   a picture worth looking at
 *   6. getting started    no goal, nothing in flight
 *
 * The first rung is the one that matters most, and it exists because of a
 * failure it prevents. A customer whose submission was never confirmed has
 * been told, in as many words, not to submit again — and a home screen that
 * simultaneously suggested exploring another loan would be the product
 * contradicting itself about the most consequential thing it had said to
 * them. Truth and priority have to agree.
 *
 * Nothing here reads a score to decide, weights anything, or personalises
 * beyond choosing which existing state to point at. This changes what
 * FynnEdge suggests — never what it approves, prices or scores.
 */
class NextActionRules
{
    /**
     * The most useful thing, and nothing else.
     *
     * The head of the plan. Home shows one action, so Home asks for one —
     * and gets the same first item the plan starts with, because it is the
     * same ladder either way.
     */
    public function evaluate(FinancialState $state): ?NextBestAction
    {
        return $this->plan($state)[0] ?? null;
    }

    /**
     * A short plan, in the order the rules produced it.
     *
     * Every rung is asked, and the ones with something to say become the
     * plan. That makes the plan and the single next action the same
     * reasoning rather than two engines that could disagree about the same
     * customer.
     *
     * Capped at three. A customer given nine things to do has been given a
     * backlog, not a plan, and will do none of them.
     *
     * Deliberately allowed to be short — or empty. A customer whose
     * finances are in order and who has nothing pending should be told
     * that, not handed an invented task to keep them opening the app.
     *
     * @return list<NextBestAction>
     */
    public function plan(FinancialState $state): array
    {
        $rungs = [
            // Stuck, and told not to retry.
            $this->blockedBySubmission($state),

            // Nothing can be worked out without these.
            $this->completeProfile($state),
            $this->addYourName($state),

            // Their outgoings exceed their income, which may be a figure
            // that has moved rather than a life that has.
            $this->negativeSurplus($state),

            // Something already in flight.
            $this->activeApplication($state),

            // A target with a date approaching.
            $this->goalDeadline($state),

            // Their financial picture, against references the product
            // already uses.
            $this->emergencyBuffer($state),
            $this->improveScore($state),

            // Something they explored and could pick up again.
            $this->reviewDecision($state),

            // Getting started.
            $this->createGoal($state),
            $this->exploreOptions($state),
            $this->reviewScore($state),
        ];

        $plan = [];
        $priority = 0;

        foreach ($rungs as $action) {
            if ($action === null) {
                continue;
            }

            $plan[] = $action->atPriority(++$priority * 10);

            if (count($plan) === self::MAX_ACTIONS) {
                break;
            }
        }

        return $plan;
    }

    /** A plan, not a backlog. */
    public const MAX_ACTIONS = 3;

    /**
     * Stuck, and told not to retry.
     *
     * Above everything, including an incomplete profile: a customer in this
     * state cannot proceed and cannot safely act on their own, and no other
     * suggestion is worth more to them than resolving it.
     */
    private function blockedBySubmission(FinancialState $state): ?NextBestAction
    {
        $stuck = $state->applications->first(
            fn (LoanApplication $a) => $a->latestSubmission()?->outcome() === SubmissionOutcome::Unknown
        );

        if ($stuck === null) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ResolveSubmissionStatus,
            title: 'Your application needs attention',
            // Says the same thing the application screen says, because it
            // is the same fact. Nothing here suggests trying again.
            reason: 'We could not confirm whether the provider received '
                .$stuck->reference.'.',
            actionLabel: 'Open application',
            actionRoute: '/applications/'.$stuck->getKey(),
            rule: 'submission.outcome == unknown',
        );
    }

    private function completeProfile(FinancialState $state): ?NextBestAction
    {
        if ($state->hasFinancialProfile()) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::CompleteProfile,
            title: 'Add your financial details',
            reason: 'Without them FynnEdge cannot work out your FynnScore or '
                .'what you can afford.',
            actionLabel: 'Add your details',
            actionRoute: '/financial-profile',
            rule: 'snapshot.has_income == false',
        );
    }

    /**
     * The figures are in but FynnEdge has no name to greet them by.
     *
     * Below the profile and below being blocked, because it costs the
     * customer nothing to leave it — but above everything else, because it
     * is a thing they can finish in one tap and it is asked for rather
     * than a name being assumed.
     */
    private function addYourName(FinancialState $state): ?NextBestAction
    {
        if ($state->hasName) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::AddYourName,
            title: 'Tell us your name',
            reason: 'So FynnEdge can address you properly.',
            actionLabel: 'Add your name',
            actionRoute: '/profile',
            rule: 'customer.name absent',
        );
    }

    private function activeApplication(FinancialState $state): ?NextBestAction
    {
        /** @var LoanApplication|null $application */
        $application = $state->activeApplications()->sortByDesc('id')->first();

        if ($application === null) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ReviewApplication,
            title: 'Check your application',
            reason: $application->reference.' is still open.',
            actionLabel: 'Open application',
            actionRoute: '/applications/'.$application->getKey(),
            rule: 'application.status.is_terminal == false',
        );
    }

    private function goalDeadline(FinancialState $state): ?NextBestAction
    {
        /** @var FinancialGoal|null $goal */
        $goal = $state->goals
            ->filter(fn (FinancialGoal $g) => $g->progress() < 1.0)
            ->filter(fn (FinancialGoal $g) => $g->monthsRemaining() > 0
                && $g->monthsRemaining() <= InsightRules::GOAL_DEADLINE_MONTHS)
            ->sortBy(fn (FinancialGoal $g) => $g->monthsRemaining())
            ->first();

        if ($goal === null) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ReviewGoal,
            title: 'Your target date is close',
            reason: 'There are about '.$goal->monthsRemaining()
                .' months left on one of your goals.',
            actionLabel: 'Review your goals',
            actionRoute: '/goals',
            rule: 'goal.months_remaining <= '.InsightRules::GOAL_DEADLINE_MONTHS,
        );
    }

    /**
     * More going out than coming in.
     *
     * Stated as what FynnEdge has recorded rather than as a fact about
     * their life, because the commonest cause is a figure that moved
     * without the profile being updated. Nothing here diagnoses, blames or
     * suggests a way to cover the gap — and in particular nothing suggests
     * borrowing to cover it, which is the shape this rung exists to keep
     * the planner away from.
     */
    private function negativeSurplus(FinancialState $state): ?NextBestAction
    {
        if (! $state->hasFinancialProfile() || ! $state->snapshot->surplus->isNegative()) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ReviewProfile,
            title: 'Check your recorded figures',
            reason: 'What FynnEdge has recorded going out each month is more '
                .'than what it has recorded coming in. If any of those '
                .'figures have changed, updating them gives a more accurate '
                .'picture.',
            actionLabel: 'Review your figures',
            actionRoute: '/financial-profile',
            rule: 'snapshot.surplus is negative',
        );
    }

    /**
     * Their savings against the reference the product already uses.
     *
     * Six months, from FinancialEngine — the same figure the emergency-fund
     * planner and the FynnScore buffer component measure against. Nothing
     * new is defined here, and the sentence says it is FynnEdge's reference
     * rather than a rule anyone else set.
     *
     * It offers a review of their own figures, not a product. A thin buffer
     * is not a reason to borrow, and a planner that suggested borrowing to
     * fix one would be selling.
     */
    private function emergencyBuffer(FinancialState $state): ?NextBestAction
    {
        if (! $state->hasFinancialProfile()) {
            return null;
        }

        $position = FinancialPosition::from($state->snapshot);

        /*
         * Silent when the buffer question has no answer.
         *
         * A customer with nothing recorded as going out has no number of
         * months to cover, and the ratio's zero was being read as "less
         * than a month" — telling somebody their savings were thin when
         * FynnEdge had simply not been told what they spend. The position
         * object makes the difference between a low figure and an absent
         * one, and this rung now asks for the former.
         */
        if (! $position->bufferIsBelowReference()) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ReviewBuffer,
            title: 'Look at your savings buffer',
            // The same sentence the plan header carries, from the same
            // reading of the same snapshot.
            reason: (string) $position->bufferStatement(),
            actionLabel: 'Plan a buffer',
            actionRoute: '/fynnlab/emergency-fund',
            rule: 'emergency_months < '.FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS,
        );
    }

    /**
     * The score component furthest from its reference.
     *
     * From ScoreRecommendation, which the scoring engine already produces:
     * it carries the metric, its current value, the reference value, the
     * component's current score and what the engine calculates that
     * component would score at the reference. Nothing here recalculates any
     * of that, and nothing predicts.
     *
     * The wording is deliberately conditional. "Would" is a statement about
     * the engine's arithmetic; "will" would be a statement about the
     * customer's future, which FynnEdge cannot make.
     *
     * Silent when the score is already strong. A customer whose finances
     * are in order does not need an improvement task invented for them.
     */
    private function improveScore(FinancialState $state): ?NextBestAction
    {
        if (! $state->hasScore() || $state->score->band === ScoreBand::Strong) {
            return null;
        }

        $recommendation = collect($state->score->recommendations)
            ->sortByDesc(fn (ScoreRecommendation $r) => $r->delta())
            ->first();

        if ($recommendation === null || $recommendation->delta() <= 0) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ImproveScore,
            title: 'See what moves your FynnScore',
            reason: 'Of the three things FynnScore measures, '
                .strtolower($recommendation->title).' is furthest from '
                ."FynnEdge's reference.",
            actionLabel: 'See your FynnScore',
            actionRoute: '/fynn-score',
            rule: 'largest score recommendation delta',
        );
    }

    /**
     * Something they explored and could pick up again.
     *
     * From Module 32's recorded decisions only — a simulation they ran or
     * a comparison they asked for. Never reconstructed, and never described
     * as something they wanted: "you ran a simulation" is a fact, "you were
     * considering this loan" is an inference about somebody's mind.
     */
    private function reviewDecision(FinancialState $state): ?NextBestAction
    {
        if (! $state->hasRecordedDecision) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ReviewDecision,
            title: 'Look again at what you explored',
            reason: 'You have something in your history. You can see the '
                .'figures FynnEdge used at the time and how it was worked '
                .'out.',
            actionLabel: 'What you explored',
            actionRoute: '/decisions',
            rule: 'decision history is not empty',
        );
    }

    private function createGoal(FinancialState $state): ?NextBestAction
    {
        if ($state->goals->isNotEmpty()) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::CreateGoal,
            title: 'Set something to aim at',
            reason: 'A goal gives FynnEdge something to measure your options '
                .'against.',
            actionLabel: 'Add a goal',
            actionRoute: '/goals',
            rule: 'goals.empty',
        );
    }

    /**
     * A goal, and nothing yet done about it.
     *
     * Above reviewing a score, because it is the step that moves them
     * toward the thing they said they wanted. Reviewing a number is
     * something to do when there is nothing to do.
     */
    private function exploreOptions(FinancialState $state): ?NextBestAction
    {
        if ($state->applications->isNotEmpty()) {
            return null;
        }

        /*
         * Only to somebody who has actually set a goal.
         *
         * The rung's own rule said `goals.any` and its sentence says "see
         * what suits the goal you set" — and the code checked neither, so a
         * customer who had entered nothing at all was offered loan
         * discovery against a goal that did not exist. Nothing FynnEdge
         * knows about that customer could make anything suit them.
         */
        if ($state->goals->isEmpty()) {
            return null;
        }

        /*
         * Nor before FynnEdge knows what they can afford.
         *
         * Without an income there is no affordability, no FynnScore and
         * nothing FynnMatch can measure a product against — so "see what
         * suits" is a claim with nothing behind it, and the profile rung
         * above is the step that actually helps.
         */
        if (! $state->hasFinancialProfile()) {
            return null;
        }

        /*
         * Not to somebody whose outgoings already exceed their income.
         *
         * This was found by probing rather than by a test: the plan for
         * such a customer read "look at your buffer, see what moves your
         * score, explore your options" — the last of which points at loan
         * discovery. Suggesting borrowing to a customer who cannot cover
         * their current life is the exact sales bias this planner must not
         * have, and the earlier test passed only because the three-item cap
         * happened to hide it.
         */
        if ($state->hasFinancialProfile() && $state->snapshot->surplus->isNegative()) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ExploreOptions,
            title: 'Explore your options',
            reason: 'See what suits the goal you set.',
            actionLabel: 'Explore',
            actionRoute: '/loan-discovery',
            rule: 'goals.any and applications.empty',
        );
    }

    /**
     * The last rung: nothing needs them.
     *
     * Reached by a customer with a goal who has already been through an
     * application and has none in flight. There is no urgent step, so the
     * honest suggestion is to look at where they stand rather than to
     * manufacture one.
     */
    private function reviewScore(FinancialState $state): ?NextBestAction
    {
        if (! $state->hasScore()) {
            return null;
        }

        return new NextBestAction(
            type: NextActionType::ReviewScore,
            title: 'Review your financial progress',
            reason: 'Nothing needs you right now.',
            actionLabel: 'See your FynnScore',
            actionRoute: '/fynn-score',
            rule: 'has_score and nothing pending',
        );
    }
}
