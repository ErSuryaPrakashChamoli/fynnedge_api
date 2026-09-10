<?php

namespace App\Services\Decisions;

use App\Domain\Decisions\DecisionEntry;
use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionType;
use App\Models\DecisionContext;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the customer explored, from the two places FynnEdge already knows it.
 *
 * Recorded explorations come from `decision_contexts`. Applications come
 * from `loan_applications`, which has held a complete immutable snapshot of
 * the terms shown since Module 9 — the amount, tenure, rate, EMI, fees and
 * the catalogue version behind them. Reading that is better than copying
 * it: two records of the same decision can disagree, and the one a customer
 * is shown would then be a coin toss.
 *
 * Nothing here reconstructs. A customer who explored nothing has an empty
 * history, and no entry is ever synthesised from their current profile,
 * current score, current goal or current navigation.
 */
class DecisionHistory
{
    public const MAX_ENTRIES = 50;

    /**
     * The customer's decision history, newest first.
     *
     * @return list<DecisionEntry>
     */
    public function for(User $user, int $limit = self::MAX_ENTRIES): array
    {
        $limit = max(1, min($limit, self::MAX_ENTRIES));

        $entries = $this->recorded($user, $limit)
            ->merge($this->fromApplications($user, $limit))
            ->sortByDesc(fn (DecisionEntry $e) => $e->occurredAt->getTimestamp())
            ->values()
            ->take($limit);

        return $entries->all();
    }

    /** One entry, or null when it is not this customer's. */
    public function find(User $user, string $id): ?DecisionEntry
    {
        foreach ($this->for($user) as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @return Collection<int, DecisionEntry> */
    private function recorded(User $user, int $limit): Collection
    {
        return $user->decisionContexts()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            // toBase, because an Eloquent collection of value objects still
            // tries to merge them as models.
            ->toBase()
            ->map(fn (DecisionContext $d) => new DecisionEntry(
                id: 'd'.$d->getKey(),
                type: $d->type(),
                occurredAt: $d->occurred_at,
                snapshot: $d->snapshot(),
                productCode: $d->product_code,
                productVersionId: $d->product_version_id,

                // Everything this table holds is an exploration. A
                // simulation is a what-if and a comparison is a look; a
                // customer reading either must not take it for a
                // commitment.
                isSimulated: $d->type() === DecisionType::Simulation,
            ));
    }

    /**
     * The customer's applications, read as decisions.
     *
     * An application carries its own terms, so the snapshot comes straight
     * off the row rather than from a second calculation. A submitted
     * application yields two entries — started and submitted — because they
     * are two different decisions and happened at different times.
     *
     * @return Collection<int, DecisionEntry>
     */
    private function fromApplications(User $user, int $limit): Collection
    {
        $entries = collect();

        $applications = $user->applications()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        foreach ($applications as $application) {
            $snapshot = $this->snapshotOf($application);

            $entries->push(new DecisionEntry(
                id: 'a'.$application->getKey().':started',
                type: DecisionType::ApplicationStarted,
                occurredAt: $application->created_at,
                snapshot: $snapshot,
                productCode: $application->product_id,
                productName: $application->product_name,
                productVersionId: $application->product_version_id,
                reference: $application->reference,
            ));

            /*
             * Submitted is a separate decision, and only when it happened.
             *
             * Never inferred from the status: `submitted_at` is written
             * when the customer asked FynnEdge to send it, and nothing else
             * sets it. An application that was started and abandoned yields
             * one entry.
             */
            if ($application->submitted_at !== null) {
                $entries->push(new DecisionEntry(
                    id: 'a'.$application->getKey().':submitted',
                    type: DecisionType::ApplicationSubmitted,
                    occurredAt: $application->submitted_at,
                    snapshot: $snapshot,
                    productCode: $application->product_id,
                    productName: $application->product_name,
                    productVersionId: $application->product_version_id,
                    reference: $application->reference,
                ));
            }
        }

        return $entries;
    }

    /**
     * The terms the application was created with.
     *
     * Straight off the row. These were snapshotted when the application was
     * created and nothing rewrites them, which is what makes an application
     * from six months ago still show the rate it was actually offered at.
     */
    private function snapshotOf(LoanApplication $application): DecisionSnapshot
    {
        return DecisionSnapshot::of([
            'amount' => (float) $application->amount,
            'tenure_months' => (int) $application->tenure_months,
            'interest_rate' => (float) $application->interest_rate,
            'emi' => (float) $application->emi,
            'total_interest' => (float) $application->total_interest,
            'total_payable' => (float) $application->total_payable,
            'processing_fee' => (float) $application->processing_fee,
            'projected_emi_ratio' => (float) $application->projected_emi_ratio,
        ]);
    }
}
