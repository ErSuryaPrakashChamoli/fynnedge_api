<?php

namespace Tests\Unit;

use App\Domain\Kyc\CanonicalVerification;
use App\Domain\Kyc\CheckOutcome;
use App\Domain\Kyc\FieldMatch;
use App\Domain\Kyc\IdentityFieldPurpose;
use App\Domain\Kyc\MatchStatus;
use App\Domain\Kyc\VerificationNormalizationFailed;
use App\Domain\Kyc\VerificationType;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an adapter is allowed to hand FynnEdge.
 *
 * IdentityFieldPurpose lists the identity fields FynnEdge keeps and the
 * reason it refuses each of the rest. That list was, until these tests, a
 * description of how the one existing normalizer happened to behave: a new
 * adapter written against a real provider's field list could persist a PAN,
 * an address or a photograph by returning them, and nothing between the
 * adapter and the database would object.
 *
 * CanonicalVerification is the single object every adapter must produce, so
 * the refusal lives in its constructor and these tests are what prove it
 * refuses. NO LIVE KYC PROVIDER IS CONNECTED — that is exactly why this is
 * worth fixing now, while the only adapter is one FynnEdge wrote and the
 * boundary can be established before anything real crosses it.
 */
class IdentityBoundaryTest extends TestCase
{
    /** @param list<FieldMatch> $matches */
    private function verification(
        array $matches = [],
        ?string $maskedReference = null,
    ): CanonicalVerification {
        return new CanonicalVerification(
            type: VerificationType::IdentityDocument,
            documentResult: CheckOutcome::Passed,
            identityResult: CheckOutcome::Passed,
            fieldMatches: $matches,
            checkedAt: Carbon::parse('2026-09-07 10:00:00'),
            maskedReference: $maskedReference,
        );
    }

    // --- What is allowed ---------------------------------------------------

    public function test_a_kept_field_may_carry_its_values(): void
    {
        $result = $this->verification([
            FieldMatch::mismatched('name', 'Name', 'A Kumar', 'Anil Kumar'),
        ]);

        $this->assertSame('Anil Kumar', $result->match('name')?->verifiedValue);
    }

    public function test_a_discarded_field_may_be_named_without_its_value(): void
    {
        // "We did not compare this" is information. Silence would let a
        // customer assume it was checked.
        $result = $this->verification([
            FieldMatch::unableToCompare(
                'date_of_birth',
                'Date of birth',
                'FynnEdge does not hold your date of birth.',
            ),
        ]);

        $this->assertNotNull($result->match('date_of_birth'));
        $this->assertNull($result->match('date_of_birth')?->verifiedValue);
    }

    public function test_a_masked_tail_is_accepted(): void
    {
        $this->assertSame('XXXXXX000A', $this->verification([], 'XXXXXX000A')->maskedReference);
        $this->assertNull($this->verification([], null)->maskedReference);
    }

    // --- What is refused ---------------------------------------------------

    /**
     * Every field IdentityFieldPurpose refuses, driven from the list itself
     * so a field added there is covered without anyone remembering to.
     *
     * @return iterable<string, array{string}>
     */
    public static function discardedFields(): iterable
    {
        foreach (array_keys(IdentityFieldPurpose::DISCARDED) as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('discardedFields')]
    public function test_a_discarded_field_may_not_carry_a_value(string $field): void
    {
        $this->expectException(VerificationNormalizationFailed::class);

        $this->verification([
            FieldMatch::mismatched($field, 'Whatever', 'ours', 'theirs'),
        ]);
    }

    public function test_a_discarded_field_may_not_carry_only_the_verified_value(): void
    {
        $this->expectException(VerificationNormalizationFailed::class);

        $this->verification([
            new FieldMatch(
                field: 'address',
                label: 'Address',
                status: MatchStatus::UnableToCompare,
                verifiedValue: '12 Invented Street',
            ),
        ]);
    }

    public function test_an_unlisted_field_may_not_carry_a_value_either(): void
    {
        // A provider field nobody has considered is refused by default
        // rather than allowed by omission.
        $this->expectException(VerificationNormalizationFailed::class);

        $this->verification([
            FieldMatch::mismatched('pan_status', 'PAN status', 'a', 'b'),
        ]);
    }

    public function test_aadhaar_may_not_be_named_at_all(): void
    {
        $this->expectException(VerificationNormalizationFailed::class);

        // Not even to say it was not compared. FynnEdge holds no authorised
        // role in that ecosystem, so a result naming it describes a check
        // FynnEdge cannot have performed.
        $this->verification([
            FieldMatch::unableToCompare('aadhaar_number', 'Aadhaar', 'not checked'),
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function unmaskedReferences(): iterable
    {
        yield 'the number itself' => ['AAAAA0000A'];
        yield 'an Aadhaar number' => ['000000000000'];
        yield 'no masking at all' => ['1234'];
        yield 'too much left visible' => ['XX345678'];
        yield 'masked in the wrong place' => ['AAAAA0XXXX'];
        yield 'one masking character' => ['XAAAA0000A'];
        yield 'a different mask style' => ['****000A'];
        yield 'empty' => [''];
    }

    #[DataProvider('unmaskedReferences')]
    public function test_a_reference_that_is_not_a_masked_tail_is_refused(string $reference): void
    {
        $this->expectException(VerificationNormalizationFailed::class);

        $this->verification([], $reference);
    }

    // --- The list itself ---------------------------------------------------

    public function test_every_listed_field_carries_a_reason(): void
    {
        foreach (IdentityFieldPurpose::KEPT + IdentityFieldPurpose::DISCARDED as $field => $reason) {
            $this->assertGreaterThan(
                20,
                strlen($reason),
                "[{$field}] needs a sentence saying why, not a placeholder.",
            );
        }
    }

    public function test_no_field_is_both_kept_and_discarded(): void
    {
        $this->assertSame([], array_intersect(
            array_keys(IdentityFieldPurpose::KEPT),
            array_keys(IdentityFieldPurpose::DISCARDED),
        ));
    }
}
