<?php

namespace App\Domain\Kyc;

/**
 * The result of one check, on its own.
 *
 * A verification carries two of these and never merges them. A document can
 * pass a provider's authenticity checks while the identity on it does not
 * match the customer's profile; collapsing both into one boolean would lose
 * exactly the information the customer needs to act on.
 */
enum CheckOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';

    /** The provider ran and could not reach a definitive answer. */
    case Inconclusive = 'inconclusive';

    /** This provider does not perform this check at all. */
    case NotPerformed = 'not_performed';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Did not pass',
            self::Inconclusive => 'Could not be established',
            self::NotPerformed => 'Not checked',
        };
    }
}
