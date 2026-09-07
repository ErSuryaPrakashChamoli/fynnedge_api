<?php

namespace App\Domain\Lender;

use RuntimeException;
use Throwable;

/**
 * The submission did not reach a provider, or may not have.
 *
 * Carries a canonical reason, never the provider's own code. The reason
 * decides how the outcome is recorded, and the important split is
 * [TIMEOUT_AFTER_SEND] against everything else: a request that left and was
 * never answered has an unknown fate, not a failed one.
 */
class LenderSubmissionUnavailable extends RuntimeException
{
    /** No submission channel is configured, or its credentials are absent. */
    public const NOT_CONFIGURED = 'lender_provider_unavailable';

    /** The provider answered with an error. */
    public const PROVIDER_ERROR = 'provider_error';

    /** The provider is rate limiting. */
    public const RATE_LIMITED = 'provider_rate_limited';

    /** The request never left, so nothing arrived. */
    public const NOT_SENT = 'not_sent';

    /**
     * The request left and no answer came back.
     *
     * The provider may or may not hold a copy. This must never become a
     * failure, and must never lead to an automatic retry.
     */
    public const TIMEOUT_AFTER_SEND = 'provider_timeout';

    public function __construct(
        public readonly string $reason = self::PROVIDER_ERROR,
        string $message = 'The application could not be submitted.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Whether the provider may nonetheless hold the application. */
    public function outcomeIsUnknown(): bool
    {
        return $this->reason === self::TIMEOUT_AFTER_SEND;
    }

    public function outcome(): SubmissionOutcome
    {
        return match ($this->reason) {
            self::TIMEOUT_AFTER_SEND => SubmissionOutcome::Unknown,
            self::NOT_CONFIGURED => SubmissionOutcome::Unavailable,
            default => SubmissionOutcome::Failed,
        };
    }

    /**
     * What a customer is told.
     *
     * None of these says a loan was declined, because none of them is a
     * lending decision — they are all facts about a delivery attempt.
     */
    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::NOT_CONFIGURED => 'FynnEdge cannot send applications to a '
                .'provider yet.',
            self::RATE_LIMITED => 'The application provider is busy right '
                .'now. Your application is safe in FynnEdge — please try '
                .'again shortly.',
            self::TIMEOUT_AFTER_SEND => 'We could not confirm whether the '
                .'provider received your application. Please do not send it '
                .'again until we know — contact support and we will check.',
            self::NOT_SENT => 'Your application was not sent. Nothing has '
                .'reached a provider, and nothing about your application has '
                .'changed.',
            default => 'The application provider is temporarily unavailable. '
                .'Your application is safe in FynnEdge.',
        };
    }
}
