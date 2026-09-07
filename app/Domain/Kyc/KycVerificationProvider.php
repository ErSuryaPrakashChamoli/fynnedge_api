<?php

namespace App\Domain\Kyc;

use App\Models\Document;

/**
 * Whatever eventually verifies an identity document.
 *
 * The domain names no vendor. A future adapter for an authorised KYC
 * partner implements this and nothing above it changes.
 *
 * An adapter authenticates to its provider, transfers what the provider
 * needs, makes the request, and turns the provider's failures into canonical
 * ones. It does NOT update profiles, touch FynnScore, decide eligibility,
 * create or submit applications, or write to the database at all.
 */
interface KycVerificationProvider
{
    /** False while nothing can verify anything. */
    public function isAvailable(): bool;

    /** How this provider identifies itself in stored records. */
    public function name(): ?string;

    /**
     * Whether a fictional provider is behind this.
     *
     * Server-side and provider-owned. Nothing a client sends can change it,
     * and it travels unchanged to the app so a synthetic result can never
     * be displayed as a real verification.
     */
    public function isSample(): bool;

    /** Whether this provider performs this kind of verification. */
    public function supports(VerificationType $type): bool;

    /**
     * Verifies a document and returns the provider's own response.
     *
     * Bytes are handed in, having been read from private storage by the
     * caller: an adapter never resolves a storage key and no provider is
     * given a URL to the vault.
     *
     * @return array<string, mixed> the provider's raw response, in its own
     *                              vocabulary, for a normalizer to translate
     *
     * @throws VerificationUnavailable
     */
    public function verify(
        Document $document,
        string $bytes,
        VerificationType $type,
    ): array;
}
