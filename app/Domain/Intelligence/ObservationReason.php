<?php

namespace App\Domain\Intelligence;

/**
 * Why FynnEdge wrote down what was true.
 *
 * An allowlist, and a short one. Every case is a change to something the
 * customer did or something a provider said — never a page view, a refresh
 * or an app open. A financial history that grew when somebody opened the
 * app would be a record of their attention, not their money.
 */
enum ObservationReason: string
{
    /** The customer changed their income, expenses, EMIs or savings. */
    case FinancialProfileChanged = 'financial_profile_changed';

    /** A goal was created or its figures moved. */
    case GoalChanged = 'goal_changed';

    /** An application reached a state a provider or the customer set. */
    case ApplicationMilestone = 'application_milestone';

    public function label(): string
    {
        return match ($this) {
            self::FinancialProfileChanged => 'Financial details changed',
            self::GoalChanged => 'Goal changed',
            self::ApplicationMilestone => 'Application milestone',
        };
    }
}
