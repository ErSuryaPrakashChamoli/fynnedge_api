<?php

namespace App\Domain\Match;

enum CriterionStatus: string
{
    /** The customer's situation satisfies a rule the product documents. */
    case Passed = 'passed';

    /** A documented rule is not satisfied. */
    case Failed = 'failed';

    /** Worth knowing before proceeding, but not a rule failure. */
    case Caution = 'caution';

    /**
     * FynnEdge cannot evaluate this — either the product documents no such
     * rule, or we do not hold the customer data it needs.
     *
     * This is deliberately not the same as "passed". Claiming a criterion is
     * met when it was never checked is how a matching layer starts implying
     * approval it has no basis for.
     */
    case Unavailable = 'unavailable';
}
