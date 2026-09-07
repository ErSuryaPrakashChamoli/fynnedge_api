<?php

namespace App\Domain\Scan;

use App\Models\Document;

/**
 * The provider FynnEdge has: none.
 *
 * No document-understanding service has been selected, so this reports that
 * plainly rather than simulating one. A provider returning invented names,
 * PAN numbers or balances would be indistinguishable, to the customer and to
 * every screen downstream, from a real reading of their document.
 *
 * It cannot be asked to read anything: process() refuses rather than
 * returning an empty result, which would read downstream as a scan that ran
 * and found nothing.
 */
class UnavailableDocumentUnderstandingProvider implements DocumentUnderstandingProvider
{
    public const REASON = 'document_understanding_not_configured';

    public const MESSAGE = 'Your document is stored safely, but FynnEdge is '
        .'not connected to a document-reading service. Nothing has been '
        .'extracted or interpreted.';

    public function isAvailable(): bool
    {
        return false;
    }

    public function name(): ?string
    {
        return null;
    }

    public function isSample(): bool
    {
        return false;
    }

    public function supports(DocumentFamily $family): bool
    {
        return false;
    }

    public function process(
        Document $document,
        string $bytes,
        DocumentFamily $family,
    ): array {
        throw new DocumentUnderstandingUnavailable(
            DocumentUnderstandingUnavailable::PROVIDER_ERROR,
            self::MESSAGE,
        );
    }
}
