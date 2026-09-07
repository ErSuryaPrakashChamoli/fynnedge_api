<?php

namespace App\Domain\Home;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\FinancialSnapshot;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;
use Illuminate\Support\Collection;

/**
 * What matters about this customer's money right now.
 *
 * Deterministic and ordered. Nothing here decides by score, weighting or
 * model: each rule is a comparison against a figure an engine produced, and
 * the order below is the order they are considered.
 *
 * PRIORITY, highest first:
 *
 *   1. negative_surplus         spending more than you earn
 *   2. emi_ratio_past_reference EMIs past FynnEdge's 45% reference
 *   3. thin_emergency_buffer    savings cover under 6 months of outgo
 *   4. application_action       a provider asked for something
 *   5. goal_overdue             a goal's date has passed
 *
 * Every threshold is an existing product definition — the affordability
 * reference from FinancialEngine and the emergency-fund target from the same
 * place. No number here was invented to make a card appear.
 */
class AttentionRules
{
    /**
     * @param  Collection<int, FinancialGoal>  $goals
     * @param  Collection<int, LoanApplication>  $applications
     * @return list<AttentionItem>
     */
    public function evaluate(
        FinancialSnapshot $financials,
        Collection $goals,
        Collection $applications,
    ): array {
        // Without an income nothing can be measured, so nothing is claimed.
        if (! $financials->hasIncome) {
            return [];
        }

        return array_values(array_filter([
            $this->negativeSurplus($financials),
            $this->emiRatio($financials),
            $this->emergencyBuffer($financials),
            $this->applicationAction($applications),
            $this->overdueGoal($goals),
        ]));
    }

    private function negativeSurplus(FinancialSnapshot $f): ?AttentionItem
    {
        if (! $f->surplus->isNegative()) {
            return null;
        }

        return new AttentionItem(
            key: 'negative_surplus',
            severity: AttentionItem::SEVERITY_SERIOUS,
            title: 'You are spending more than you earn',
            detail: 'Your outgoings of '.$f->totalOutgo->toIndianString()
                .' are above your income of '.$f->income->toIndianString()
                .' each month.',
            actionLabel: 'Review your figures',
            actionRoute: '/financial-profile',
        );
    }

    /** The affordability reference FynnEdge already uses everywhere else. */
    private function emiRatio(FinancialSnapshot $f): ?AttentionItem
    {
        $ratio = (float) $f->emiRatio->toJsonNumber();

        if ($ratio <= FinancialEngine::AFFORDABILITY_CEILING_PERCENT) {
            return null;
        }

        return new AttentionItem(
            key: 'emi_ratio_past_reference',
            severity: AttentionItem::SEVERITY_CAUTION,
            title: 'Your EMIs take a large share of your income',
            detail: 'Repayments use '.$this->percent($ratio).'% of what you '
                .'earn, past the '
                .$this->percent(FinancialEngine::AFFORDABILITY_CEILING_PERCENT)
                .'% FynnEdge uses as its affordability reference. That is '
                .'FynnEdge\'s reference, not a lender rule.',
            actionLabel: 'See what a change would do',
            actionRoute: '/fynn-twin',
        );
    }

    /** The six-month target the emergency-fund definition already uses. */
    private function emergencyBuffer(FinancialSnapshot $f): ?AttentionItem
    {
        $months = (float) $f->emergencyMonths->toJsonNumber();

        if ($months >= FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS) {
            return null;
        }

        return new AttentionItem(
            key: 'thin_emergency_buffer',
            severity: AttentionItem::SEVERITY_CAUTION,
            title: 'Your savings would not last long',
            detail: 'What you have saved covers about '
                .$this->percent($months).' months of your outgoings. '
                .FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS
                .' months is the buffer FynnEdge measures against.',
            actionLabel: 'Plan a buffer',
            actionRoute: '/fynnlab/emergency-fund',
        );
    }

    /**
     * Only a status a provider actually reported.
     *
     * No provider integration exists, so this cannot fire today. It is here
     * because the rule belongs with the others, not to fill the card.
     *
     * @param  Collection<int, LoanApplication>  $applications
     */
    private function applicationAction(Collection $applications): ?AttentionItem
    {
        $needsAction = $applications->first(
            fn (LoanApplication $a) => $a->status === 'action_needed'
        );

        if ($needsAction === null) {
            return null;
        }

        return new AttentionItem(
            key: 'application_action',
            severity: AttentionItem::SEVERITY_CAUTION,
            title: $needsAction->lender.' needs something from you',
            detail: 'Your application '.$needsAction->reference
                .' cannot move forward until you respond.',
            actionLabel: 'Open the application',
            actionRoute: '/applications',
        );
    }

    /**
     * A goal whose date has passed, from the goal's own metrics.
     *
     * @param  Collection<int, FinancialGoal>  $goals
     */
    private function overdueGoal(Collection $goals): ?AttentionItem
    {
        $overdue = $goals->first(fn (FinancialGoal $g) => $g->metrics()->isOverdue);

        if ($overdue === null) {
            return null;
        }

        return new AttentionItem(
            key: 'goal_overdue',
            severity: AttentionItem::SEVERITY_INFO,
            title: $overdue->title.' has passed its date',
            detail: 'You saved '
                .$overdue->metrics()->saved->toIndianString().' of '
                .$overdue->metrics()->target->toIndianString()
                .'. Moving the date or the target keeps it useful.',
            actionLabel: 'Open goals',
            actionRoute: '/goals',
        );
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
