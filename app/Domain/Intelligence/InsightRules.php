<?php

namespace App\Domain\Intelligence;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Lender\SubmissionOutcome;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;

/**
 * What FynnEdge can honestly tell a customer about their own money.
 *
 * Deterministic. Every rule is a comparison against a figure an engine
 * produced, and every one returns null when it cannot prove what it would
 * say. There is no model, no weighting and no confidence: an insight either
 * follows from the data or it does not appear.
 *
 * The rule that shapes the whole class is the one about history. FynnEdge
 * kept none until Module 31, so for every customer today `previous` is null
 * — and every comparison here checks that rather than treating "I have
 * nothing to compare with" as "nothing changed". A trend manufactured from
 * one observation is the single most damaging thing this file could do,
 * because a customer would believe it and act on it.
 *
 * Thresholds are existing product definitions, not new numbers: the 45%
 * affordability reference and the six-month emergency target both come from
 * FinancialEngine. Module 31 introduces one threshold of its own, for how
 * far a score must move, and it lives on ScoreChangePolicy with the
 * reasoning beside it.
 */
class InsightRules
{
    /** Months at which a goal's deadline is worth raising. */
    public const GOAL_DEADLINE_MONTHS = 3;

    /** @return list<Insight> */
    public function evaluate(FinancialState $state): array
    {
        $insights = array_values(array_filter([
            $this->profileIncomplete($state),
            $this->applicationNeedsAttention($state),
            $this->applicationUpdate($state),
            $this->goalDeadline($state),
            $this->financialHealth($state),
        ]));

        // Ordered by what it costs to ignore, never by when it appeared.
        usort($insights, fn (Insight $a, Insight $b) => $a->priority() <=> $b->priority());

        return $insights;
    }

    // --- Profile --------------------------------------------------------------

    private function profileIncomplete(FinancialState $state): ?Insight
    {
        if ($state->hasFinancialProfile()) {
            return null;
        }

        return new Insight(
            type: InsightType::ProfileIncomplete,
            source: InsightSource::FinancialEngine,
            title: 'Add your financial details',
            body: 'FynnEdge cannot work anything out without them — not your '
                .'FynnScore, not what you can afford, and not which options '
                .'suit you.',
            actionLabel: 'Add your details',
            actionRoute: '/financial-profile',
            rule: 'snapshot.has_income == false',
            evidence: 'monthly_income absent',
        );
    }

    // --- Applications ---------------------------------------------------------

    /**
     * The one state where the customer is genuinely stuck.
     *
     * FynnEdge sent an application and never heard back, and Module 28 has
     * told them not to send it again. Nothing else on this screen matters
     * as much, so it sorts first and the next action agrees with it.
     */
    private function applicationNeedsAttention(FinancialState $state): ?Insight
    {
        $stuck = $state->applications->first(
            fn (LoanApplication $a) => $a->latestSubmission()?->outcome() === SubmissionOutcome::Unknown
        );

        if ($stuck === null) {
            return null;
        }

        return new Insight(
            type: InsightType::ApplicationAttention,
            source: InsightSource::Application,
            title: 'Your application needs attention',
            body: 'We could not confirm whether the provider received '
                .$stuck->reference.'. Please do not submit it again — we can '
                .'check what happened to it.',
            actionLabel: 'Get help',
            actionRoute: '/applications/'.$stuck->getKey(),
            rule: 'submission.outcome == unknown',
            evidence: 'application '.$stuck->reference,
        );
    }

    /**
     * A provider decision worth surfacing.
     *
     * Only from a status a provider actually set. FynnEdge never writes
     * under_review, approved, declined or disbursed itself, so reading them
     * here is safe — and nothing infers "submitted" into "received".
     */
    private function applicationUpdate(FinancialState $state): ?Insight
    {
        $decided = $state->applications->first(
            fn (LoanApplication $a) => $a->status()->isProviderDecision()
        );

        if ($decided === null) {
            return null;
        }

        return new Insight(
            type: InsightType::ApplicationUpdate,
            source: InsightSource::ProviderEvent,
            title: 'There is news on your application',
            // No reason and no next step invented: a provider decision is
            // the provider's, and FynnEdge does not know why.
            body: 'The provider has updated '.$decided->reference
                .'. Open it to see what they said.',
            actionLabel: 'Open application',
            actionRoute: '/applications/'.$decided->getKey(),
            rule: 'application.status.is_provider_decision',
            evidence: 'application '.$decided->reference.' status '.$decided->status,
        );
    }

    // --- Goals ----------------------------------------------------------------

    /**
     * A goal whose date is close, described from its own metrics.
     *
     * The months remaining and the amount left both come from the goal's
     * own derivation. Nothing here recalculates them, and nothing says a
     * customer will or will not get there — only what is left and how long
     * there is.
     */
    private function goalDeadline(FinancialState $state): ?Insight
    {
        /** @var FinancialGoal|null $goal */
        $goal = $state->goals
            ->filter(fn (FinancialGoal $g) => $g->progress() < 1.0)
            ->filter(fn (FinancialGoal $g) => $g->monthsRemaining() > 0
                && $g->monthsRemaining() <= self::GOAL_DEADLINE_MONTHS)
            ->sortBy(fn (FinancialGoal $g) => $g->monthsRemaining())
            ->first();

        if ($goal === null) {
            return null;
        }

        $months = $goal->monthsRemaining();
        $remaining = $goal->remainingAmount();

        return new Insight(
            type: InsightType::GoalDeadline,
            source: InsightSource::Goal,
            title: 'Your target date is close',
            body: 'You have about '.$months.' '
                .($months === 1 ? 'month' : 'months').' left and '
                .$this->rupees($remaining).' still to save.',
            actionLabel: 'Review your goal',
            actionRoute: '/goals',
            rule: 'goal.months_remaining <= '.self::GOAL_DEADLINE_MONTHS.' and progress < 1',
            evidence: 'goal '.$goal->getKey().' months '.$months,
        );
    }

    // --- Financial health -----------------------------------------------------

    /**
     * The state of the ratios, using thresholds the product already has.
     *
     * Nothing here is new. The 45% affordability reference and the
     * six-month emergency target are FinancialEngine's, used everywhere
     * else in FynnEdge, and stated as FynnEdge's own references rather than
     * as lender rules — because that is what they are.
     */
    private function financialHealth(FinancialState $state): ?Insight
    {
        if (! $state->hasFinancialProfile()) {
            return null;
        }

        $months = (float) $state->snapshot->emergencyMonths->toJsonNumber();

        if ($months >= FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS) {
            return null;
        }

        return new Insight(
            type: InsightType::FinancialHealth,
            source: InsightSource::FinancialEngine,
            title: 'Your savings would not last long',
            body: 'What you have saved covers about '
                .$this->months($months).' months of your outgoings. '
                .FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS
                .' months is what FynnEdge measures against.',
            actionLabel: 'Plan a buffer',
            actionRoute: '/fynnlab/emergency-fund',
            rule: 'emergency_months < '.FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS,
            evidence: 'emergency_months '.$this->months($months),
        );
    }

    private function months(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function rupees(float $value): string
    {
        return '₹'.number_format($value, 0, '.', ',');
    }
}
