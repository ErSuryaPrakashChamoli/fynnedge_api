<?php

namespace App\Services\Intelligence;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\FinancialPosition;
use App\Domain\Intelligence\FinancialProgress;
use App\Domain\Intelligence\FinancialState;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\InsightRules;
use App\Domain\Intelligence\NextActionRules;
use App\Domain\Intelligence\NextBestAction;
use App\Domain\Score\FynnScoreEngine;
use App\Models\FinancialGoal;
use App\Models\FinancialMoment;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Support\Collection;

/**
 * What FynnEdge understands about one customer's money.
 *
 * The assembly point, and deliberately thin. It reads the state once, hands
 * it to two rule classes, and returns what they produced. It computes
 * nothing itself — there is one FinancialEngine, one FynnScoreEngine and
 * one definition of a goal's progress, and this is not a second version of
 * any of them.
 *
 * Reading once matters beyond performance. Home, the insights and the next
 * action all describe the same customer, and computing the snapshot three
 * times is three chances for them to disagree about it.
 *
 * Insights and the next action are derived here and never stored. A stored
 * recommendation outlives its condition: "complete your financial profile",
 * written to a table, keeps appearing after the profile is complete. Only
 * moments are persisted, because only moments are history.
 */
class FinancialIntelligence
{
    /** How much of the timeline any one response carries. */
    public const MAX_MOMENTS = 20;

    public const MAX_RECENT_MOMENTS = 5;

    /**
     * How many goals one response carries.
     *
     * The same figure FynnAI already uses, for the same reason: a customer
     * with forty goals has a list, not a review, and nothing that reads
     * this needs all of it. The true count is carried alongside, so a
     * bounded list never reads as the whole of somebody's goals.
     */
    public const MAX_GOALS = 10;

    public function __construct(
        private readonly CustomerService $customers,
        private readonly FinancialEngine $financial,
        private readonly FynnScoreEngine $scoring,
        private readonly ObservationRecorder $observations,
        private readonly InsightRules $insights,
        private readonly NextActionRules $actions,
    ) {}

    /**
     * The customer's state, read once from the engines that own it.
     *
     * @param  Collection<int, FinancialGoal>|null  $goals
     */
    public function stateFor(
        User $user,
        ?Collection $goals = null,
        ?Collection $applications = null,
    ): FinancialState {
        /*
         * Loaded here only when a caller has not already got them.
         *
         * Home has both in hand by the time it asks for a next action, and
         * fetching them again would be two more queries describing the same
         * customer — plus a chance for the two reads to disagree.
         */
        $goals ??= $user->goals()->where('status', 'active')->orderBy('target_date')->get();

        $applications ??= $user->applications()
            ->with('submissions')
            ->orderByDesc('id')
            ->get();

        $snapshot = $this->financial->snapshot(
            $this->customers->financialProfileFor($user),
            $goals,
        );

        return new FinancialState(
            snapshot: $snapshot,
            score: $this->scoring->score($snapshot),
            goals: $goals,
            applications: $applications,
            previous: $this->observations->previousReading($user),
            observationCount: $this->observations->count($user),
            hasName: trim((string) $user->customerProfile?->full_name) !== '',

            // Exists-only: one indexed count, not the history itself.
            hasRecordedDecision: $user->decisionContexts()->exists(),
        );
    }

    /**
     * Where the customer stands, read from the snapshot already in hand.
     *
     * No query and no arithmetic: FinancialEngine produced the figures when
     * the state was built, and this hands the same ones to the plan. The
     * financial position on the plan screen and the buffer rung of the
     * action ladder are therefore the same reading of the same customer.
     */
    public function positionFor(FinancialState $state): FinancialPosition
    {
        return FinancialPosition::from($state->snapshot);
    }

    /** @return list<Insight> */
    public function insightsFor(FinancialState $state): array
    {
        return $this->insights->evaluate($state);
    }

    public function nextActionFor(FinancialState $state): ?NextBestAction
    {
        return $this->actions->evaluate($state);
    }

    /**
     * A short plan, in the order the rules produced it.
     *
     * The same ladder the single next action comes from, so the first item
     * is that action. Home shows one; the plan shows up to three. There is
     * no second ranking engine and therefore nothing that can disagree
     * with Home about the same customer.
     *
     * @return list<NextBestAction>
     */
    public function planFor(FinancialState $state): array
    {
        return $this->actions->plan($state);
    }

    /**
     * What actually changed, between things FynnEdge actually observed.
     *
     * Read from the observations table and nowhere else. The customer's
     * current figures are deliberately not one end of any comparison here:
     * they are what is true now, not something FynnEdge wrote down, and a
     * history that compared a record with a live reading would be inventing
     * the second half of every entry.
     *
     * Bounded by the same figure the timeline uses.
     */
    public function progressFor(User $user): FinancialProgress
    {
        return FinancialProgress::from(
            $this->observations->history($user, self::MAX_MOMENTS)
        );
    }

    /**
     * The customer's financial timeline, newest first.
     *
     * Bounded. A timeline is something a customer reads, and the whole of
     * one is neither readable nor something a response should carry.
     *
     * @return Collection<int, FinancialMoment>
     */
    public function momentsFor(User $user, int $limit = self::MAX_MOMENTS): Collection
    {
        return $user->financialMoments()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(min($limit, self::MAX_MOMENTS))
            ->get();
    }
}
