<?php

namespace App\Domain\Scan;

use App\Models\Document;
use Carbon\CarbonInterface;

/**
 * What FynnEdge knows about reading one document.
 *
 * There is no scan table. Nothing has ever been scanned, so persisting a row
 * per document to record that nothing happened would be storage without
 * information. When a provider exists and produces results worth keeping,
 * this is the shape those results take and persistence goes behind it.
 */
final class DocumentScan
{
    public function __construct(
        public readonly string $documentId,
        public readonly ScanStatus $status,
        /** Which provider, once one exists. Null while none does. */
        public readonly ?string $provider = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
        public readonly ?CarbonInterface $startedAt = null,
        public readonly ?CarbonInterface $completedAt = null,
    ) {}

    public static function unavailable(
        Document $document,
        string $reason,
        string $message,
    ): self {
        return new self(
            documentId: (string) $document->getKey(),
            status: ScanStatus::Unavailable,
            provider: null,
            reason: $reason,
            message: $message,
        );
    }

    public static function notStarted(Document $document): self
    {
        return new self(
            documentId: (string) $document->getKey(),
            status: ScanStatus::NotStarted,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Named, not described: no endpoint, credential, model name or
            // vendor detail belongs in a customer response.
            'provider' => $this->provider,

            'reason' => $this->reason,
            'message' => $this->message,

            'started_at' => $this->startedAt?->toIso8601String(),
            'completed_at' => $this->completedAt?->toIso8601String(),

            // The contract a future provider fills. Null, because nothing
            // has read this document — not an empty shape suggesting a
            // reading that found nothing.
            'extraction' => null,
            'confidence' => null,
        ];
    }
}
