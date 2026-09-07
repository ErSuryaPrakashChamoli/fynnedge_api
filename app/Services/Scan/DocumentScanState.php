<?php

namespace App\Services\Scan;

use App\Domain\Scan\DocumentFamily;
use App\Domain\Scan\DocumentUnderstandingUnavailable;
use App\Domain\Scan\ScanStatus;
use App\Models\Document;
use App\Models\DocumentScan;
use App\Models\DocumentScanField;
use App\Models\DocumentScanReview;

/**
 * What FynnScan can tell the customer about one document, right now.
 *
 * Everything a screen needs and nothing it does not: no storage key, no raw
 * provider response, no credential, no stack trace, no provider error code,
 * and no provider reference.
 */
final class DocumentScanState
{
    private function __construct(
        public readonly Document $document,
        public readonly ScanStatus $status,
        public readonly ?DocumentScan $scan = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,

        /** True when the reading no longer describes the stored file. */
        public readonly bool $isStale = false,
    ) {}

    public const NOT_CONFIGURED = 'document_understanding_not_configured';

    public const UNSUPPORTED = 'document_family_not_supported';

    /*
     * One explanation per fact.
     *
     * Reading the state and asking for a scan describe the same situation,
     * so they say the same words. Two different explanations for one fact
     * is the sort of small inconsistency that makes a customer wonder which
     * of the two is the real answer.
     */
    public const NOT_CONFIGURED_MESSAGE = 'Your document is stored safely, '
        .'but FynnEdge is not connected to a document-reading service. '
        .'Nothing has been extracted or interpreted.';

    public const UNSUPPORTED_MESSAGE = 'FynnScan does not read this kind of '
        .'document. It is stored safely and nothing has been read from it.';

    public static function unavailable(Document $document): self
    {
        return new self(
            document: $document,
            status: ScanStatus::Unavailable,
            reason: self::NOT_CONFIGURED,
            message: self::NOT_CONFIGURED_MESSAGE,
        );
    }

    public static function unsupported(Document $document): self
    {
        return new self(
            document: $document,
            status: ScanStatus::Unavailable,
            reason: self::UNSUPPORTED,
            message: self::UNSUPPORTED_MESSAGE,
        );
    }

    public static function consentRequired(Document $document): self
    {
        return new self(
            document: $document,
            status: ScanStatus::ConsentRequired,
            reason: 'document_processing_consent_required',
            message: 'FynnEdge has not sent this document anywhere, and will '
                .'not until you allow it.',
        );
    }

    public static function notStarted(Document $document): self
    {
        return new self($document, ScanStatus::NotStarted);
    }

    public static function of(
        Document $document,
        DocumentScan $scan,
        bool $isStale,
    ): self {
        return new self(
            document: $document,
            status: $scan->status(),
            scan: $scan,
            reason: $scan->failure_reason,
            message: $scan->failure_reason === null
                ? null
                : (new DocumentUnderstandingUnavailable($scan->failure_reason))
                    ->customerMessage(),
            isStale: $isStale,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $scan = $this->scan;
        $family = DocumentFamily::forType($this->document->type());

        return [
            'document_id' => (string) $this->document->getKey(),
            'scan_id' => $scan === null ? null : (string) $scan->getKey(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'reason' => $this->reason,
            'message' => $this->message,

            // Which family this document belongs to, and whether FynnScan
            // reads it. Stated rather than implied, so the app never has to
            // infer support from an empty result.
            'family' => $family?->value,
            'is_supported' => $family !== null,
            'schema_version' => $scan?->schema_version,

            // Named, not described: no endpoint, credential, model name or
            // vendor detail belongs in a customer response.
            'provider' => $scan?->provider,

            /*
             * Whether a fictional provider produced this.
             *
             * Set from the adapter when the scan was written and never from
             * a request, so a sample reading cannot be displayed as a real
             * one however the client asks.
             */
            'is_sample' => (bool) ($scan?->is_sample ?? false),

            // What the provider thought this was, kept apart from what the
            // customer filed it as. A disagreement is reported, never acted
            // on: FynnEdge does not re-file a customer's document for them.
            'classified_as' => $scan?->classified_as,
            'classification_mismatch' => $this->hasClassificationMismatch(),

            // The period the document covers, which is not when it was read.
            'document_period' => $scan?->document_period,
            'processed_at' => $scan?->completed_at?->toIso8601String(),
            'started_at' => $scan?->started_at?->toIso8601String(),

            /*
             * True when the file behind this document has changed since it
             * was read. The extraction described different bytes, so it is
             * not presented as describing these.
             */
            'is_stale' => $this->isStale,

            'extraction' => $this->extraction(),
            'reviews' => $this->reviews(),

            /*
             * The sentence that keeps this feature honest.
             *
             * FynnScan reads a document. It does not check one, and nothing
             * it returns is the customer's data until they say it is.
             */
            'disclaimer' => 'FynnScan reads what a document appears to '
                .'contain. It does not verify your document, and nothing '
                .'here is saved to your profile until you confirm it.',
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function extraction(): ?array
    {
        if ($this->scan === null || ! $this->status->hasExtraction()) {
            // Null, not an empty list. An empty extraction reads downstream
            // as a reading that ran and found nothing.
            return null;
        }

        return $this->scan->fields
            ->map(fn (DocumentScanField $f) => $f->toField()->toArray())
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function reviews(): array
    {
        if ($this->scan === null) {
            return [];
        }

        return $this->scan->reviews
            ->map(fn (DocumentScanReview $r) => $r->toArray())
            ->values()
            ->all();
    }

    private function hasClassificationMismatch(): bool
    {
        $family = DocumentFamily::forType($this->document->type());

        return $this->scan?->classified_as !== null
            && $family !== null
            && $this->scan->classified_as !== $family->value;
    }
}
