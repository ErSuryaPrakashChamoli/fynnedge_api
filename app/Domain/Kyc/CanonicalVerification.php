<?php

namespace App\Domain\Kyc;

use Carbon\CarbonInterface;

/**
 * What a verification service established, in FynnEdge's own vocabulary.
 *
 * No provider field name, result code or error string survives to this
 * point. Two findings are carried separately and never merged: what the
 * provider's own checks said about the document, and how the identity on it
 * compared with the customer's profile.
 *
 * This is evidence about a document. It is not the customer's profile, and
 * it is emphatically not a lending decision.
 */
final class CanonicalVerification
{
    /**
     * @param  list<FieldMatch>  $fieldMatches
     */
    public function __construct(
        public readonly VerificationType $type,

        /** What the provider's own checks said about the document. */
        public readonly CheckOutcome $documentResult,

        /** Whether the identity on it matched the customer's profile. */
        public readonly CheckOutcome $identityResult,

        public readonly array $fieldMatches,
        public readonly CarbonInterface $checkedAt,

        /**
         * A masked tail of the document number, never the number.
         *
         * Its only purpose is letting a customer tell which of their
         * documents was checked. Null when the provider gives nothing safe
         * to mask, and null always for document kinds FynnEdge will not
         * hold a reference to at all.
         */
        public readonly ?string $maskedReference = null,

        /** The provider's own reference. Operational only. */
        public readonly ?string $providerReference = null,

        /**
         * When this result stops being current.
         *
         * Null unless a provider or a written policy defines one. FynnEdge
         * does not invent an expiry: an arbitrary period would make a
         * verification look stale on a date nobody chose.
         */
        public readonly ?CarbonInterface $expiresAt = null,
    ) {
        $this->refuseWhatFynnEdgeDoesNotKeep();
    }

    /**
     * The privacy boundary, enforced where every adapter must cross it.
     *
     * IdentityFieldPurpose says which identity fields FynnEdge keeps and
     * why. Until now that policy lived in the one normalizer that happened
     * to follow it, which meant the next adapter — the one written against
     * a real provider, by someone reading a provider's field list rather
     * than this file — could persist a PAN, an address or a photograph
     * simply by returning them. A canonical result is the single thing
     * every adapter produces, so this is where the policy belongs.
     *
     * A violation is a normalization failure, not a silent drop: an adapter
     * handing over data FynnEdge refuses is a bug in that adapter, and a
     * verification that quietly discarded it would leave the bug in place
     * for the next field.
     *
     * @throws VerificationNormalizationFailed
     */
    private function refuseWhatFynnEdgeDoesNotKeep(): void
    {
        foreach ($this->fieldMatches as $match) {
            if (! IdentityFieldPurpose::mayBeNamed($match->field)) {
                throw new VerificationNormalizationFailed(
                    "a result named [{$match->field}], which FynnEdge does "
                    .'not check at all',
                );
            }

            $carriesValue = $match->profileValue !== null
                || $match->verifiedValue !== null;

            if ($carriesValue && ! IdentityFieldPurpose::mayCarryValue($match->field)) {
                throw new VerificationNormalizationFailed(
                    "a result carried the value of [{$match->field}], which "
                    .'FynnEdge discards — see IdentityFieldPurpose::DISCARDED',
                );
            }
        }

        /*
         * A tail, and demonstrably a tail.
         *
         * The shape is fixed rather than trusted: masking characters, then
         * at most four visible ones. An adapter that hands over the number
         * itself fails here instead of writing it to a column named for
         * something else.
         */
        if (
            $this->maskedReference !== null
            && preg_match('/^X{2,}[A-Z0-9]{1,4}$/', $this->maskedReference) !== 1
        ) {
            throw new VerificationNormalizationFailed(
                'a result carried a document reference that is not masked; '
                .'a reference must be masking characters followed by at '
                .'most four visible ones',
            );
        }
    }

    public function match(string $field): ?FieldMatch
    {
        foreach ($this->fieldMatches as $match) {
            if ($match->field === $field) {
                return $match;
            }
        }

        return null;
    }

    /** @return list<FieldMatch> */
    public function differences(): array
    {
        return array_values(array_filter(
            $this->fieldMatches,
            fn (FieldMatch $m) => $m->status === MatchStatus::Mismatched,
        ));
    }

    /**
     * The one status, derived from the two findings.
     *
     * Verified requires both: the provider's checks passed AND the identity
     * matched. A document that passes its checks while naming somebody else
     * has verified a document, not this customer — so that is a difference
     * for them to resolve, not a pass.
     */
    public function status(): VerificationStatus
    {
        if ($this->documentResult === CheckOutcome::Failed) {
            return VerificationStatus::NotVerified;
        }

        if ($this->differences() !== []) {
            return VerificationStatus::NeedsReview;
        }

        if (
            $this->documentResult === CheckOutcome::Inconclusive
            || $this->identityResult === CheckOutcome::Inconclusive
        ) {
            return VerificationStatus::NeedsReview;
        }

        if (
            $this->documentResult === CheckOutcome::Passed
            && $this->identityResult === CheckOutcome::Passed
        ) {
            return VerificationStatus::Verified;
        }

        // Anything left is a check that did not establish what it set out
        // to. It is not a pass, and it is not a failure of the customer.
        return VerificationStatus::NotVerified;
    }
}
