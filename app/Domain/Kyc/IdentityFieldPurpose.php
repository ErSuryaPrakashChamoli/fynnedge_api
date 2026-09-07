<?php

namespace App\Domain\Kyc;

/**
 * Why FynnEdge keeps any identity field at all.
 *
 * An identity document carries far more than a product needs. Module 24
 * established that surplus extraction is data collected because it was
 * possible; on an identity document the same habit collects a PAN, an
 * Aadhaar number, a date of birth, an address, a parent's name and a
 * photograph. So every field is listed here with the purpose that justifies
 * it, and anything not listed is discarded by the normalizer.
 *
 * This map is the answer to "why do you have this?" for every value in the
 * kyc tables. If a field cannot be given a sentence here, it does not get
 * stored.
 */
final class IdentityFieldPurpose
{
    /**
     * The fields FynnEdge keeps, and why.
     *
     * @var array<string, string>
     */
    public const KEPT = [
        'name' => 'Compared against the name on the customer\'s profile. '
            .'This is the whole point of an identity check, and FynnEdge '
            .'already holds a name to compare it with.',

        'document_reference' => 'A masked tail of the document number — '
            .'never the number itself — so a customer can tell which of '
            .'their documents was checked when they hold more than one.',
    ];

    /**
     * The fields a provider will return that FynnEdge deliberately drops,
     * and why each one is refused.
     *
     * @var array<string, string>
     */
    public const DISCARDED = [
        'document_number' => 'The full number is never stored. FynnEdge does '
            .'not need to hold a PAN to know that a verification service '
            .'checked one.',

        'aadhaar_number' => 'Never stored, never logged, never displayed, '
            .'not even masked. FynnEdge holds no authorised role in the '
            .'Aadhaar ecosystem — see AadhaarBoundary.',

        'date_of_birth' => 'FynnEdge does not hold a date of birth, so there '
            .'is nothing to compare one against. Storing it to enable a '
            .'comparison nobody asked for would be collecting it first and '
            .'finding a use later.',

        'address' => 'FynnEdge holds a city, entered by the customer for '
            .'context. It does not do address verification and has no rule '
            .'that would use a verified address.',

        'gender' => 'Nothing in FynnEdge uses it.',
        'father_name' => 'Nothing in FynnEdge uses it.',
        'photograph' => 'Nothing in FynnEdge uses it. There is no face '
            .'matching, and storing a customer\'s photograph without one '
            .'would be holding biometric-adjacent data for no purpose.',
        'phone' => 'FynnEdge already has the number the customer signed in '
            .'with. A second one from a document has no purpose.',
    ];

    /**
     * Fields that may not even be named in a result.
     *
     * Reporting that a field was not compared is ordinarily useful — a
     * customer reading "we did not check your date of birth" learns
     * something, and silence would let them assume it was checked. Aadhaar
     * is the exception: FynnEdge holds no authorised role in that
     * ecosystem (see AadhaarBoundary), so a result that names an Aadhaar
     * number describes a check FynnEdge cannot have performed.
     *
     * @var list<string>
     */
    public const REFUSED_ENTIRELY = ['aadhaar_number'];

    /** Whether this field has a purpose that justifies keeping it. */
    public static function isKept(string $field): bool
    {
        return array_key_exists($field, self::KEPT);
    }

    /**
     * Whether a result may carry this field's actual value.
     *
     * Naming a field and holding its value are different acts. A result may
     * say that a date of birth was not compared; it may not carry the date.
     */
    public static function mayCarryValue(string $field): bool
    {
        return self::isKept($field);
    }

    /** Whether a result may mention this field at all. */
    public static function mayBeNamed(string $field): bool
    {
        return ! in_array($field, self::REFUSED_ENTIRELY, true);
    }
}
