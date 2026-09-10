<?php

namespace App\Services\Intelligence;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Intelligence\FinancialObservationReading;
use App\Domain\Intelligence\ObservationReason;
use App\Domain\Score\FynnScoreEngine;
use App\Models\FinancialObservation;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Support\Facades\DB;

/**
 * Writing down what is true, so that one day something can be compared.
 *
 * FynnEdge kept no financial history before Module 31. That is why an
 * income edit destroyed the previous income and why no score had ever been
 * remembered — and why, on the day this shipped, no customer had a trend
 * and none could truthfully be shown one.
 *
 * This class is how that changes going forward, and it changes nothing
 * about the past: no row is ever reconstructed from today's profile. A
 * customer's history begins the first time they change something after
 * Module 31, not before.
 *
 * Written at meaningful change only. An app open is not a financial event,
 * and a table that grew on page views would be a record of somebody's
 * attention rather than their money.
 */
class ObservationRecorder
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly FinancialEngine $financial,
        private readonly FynnScoreEngine $scoring,
    ) {}

    /**
     * Observes the customer's current figures.
     *
     * Insert-only, and deduplicated by the database rather than by a read
     * beforehand. A caller has already decided that something meaningful
     * happened; what it cannot know is whether the figures actually moved,
     * and an observation identical to the one it follows records nothing.
     *
     * Returns the observation that now stands at the head of this
     * customer's history — the new row, or the existing one when this call
     * described a state already written down.
     */
    public function observe(User $user, ObservationReason $reason): FinancialObservation
    {
        $snapshot = $this->financial->snapshot(
            $this->customers->financialProfileFor($user)
        );

        $score = $this->scoring->score($snapshot);

        $figures = [
            // Null when there was not enough to produce one. That absence
            // is itself worth recording: it is how "your score became
            // available" stays distinguishable from "your score improved".
            'score' => $score->hasScore ? $score->score : null,
            'score_band' => $score->band?->value,
            'score_model_version' => $score->hasScore
                ? FynnScoreEngine::MODEL_VERSION
                : null,

            // The engine's own figures, as the engine serialises them.
            'monthly_income' => $snapshot->income->toDecimalString(),
            'monthly_outgo' => $snapshot->totalOutgo->toDecimalString(),
            'surplus' => $snapshot->surplus->toDecimalString(),
            'emi_ratio' => $snapshot->emiRatio->toJsonNumber(),
            'expense_ratio' => $snapshot->expenseRatio->toJsonNumber(),
            'emergency_months' => $snapshot->emergencyMonths->toJsonNumber(),
        ];

        $latest = $this->latest($user);

        $digest = self::digestOf(
            score: $figures['score'],
            scoreBand: $figures['score_band'],
            scoreModelVersion: $figures['score_model_version'],
            monthlyIncome: $figures['monthly_income'],
            monthlyOutgo: $figures['monthly_outgo'],
            surplus: $figures['surplus'],
            emiRatio: (string) $figures['emi_ratio'],
            expenseRatio: (string) $figures['expense_ratio'],
            emergencyMonths: (string) $figures['emergency_months'],
        );

        /*
         * Identical to the state already at the head of the history, so
         * there is nothing to write down. A caller decided something
         * happened; what it could not know is whether any figure moved.
         */
        if ($latest?->state_digest === $digest) {
            return $latest;
        }

        $now = now();

        /*
         * insertOrIgnore against the unique index, which settles the case
         * the check above cannot see: two callers reaching here together
         * both find the same head and compute the same digest, and only one
         * of them may become a row. A comparison on its own has a window
         * between reading and writing, and only the database can close it.
         *
         */
        $inserted = DB::table('financial_observations')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'reason' => $reason->value,
            ...$figures,
            'follows_id' => $latest?->getKey() ?? 0,
            'state_digest' => $digest,
            'observed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Nothing was written because nothing had changed, so the head of
        // the history is still where it was.
        return $inserted === 0 && $latest !== null
            ? $latest
            : $this->latest($user);
    }

    /**
     * The observed figures, as one comparable value.
     *
     * The reason is deliberately absent. A goal created a minute after a
     * profile edit describes the same financial state, and the state is
     * what an observation is a record of — recording it twice under two
     * labels would make a customer's history longer without making it say
     * anything more.
     */
    public static function digestOf(
        ?int $score,
        ?string $scoreBand,
        ?string $scoreModelVersion,
        string $monthlyIncome,
        string $monthlyOutgo,
        string $surplus,
        string $emiRatio,
        string $expenseRatio,
        string $emergencyMonths,
    ): string {
        return hash('sha256', implode('|', [
            $score ?? 'none',
            $scoreBand ?? 'none',
            $scoreModelVersion ?? 'none',

            // Normalised, so 100000 and 100000.00 are the one figure they
            // describe rather than two states a customer moved between.
            self::number($monthlyIncome),
            self::number($monthlyOutgo),
            self::number($surplus),
            self::number($emiRatio),
            self::number($expenseRatio),
            self::number($emergencyMonths),
        ]));
    }

    private static function number(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function latest(User $user): ?FinancialObservation
    {
        return $user->financialObservations()
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The observation before now, or null when there is none.
     *
     * Null is the ordinary answer for a customer FynnEdge has looked at
     * once, and every rule that would state a change checks it. Returning
     * today's figures as a stand-in would be manufacturing the history this
     * class exists to build honestly.
     */
    public function previousReading(User $user): ?FinancialObservationReading
    {
        return $this->latest($user)?->reading();
    }

    /**
     * What FynnEdge actually observed, oldest first.
     *
     * Bounded by the same figure the timeline uses, because it is the same
     * question: how much of somebody's history one response should carry.
     * Ordered by when it was observed and then by id, so two observations
     * written in the same second still read in the order they happened.
     *
     * @return list<FinancialObservationReading>
     */
    public function history(User $user, int $limit): array
    {
        return $user->financialObservations()
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (FinancialObservation $o) => $o->reading())
            ->all();
    }

    public function count(User $user): int
    {
        return $user->financialObservations()->count();
    }
}
