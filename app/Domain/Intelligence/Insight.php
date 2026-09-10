<?php

namespace App\Domain\Intelligence;

/**
 * One thing FynnEdge can say, and the reason it can say it.
 *
 * Derived on every request and never stored. That is the point: an insight
 * is a statement about how things are now, and a stored one outlives the
 * condition that produced it. "Complete your financial profile" persisted
 * in a table is a recommendation that keeps appearing after the profile is
 * complete, and the customer stops believing any of them.
 *
 * `rule` and `evidence` are the explanation. Every insight can answer why
 * it appeared — which rule fired and on what values — and a test asserts
 * that none exists without one. `evidence` is FynnEdge's own record: it is
 * never shown to a customer, and it carries figures the engines derived,
 * never a document, a provider payload or an identity value.
 */
final class Insight
{
    public function __construct(
        public readonly InsightType $type,
        public readonly InsightSource $source,

        public readonly string $title,
        public readonly string $body,

        /** Where the customer goes to act on it, or null. */
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionRoute = null,

        /** The rule that produced this, named. */
        public readonly string $rule = '',

        /** What it fired on, e.g. "emergency_months 2.1 -> 3.0". */
        public readonly ?string $evidence = null,
    ) {}

    public function priority(): int
    {
        return $this->type->priority();
    }

    /** What the customer sees. The rule and evidence stay server-side. */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'source' => $this->source->value,
            'title' => $this->title,
            'body' => $this->body,
            'action_label' => $this->actionLabel,
            'action_route' => $this->actionRoute,
        ], fn ($v) => $v !== null);
    }

    /** The same insight, with the reasoning, for FynnEdge's own use. */
    public function explain(): array
    {
        return [
            ...$this->toArray(),
            'rule' => $this->rule,
            'evidence' => $this->evidence,
        ];
    }
}
