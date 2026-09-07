<?php

namespace App\Domain\Kyc;

use App\Domain\Vault\DocumentType;

/**
 * What FynnEdge asks a verification service to establish.
 *
 * Deliberately one. An identity document is checked, and what comes back is
 * two separate findings that are never merged: whether the provider's own
 * authenticity checks passed, and whether the identity on the document
 * matches what the customer told FynnEdge.
 *
 * Considered and left out, each for a reason rather than by oversight:
 *
 *   face match          needs a provider that does it and a legal basis for
 *                       collecting a customer's face. FynnEdge has neither.
 *   liveness / selfie   a separate capability with its own consent.
 *   Aadhaar e-KYC       FynnEdge holds no authorised role in that ecosystem.
 *                       See AadhaarBoundary.
 *   bank penny-drop     account verification, not identity verification.
 */
enum VerificationType: string
{
    /**
     * An identity document, checked and matched against the profile.
     *
     * The method is: the provider runs its own document checks, and returns
     * the identity fields it read; FynnEdge compares those against what the
     * customer has already told it. Two findings, reported separately.
     */
    case IdentityDocument = 'identity_document';

    public function label(): string
    {
        return match ($this) {
            self::IdentityDocument => 'Identity document check',
        };
    }

    /**
     * The method, in words a customer can read.
     *
     * A status is meaningless without it. "Verified" has to answer
     * "verified how?", or it is a badge rather than a finding.
     */
    public function method(): string
    {
        return match ($this) {
            self::IdentityDocument => 'The verification service ran its own '
                .'checks on the document, and read the identity details off '
                .'it. FynnEdge compared those details with the ones on your '
                .'profile.',
        };
    }

    /** The vault type this reads. */
    public function documentType(): DocumentType
    {
        return match ($this) {
            self::IdentityDocument => DocumentType::Identity,
        };
    }

    /** Null when FynnEdge does not verify that kind of document. */
    public static function forType(DocumentType $type): ?self
    {
        return match ($type) {
            DocumentType::Identity => self::IdentityDocument,
            default => null,
        };
    }
}
