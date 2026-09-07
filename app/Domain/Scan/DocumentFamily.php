<?php

namespace App\Domain\Scan;

use App\Domain\Vault\DocumentType;

/**
 * The document families FynnScan can actually extract from.
 *
 * Deliberately one. FynnEdge has exactly one place a document-derived figure
 * can go — the customer's financial profile — and monthly income is the
 * field that reaches it. Extracting a PAN, a property valuation or a
 * statement's transaction history would produce information the product has
 * nowhere to put and no rule for interpreting, which is data collected
 * because it was possible rather than because it was needed.
 *
 * The other five vault types are not unsupported by oversight. Each was
 * considered and left out:
 *
 *   identity   identity extraction without verification is a claim FynnEdge
 *              cannot back (see Module 25's boundary). PAN and Aadhaar are
 *              deliberately never extracted.
 *   bank       a statement analyser is a financial-rules feature, not an
 *              extraction one. Nothing in FynnEdge consumes one yet.
 *   loan       an extracted EMI is not proof of a live liability, and no
 *              rule exists for treating it as one.
 *   property   OCR establishes no market value, title or ownership.
 *   other      by definition unclassified.
 */
enum DocumentFamily: string
{
    case Income = 'income';

    /** The vault type this family reads. */
    public function documentType(): DocumentType
    {
        return match ($this) {
            self::Income => DocumentType::Income,
        };
    }

    /** The extraction schema in force for this family. */
    public function schemaVersion(): string
    {
        return match ($this) {
            self::Income => 'income_v1',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Income document',
        };
    }

    /**
     * The family for a vault type, or null when FynnScan does not read it.
     *
     * Null is a real answer, not a gap: the customer is told the document is
     * stored and not read, rather than shown an empty extraction.
     */
    public static function forType(DocumentType $type): ?self
    {
        return match ($type) {
            DocumentType::Income => self::Income,
            default => null,
        };
    }

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return array_map(
            fn (self $f) => $f->documentType()->value,
            self::cases(),
        );
    }
}
