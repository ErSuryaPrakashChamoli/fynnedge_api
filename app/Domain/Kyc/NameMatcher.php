<?php

namespace App\Domain\Kyc;

/**
 * Whether two names are the same name.
 *
 * This is a deliberately small policy, and the smallness is the point. Name
 * matching is a field with no clean answer: transliteration, initials,
 * patronymics, married names, honorifics and clerical error all produce
 * differences that a person can resolve in a second and an algorithm cannot
 * resolve at all. FynnEdge does not attempt to.
 *
 * So the rule is narrow: normalize away what is certainly presentational,
 * compare, and hand anything that survives to the customer.
 *
 * Normalized away — none of these change who is named:
 *   case, surrounding and repeated whitespace, punctuation used as
 *   separators (full stops, hyphens, apostrophes, commas), and leading
 *   honorifics.
 *
 * Never normalized away:
 *   a missing name part, an extra name part, a different spelling, an
 *   initial standing where a word is, or a different word order.
 *
 * "Rahul Kumar" against "Rahul Kumar Sharma" is a mismatch, and a mismatch
 * is a question for the customer rather than a verdict about them. Guessing
 * that the surname was simply omitted would be inventing agreement; guessing
 * the opposite would be inventing an accusation.
 */
final class NameMatcher
{
    /** Titles that name nobody. */
    private const HONORIFICS = [
        'mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'prof', 'shri', 'smt', 'sri',
    ];

    /**
     * Compares a profile name against one a verification service returned.
     *
     * Returns UnableToCompare when either side is absent. That is not a
     * mismatch: FynnEdge simply has nothing to put alongside the other.
     */
    public static function compare(?string $profile, ?string $verified): MatchStatus
    {
        $left = self::normalize($profile);
        $right = self::normalize($verified);

        if ($left === '' || $right === '') {
            return MatchStatus::UnableToCompare;
        }

        return $left === $right
            ? MatchStatus::Matched
            : MatchStatus::Mismatched;
    }

    /**
     * The presentational form stripped away, and nothing else.
     */
    public static function normalize(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        // An apostrophe sits inside a word, so it is removed rather than
        // spaced: O'Brien and OBrien are one name written two ways.
        $value = preg_replace('/[\'’]+/u', '', $name) ?? '';

        // The rest separate words, so they become spaces rather than
        // vanishing — "Rahul.Kumar" stays two words instead of collapsing
        // into one.
        $value = preg_replace('/[.\-,]+/u', ' ', $value) ?? '';

        // Anything else that is not a letter, a digit or a space goes.
        $value = preg_replace('/[^\p{L}\p{N} ]+/u', '', $value) ?? '';

        $value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));

        if ($value === '') {
            return '';
        }

        $words = explode(' ', $value);

        // Only a leading honorific is dropped. "Dr" in the middle of a name
        // is part of the name as written, and removing it there would be
        // editing rather than normalizing.
        if (count($words) > 1 && in_array($words[0], self::HONORIFICS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }
}
