<?php

namespace App\Domain\Catalog;

/**
 * Phrases a product record may not contain.
 *
 * No application FynnEdge creates reaches a provider, so nothing in a
 * product's copy may describe how one behaves, how quickly it moves, or how
 * likely it is to say yes. Nor may a record in a six-product sample
 * catalogue claim to be the best of anything in a market it cannot see.
 *
 * The list is checked when a version is published, so bad copy never enters
 * the catalogue rather than being found later by a scan.
 *
 * NOTE TO THE LANGUAGE SCAN: this file holds the denylist itself, so every
 * phrase below appears here by design. test/product_language_test.dart skips
 * this one file for that reason, and only this one.
 */
final class UnsupportedProductClaims
{
    /** @var list<string> Lowercase; matched as substrings. */
    private const PHRASES = [
        'guaranteed approval',
        'instant approval',
        'approval chances',
        'guaranteed disbursal',
        'easy approval',
        'quick approval',
        'likely to approve',
        'pre-approved',
        'most lenders',
        'fastest',
        'cheapest',
        'lowest in india',
        'best lender',
        // No application reaches a provider, so nothing can know how many
        // days one takes.
        'working days',
    ];

    /**
     * The first unsupported claim in this text, or null if there is none.
     */
    public static function firstIn(string $text): ?string
    {
        $haystack = strtolower($text);

        foreach (self::PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::PHRASES;
    }
}
