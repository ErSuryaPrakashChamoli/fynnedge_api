<?php

namespace App\Services\ApplicationDocuments;

use App\Domain\ApplicationDocuments\DocumentRequirement;
use App\Domain\ApplicationDocuments\RequirementStatus;
use App\Models\ApplicationDocument;
use App\Models\Document;

/**
 * One requirement as the customer sees it.
 *
 * Carries enough to identify the document they chose — its name, type and
 * when they stored it — and nothing about where it lives. No storage key, no
 * disk, no path, no URL. The document is fetched, as it always was, through
 * the vault's own authenticated endpoint.
 */
final class ChecklistItem
{
    public function __construct(
        public readonly DocumentRequirement $requirement,
        public readonly RequirementStatus $status,
        public readonly ?Document $document = null,
        public readonly ?ApplicationDocument $choice = null,

        /** The document the customer chose is no longer in the vault. */
        public readonly bool $unavailable = false,

        /** The file changed since they confirmed it. */
        public readonly bool $stale = false,
    ) {}

    /** Whether this requirement is satisfied for readiness purposes. */
    public function isSatisfied(): bool
    {
        return $this->status->satisfiesRequirement();
    }

    /** Whether this requirement blocks the application. */
    public function blocks(): bool
    {
        return $this->requirement->required && ! $this->isSatisfied();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->requirement->toArray(), [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'action' => $this->status->action(),

            // Blocking is stated rather than left for a client to work out
            // from `required` and `status`, so there is one answer to it.
            'blocks_readiness' => $this->blocks(),

            /*
             * The chosen document, by the facts a customer recognises it by.
             *
             * The id is here because the app needs it to open the document
             * through FynnVault's own authenticated route — which remains
             * the only way to read one.
             */
            'document' => $this->document === null ? null : [
                'id' => (string) $this->document->getKey(),
                'name' => $this->document->display_name,
                'type' => $this->document->type,
                'size_bytes' => $this->document->size_bytes,
                'stored_at' => $this->document->created_at?->toIso8601String(),
            ],

            'confirmed_at' => $this->choice?->reviewed_at?->toIso8601String(),

            /*
             * Why the customer is being asked to look again.
             *
             * Two different things happen here and they need different
             * sentences: a document that is gone, and a document whose
             * contents changed after it was confirmed.
             */
            'notice' => match (true) {
                $this->unavailable => 'The document you chose is no longer '
                    .'in FynnVault. Please choose another one.',
                $this->stale => 'This document has changed since you '
                    .'confirmed it. Please check it again.',
                default => null,
            },
        ]);
    }
}
