<?php

namespace App\Services;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Home\AttentionItem;
use App\Domain\Home\AttentionRules;
use App\Domain\Intelligence\NextActionType;
use App\Domain\Score\FynnScoreEngine;
use App\Http\Resources\FinancialGoalResource;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\Intelligence\FinancialIntelligence;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Everything Home shows, assembled from what already exists.
 *
 * Home orchestrates; it does not calculate. Every figure here was produced
 * by FinancialEngine, FynnScoreEngine or a goal's own metrics, and is passed
 * through unchanged. There is no surplus, ratio or progress arithmetic in
 * this class, and there must never be: a second definition of any of them
 * would let Home disagree with the screen the customer just came from.
 */
class HomeSnapshotService
{
    /** Enough to be useful on one screen, with a link to the rest. */
    public const GOALS_SHOWN = 3;

    public const APPLICATIONS_SHOWN = 3;

    public function __construct(
        private readonly CustomerService $customers,
        private readonly FinancialEngine $financial,
        private readonly FynnScoreEngine $scoring,
        private readonly AttentionRules $attention,
        private readonly FinancialIntelligence $intelligence,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, Request $request): array
    {
        $profile = $this->customers->profileFor($user);
        $financialProfile = $this->customers->financialProfileFor($user);

        // Goals are loaded once and reused by the snapshot, the attention
        // rules and the counts — not queried per section.
        $goals = $user->goals()
            ->where('status', 'active')
            ->orderBy('target_date')
            ->orderBy('id')
            ->get();

        /*
         * With their submissions.
         *
         * Both the attention rules and the next action ask each
         * application what became of its latest submission, and without
         * this that question is a round trip per application.
         */
        $applications = $user->applications()
            ->with('submissions')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $snapshot = $this->financial->snapshot($financialProfile, $goals);
        $score = $this->scoring->score(
            $snapshot,
            $financialProfile->updated_at?->toIso8601String(),
        );

        $documentCount = $user->documents()->count();

        return [
            'customer' => [
                'first_name' => $this->firstName($profile->full_name),
                'has_financial_profile' => $snapshot->hasIncome,
            ],

            // Straight from the engine. Home reformats nothing.
            'financial' => [
                'monthly_income' => $snapshot->income->toJsonNumber(),
                'monthly_expenses' => $snapshot->expenses->toJsonNumber(),
                'monthly_outgo' => $snapshot->totalOutgo->toJsonNumber(),
                'surplus' => $snapshot->surplus->toJsonNumber(),
                'existing_emi' => $snapshot->existingEmi->toJsonNumber(),
                'other_obligations' => $snapshot->otherObligations->toJsonNumber(),
                'savings' => $snapshot->savings->toJsonNumber(),
                'emi_ratio' => $snapshot->emiRatio->toJsonNumber(),
                'expense_ratio' => $snapshot->expenseRatio->toJsonNumber(),
                'emergency_months' => $snapshot->emergencyMonths->toJsonNumber(),
                'has_income' => $snapshot->hasIncome,
            ],

            // The same score the FynnScore screen shows, from one engine.
            'score' => $score->toArray(),

            'goals' => [
                'items' => FinancialGoalResource::collection(
                    $goals->take(self::GOALS_SHOWN)
                )->toArray($request),
                'total' => $goals->count(),
            ],

            'applications' => [
                'items' => $applications
                    ->take(self::APPLICATIONS_SHOWN)
                    ->map(fn (LoanApplication $a) => [
                        'id' => (string) $a->id,
                        'reference' => $a->reference,
                        'lender' => $a->lender,
                        'product_name' => $a->product_name,
                        'amount' => (float) $a->amount,
                        'status' => $a->status,
                        'status_label' => $a->status()->label(),
                        // Module 9's distinction, carried onto Home: a
                        // submission that went nowhere is not one that did.
                        'is_simulated' => (bool) $a->is_simulated,
                    ])->values()->all(),
                'total' => $applications->count(),
                'active' => $applications
                    ->reject(fn (LoanApplication $a) => $a->status()->isTerminal())
                    ->count(),
            ],

            // A count, and nothing else. FynnEdge cannot read a document, so
            // Home says only how many are stored.
            'vault' => ['document_count' => $documentCount],

            'attention' => array_map(
                fn (AttentionItem $item) => $item->toArray(),
                $this->attention->evaluate($snapshot, $goals, $applications),
            ),

            /*
             * The one next step, from the intelligence layer.
             *
             * Home used to decide this itself, with a ladder that put
             * "set your first goal" above an application FynnEdge had sent
             * and never heard back about — so a customer told in as many
             * words not to submit again was simultaneously invited to go
             * and do something else. Truth and priority have to agree, and
             * they can only agree if one place decides.
             */
            'primary_action' => $this->primaryAction($user, $goals, $applications),
        ];
    }

