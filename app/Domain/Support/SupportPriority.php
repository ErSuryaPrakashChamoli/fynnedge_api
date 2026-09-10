<?php

namespace App\Domain\Support;

/**
 * How soon a request needs a person, decided by FynnEdge.
 *
 * Never by the customer. A field a customer can set to "urgent" is a field
 * every request is urgent in, which leaves the genuinely stuck ones
 * indistinguishable from the rest — so priority is derived from what the
 * request is about and never accepted from a request body.
 *
 * Two levels, because FynnEdge has no support rota to feed a third into.
 * Inventing "critical" would be inventing a queue that responds to it.
 */
enum SupportPriority: string
{
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::High => 'High',
        };
    }

    /**
     * The rule, in one place.
     *
     * High means the customer is stuck on something they cannot resolve
     * themselves and have been told not to retry. Today that is exactly one
     * situation: an application FynnEdge sent and never heard back about.
     * Module 28 tells that customer not to submit again and to contact
     * support, so when they do, it is not an ordinary question.
     *
     * Note what does NOT raise priority: the category. A customer can pick
     * "Application status" out of curiosity, and a question about a score
     * is normal however strongly it is worded. What makes a request high is
     * the state of the thing behind it, established from FynnEdge's own
     * records — so this reads the context and nothing else.
     */
    public static function forRequest(
        SupportCategory $category,
        ?ResolvedContext $context,
    ): self {
        return $context?->isBlocked === true ? self::High : self::Normal;
    }
}
