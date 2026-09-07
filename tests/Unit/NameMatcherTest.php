<?php

namespace Tests\Unit;

use App\Domain\Kyc\MatchStatus;
use App\Domain\Kyc\NameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Whether two names are the same name.
 *
 * Every name here is invented. The rule under test is deliberately narrow:
 * normalize away what is certainly presentational, and hand anything that
 * survives to the customer rather than guessing.
 */
class NameMatcherTest extends TestCase
{
    #[DataProvider('presentational')]
    public function test_differences_that_name_the_same_person_match(
        string $profile,
        string $verified,
        string $why,
    ): void {
        $this->assertSame(
            MatchStatus::Matched,
            NameMatcher::compare($profile, $verified),
            $why,
        );
    }

    public static function presentational(): array
    {
        return [
            'identical' => ['Sample Customer', 'Sample Customer', 'identical'],
            'case' => ['Sample Customer', 'SAMPLE CUSTOMER', 'case'],
            'extra spaces' => ['Sample  Customer', ' Sample Customer ', 'whitespace'],
            'full stops' => ['Sample.Customer', 'Sample Customer', 'separator'],
            'hyphen' => ['Anne-Marie Roy', 'Anne Marie Roy', 'separator'],
            'comma' => ['Roy, Anne', 'Roy Anne', 'separator'],
            'apostrophe' => ["Sean O'Brien", 'Sean OBrien', 'intra-word mark'],
            'curly apostrophe' => ["Sean O'Brien", 'Sean O’Brien', 'unicode mark'],
            'leading honorific' => ['Sample Customer', 'Mr. Sample Customer', 'title'],
            'honorific both sides' => ['Dr Sample Customer', 'Sample Customer', 'title'],
            'shri' => ['Sample Customer', 'Shri Sample Customer', 'title'],
        ];
    }

    #[DataProvider('meaningful')]
    public function test_differences_that_might_name_someone_else_do_not_match(
        string $profile,
        string $verified,
        string $why,
    ): void {
        $this->assertSame(
            MatchStatus::Mismatched,
            NameMatcher::compare($profile, $verified),
            $why,
        );
    }

    /**
     * Each of these is a question for the customer.
     *
     * A missing surname is very often the same person, and guessing that it
     * is would be inventing agreement; guessing that it is not would be
     * inventing an accusation. FynnEdge does neither and asks.
     */
    public static function meaningful(): array
    {
        return [
            'extra name part' => [
                'Sample Customer', 'Sample Customer Verma', 'a name part was added',
            ],
            'missing name part' => [
                'Sample Customer Verma', 'Sample Customer', 'a name part is absent',
            ],
            'initial for a word' => [
                'Sample Customer', 'S Customer', 'an initial is not the word',
            ],
            'different spelling' => [
                'Sample Customer', 'Sampal Customer', 'spelling differs',
            ],
            'different order' => [
                'Sample Customer', 'Customer Sample', 'order differs',
            ],
            'different person' => [
                'Sample Customer', 'Another Person', 'a different name',
            ],
            'honorific mid-name kept' => [
                'Sample Dr Customer', 'Sample Customer', 'only a leading title is dropped',
            ],
        ];
    }

    #[DataProvider('incomparable')]
    public function test_nothing_to_compare_is_not_a_mismatch(
        ?string $profile,
        ?string $verified,
    ): void {
        // FynnEdge has nothing to set alongside the other. That is a
        // different answer from "these differ", and a customer told the
        // latter has been contradicted about something never checked.
        $this->assertSame(
            MatchStatus::UnableToCompare,
            NameMatcher::compare($profile, $verified),
        );
    }

    public static function incomparable(): array
    {
        return [
            'no profile name' => [null, 'Sample Customer'],
            'no verified name' => ['Sample Customer', null],
            'neither' => [null, null],
            'empty profile' => ['   ', 'Sample Customer'],
            'punctuation only' => ['...', 'Sample Customer'],
        ];
    }

    public function test_normalization_never_silently_drops_a_word(): void
    {
        // The one failure mode that would produce false agreement.
        $this->assertSame(
            'sample customer verma',
            NameMatcher::normalize('  Mr.  Sample   Customer-Verma  '),
        );

        $this->assertSame(
            'sample customer',
            NameMatcher::normalize('Sample Customer'),
        );
    }

    public function test_scripts_other_than_latin_are_left_alone(): void
    {
        // Normalization is presentational only. It does not transliterate,
        // because a transliteration is a guess about what a name is.
        $this->assertSame(
            MatchStatus::Matched,
            NameMatcher::compare('नमूना ग्राहक', 'नमूना ग्राहक'),
        );

        $this->assertSame(
            MatchStatus::Mismatched,
            NameMatcher::compare('नमूना ग्राहक', 'Sample Customer'),
        );
    }
}
