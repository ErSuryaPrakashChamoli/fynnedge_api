<?php

namespace App\Services\Kyc;

use App\Domain\Kyc\FieldMatch;
use App\Domain\Kyc\VerificationStatus;
use App\Domain\Kyc\VerificationType;
use App\Domain\Kyc\VerificationUnavailable;
use App\Models\Document;
use App\Models\KycFieldMatch;
use App\Models\KycVerification;

/**
 * What FynnEdge can tell the customer about one document's verification.
 *
 * Everything a screen needs and nothing it does not: no storage key, no raw
 * provider response, no credential, no stack trace, no provider result code,
 * no provider reference, and no identity value beyond the one side of a
 * difference the customer has to resolve.
 */
final class KycVerificationState
{
    private function __construct(
        public readonly Document $document,
        public readonly VerificationStatus $status,
        public readonly ?KycVerification $verification = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
        public readonly bool $isStale = false,
    ) {}

    public const NOT_CONFIGURED = 'verification_not_configured';

    public const UNSUPPORTED = 'document_type_not_verifiable';

    /*
     * One explanation per fact, shared by every route that reports it.
     *
     * "Not available" and "could not be verified" are the two sentences this
     * whole module exists to keep apart, so the first is written once, here,
     * and never paraphrased into the second.
     */
    public const NOT_CONFIGURED_MESSAGE = 'Identity verification is not '
        .'available yet. FynnEdge is not connected to a verification '
        .'service, so nothing about your document has been checked.';

    public const UNSUPPORTED_MESSAGE = 'FynnEdge does not check this kind of '
        .'document. It is stored safely and nothing has been checked.';

    public static function unavailable(Document $document): self
    {
        return new self(
            document: $document,
            status: VerificationStatus::Unavailable,
            reason: self::NOT_CONFIGURED,
            message: self::NOT_CONFIGURED_MESSAGE,
        );
    }

    public static function unsupported(Document $document): self
    {
        return new self(
            document: $document,
            status: VerificationStatus::Unavailable,
            reason: self::UNSUPPORTED,
            message: self::UNSUPPORTED_MESSAGE,
        );
    }

    public static function consentRequired(Document $document): self
    {
        return new self(
            document: $document,
            status: VerificationStatus::ConsentRequired,
            reason: 'identity_verification_consent_required',
            message: 'FynnEdge has not sent this document anywhere, and will '
                .'not until you allow it.',
        );
    }

    public static function notStarted(Document $document): self
    {
        return new self($document, VerificationStatus::NotStarted);
    }

    public static function of(
        Document $document,
        KycVerification $verification,
        bool $isStale,
    ): self {
        return new self(
            document: $document,
            status: $verification->status(),
            verification: $verification,
            reason: $verification->failure_reason,
            message: $verification->failure_reason === null
                ? null
                : (new VerificationUnavailable($verification->failure_reason))
                    ->customerMessage(),
            isStale: $isStale,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $verification = $this->verification;
        $type = VerificationType::forType($this->document->type());

        return [
            'document_id' => (string) $this->document->getKey(),
            'verification_id' => $verification === null
                ? null
                : (string) $verification->getKey(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'reason' => $this->reason,
            'message' => $this->message,

            'verification_type' => $type?->value,
            'is_supported' => $type !== null,

            // What was actually done, in words. A status without its method
            // is a badge rather than a finding.
            'method' => $type?->method(),

            // Named, not described: no endpoint, credential, engine name or
            // vendor detail belongs in a customer response.
            'provider' => $verification?->provider,

            /*
             * Whether a fictional service produced this.
             *
             * Set from the adapter when the record was written and never
             * from a request, so a synthetic result cannot be displayed as
             * a real verification however the client asks.
             */
            'is_sample' => (bool) ($verification?->is_sample ?? false),

            // The two findings, apart. A document can pass its own checks
            // while the identity on it belongs to somebody else.
            'document_result' => $verification?->document_result,
            'document_result_label' => $verification?->documentOutcome()?->label(),
            'identity_result' => $verification?->identity_result,
            'identity_result_label' => $verification?->identityOutcome()?->label(),

            // A tail, so the customer can tell which of their documents was
            // checked. Never the number.
            'masked_reference' => $verification?->masked_reference,

            'checked_at' => $verification?->checked_at?->toIso8601String(),

            /*
             * When this result stops being current.
             *
             * Always null today: no provider contract and no written policy
             * defines an expiry, so FynnEdge does not invent one.
             */
            'expires_at' => $verification?->expires_at?->toIso8601String(),

            /*
             * True when the file behind this document has changed since it
             * was checked. The result described different bytes, so it is
             * not presented as describing these.
             */
            'is_stale' => $this->isStale,

            'field_matches' => $this->matches(),
            'differences' => $this->differences(),

            /*
             * The sentence that keeps this feature honest.
             */
            'disclaimer' => 'A verification result says what a verification '
                .'service established about a document. It is not a lending '
                .'decision, it is not approval, and it does not change '
                .'anything on your profile.',
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function matches(): ?array
    {
        if ($this->verification === null || ! $this->status->hasResult()) {
            // Null, not an empty list. An empty comparison reads downstream
            // as a check that ran and found nothing to disagree with.
            return null;
        }

        return $this->verification->matches
            ->map(fn (KycFieldMatch $m) => $m->toMatch()->toArray())
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function differences(): array
    {
        return array_values(array_filter(
            $this->matches() ?? [],
            fn (array $m) => $m['status'] === 'mismatched',
        ));
    }

    /** @return list<FieldMatch> */
    public function mismatchedFields(): array
    {
        if ($this->verification === null) {
            return [];
        }

        return $this->verification->matches
            ->map(fn (KycFieldMatch $m) => $m->toMatch())
            ->filter(fn (FieldMatch $m) => $m->status->value === 'mismatched')
            ->values()
            ->all();
    }
}
