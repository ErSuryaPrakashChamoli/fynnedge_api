<?php

namespace App\Domain\Kyc;

use RuntimeException;
use Throwable;

/**
 * The provider did not produce a result.
 *
 * Carries a canonical reason, never the provider's own code. A provider's
 * 401, its 429, its 500 and its timeout all become one of these, so nothing
 * downstream branches on a vendor's error vocabulary and no vendor detail
 * reaches a customer.
 *
 * Every one of these is a failure of the check, never a finding about the
 * customer.
 */
class VerificationUnavailable extends RuntimeException
{
    /** The provider was reachable and refused, errored, or was rate limited. */
    public const PROVIDER_ERROR = 'provider_error';

    public const PROVIDER_TIMEOUT = 'provider_timeout';

    /** Configured for a real provider whose credentials are absent. */
    public const PROVIDER_NOT_CONFIGURED = 'kyc_provider_unavailable';

    /** The provider could not read the file well enough to check it. */
    public const UNREADABLE_DOCUMENT = 'unreadable_document';

    /** The provider does not handle this kind of document. */
    public const UNSUPPORTED_DOCUMENT = 'unsupported_document';

    public function __construct(
        public readonly string $reason = self::PROVIDER_ERROR,
        string $message = 'The verification could not be completed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * What a customer is told.
     *
     * None of these says anything about the customer or their document's
     * genuineness, because none of these establishes anything about either.
     */
    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::UNREADABLE_DOCUMENT => 'The verification service could not '
                .'read this document clearly enough to check it. A clearer '
                .'photo or the original file usually works better.',
            self::UNSUPPORTED_DOCUMENT => 'The verification service does not '
                .'check this kind of document.',
            self::PROVIDER_NOT_CONFIGURED => 'Identity verification is not '
                .'available right now.',
            default => 'We could not complete the check right now. Nothing '
                .'has been concluded about you or your document.',
        };
    }
}
