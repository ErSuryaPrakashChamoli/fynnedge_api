<?php

namespace App\Domain\Scan;

use App\Domain\Money;

/**
 * One value FynnScan believes it found in a document.
 *
 * "Believes it found" is the whole point. A field carries what was read,
 * how sure the provider was, and where in the document it came from — and
 * nothing about whether it is true. Whether it is true is the customer's
 * answer, recorded separately in a review.
 */
final class ExtractedField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,

        /** As read, normalized to FynnEdge's own representation. */
        public readonly ?string $value,

        /** money | text | period. Decides how the app renders it. */
        public readonly string $valueType,

        public readonly ConfidenceLevel $confidence,

        /** Where in the document. Null when the provider gave no evidence. */
        public readonly ?FieldEvidence $evidence = null,

        /**
         * Why this field needs looking at, when it does. Set for a missing
         * value, an ambiguous one, or two candidates the document offers
         * without saying which is meant.
         */
        public readonly ?string $reviewReason = null,

        /**
         * A monetary value in paise. Only ever set from Money, never from a
         * float: an income read off a payslip is money and is subject to the
         * same representation as every other rupee in FynnEdge.
         */
        public readonly ?int $paise = null,
    ) {}

    public static function money(
        string $key,
        string $label,
        Money $amount,
        ConfidenceLevel $confidence,
        ?FieldEvidence $evidence = null,
        ?string $reviewReason = null,
    ): self {
        return new self(
            key: $key,
            label: $label,
            value: $amount->toDecimalString(),
            valueType: 'money',
            confidence: $confidence,
            evidence: $evidence,
            reviewReason: $reviewReason,
            paise: $amount->paise,
        );
    }

    public static function text(
        string $key,
        string $label,
        ?string $value,
        ConfidenceLevel $confidence,
        ?FieldEvidence $evidence = null,
        ?string $reviewReason = null,
        string $valueType = 'text',
    ): self {
        return new self(
            key: $key,
            label: $label,
            value: $value,
            valueType: $valueType,
            confidence: $confidence,
            evidence: $evidence,
            reviewReason: $reviewReason,
        );
    }

    /** A field the provider could not read at all. */
    public static function missing(
        string $key,
        string $label,
        string $valueType,
        string $reason,
    ): self {
        return new self(
            key: $key,
            label: $label,
            value: null,
            valueType: $valueType,
            confidence: ConfidenceLevel::Unavailable,
            reviewReason: $reason,
        );
    }

    public function isPresent(): bool
    {
        return $this->value !== null;
    }

    /**
     * A field must be looked at when it is missing, when the provider was
     * unsure, or when something about it was ambiguous.
     */
    public function needsReview(): bool
    {
        return $this->reviewReason !== null
            || ! $this->isPresent()
            || $this->confidence->needsReview();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'value_type' => $this->valueType,

            // Money travels as its own number as well, so the app never
            // parses a display string back into an amount.
            'amount' => $this->paise === null
                ? null
                : Money::fromPaise($this->paise)->toJsonNumber(),

            'confidence' => $this->confidence->value,
            'confidence_label' => $this->confidence->label(),

            'needs_review' => $this->needsReview(),
            'review_reason' => $this->reviewReason,

            'evidence' => $this->evidence?->toArray(),
        ];
    }
}
