<?php

namespace App\Domain\ProviderEvents;

use Carbon\CarbonInterface;

/**
 * One provider event, in FynnEdge's own vocabulary.
 *
 * No provider field name, status code or payload shape survives to this
 * point. What is here is the minimum needed to find the right application
 * and decide what changed: an identity, a type, a reference and two
 * timestamps.
 *
 * There is deliberately no raw payload, and no place to put one. A provider
 * callback about a loan application arrives alongside whatever else that
 * provider chooses to send — an applicant's name, income, PAN, decision
 * reasons — and storing the body wholesale would recreate, in an operational
 * table, every copy of customer data the last five modules took care not to
 * make.
 */
final class CanonicalProviderEvent
{
    public function __construct(
        /**
         * The provider's own event id, where the contract supplies one.
         *
         * The idempotency key. Null only when a provider genuinely does not
         * issue ids, in which case the receiver derives a deterministic
         * fingerprint instead — never a timestamp alone, which would let
         * two distinct events collide and one real event be dropped.
         */
        public readonly ?string $providerEventId,

        public readonly string $providerCode,
        public readonly ProviderEventType $type,

        /**
         * The provider's own reference for the submission.
         *
         * How an event finds its application. Nothing about the customer
         * comes from the request: no user id, no customer id, no internal
         * application id.
         */
        public readonly string $providerReference,

        /** When the provider says it happened. */
        public readonly CarbonInterface $occurredAt,

        /** True when a simulated source produced this. */
        public readonly bool $isSimulated,

        /**
         * The provider's own reason code, where it gives one.
         *
         * Short, canonical and operational. Never a free-text explanation
         * from a provider, which could carry anything.
         */
        public readonly ?string $reasonCode = null,
    ) {}

    /**
     * The key that makes processing this twice safe.
     *
     * A provider event id when there is one. Otherwise a fingerprint of the
     * facts that identify the event — provider, reference, type and the
     * moment it occurred — which is stable across redeliveries of the same
     * event and different for genuinely different ones.
     */
    public function idempotencyKey(): string
    {
        if ($this->providerEventId !== null && $this->providerEventId !== '') {
            return $this->providerCode.':'.$this->providerEventId;
        }

        return $this->providerCode.':fp:'.hash('sha256', implode('|', [
            $this->providerReference,
            $this->type->value,
            $this->occurredAt->toIso8601String(),
        ]));
    }
}
