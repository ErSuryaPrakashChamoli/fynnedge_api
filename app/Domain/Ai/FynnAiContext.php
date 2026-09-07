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
        ];
    }
}
