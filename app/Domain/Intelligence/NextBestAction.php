<?php

namespace App\Domain\Intelligence;

/**
 * The one useful thing a customer could do next.
 *
 * Singular on purpose. A screen offering four equally weighted next steps
 * has not decided anything and leaves the customer to work out which
 * matters — which is the job FynnEdge is supposed to be doing.
 *
 * `reason` is why this one was chosen, in the customer's own terms, and
 * `rule` is why for FynnEdge's. Both exist so that a next action can always
 * be accounted for; neither is a claim about what will happen if they take
 * it.
 */
final class NextBestAction
{
    public function __construct(
        public readonly NextActionType $type,
        public readonly string $title,
        public readonly string $reason,
        public readonly string $actionLabel,
        public readonly string $actionRoute,
        public readonly string $rule,

        /**
         * Where this sat in the ladder when it was produced.
         *
         * Lower is more urgent. Carried so a client can order a plan
         * without re-deriving the reasoning, and so the order a customer
         * sees is provably the order the rules produced.
         */
        public readonly int $priority = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,

            /*
             * Why the customer is seeing this, in their own terms.
             *
             * Every action has one, and a test fails if any arrives
             * without it. "Because your savings cover about two months"
             * is something a person can check; "we recommend" is not.
             */
            'reason' => $this->reason,

            'action_label' => $this->actionLabel,
            'action_route' => $this->actionRoute,
            'priority' => $this->priority,
        ];
    }

    /** The same action at a different rung, for building a ranked plan. */
    public function atPriority(int $priority): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            reason: $this->reason,
            actionLabel: $this->actionLabel,
            actionRoute: $this->actionRoute,
            rule: $this->rule,
            priority: $priority,
        );
    }

    public function explain(): array
    {
        return [...$this->toArray(), 'rule' => $this->rule];
    }
}
