<?php

namespace App\Domain\Ai;

use App\Domain\Consent\ConsentType;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Score\FynnScoreEngine;
use App\Models\Document;
use App\Models\FinancialGoal;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\CustomerService;

/**
 * Assembles what FynnAI is allowed to know.
 *
 * Every field here was chosen. Nothing is reached for by serialising a model,
 * so a column added elsewhere in the product does not silently become
 * something an assistant can say out loud.
 *
 * Ownership is settled before anything is loaded: the builder takes an
 * authenticated user and reads only through that user's relations. No message
 * content reaches this class, so nothing a customer types can widen what it
 * loads.
 */
class FynnAiContextBuilder
{
    /** Enough recent history to be useful, few enough to stay compact. */
    public const MAX_GOALS = 10;

    public const MAX_APPLICATIONS = 5;

    public const MAX_DOCUMENTS = 20;

    public function __construct(
        private readonly CustomerService $customers,
        private readonly FinancialEngine $financial,
        private readonly FynnScoreEngine $scoring,
        private readonly ConsentService $consents,
    ) {}

    public function build(User $user): FynnAiContext
    {
        $missing = [];

        $profile = $user->customerProfile;
        $financialProfile = $this->customers->financialProfileFor($user);

        $goals = $user->goals()
            ->where('status', 'active')
            ->orderBy('target_date')
            ->limit(self::MAX_GOALS)
            ->get();

        $snapshot = $this->financial->snapshot($financialProfile, $goals);

        // Absent facts are recorded as absent. The assistant is told what it
        // does not know so it can say so instead of filling the gap.
        if (! $snapshot->hasIncome) {
            $missing[] = 'monthly_income';
        }
        if (! $snapshot->expenses->isPositive()) {
            $missing[] = 'monthly_expenses';
        }
        if ($goals->isEmpty()) {
            $missing[] = 'goals';
        }

        $score = $this->scoring->score($snapshot);

        if (! $score->hasScore) {
            $missing[] = 'fynn_score';
        }

        $applications = $user->applications()
            ->orderByDesc('created_at')
            ->limit(self::MAX_APPLICATIONS)
            ->get();

        if ($applications->isEmpty()) {
            $missing[] = 'applications';
        }

        $documents = $user->documents()
            ->orderByDesc('created_at')
            ->limit(self::MAX_DOCUMENTS)
            ->get();

        if ($documents->isEmpty()) {
            $missing[] = 'documents';
        }

        // Persistent memory is a capability the customer switches on. Without
        // it the conversation still works; it simply starts fresh.
        $memoryAllowed = $this->consents
            ->hasCurrentConsent($user, ConsentType::AiMemory);

        return new FynnAiContext(
            customer: [
                'first_name' => $this->firstName($profile?->full_name),
                'city' => $profile?->city ?: null,
                'employment_type' => $financialProfile->employment_type ?: null,
            ],

            financialProfile: $snapshot->hasIncome || $snapshot->expenses->isPositive()
                ? [
                    'monthly_income' => $snapshot->income->toJsonNumber(),
                    'monthly_expenses' => $snapshot->expenses->toJsonNumber(),
                    'existing_emi' => $snapshot->existingEmi->toJsonNumber(),
                    'other_obligations' => $snapshot->otherObligations->toJsonNumber(),
                    'savings' => $snapshot->savings->toJsonNumber(),

                    // Derived by the Financial Engine, never recomputed here.
                    'monthly_outgo' => $snapshot->totalOutgo->toJsonNumber(),
                    'surplus' => $snapshot->surplus->toJsonNumber(),
                    'emi_ratio' => $snapshot->emiRatio->toJsonNumber(),
                    'expense_ratio' => $snapshot->expenseRatio->toJsonNumber(),
                    'emergency_months' => $snapshot->emergencyMonths->toJsonNumber(),
                ]
                : null,

            goals: $goals->map(fn (FinancialGoal $goal) => [
                'title' => $goal->title,
                'category' => $goal->category,
                'target_amount' => (float) $goal->target_amount,
                'saved_amount' => (float) $goal->saved_amount,
                'target_date' => $goal->target_date?->toDateString(),
                'progress' => $goal->progress(),
                'months_remaining' => $goal->monthsRemaining(),
                'monthly_required' => $goal->monthlyRequired(),
            ])->values()->all(),

            fynnScore: $score->hasScore
                ? [
                    'score' => $score->score,
                    'band' => $score->band?->value,
                    'band_label' => $score->band?->label(),
                    'model_version' => FynnScoreEngine::MODEL_VERSION,
                    // The engine's own dimensions and its own words for
                    // them. FynnAI explains these; it does not invent them.
                    'dimensions' => array_map(fn ($d) => [
                        'key' => $d->key,
                        'name' => $d->name,
                        'score' => $d->score(),
                        'metric' => $d->metric,
                        'metric_value' => $d->metricValue,
                        'metric_unit' => $d->metricUnit,
                        'explanation' => $d->explanation,
                        'is_helping' => $d->isHelping,
                    ], $score->dimensions),
                ]
                : null,

            applications: $applications->map(fn (LoanApplication $a) => [
                'reference' => $a->reference,
                'lender' => $a->lender,
                'product_name' => $a->product_name,
                'amount' => (float) $a->amount,
                'tenure_months' => $a->tenure_months,
                'emi' => (float) $a->emi,
                'status' => $a->status,
                'status_label' => $a->status()->label(),
                // Preserved from Module 9: a submission that went nowhere
                // must not be described as one that did.
                'is_simulated' => (bool) $a->is_simulated,
                'submitted_at' => $a->submitted_at?->toDateString(),
            ])->values()->all(),

            // Metadata only, and deliberately so: FynnEdge has no
            // document-reading provider, so there is nothing about a
            // document's contents to pass on.
            documents: $documents->map(fn (Document $d) => [
                'name' => $d->display_name,
                'type' => $d->type,
                'status' => $d->status,
                'uploaded_at' => $d->created_at?->toDateString(),
            ])->values()->all(),

            memories: $memoryAllowed
                ? $user->aiMemories()
                    ->orderBy('key')
                    ->get()
                    ->map(fn ($m) => ['key' => $m->key, 'value' => $m->value])
                    ->values()
                    ->all()
                : [],

            missing: $missing,
            memoryAllowed: $memoryAllowed,
        );
    }

    private function firstName(?string $fullName): ?string
    {
        $name = trim((string) $fullName);

        return $name === '' ? null : explode(' ', $name)[0];
    }
}
