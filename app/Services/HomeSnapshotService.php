<?php

namespace App\Services;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Home\AttentionItem;
use App\Domain\Home\AttentionRules;
use App\Domain\Score\FynnScoreEngine;
use App\Http\Resources\FinancialGoalResource;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Http\Request;

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

        $applications = $user->applications()
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

            'primary_action' => $this->primaryAction(
                $snapshot->hasIncome,
                $this->firstName($profile->full_name) !== null,
                $goals->count(),
                $applications->count(),
            ),
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
    private function primaryAction(
        bool $hasIncome,
        bool $hasName,
        int $goalCount,
        int $applicationCount,
    ): array {
        if (! $hasIncome) {
            return [
                'key' => 'complete_profile',
                'label' => 'Complete your profile',
                'route' => '/financial-profile',
            ];
        }

        // The figures are in but FynnEdge has no name to greet them by, so
        // the way to fix that is offered rather than a name being assumed.
        if (! $hasName) {
            return [
                'key' => 'add_your_name',
                'label' => 'Tell us your name',
                'route' => '/profile',
            ];
        }

        if ($goalCount === 0) {
            return [
                'key' => 'set_goal',
                'label' => 'Set your first goal',
                'route' => '/goals',
            ];
        }

        if ($applicationCount > 0) {
            return [
                'key' => 'view_applications',
                'label' => 'View your applications',
                'route' => '/applications',
            ];
        }

        return [
            'key' => 'explore_options',
            'label' => 'Explore your options',
            'route' => '/loan-discovery',
        ];
    }

    private function firstName(?string $fullName): ?string
    {
        $name = trim((string) $fullName);

        return $name === '' ? null : explode(' ', $name)[0];
    }
}
