<?php

namespace App\Domain\Scan;

use App\Models\Document;

/**
 * Whatever eventually reads a document.
 *
 * The domain names no vendor. A future adapter for a hosted document
 * intelligence service, a self-hosted model, or a human review desk
 * implements this and nothing above it changes.
 *
 * An adapter authenticates to its provider, transfers the file, makes the
 * request and turns the provider's failures into canonical ones. It does
 * NOT update customer profiles, decide eligibility, touch FynnScore, create
 * applications or change financial goals — and it never writes to the
 * database at all.
 */
interface DocumentUnderstandingProvider
{
    /** False while nothing can read a document. */
    public function isAvailable(): bool;

    /** How this provider identifies itself in stored records. */
    public function name(): ?string;

    /**
     * Whether a fictional provider is behind this.
     *
     * Server-side and provider-owned. Nothing a client sends can change it,
     * and it travels unchanged all the way to the app so a sample reading
     * can never be displayed as a real one.
     */
    public function isSample(): bool;

    /** Whether this provider reads documents of this family at all. */
    public function supports(DocumentFamily $family): bool;

    /**
     * Reads a document and returns the provider's own response.
     *
     * Bytes are handed in, having been read from private storage by the
     * caller: an adapter never resolves a storage key and no provider is
     * ever given a URL to the vault.
     *
     * @return array<string, mixed> the provider's raw response, in its own
     *                              vocabulary, for a normalizer to translate
     *
     * @throws DocumentUnderstandingUnavailable
     */
    public function process(
        Document $document,
        string $bytes,
        DocumentFamily $family,
    ): array;
}
