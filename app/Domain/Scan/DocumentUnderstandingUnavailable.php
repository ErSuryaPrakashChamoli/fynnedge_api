<?php

namespace App\Domain\Scan;

use RuntimeException;
use Throwable;

/**
 * The provider did not produce a reading.
 *
 * Carries a canonical reason, never the provider's own code. A provider's
 * 429, its 500, its timeout and its "unsupported file" all become one of
 * these, so nothing downstream branches on a vendor's error vocabulary and
 * no vendor detail reaches a customer.
 */
class DocumentUnderstandingUnavailable extends RuntimeException
{
    public const PROVIDER_ERROR = 'provider_error';

    public const PROVIDER_TIMEOUT = 'provider_timeout';

    public const UNREADABLE_DOCUMENT = 'unreadable_document';

    public const UNSUPPORTED_DOCUMENT = 'unsupported_document';

    /*
     * Refusals that happen before a provider is reached.
     *
     * These strings match ExternalTransferRefused::reason(), so a reading
     * stopped at the transfer gate carries the gate's own explanation to
     * the customer rather than a generic "could not read", which would
     * describe the wrong thing entirely.
     */
    public const SAFETY_CHECK_FAILED = 'document_failed_safety_check';

    public const SAFETY_CHECK_INCONCLUSIVE = 'document_check_inconclusive';

    public const NO_MALWARE_SCANNER = 'malware_scanning_not_configured';

    /** The file changed while the reading was queued. */
    public const DOCUMENT_CHANGED = 'document_changed';

    /** The file was gone by the time the worker looked. */
    public const DOCUMENT_MISSING = 'document_missing';

    public function __construct(
        public readonly string $reason = self::PROVIDER_ERROR,
        string $message = 'The document could not be read.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * What a customer is told. Deliberately the same for every provider
     * fault: which upstream thing broke is not their problem to read.
     */
    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::UNREADABLE_DOCUMENT => 'We could not read this document. '
                .'A clearer photo or the original PDF usually works better.',
            self::UNSUPPORTED_DOCUMENT => 'FynnScan cannot read this kind of '
                .'document yet.',

            self::SAFETY_CHECK_FAILED => 'This document did not pass a '
                .'safety check, so FynnEdge has not sent it anywhere. '
                .'Please try a different file.',

            self::SAFETY_CHECK_INCONCLUSIVE => 'FynnEdge could not finish '
                .'checking this document, so it has not been sent anywhere.',

            self::NO_MALWARE_SCANNER => 'FynnEdge cannot send documents to '
                .'an external service yet. Your document is stored safely '
                .'and has not been sent anywhere.',

            self::DOCUMENT_CHANGED => 'This document changed while we were '
                .'getting to it, so nothing was read. Please try again.',

            self::DOCUMENT_MISSING => 'That document could not be read from '
                .'storage, so nothing was sent anywhere.',

            default => 'We could not read your document right now. Nothing '
                .'has been assumed about what it contains.',
        };
    }
}