    /**
     * The one next step most worth taking, from what the customer has.
     *
     * Deterministic and ordered: finish the profile, tell us your name,
     * then set a goal, then follow an application, and otherwise explore.
     * No "recent activity" is invented to justify a suggestion.
     *
     * @return array<string, string>
     */
    /**
     * The next step, mapped onto Home's own vocabulary.
     *
     * NextActionRules decides; this translates. The keys are kept as they
     * were wherever the rule is the same, because a customer's app in the
     * field is reading them — and the two new ones are the states Home
     * previously had no way to express.
     *
     * @param  Collection<int, FinancialGoal>  $goals
     * @param  Collection<int, LoanApplication>  $applications
     * @return array<string, string>
     */
    private function primaryAction(User $user, $goals, $applications): array
    {
        $action = $this->intelligence->nextActionFor(
            $this->intelligence->stateFor($user, $goals, $applications)
        );

        if ($action === null) {
            return [
                'key' => 'explore_options',
                'label' => 'Explore your options',
                'route' => '/loan-discovery',
            ];
        }

        return match ($action->type) {
            NextActionType::CompleteProfile => [
                'key' => 'complete_profile',
                'label' => 'Complete your profile',
                'route' => '/financial-profile',
            ],
            NextActionType::AddYourName => [
                'key' => 'add_your_name',
                'label' => 'Tell us your name',
                'route' => '/profile',
            ],
            NextActionType::CreateGoal => [
                'key' => 'set_goal',
                'label' => 'Set your first goal',
                'route' => '/goals',
            ],
            NextActionType::ReviewApplication => [
                'key' => 'view_applications',
                'label' => 'View your applications',
                'route' => '/applications',
            ],
            NextActionType::ExploreOptions => [
                'key' => 'explore_options',
                'label' => 'Explore your options',
                'route' => '/loan-discovery',
            ],

            // The two Home could not say before.
            NextActionType::ResolveSubmissionStatus => [
                'key' => 'resolve_submission_status',
                'label' => 'Check your application',
                'route' => $action->actionRoute,
            ],
            NextActionType::ReviewGoal => [
                'key' => 'review_goal',
                'label' => 'Review your goals',
                'route' => '/goals',
            ],
            NextActionType::ReviewScore => [
                'key' => 'review_score',
                'label' => 'Review your progress',
                'route' => '/fynn-score',
            ],

            // Module 33's rungs. Each keeps the action's own route, so
            // Home sends the customer where the rule intended.
            NextActionType::ReviewProfile => [
                'key' => 'review_profile',
                'label' => 'Review your figures',
                'route' => '/financial-profile',
            ],
            NextActionType::ReviewBuffer => [
                'key' => 'review_buffer',
                'label' => 'Plan a buffer',
                'route' => $action->actionRoute,
            ],
            NextActionType::ImproveScore => [
                'key' => 'improve_score',
                'label' => 'See your FynnScore',
                'route' => '/fynn-score',
            ],
            NextActionType::ReviewDecision => [
                'key' => 'review_decision',
                'label' => 'What you explored',
                'route' => '/decisions',
            ],
            NextActionType::RunSimulation => [
                'key' => 'run_simulation',
                'label' => 'Try a what-if',
                'route' => '/fynn-twin',
            ],
        };
    }

    private function firstName(?string $fullName): ?string
    {
        $name = trim((string) $fullName);

        return $name === '' ? null : explode(' ', $name)[0];
    }
}
