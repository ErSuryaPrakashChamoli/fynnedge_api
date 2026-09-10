<?php

namespace App\Domain\Ai;

/**
 * Everything FynnAI is allowed to know about one customer, for one question.
 *
 * Built deliberately, never serialised from a model graph. Two things follow
 * from that: nothing reaches the assistant that was not chosen for it, and
 * anything absent is absent as a fact — a missing income means the customer
 * has not given us one, not that the assistant should guess.
 *
 * What is deliberately not here: tokens, passwords, OTPs, storage keys,
 * storage paths, document bytes, provider credentials, other customers'
 * anything.
 */
final class FynnAiContext
{
    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>|null  $financialProfile
     * @param  list<array<string, mixed>>  $goals
     * @param  array<string, mixed>|null  $fynnScore
     * @param  list<array<string, mixed>>  $applications
     * @param  list<array<string, mixed>>  $documents  metadata only
     * @param  list<array<string, mixed>>  $memories
     * @param  list<string>  $missing  what the customer has not told us
     */
    public function __construct(
        public readonly array $customer,
        public readonly ?array $financialProfile,
        public readonly array $goals,
        public readonly ?array $fynnScore,
        public readonly array $applications,
        public readonly array $documents,
        public readonly array $memories,
        public readonly array $missing,
        /** True when the customer has allowed persistent memory. */
        public readonly bool $memoryAllowed,

        /**
         * The one next step FynnEdge would suggest, or null.
         *
         * So the assistant can agree with the rest of the product rather
         * than improvising a different suggestion. It is told what FynnEdge
         * already decided, not asked to decide.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $nextAction = null,

        /**
         * A few recent things that happened in this customer's financial
         * life — title, description and date only.
         *
         * Bounded hard. The whole of a timeline in every request would be
         * an assistant reasoning about a customer's entire history to
         * answer a question about this month, and a payload that grows
         * without limit.
         *
         * @var list<array<string, mixed>>
         */
        public readonly array $recentMoments = [],

        /**
         * Whether FynnEdge has enough observations to compare anything.
         *
         * The most important field here. Without it an assistant looking at
         * one set of figures has no way to know whether it is looking at a
         * trend or a single reading, and "your expenses are falling" is the
         * kind of thing a customer acts on.
         */
        public readonly bool $hasComparableHistory = false,

        /**
         * The most recent thing FynnEdge observed changing, as a sentence.
         *
         * One item, already written, already checked. Not the observations
         * themselves: handing over a customer's stored financial history
         * would let an assistant describe a shape in it, and a shape read
         * off two or three form edits is a story rather than a fact.
         *
         * Null whenever nothing has been observed to change, which is the
         * ordinary case and is not the same as nothing having happened.
         */
        public readonly ?string $latestObservedChange = null,
    ) {}

    public function hasFinancialProfile(): bool
    {
        return $this->financialProfile !== null;
    }

    public function lacks(string $what): bool
    {
        return in_array($what, $this->missing, true);
    }

    /**
     * The shape a provider receives.
     *
     * Also what a test asserts against, which is the point: what FynnAI can
     * see is one readable structure rather than whatever a prompt happened
     * to interpolate.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer' => $this->customer,
            'financial_profile' => $this->financialProfile,
            'goals' => $this->goals,
            'fynn_score' => $this->fynnScore,
            'applications' => $this->applications,

            // Metadata only. FynnEdge cannot read a document, so FynnAI
            // cannot be told what one says.
            'documents' => $this->documents,

            'memories' => $this->memories,
            'missing' => $this->missing,
            'memory_allowed' => $this->memoryAllowed,

            // What FynnEdge decided, so the assistant does not decide
            // something else.
            'next_action' => $this->nextAction,
            'recent_moments' => $this->recentMoments,

            /*
             * And whether any comparison is possible at all.
             *
             * FynnEdge kept no financial history before Module 31, so for
             * a customer who has changed nothing this is false — and an
             * assistant told so cannot turn one reading into a trend.
             */
            'has_comparable_history' => $this->hasComparableHistory,
            'latest_observed_change' => $this->latestObservedChange,
        ];
    }
}
