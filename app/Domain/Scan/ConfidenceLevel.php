<?php

namespace App\Domain\Scan;

/**
 * How sure the provider was, on FynnEdge's own scale.
 *
 * Deliberately not a number. A provider's 0.97 means whatever that
 * provider's model defines it to mean, and showing it to a customer as
 * "97%" would invent a precision and an interpretation nobody measured.
 *
 * Providers whose confidence semantics are undocumented map to Unavailable
 * rather than being guessed onto this scale. Unavailable is not low
 * confidence — it means the provider said nothing about how sure it was.
 */
enum ConfidenceLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High confidence',
            self::Medium => 'Worth checking',
            self::Low => 'Please check this',
            self::Unavailable => 'Confidence unknown',
        };
    }

    /**
     * Whether a value at this confidence must be looked at before it can be
     * used.
     *
     * Anything but High does. This is a per-family threshold — it lives here
     * because one family exists; a second family with different provider
     * semantics gets its own, rather than inheriting a number that happened
     * to suit the first.
     */
    public function needsReview(): bool
    {
        return $this !== self::High;
    }
}
