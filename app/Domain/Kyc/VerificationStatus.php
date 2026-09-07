<?php

namespace App\Domain\Kyc;

/**
 * Where one verification stands.
 *
 * The distinction this enum exists to hold is between Unavailable and
 * NotVerified. "FynnEdge cannot check identity" and "we checked, and could
 * not establish it" are different facts about different things — the first
 * about the product, the second about the customer — and a customer told the
 * second when the first is true has been accused of something.
 *
 * Verified is likewise never a summary. It means the provider established
 * the criterion its method defines, and the method travels with it.
 */
enum VerificationStatus: string
{
    /** A provider exists and nothing has been asked of it. */
    case NotStarted = 'not_started';

    /** No provider is configured, so nothing can be checked. */
    case Unavailable = 'unavailable';

    /** A provider exists, and the customer has not permitted its use. */
    case ConsentRequired = 'consent_required';

    /** Sent, and not yet answered. */
    case Processing = 'processing';

    /** The provider established what its method defines. */
    case Verified = 'verified';

    /** The provider ran, and did not establish it. */
    case NotVerified = 'not_verified';

    /** Something needs the customer to look at it before this can settle. */
    case NeedsReview = 'needs_review';

    /** A technical or provider failure. Says nothing about the customer. */
    case Failed = 'failed';

    /**
     * A previous result is no longer considered current.
     *
     * Not a failure, and emphatically not "identity failed". Only ever set
     * from a provider- or policy-defined expiry; FynnEdge does not invent
     * one, so nothing in this codebase sets it today.
     */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not checked',
            self::Unavailable => 'Not available',
            self::ConsentRequired => 'Your permission needed',
            self::Processing => 'Checking',
            self::Verified => 'Verification passed',
            self::NotVerified => 'Could not be established',
            self::NeedsReview => 'Please review a difference',
            self::Failed => 'Check did not complete',
            self::Expired => 'No longer current',
        };
    }

    /** Whether a provider must have produced this. */
    public function requiresProvider(): bool
    {
        return in_array($this, [
            self::Processing,
            self::Verified,
            self::NotVerified,
            self::NeedsReview,
            self::Failed,
            self::Expired,
        ], true);
    }

    /** Whether there is a result to show. */
    public function hasResult(): bool
    {
        return in_array($this, [
            self::Verified,
            self::NotVerified,
            self::NeedsReview,
            self::Expired,
        ], true);
    }

    /** Whether asking again would just spend another provider call. */
    public function isSettled(): bool
    {
        return $this->hasResult() || $this === self::Failed;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
