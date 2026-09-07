<?php

namespace App\Domain\Kyc\Fixture;

use App\Domain\Kyc\CanonicalVerification;
use App\Domain\Kyc\CheckOutcome;
use App\Domain\Kyc\FieldMatch;
use App\Domain\Kyc\KycNormalizer;
use App\Domain\Kyc\MatchStatus;
use App\Domain\Kyc\NameMatcher;
use App\Domain\Kyc\VerificationNormalizationFailed;
use App\Domain\Kyc\VerificationType;
use App\Models\CustomerProfile;
use Illuminate\Support\Carbon;

/**
 * An identity verification result, translated.
 *
 * Provider field                →  FynnEdge
 *   checkOutcome / docAuthenticity.result
 *                               →  document_result (passed | failed |
 *                                  inconclusive)
 *   holder.holderName           →  compared against the profile name
 *   holder.idNumber             →  masked to a tail, never stored whole
 *   referenceId                 →  provider_reference (operational only)
 *   engineVersion               →  method_version (operational only)
 *
 * Dropped entirely: holder.aadhaarNumber, dateOfBirth, gender, fatherName,
 * address, phone, photograph, and rawText. Every one of them is listed in
 * IdentityFieldPurpose::DISCARDED with the reason it is refused.
 *
 * The comparison lives here too, because deciding what a provider's answer
 * means when set beside FynnEdge's own record is part of translating it into
 * something FynnEdge can stand behind.
 */
class IdentityDocumentNormalizer implements KycNormalizer
{
    public function type(): VerificationType
    {
        return VerificationType::IdentityDocument;
    }

    public function normalize(
        array $response,
        ?CustomerProfile $profile,
    ): CanonicalVerification {
        $outcome = $response['docAuthenticity']['result']
            ?? $response['checkOutcome']
            ?? null;

        if (! is_string($outcome)) {
            // A response with no outcome could be coerced into a plausible
            // reading, and the plausible reading of a verification response
            // is "verified". Refuse it instead.
            throw new VerificationNormalizationFailed(
                'the response carried no verification outcome',
            );
        }

        $documentResult = $this->documentOutcome($outcome);
        $holder = is_array($response['holder'] ?? null) ? $response['holder'] : [];
        $verifiedName = $this->name($holder);

        $matches = [$this->nameMatch($profile, $verifiedName)];

        // FynnEdge holds no date of birth, so there is nothing to set the
        // provider's against. Reported rather than omitted: "we did not
        // compare this" is information, and silence would let a customer
        // assume it was checked.
        $matches[] = FieldMatch::unableToCompare(
            'date_of_birth',
            'Date of birth',
            'FynnEdge does not hold your date of birth, so there was nothing '
                .'to compare against.',
        );

        return new CanonicalVerification(
            type: VerificationType::IdentityDocument,
            documentResult: $documentResult,
            identityResult: $this->identityOutcome($matches, $documentResult),
            fieldMatches: $matches,
            checkedAt: Carbon::now(),
            maskedReference: $this->mask($holder['idNumber'] ?? null),
            providerReference: isset($response['referenceId'])
                ? (string) $response['referenceId']
                : null,

            // No expiry. Neither a provider contract nor a written policy
            // defines one, and inventing a period would make a result look
            // stale on a date nobody chose.
            expiresAt: null,
        );
    }

    /**
     * The provider's document verdict, on FynnEdge's scale.
     *
     * A tampering signal maps to Failed, not to a separate accusation. A
     * provider reporting that a document's layout looks wrong is a finding
     * about the document; it is not a determination that a person committed
     * fraud, and FynnEdge has no system that could make one.
     */
    private function documentOutcome(string $raw): CheckOutcome
    {
        return match (strtoupper($raw)) {
            'PASS', 'PASSED', 'OK' => CheckOutcome::Passed,
            'FAIL', 'FAILED', 'TAMPER_SUSPECTED' => CheckOutcome::Failed,
            'INDETERMINATE', 'UNKNOWN' => CheckOutcome::Inconclusive,

            // An outcome this normalizer has never seen is not a pass.
            default => CheckOutcome::Inconclusive,
        };
    }

    /**
     * Whether the identity was established.
     *
     * Only ever Passed when a name actually matched. A document that passes
     * its own checks while naming somebody else has verified a document, not
     * this customer.
     *
     * @param  list<FieldMatch>  $matches
     */
    private function identityOutcome(
        array $matches,
        CheckOutcome $documentResult,
    ): CheckOutcome {
        if ($documentResult === CheckOutcome::Failed) {
            // Nothing read off a document that failed its own checks is
            // worth comparing.
            return CheckOutcome::NotPerformed;
        }

        $name = null;

        foreach ($matches as $match) {
            if ($match->field === 'name') {
                $name = $match;
            }
        }

        return match ($name?->status) {
            MatchStatus::Matched => CheckOutcome::Passed,
            MatchStatus::Mismatched => CheckOutcome::Failed,
            default => CheckOutcome::Inconclusive,
        };
    }

    /** @param array<string, mixed> $holder */
    private function name(array $holder): ?string
    {
        $name = $holder['holderName'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function nameMatch(?CustomerProfile $profile, ?string $verified): FieldMatch
    {
        $profileName = $profile?->full_name;

        if ($verified === null) {
            return FieldMatch::unableToCompare(
                'name',
                'Name',
                'The verification service did not return a name.',
            );
        }

        if ($profileName === null || trim($profileName) === '') {
            return FieldMatch::unableToCompare(
                'name',
                'Name',
                'There is no name on your profile to compare against.',
            );
        }

        $status = NameMatcher::compare($profileName, $verified);

        // Both values travel only here, where the customer has a difference
        // to resolve and needs to see what differs.
        return $status === MatchStatus::Matched
            ? FieldMatch::matched('name', 'Name')
            : FieldMatch::mismatched('name', 'Name', $profileName, $verified);
    }

    /**
     * A tail, so a customer can tell which document was checked.
     *
     * Never the number. Four characters is enough to distinguish one of a
     * person's own documents from another and not enough to be the
     * identifier.
     */
    private function mask(mixed $number): ?string
    {
        if (! is_string($number)) {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '';

        if (mb_strlen($clean) < 6) {
            return null;
        }

        return str_repeat('X', max(0, mb_strlen($clean) - 4))
            .mb_strtoupper(mb_substr($clean, -4));
    }
}
